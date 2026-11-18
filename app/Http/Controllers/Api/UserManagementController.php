<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\Permission as PermissionEnum;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\UserManagementService;
use App\Support\PosContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * User, Role, Permission & Activity Log management API.
 * Route-level permission checks are applied in routes/api.php via the `permission` middleware;
 * object-level checks (outlet isolation, escalation, last super admin) live in UserManagementService.
 */
final class UserManagementController extends Controller
{
    /** Canonical action columns of the permission matrix UI. */
    private const MATRIX_ACTIONS = ['view', 'create', 'edit', 'delete', 'approve', 'cancel', 'export', 'manage'];

    /** Human readable module labels. */
    private const MODULE_LABELS = [
        'pos' => 'POS Kasir',
        'discounts' => 'Diskon',
        'overrides' => 'Otorisasi Supervisor',
        'orders' => 'Penjualan Counter',
        'shifts' => 'Shift Kasir',
        'cash_movements' => 'Kas Masuk/Keluar',
        'shift_checkin' => 'Check-in Shift Karyawan',
        'inventory' => 'Inventori',
        'batches' => 'Batch & Expiry',
        'catalog' => 'Katalog Outlet',
        'products' => 'Master Produk',
        'master_data' => 'Master Data',
        'pricing' => 'Harga & Tier',
        'procurement' => 'Pembelian (Procurement)',
        'purchase_requests' => 'Purchase Request',
        'purchase_orders' => 'Purchase Order',
        'customers' => 'Pelanggan',
        'sales_orders' => 'Sales Order B2B',
        'receivables' => 'Piutang (AR)',
        'warehouse' => 'Gudang',
        'stock_transfer' => 'Transfer Stok',
        'users' => 'User Management',
        'roles' => 'Role Management',
        'outlets' => 'Outlet',
        'company' => 'Dashboard Perusahaan',
        'audit' => 'Audit Trail',
        'activity_logs' => 'Activity Log',
    ];

    public function __construct(
        private readonly PosContext $context,
        private readonly UserManagementService $service,
    ) {}

    // =====================================================================
    // META & STATS
    // =====================================================================

    public function meta(): JsonResponse
    {
        $actor = $this->actor();

        $outlets = Outlet::query()->withoutGlobalScopes()
            ->where('organization_id', $actor->organization_id)
            ->whereIn('id', $actor->accessibleOutletIds())
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'city']);

        $allRoles = Role::query()
            ->visibleTo((int) $actor->organization_id)
            ->orderByDesc('level')
            ->get(['id', 'name', 'display_name', 'level', 'is_system', 'is_active']);

        $assignableIds = $this->service->assignableRoles($actor)->pluck('id')->all();

        return response()->json([
            'stats' => $this->stats($actor),
            'outlets' => $outlets,
            'roles' => $allRoles->map(static fn (Role $r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'display_name' => $r->display_name,
                'level' => $r->level,
                'is_system' => $r->is_system,
                'is_active' => $r->is_active,
                'assignable' => in_array($r->id, $assignableIds, true),
            ])->values(),
            'actor' => [
                'id' => $actor->id,
                'is_super_admin' => $actor->isSuperAdmin(),
                'permissions' => $actor->permissionNames(),
            ],
        ]);
    }

    // =====================================================================
    // USERS
    // =====================================================================

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role_id' => ['nullable', 'integer'],
            'filter_outlet_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'SUSPENDED', 'DELETED', 'ALL'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->scopedUserQuery($actor)
            ->with(['roles:id,name,display_name,level', 'outlets' => static fn ($q) => $q->withoutGlobalScopes()->select('outlets.id', 'outlets.code', 'outlets.name')]);

        $status = $validated['status'] ?? 'ALL';
        match ($status) {
            'ACTIVE' => $query->where('is_active', true),
            'SUSPENDED' => $query->where('is_active', false),
            'DELETED' => $query->onlyTrashed(),
            default => null,
        };

        if (! empty($validated['search'])) {
            $like = '%'.mb_strtolower($validated['search']).'%';
            $query->where(static function (Builder $q) use ($like): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(username) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            });
        }

        if (! empty($validated['role_id'])) {
            $roleId = (int) $validated['role_id'];
            $query->whereHas('roles', static fn ($q) => $q->where('roles.id', $roleId));
        }

        if (! empty($validated['filter_outlet_id'])) {
            $outletId = (int) $validated['filter_outlet_id'];
            if (! $actor->canAccessOutlet($outletId)) {
                throw new BusinessRuleException('OUTLET_ACCESS_DENIED', 'Anda tidak memiliki akses ke outlet tersebut.', 403);
            }
            $query->whereExists(static function ($q) use ($outletId): void {
                $q->select(DB::raw(1))->from('user_outlets')
                    ->whereColumn('user_outlets.user_id', 'users.id')
                    ->where('user_outlets.outlet_id', $outletId);
            });
        }

        $paginator = $query->orderBy('name')->paginate((int) ($validated['per_page'] ?? 15));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (User $u): array => $this->presentUser($u, $actor))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'stats' => $this->stats($actor),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $actor = $this->actor();
        $user = $this->findUser($actor, $id, withTrashed: true);
        $user->load(['roles.permissions:id,name,group', 'outlets' => static fn ($q) => $q->withoutGlobalScopes()]);

        $recentActivity = AuditLog::query()->withoutGlobalScopes()
            ->where('organization_id', $actor->organization_id)
            ->where(static function (Builder $q) use ($user): void {
                $q->where('actor_id', $user->id)
                    ->orWhere(static fn (Builder $w) => $w->where('entity_type', 'User')->where('entity_id', (string) $user->id));
            })
            ->orderByDesc('created_at')
            ->limit(15)
            ->get(['id', 'action_event', 'actor_id', 'actor_name_snapshot', 'entity_type', 'entity_id', 'outlet_id', 'ip_address', 'created_at', 'new_values']);

        return response()->json([
            'user' => array_merge($this->presentUser($user, $actor), [
                'permissions' => $user->permissionNames(),
                'active_sessions' => $user->tokens()->count(),
                'suspension_reason' => $user->suspension_reason,
                'suspended_at' => $user->suspended_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'deleted_at' => $user->deleted_at?->toIso8601String(),
            ]),
            'recent_activity' => $recentActivity->map(static fn (AuditLog $l): array => [
                'id' => $l->id,
                'action_event' => $l->action_event->value,
                'label' => $l->action_event->label(),
                'actor_name' => $l->actor_name_snapshot,
                'is_actor' => (int) $l->actor_id === (int) $user->id,
                'ip_address' => $l->ip_address,
                'created_at' => $l->created_at->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'username')],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer'],
            'outlet_ids' => ['nullable', 'array'],
            'outlet_ids.*' => ['integer'],
            'default_outlet_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'regex:/^[0-9]{4,8}$/'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'username.unique' => 'Username sudah digunakan (termasuk akun yang telah dihapus).',
            'email.unique' => 'Email sudah digunakan (termasuk akun yang telah dihapus).',
            'username.regex' => 'Username hanya boleh berisi huruf, angka, titik, underscore, dan strip.',
            'pin.regex' => 'PIN harus 4-8 digit angka.',
        ]);

        $user = $this->service->createUser($actor, $data);
        $user->load(['roles:id,name,display_name,level', 'outlets' => static fn ($q) => $q->withoutGlobalScopes()]);

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'user' => $this->presentUser($user, $actor),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor();
        $user = $this->findUser($actor, $id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['sometimes', 'required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role_ids' => ['sometimes', 'array', 'min:1'],
            'role_ids.*' => ['integer'],
            'outlet_ids' => ['sometimes', 'array'],
            'outlet_ids.*' => ['integer'],
            'default_outlet_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'regex:/^[0-9]{4,8}$/'],
            'clear_pin' => ['nullable', 'boolean'],
        ], [
            'username.unique' => 'Username sudah digunakan.',
            'email.unique' => 'Email sudah digunakan.',
            'pin.regex' => 'PIN harus 4-8 digit angka.',
        ]);

        $user = $this->service->updateUser($actor, $user, $data);
        $user->load(['roles:id,name,display_name,level', 'outlets' => static fn ($q) => $q->withoutGlobalScopes()]);

        return response()->json([
            'message' => 'User berhasil diperbarui.',
            'user' => $this->presentUser($user, $actor),
        ]);
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor();
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $user = $this->service->suspendUser($actor, $this->findUser($actor, $id), $data['reason'] ?? null);

        return response()->json(['message' => 'Akun berhasil di-suspend dan seluruh sesi aktif dicabut.', 'status' => $user->accountStatus()]);
    }

    public function activate(int $id): JsonResponse
    {
        $actor = $this->actor();
        $user = $this->service->activateUser($actor, $this->findUser($actor, $id));

        return response()->json(['message' => 'Akun berhasil diaktifkan kembali.', 'status' => $user->accountStatus()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $actor = $this->actor();
        $this->service->deleteUser($actor, $this->findUser($actor, $id));

        return response()->json(['message' => 'Akun berhasil dihapus (soft delete).']);
    }

    public function restore(int $id): JsonResponse
    {
        $actor = $this->actor();
        $user = $this->service->restoreUser($actor, $this->findUser($actor, $id, withTrashed: true));

        return response()->json(['message' => 'Akun dipulihkan dalam status SUSPENDED. Aktifkan untuk mengizinkan login.', 'status' => $user->accountStatus()]);
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor();
        $data = $request->validate([
            'mode' => ['required', Rule::in(['manual', 'generate'])],
            'password' => ['required_if:mode,manual', 'nullable', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'revoke_sessions' => ['nullable', 'boolean'],
        ]);

        $generated = $this->service->resetPassword(
            $actor,
            $this->findUser($actor, $id),
            $data['mode'] === 'manual' ? (string) $data['password'] : null,
            (bool) ($data['revoke_sessions'] ?? true),
        );

        return response()->json([
            'message' => 'Password berhasil direset.',
            'temporary_password' => $generated,
        ]);
    }

    // =====================================================================
    // ROLES
    // =====================================================================

    public function rolesIndex(Request $request): JsonResponse
    {
        $actor = $this->actor();
        $search = trim((string) $request->query('search', ''));

        $query = Role::query()
            ->visibleTo((int) $actor->organization_id)
            ->withCount(['permissions'])
            ->with('permissions:id,name');

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(static fn (Builder $q) => $q->whereRaw('LOWER(display_name) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like]));
        }

        $roles = $query->orderByDesc('level')->orderBy('display_name')->get();

        $userCounts = DB::table('model_has_roles')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('users.organization_id', $actor->organization_id)
            ->whereNull('users.deleted_at')
            ->groupBy('model_has_roles.role_id')
            ->selectRaw('model_has_roles.role_id, COUNT(*) as c')
            ->pluck('c', 'role_id');

        $assignableIds = $this->service->assignableRoles($actor)->pluck('id')->all();
        $actorRoleIds = $actor->roles->pluck('id')->all();
        $actorPerms = $actor->permissionNames();
        $isSuper = $actor->isSuperAdmin();

        return response()->json([
            'data' => $roles->map(static function (Role $r) use ($userCounts, $assignableIds, $actorRoleIds, $actorPerms, $isSuper): array {
                $perms = $r->permissions->pluck('name')->all();
                $withinActor = collect($perms)->diff($actorPerms)->isEmpty();
                $editable = ! $r->isSuperAdminRole() && ($isSuper || (! $r->is_system && ! in_array($r->id, $actorRoleIds, true) && $withinActor));

                return [
                    'id' => $r->id,
                    'name' => $r->name,
                    'display_name' => $r->display_name,
                    'description' => $r->description,
                    'level' => $r->level,
                    'is_system' => $r->is_system,
                    'is_active' => $r->is_active,
                    'is_super_admin' => $r->isSuperAdminRole(),
                    'permissions_count' => $r->isSuperAdminRole() ? Permission::query()->count() : $r->permissions_count,
                    'permission_names' => $perms,
                    'users_count' => (int) ($userCounts[$r->id] ?? 0),
                    'assignable' => in_array($r->id, $assignableIds, true),
                    'editable' => $editable,
                    'deletable' => $editable && (int) ($userCounts[$r->id] ?? 0) === 0,
                    'updated_at' => $r->updated_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    public function rolesShow(int $id): JsonResponse
    {
        $actor = $this->actor();
        $role = $this->findRole($actor, $id);
        $role->load('permissions:id,name,group,description');

        return response()->json([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'level' => $role->level,
                'is_system' => $role->is_system,
                'is_active' => $role->is_active,
                'is_super_admin' => $role->isSuperAdminRole(),
                'permission_ids' => $role->isSuperAdminRole()
                    ? Permission::query()->pluck('id')->all()
                    : $role->permissions->pluck('id')->all(),
            ],
        ]);
    }

    public function rolesStore(Request $request): JsonResponse
    {
        $actor = $this->actor();
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'integer', 'min:0', 'max:99'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->assertUniqueRoleDisplayName($actor, $data['display_name']);
        $role = $this->service->createRole($actor, $data);

        return response()->json(['message' => 'Role berhasil dibuat.', 'role_id' => $role->id], 201);
    }

    public function rolesUpdate(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor();
        $role = $this->findRole($actor, $id);
        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'integer', 'min:0', 'max:99'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['display_name'])) {
            $this->assertUniqueRoleDisplayName($actor, $data['display_name'], $role->id);
        }

        $this->service->updateRole($actor, $role, $data);

        return response()->json(['message' => 'Role berhasil diperbarui.']);
    }

    public function rolesDestroy(int $id): JsonResponse
    {
        $actor = $this->actor();
        $this->service->deleteRole($actor, $this->findRole($actor, $id));

        return response()->json(['message' => 'Role berhasil dihapus.']);
    }

    // =====================================================================
    // PERMISSIONS
    // =====================================================================

    public function permissionsIndex(): JsonResponse
    {
        $actor = $this->actor();
        $permissions = Permission::query()->orderBy('group')->orderBy('name')->get(['id', 'name', 'group', 'description']);

        $roles = Role::query()
            ->visibleTo((int) $actor->organization_id)
            ->with('permissions:id')
            ->orderByDesc('level')
            ->get(['id', 'name', 'display_name', 'level', 'is_system', 'is_active']);

        $actorPerms = $actor->permissionNames();
        $isSuper = $actor->isSuperAdmin();

        $modules = $permissions->groupBy('group')->map(function ($items, string $group) use ($actorPerms, $isSuper): array {
            return [
                'module' => $group,
                'label' => self::MODULE_LABELS[$group] ?? ucwords(str_replace('_', ' ', $group)),
                'permissions' => $items->map(static function (Permission $p) use ($actorPerms, $isSuper): array {
                    $action = explode('.', $p->name, 2)[1] ?? 'general';

                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'action' => $action,
                        'is_standard_action' => in_array($action, self::MATRIX_ACTIONS, true),
                        'description' => $p->description,
                        'grantable' => $isSuper || in_array($p->name, $actorPerms, true),
                    ];
                })->values()->all(),
            ];
        })->values();

        $allIds = $permissions->pluck('id')->all();

        return response()->json([
            'actions' => self::MATRIX_ACTIONS,
            'modules' => $modules,
            'roles' => $roles->map(static fn (Role $r): array => [
                'id' => $r->id,
                'display_name' => $r->display_name,
                'is_system' => $r->is_system,
                'is_active' => $r->is_active,
                'is_super_admin' => $r->isSuperAdminRole(),
                'permission_ids' => $r->isSuperAdminRole() ? $allIds : $r->permissions->pluck('id')->all(),
            ])->values(),
            'total_permissions' => count($allIds),
        ]);
    }

    // =====================================================================
    // ACTIVITY LOG (user-management & auth events)
    // =====================================================================

    public function activityLogs(Request $request): JsonResponse
    {
        $actor = $this->actor();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'action_event' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', Rule::in(['auth', 'users', 'all'])],
            'actor_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $category = $validated['category'] ?? 'all';
        $events = collect(AuditAction::cases())
            ->filter(static fn (AuditAction $a): bool => $category === 'all'
                ? in_array($a->category(), ['auth', 'users'], true)
                : $a->category() === $category)
            ->map(static fn (AuditAction $a): string => $a->value)
            ->values()
            ->all();

        $query = AuditLog::query()->withoutGlobalScopes()
            ->with(['outlet:id,code,name'])
            ->where('organization_id', $actor->organization_id)
            ->whereIn('action_event', $events);

        // Outlet isolation for non super admins
        $manageable = $this->service->manageableUserIds($actor);
        if ($manageable !== null) {
            $outletIds = $actor->accessibleOutletIds();
            $query->where(static function (Builder $q) use ($outletIds, $manageable): void {
                $q->whereIn('outlet_id', $outletIds === [] ? [0] : $outletIds)
                    ->orWhereIn('actor_id', $manageable)
                    ->orWhere(static fn (Builder $w) => $w->where('entity_type', 'User')->whereIn('entity_id', array_map('strval', $manageable)));
            });
        }

        if (! empty($validated['action_event'])) {
            $query->where('action_event', $validated['action_event']);
        }
        if (! empty($validated['actor_id'])) {
            $query->where('actor_id', (int) $validated['actor_id']);
        }
        if (! empty($validated['date_from'])) {
            $query->where('created_at', '>=', $validated['date_from'].' 00:00:00');
        }
        if (! empty($validated['date_to'])) {
            $query->where('created_at', '<=', $validated['date_to'].' 23:59:59');
        }
        if (! empty($validated['search'])) {
            $like = '%'.mb_strtolower($validated['search']).'%';
            $query->where(static function (Builder $q) use ($like): void {
                $q->whereRaw('LOWER(actor_name_snapshot) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(new_values, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(ip_address, \'\')) LIKE ?', [$like]);
            });
        }

        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 25));

        // Resolve target user names for entity_type = User
        $items = collect($paginator->items());
        $targetIds = $items->filter(static fn (AuditLog $l): bool => $l->entity_type === 'User' && $l->entity_id !== null)
            ->pluck('entity_id')->map(static fn ($v): int => (int) $v)->unique()->all();
        $targets = User::withTrashed()->whereIn('id', $targetIds)->get(['id', 'name', 'username'])->keyBy('id');

        return response()->json([
            'data' => $items->map(static function (AuditLog $l) use ($targets): array {
                $target = $l->entity_type === 'User' && $l->entity_id !== null ? $targets->get((int) $l->entity_id) : null;

                return [
                    'id' => $l->id,
                    'action_event' => $l->action_event->value,
                    'label' => $l->action_event->label(),
                    'category' => $l->action_event->category(),
                    'severity' => $l->action_event->severity(),
                    'actor_id' => $l->actor_id,
                    'actor_name' => $l->actor_name_snapshot,
                    'actor_role' => $l->actor_role_snapshot,
                    'entity_type' => $l->entity_type,
                    'entity_id' => $l->entity_id,
                    'target_name' => $target?->name,
                    'target_username' => $target?->username,
                    'outlet' => $l->outlet ? ['id' => $l->outlet->id, 'code' => $l->outlet->code, 'name' => $l->outlet->name] : null,
                    'old_values' => $l->old_values,
                    'new_values' => $l->new_values,
                    'metadata' => collect($l->metadata ?? [])->except(['user_agent'])->all(),
                    'ip_address' => $l->ip_address,
                    'created_at' => $l->created_at->toIso8601String(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filter_options' => [
                'actions' => collect(AuditAction::cases())
                    ->filter(static fn (AuditAction $a): bool => in_array($a->category(), ['auth', 'users'], true))
                    ->map(static fn (AuditAction $a): array => ['value' => $a->value, 'label' => $a->label(), 'category' => $a->category()])
                    ->values(),
                'actors' => $this->scopedUserQuery($actor)->withTrashed()->orderBy('name')->get(['id', 'name', 'username']),
            ],
        ]);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function actor(): User
    {
        $actor = $this->context->requireUser();
        $actor->loadMissing('roles.permissions');

        return $actor;
    }

    /** @return Builder<User> */
    private function scopedUserQuery(User $actor): Builder
    {
        $query = User::query()->where('organization_id', $actor->organization_id);
        $ids = $this->service->manageableUserIds($actor);
        if ($ids !== null) {
            $query->whereIn('id', $ids === [] ? [0] : $ids);
        }

        return $query;
    }

    private function findUser(User $actor, int $id, bool $withTrashed = false): User
    {
        $query = $this->scopedUserQuery($actor);
        if ($withTrashed) {
            $query->withTrashed();
        }

        $user = $query->find($id);
        if ($user === null) {
            throw new BusinessRuleException('USER_NOT_FOUND', 'User tidak ditemukan atau berada di luar outlet yang Anda kelola.', 404);
        }

        return $user;
    }

    private function findRole(User $actor, int $id): Role
    {
        $role = Role::query()->visibleTo((int) $actor->organization_id)->find($id);
        if ($role === null) {
            throw new BusinessRuleException('ROLE_NOT_FOUND', 'Role tidak ditemukan.', 404);
        }

        return $role;
    }

    private function assertUniqueRoleDisplayName(User $actor, string $displayName, ?int $ignoreId = null): void
    {
        $exists = Role::query()
            ->visibleTo((int) $actor->organization_id)
            ->whereRaw('LOWER(display_name) = ?', [mb_strtolower(trim($displayName))])
            ->when($ignoreId, static fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw new BusinessRuleException('ROLE_NAME_TAKEN', 'Nama role sudah digunakan.', 422);
        }
    }

    /** @return array<string, int> */
    private function stats(User $actor): array
    {
        $base = fn () => $this->scopedUserQuery($actor);

        return [
            'total' => $base()->count(),
            'active' => $base()->where('is_active', true)->count(),
            'suspended' => $base()->where('is_active', false)->count(),
            'deleted' => $base()->onlyTrashed()->count(),
            'logged_in_today' => $base()->where('last_login_at', '>=', now()->startOfDay())->count(),
            'never_logged_in' => $base()->whereNull('last_login_at')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentUser(User $user, User $actor): array
    {
        $user->loadMissing(['roles', 'outlets' => static fn ($q) => $q->withoutGlobalScopes()]);
        $isSuper = $user->isSuperAdmin();
        $isSelf = $user->id === $actor->id;
        $defaultOutlet = $user->outlets->first(static fn ($o): bool => (bool) $o->pivot?->is_default);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'status' => $user->accountStatus(),
            'is_super_admin' => $isSuper,
            'is_self' => $isSelf,
            'has_pin' => $user->pin_hash !== null,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'roles' => $user->roles->sortByDesc('level')->map(static fn (Role $r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'display_name' => $r->display_name,
                'level' => $r->level,
            ])->values()->all(),
            'outlets' => $user->outlets->map(static fn ($o): array => [
                'id' => $o->id,
                'code' => $o->code,
                'name' => $o->name,
                'is_default' => (bool) $o->pivot?->is_default,
            ])->values()->all(),
            'all_outlets_access' => $isSuper,
            'default_outlet_id' => $defaultOutlet?->id,
            'suspended_at' => $user->suspended_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
