<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business rules for User, Role & Permission management.
 *
 * Security invariants enforced here (backend, not UI):
 *  - All operations are scoped to the actor's organization.
 *  - Non Super Admin actors may only manage users whose outlets are all within their own outlets.
 *  - Nobody may change their own roles / status (no self-escalation, no self-lockout).
 *  - Non Super Admin actors may only grant roles whose permissions they already hold.
 *  - Only Super Admins may assign/manage the Super Admin role or Super Admin accounts.
 *  - The last active Super Admin can never be suspended, deleted or demoted.
 */
final class UserManagementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    // ---------------------------------------------------------------------
    // Users
    // ---------------------------------------------------------------------

    /**
     * @param  array{name: string, username: string, email: string, password: string, role_ids: list<int>,
     *     outlet_ids?: list<int>, default_outlet_id?: int|null, pin?: string|null, is_active?: bool}  $data
     */
    public function createUser(User $actor, array $data): User
    {
        $roles = $this->resolveAssignableRoles($actor, $data['role_ids']);
        $outletIds = $this->resolveAssignableOutletIds($actor, $data['outlet_ids'] ?? [], $roles);

        return DB::transaction(function () use ($actor, $data, $roles, $outletIds): User {
            $user = new User;
            $user->fill([
                'organization_id' => $actor->organization_id,
                'name' => trim($data['name']),
                'username' => mb_strtolower(trim($data['username'])),
                'email' => mb_strtolower(trim($data['email'])),
                'password' => $data['password'], // hashed by cast
                'is_active' => $data['is_active'] ?? true,
            ]);
            if (! empty($data['pin'])) {
                $user->pin_hash = \Illuminate\Support\Facades\Hash::make((string) $data['pin']);
            }
            if (! $user->is_active) {
                $user->suspended_at = now();
            }
            $user->save();

            $user->roles()->sync($roles->pluck('id')->all());
            $this->syncOutlets($user, $outletIds, $data['default_outlet_id'] ?? null);
            $user->flushAccessCache();

            $this->audit->record(
                AuditAction::USER_CREATED,
                $user,
                newValues: $this->snapshot($user),
                actor: $actor,
            );

            return $user;
        });
    }

    /**
     * @param  array{name?: string, username?: string, email?: string, role_ids?: list<int>,
     *     outlet_ids?: list<int>, default_outlet_id?: int|null, pin?: string|null, clear_pin?: bool}  $data
     */
    public function updateUser(User $actor, User $target, array $data): User
    {
        $this->assertCanManage($actor, $target);
        $isSelf = $actor->id === $target->id;

        $rolesChanging = array_key_exists('role_ids', $data);
        $outletsChanging = array_key_exists('outlet_ids', $data);

        $currentRoleIds = $target->roles()->pluck('roles.id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        $newRoleIds = $rolesChanging
            ? collect($data['role_ids'])->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all()
            : $currentRoleIds;
        $rolesChanging = $rolesChanging && $newRoleIds !== $currentRoleIds;

        $currentOutletIds = $target->outlets()->withoutGlobalScopes()->pluck('outlets.id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();

        if ($isSelf && $rolesChanging) {
            throw new BusinessRuleException('SELF_ROLE_CHANGE_FORBIDDEN', 'Anda tidak dapat mengubah role akun Anda sendiri.', 403);
        }
        if ($isSelf && $outletsChanging && ! $actor->isSuperAdmin()) {
            $requested = collect($data['outlet_ids'])->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            if ($requested !== $currentOutletIds) {
                throw new BusinessRuleException('SELF_OUTLET_CHANGE_FORBIDDEN', 'Anda tidak dapat mengubah penempatan outlet akun Anda sendiri.', 403);
            }
        }

        $roles = $rolesChanging
            ? $this->resolveAssignableRoles($actor, $newRoleIds)
            : Role::query()->whereIn('id', $currentRoleIds)->get();

        if ($rolesChanging) {
            $removingSuperAdmin = $target->isSuperAdmin() && ! $roles->contains(static fn (Role $r): bool => $r->isSuperAdminRole());
            if ($removingSuperAdmin) {
                $this->assertNotLastSuperAdmin($target, 'menurunkan role');
            }
        }

        $outletIds = $outletsChanging
            ? $this->resolveAssignableOutletIds($actor, $data['outlet_ids'], $roles, $currentOutletIds)
            : $currentOutletIds;

        return DB::transaction(function () use ($actor, $target, $data, $roles, $rolesChanging, $outletsChanging, $outletIds): User {
            $before = $this->snapshot($target);

            foreach (['name', 'username', 'email'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null) {
                    $value = trim((string) $data[$field]);
                    $target->{$field} = $field === 'name' ? $value : mb_strtolower($value);
                }
            }
            if (! empty($data['pin'])) {
                $target->pin_hash = \Illuminate\Support\Facades\Hash::make((string) $data['pin']);
            } elseif (! empty($data['clear_pin'])) {
                $target->pin_hash = null;
            }
            $target->save();

            if ($rolesChanging) {
                $target->roles()->sync($roles->pluck('id')->all());
            }
            if ($outletsChanging || array_key_exists('default_outlet_id', $data)) {
                $this->syncOutlets($target, $outletIds, $data['default_outlet_id'] ?? null);
            }

            $target->flushAccessCache();
            $target->refresh();
            $after = $this->snapshot($target);

            $this->audit->record(
                AuditAction::USER_UPDATED,
                $target,
                oldValues: $before,
                newValues: $after,
                metadata: ['roles_changed' => $rolesChanging, 'outlets_changed' => $outletsChanging],
                actor: $actor,
            );

            // Role or outlet change => revoke active sessions so new access rules apply immediately
            if ($rolesChanging || ($outletsChanging && $before['outlet_ids'] !== $after['outlet_ids'])) {
                if ($actor->id !== $target->id) {
                    $target->tokens()->delete();
                }
            }

            return $target;
        });
    }

    public function suspendUser(User $actor, User $target, ?string $reason = null): User
    {
        $this->assertCanManage($actor, $target);
        if ($actor->id === $target->id) {
            throw new BusinessRuleException('SELF_SUSPEND_FORBIDDEN', 'Anda tidak dapat men-suspend akun Anda sendiri.', 403);
        }
        if (! $target->is_active) {
            throw new BusinessRuleException('USER_ALREADY_SUSPENDED', 'Akun ini sudah dalam status SUSPENDED.', 422);
        }
        $this->assertNotLastSuperAdmin($target, 'men-suspend');

        return DB::transaction(function () use ($actor, $target, $reason): User {
            $target->forceFill([
                'is_active' => false,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ])->save();

            $revoked = $target->tokens()->delete();

            $this->audit->record(
                AuditAction::USER_SUSPENDED,
                $target,
                oldValues: ['status' => 'ACTIVE'],
                newValues: ['status' => 'SUSPENDED', 'reason' => $reason, 'sessions_revoked' => $revoked],
                actor: $actor,
            );

            return $target;
        });
    }

    public function activateUser(User $actor, User $target): User
    {
        $this->assertCanManage($actor, $target);
        if ($target->is_active) {
            throw new BusinessRuleException('USER_ALREADY_ACTIVE', 'Akun ini sudah dalam status ACTIVE.', 422);
        }

        $target->forceFill([
            'is_active' => true,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        $this->audit->record(
            AuditAction::USER_ACTIVATED,
            $target,
            oldValues: ['status' => 'SUSPENDED'],
            newValues: ['status' => 'ACTIVE'],
            actor: $actor,
        );

        return $target;
    }

    public function deleteUser(User $actor, User $target): void
    {
        $this->assertCanManage($actor, $target);
        if ($actor->id === $target->id) {
            throw new BusinessRuleException('SELF_DELETE_FORBIDDEN', 'Anda tidak dapat menghapus akun Anda sendiri.', 403);
        }
        $this->assertNotLastSuperAdmin($target, 'menghapus');

        DB::transaction(function () use ($actor, $target): void {
            $before = $this->snapshot($target);
            $target->tokens()->delete();
            $target->forceFill(['is_active' => false])->save();
            $target->delete(); // soft delete

            $this->audit->record(
                AuditAction::USER_DELETED,
                $target,
                oldValues: $before,
                newValues: ['status' => 'DELETED'],
                actor: $actor,
            );
        });
    }

    public function restoreUser(User $actor, User $target): User
    {
        if (! $target->trashed()) {
            throw new BusinessRuleException('USER_NOT_DELETED', 'Akun ini tidak dalam status DELETED.', 422);
        }
        $this->assertCanManage($actor, $target);

        $target->restore();
        // Restored accounts start suspended so an admin must explicitly re-activate them
        $target->forceFill(['is_active' => false, 'suspended_at' => now(), 'suspension_reason' => 'Dipulihkan dari penghapusan'])->save();

        $this->audit->record(
            AuditAction::USER_RESTORED,
            $target,
            oldValues: ['status' => 'DELETED'],
            newValues: ['status' => 'SUSPENDED'],
            actor: $actor,
        );

        return $target;
    }

    /**
     * @return string|null generated temporary password when $newPassword is null
     */
    public function resetPassword(User $actor, User $target, ?string $newPassword = null, bool $revokeSessions = true): ?string
    {
        $this->assertCanManage($actor, $target);

        $generated = null;
        if ($newPassword === null || $newPassword === '') {
            $generated = Str::password(12, symbols: false);
            $newPassword = $generated;
        }

        $target->password = $newPassword; // hashed by cast
        $target->save();

        $revoked = 0;
        if ($revokeSessions && $actor->id !== $target->id) {
            $revoked = $target->tokens()->delete();
        }

        $this->audit->record(
            AuditAction::USER_PASSWORD_RESET,
            $target,
            newValues: [
                'generated' => $generated !== null,
                'sessions_revoked' => $revoked,
            ],
            actor: $actor,
        );

        return $generated;
    }

    // ---------------------------------------------------------------------
    // Roles
    // ---------------------------------------------------------------------

    /**
     * @param  array{display_name: string, description?: string|null, level?: int|null, permission_ids?: list<int>, is_active?: bool}  $data
     */
    public function createRole(User $actor, array $data): Role
    {
        $level = (int) ($data['level'] ?? 20);
        $this->assertRoleLevelAllowed($actor, $level);
        $permissionIds = $this->resolveGrantablePermissionIds($actor, $data['permission_ids'] ?? []);

        return DB::transaction(function () use ($actor, $data, $level, $permissionIds): Role {
            $role = Role::query()->create([
                'organization_id' => $actor->organization_id,
                'name' => $this->uniqueRoleName((string) $data['display_name'], (int) $actor->organization_id),
                'display_name' => trim((string) $data['display_name']),
                'description' => $data['description'] ?? null,
                'level' => $level,
                'is_system' => false,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $role->permissions()->sync($permissionIds);

            $this->audit->record(
                AuditAction::ROLE_CREATED,
                $role,
                newValues: $this->roleSnapshot($role),
                actor: $actor,
            );

            return $role;
        });
    }

    /**
     * @param  array{display_name?: string, description?: string|null, level?: int|null, permission_ids?: list<int>, is_active?: bool}  $data
     */
    public function updateRole(User $actor, Role $role, array $data): Role
    {
        $this->assertCanManageRole($actor, $role);

        if ($role->isSuperAdminRole()) {
            throw new BusinessRuleException('SUPER_ADMIN_ROLE_LOCKED', 'Role Super Admin bersifat tetap dan selalu memiliki seluruh permission.', 422);
        }

        if (array_key_exists('level', $data) && $data['level'] !== null) {
            $this->assertRoleLevelAllowed($actor, (int) $data['level']);
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] === false && $role->users()->exists()) {
            throw new BusinessRuleException('ROLE_IN_USE', 'Role yang masih digunakan user tidak dapat dinonaktifkan.', 422);
        }

        $permissionsChanging = array_key_exists('permission_ids', $data);
        $permissionIds = $permissionsChanging
            ? $this->resolveGrantablePermissionIds($actor, $data['permission_ids'], $role)
            : null;

        return DB::transaction(function () use ($actor, $role, $data, $permissionIds): Role {
            $before = $this->roleSnapshot($role);

            $fill = [];
            if (isset($data['display_name'])) {
                $fill['display_name'] = trim((string) $data['display_name']);
            }
            if (array_key_exists('description', $data)) {
                $fill['description'] = $data['description'];
            }
            if (array_key_exists('level', $data) && $data['level'] !== null) {
                $fill['level'] = (int) $data['level'];
            }
            if (array_key_exists('is_active', $data)) {
                $fill['is_active'] = (bool) $data['is_active'];
            }
            $role->fill($fill)->save();

            if ($permissionIds !== null) {
                $role->permissions()->sync($permissionIds);
            }

            $role->load('permissions');
            $after = $this->roleSnapshot($role);

            $permissionsChanged = $before['permissions'] !== $after['permissions'];

            $this->audit->record(
                $permissionsChanged ? AuditAction::ROLE_PERMISSIONS_UPDATED : AuditAction::ROLE_UPDATED,
                $role,
                oldValues: $before,
                newValues: $after,
                metadata: [
                    'permissions_added' => array_values(array_diff($after['permissions'], $before['permissions'])),
                    'permissions_removed' => array_values(array_diff($before['permissions'], $after['permissions'])),
                ],
                actor: $actor,
            );

            return $role;
        });
    }

    public function deleteRole(User $actor, Role $role): void
    {
        $this->assertCanManageRole($actor, $role);

        if ($role->isSuperAdminRole()) {
            throw new BusinessRuleException('SUPER_ADMIN_ROLE_LOCKED', 'Role Super Admin tidak dapat dihapus.', 422);
        }

        $usersCount = $role->users()->withTrashed()->count();
        if ($usersCount > 0) {
            throw new BusinessRuleException(
                'ROLE_IN_USE',
                "Role tidak dapat dihapus karena masih digunakan oleh {$usersCount} user.",
                422,
                ['users_count' => $usersCount],
            );
        }

        DB::transaction(function () use ($actor, $role): void {
            $before = $this->roleSnapshot($role);
            $role->permissions()->detach();
            $role->delete();

            $this->audit->record(
                AuditAction::ROLE_DELETED,
                'Role',
                oldValues: $before,
                metadata: ['role_id' => $before['id']],
                actor: $actor,
            );
        });
    }

    // ---------------------------------------------------------------------
    // Access scope helpers
    // ---------------------------------------------------------------------

    /**
     * IDs of users the actor may see/manage. null = all users of the organization.
     *
     * @return list<int>|null
     */
    public function manageableUserIds(User $actor): ?array
    {
        if ($actor->isSuperAdmin()) {
            return null;
        }

        $actorOutletIds = $actor->accessibleOutletIds();

        // Users that have at least one outlet outside of the actor's outlets are excluded
        $outsiders = DB::table('user_outlets')
            ->whereNotIn('outlet_id', $actorOutletIds === [] ? [0] : $actorOutletIds)
            ->pluck('user_id')
            ->all();

        $insiders = DB::table('user_outlets')
            ->whereIn('outlet_id', $actorOutletIds === [] ? [0] : $actorOutletIds)
            ->pluck('user_id')
            ->all();

        $superAdminIds = $this->superAdminUserIds();

        return collect($insiders)
            ->map(static fn ($id): int => (int) $id)
            ->diff(array_map('intval', $outsiders))
            ->diff($superAdminIds)
            ->push($actor->id)
            ->unique()
            ->values()
            ->all();
    }

    public function assertCanManage(User $actor, User $target): void
    {
        if ((int) $actor->organization_id !== (int) $target->organization_id) {
            throw new BusinessRuleException('USER_NOT_FOUND', 'User tidak ditemukan.', 404);
        }

        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($target->isSuperAdmin()) {
            throw new BusinessRuleException('SUPER_ADMIN_PROTECTED', 'Hanya Super Admin yang dapat mengelola akun Super Admin.', 403);
        }

        $ids = $this->manageableUserIds($actor);
        if ($ids !== null && ! in_array((int) $target->id, $ids, true)) {
            throw new BusinessRuleException('OUTLET_ACCESS_DENIED', 'User ini berada di luar outlet yang Anda kelola.', 403);
        }
    }

    /** @return list<int> */
    public function superAdminUserIds(): array
    {
        $roleId = Role::query()->where('name', RoleName::SuperAdmin->value)->value('id');
        if ($roleId === null) {
            return [];
        }

        return DB::table('model_has_roles')
            ->where('role_id', $roleId)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** Roles the actor is allowed to assign (subset of permissions rule). */
    public function assignableRoles(User $actor): Collection
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->visibleTo((int) $actor->organization_id)
            ->where('is_active', true)
            ->orderByDesc('level')
            ->get();

        if ($actor->isSuperAdmin()) {
            return $roles;
        }

        $actorPerms = $actor->permissionNames();

        return $roles->filter(static function (Role $role) use ($actorPerms): bool {
            if ($role->isSuperAdminRole()) {
                return false;
            }

            return $role->permissions->pluck('name')->diff($actorPerms)->isEmpty();
        })->values();
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param  list<int>  $roleIds
     * @return Collection<int, Role>
     */
    private function resolveAssignableRoles(User $actor, array $roleIds): Collection
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        if ($roleIds === []) {
            throw new BusinessRuleException('ROLE_REQUIRED', 'Minimal satu role harus dipilih.', 422);
        }

        $roles = Role::query()
            ->with('permissions:id,name')
            ->visibleTo((int) $actor->organization_id)
            ->whereIn('id', $roleIds)
            ->get();

        if ($roles->count() !== count($roleIds)) {
            throw new BusinessRuleException('ROLE_NOT_FOUND', 'Satu atau lebih role tidak ditemukan.', 422);
        }

        if ($roles->contains(static fn (Role $r): bool => ! $r->is_active)) {
            throw new BusinessRuleException('ROLE_INACTIVE', 'Role yang nonaktif tidak dapat ditugaskan.', 422);
        }

        if (! $actor->isSuperAdmin()) {
            $allowedIds = $this->assignableRoles($actor)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            foreach ($roles as $role) {
                if (! in_array((int) $role->id, $allowedIds, true)) {
                    throw new BusinessRuleException(
                        'ROLE_ESCALATION_FORBIDDEN',
                        "Anda tidak dapat menugaskan role \"{$role->display_name}\" karena memiliki permission melebihi hak akses Anda.",
                        403,
                    );
                }
            }
        }

        return $roles;
    }

    /**
     * @param  list<int>  $outletIds
     * @param  Collection<int, Role>  $roles
     * @param  list<int>  $currentOutletIds
     * @return list<int>
     */
    private function resolveAssignableOutletIds(User $actor, array $outletIds, Collection $roles, array $currentOutletIds = []): array
    {
        $outletIds = array_values(array_unique(array_map('intval', $outletIds)));
        $isSuperAdminTarget = $roles->contains(static fn (Role $r): bool => $r->isSuperAdminRole());

        if ($outletIds === [] && ! $isSuperAdminTarget) {
            throw new BusinessRuleException('OUTLET_REQUIRED', 'Minimal satu outlet harus ditugaskan untuk user non Super Admin.', 422);
        }

        if ($outletIds === []) {
            return [];
        }

        $valid = Outlet::query()->withoutGlobalScopes()
            ->where('organization_id', $actor->organization_id)
            ->whereNull('deleted_at')
            ->whereIn('id', $outletIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if (count($valid) !== count($outletIds)) {
            throw new BusinessRuleException('OUTLET_NOT_FOUND', 'Satu atau lebih outlet tidak ditemukan.', 422);
        }

        if (! $actor->isSuperAdmin()) {
            $allowed = $actor->accessibleOutletIds();
            foreach ($outletIds as $id) {
                if (! in_array($id, $allowed, true)) {
                    throw new BusinessRuleException('OUTLET_ACCESS_DENIED', 'Anda hanya dapat menugaskan user ke outlet yang Anda kelola.', 403);
                }
            }
        }

        return $outletIds;
    }

    /** @param list<int> $outletIds */
    private function syncOutlets(User $user, array $outletIds, ?int $defaultOutletId): void
    {
        if ($defaultOutletId === null || ! in_array($defaultOutletId, $outletIds, true)) {
            $existingDefault = DB::table('user_outlets')
                ->where('user_id', $user->id)
                ->where('is_default', true)
                ->value('outlet_id');
            $defaultOutletId = $existingDefault !== null && in_array((int) $existingDefault, $outletIds, true)
                ? (int) $existingDefault
                : ($outletIds[0] ?? null);
        }

        $multi = count($outletIds) > 1;
        $sync = [];
        foreach ($outletIds as $id) {
            $sync[$id] = [
                'is_default' => $id === $defaultOutletId,
                'can_switch_outlet' => $multi,
            ];
        }

        $user->outlets()->withoutGlobalScopes()->sync($sync);
    }

    private function assertNotLastSuperAdmin(User $target, string $actionLabel): void
    {
        if (! $target->isSuperAdmin()) {
            return;
        }

        $activeSuperAdmins = User::query()
            ->whereIn('id', $this->superAdminUserIds())
            ->where('organization_id', $target->organization_id)
            ->where('is_active', true)
            ->count();

        if ($activeSuperAdmins <= 1) {
            throw new BusinessRuleException(
                'LAST_SUPER_ADMIN',
                "Tidak dapat {$actionLabel} Super Admin terakhir yang aktif. Tambahkan Super Admin lain terlebih dahulu.",
                422,
            );
        }
    }

    private function assertCanManageRole(User $actor, Role $role): void
    {
        if ($role->organization_id !== null && (int) $role->organization_id !== (int) $actor->organization_id) {
            throw new BusinessRuleException('ROLE_NOT_FOUND', 'Role tidak ditemukan.', 404);
        }

        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($role->is_system) {
            throw new BusinessRuleException('SYSTEM_ROLE_PROTECTED', 'Role sistem hanya dapat diubah oleh Super Admin.', 403);
        }

        // Prevent self-escalation: actor cannot edit a role they currently hold
        if ($actor->roles->contains('id', $role->id)) {
            throw new BusinessRuleException('SELF_ROLE_EDIT_FORBIDDEN', 'Anda tidak dapat mengubah role yang sedang Anda gunakan sendiri.', 403);
        }

        // Cannot manage a role that already exceeds actor's permissions
        $role->loadMissing('permissions:id,name');
        if ($role->permissions->pluck('name')->diff($actor->permissionNames())->isNotEmpty()) {
            throw new BusinessRuleException('ROLE_ESCALATION_FORBIDDEN', 'Role ini memiliki permission melebihi hak akses Anda.', 403);
        }
    }

    private function assertRoleLevelAllowed(User $actor, int $level): void
    {
        if ($level < 0 || $level > 99) {
            throw new BusinessRuleException('ROLE_LEVEL_INVALID', 'Level role harus antara 0 dan 99 (100 khusus Super Admin).', 422);
        }

        if (! $actor->isSuperAdmin()) {
            $actorLevel = (int) ($actor->primaryRole()?->level ?? 0);
            if ($level >= $actorLevel) {
                throw new BusinessRuleException('ROLE_ESCALATION_FORBIDDEN', 'Level role harus lebih rendah dari level role Anda.', 403);
            }
        }
    }

    /**
     * @param  list<int>  $permissionIds
     * @return list<int>
     */
    private function resolveGrantablePermissionIds(User $actor, array $permissionIds, ?Role $role = null): array
    {
        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));
        $perms = Permission::query()->whereIn('id', $permissionIds)->get(['id', 'name']);

        if ($perms->count() !== count($permissionIds)) {
            throw new BusinessRuleException('PERMISSION_NOT_FOUND', 'Satu atau lebih permission tidak ditemukan.', 422);
        }

        if (! $actor->isSuperAdmin()) {
            $exceeding = $perms->pluck('name')->diff($actor->permissionNames())->values()->all();
            if ($exceeding !== []) {
                throw new BusinessRuleException(
                    'PERMISSION_ESCALATION_FORBIDDEN',
                    'Anda tidak dapat memberikan permission yang tidak Anda miliki: '.implode(', ', $exceeding),
                    403,
                    ['permissions' => $exceeding],
                );
            }
        }

        return $perms->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function uniqueRoleName(string $displayName, int $organizationId): string
    {
        $base = Str::slug($displayName, '_') ?: 'role';
        $base = Str::limit($base, 40, '');
        $candidate = $base;
        $i = 1;

        while (Role::query()->where('name', $candidate)->exists()) {
            $candidate = $base.'_o'.$organizationId.($i > 1 ? '_'.$i : '');
            $i++;
        }

        return $candidate;
    }

    /** @return array<string, mixed> */
    public function snapshot(User $user): array
    {
        $user->loadMissing('roles');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'status' => $user->accountStatus(),
            'roles' => $user->roles->pluck('name')->sort()->values()->all(),
            'outlet_ids' => DB::table('user_outlets')->where('user_id', $user->id)->orderBy('outlet_id')->pluck('outlet_id')->map(static fn ($id): int => (int) $id)->all(),
            'has_pin' => $user->pin_hash !== null,
        ];
    }

    /** @return array<string, mixed> */
    public function roleSnapshot(Role $role): array
    {
        $role->loadMissing('permissions');

        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'level' => $role->level,
            'is_active' => $role->is_active,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];
    }
}
