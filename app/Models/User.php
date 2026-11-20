<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $username
 * @property string $email
 * @property string $password
 * @property string|null $pin_hash
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = ['organization_id', 'name', 'username', 'email', 'password', 'pin_hash', 'is_active', 'suspended_at', 'suspension_reason'];

    protected $hidden = ['password', 'pin_hash', 'remember_token'];

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    /** @var list<int>|null */
    private ?array $outletIdCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** ACTIVE | SUSPENDED | DELETED */
    public function accountStatus(): string
    {
        if ($this->trashed()) {
            return 'DELETED';
        }

        return $this->is_active ? 'ACTIVE' : 'SUSPENDED';
    }

    /** Drop memoised permission / outlet caches (after role or outlet changes). */
    public function flushAccessCache(): void
    {
        $this->permissionCache = null;
        $this->outletIdCache = null;
        $this->unsetRelation('roles');
        $this->unsetRelation('outlets');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<EmployeeShiftCheckin, $this> */
    public function employeeShiftCheckins(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmployeeShiftCheckin::class, 'user_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<ActivityLog, $this> */
    public function activityLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ActivityLog::class, 'user_id');
    }

    /** @return MorphToMany<Role, $this> */
    public function roles(): MorphToMany
    {
        return $this->morphToMany(Role::class, 'model', 'model_has_roles');
    }

    /** @return BelongsToMany<Outlet, $this> */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'user_outlets')
            ->withPivot(['is_default', 'role_override', 'can_switch_outlet', 'is_favorite', 'last_accessed_at'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Warehouse, $this> */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'user_warehouses')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function canSwitchOutlet(): bool
    {
        if ($this->isSuperAdmin() || $this->hasPermission(PermissionEnum::OutletsSwitch)) {
            return true;
        }

        return count($this->accessibleOutletIds()) > 1;
    }

    public function hasRole(RoleName ...$roles): bool
    {
        $names = array_map(static fn (RoleName $r): string => $r->value, $roles);

        return $this->roles->contains(static fn (Role $role): bool => in_array($role->name, $names, true));
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleName::SuperAdmin);
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        if ($this->isSuperAdmin()) {
            return $this->permissionCache = array_map(static fn (PermissionEnum $p): string => $p->value, PermissionEnum::cases());
        }

        $this->loadMissing('roles.permissions');

        return $this->permissionCache = $this->roles
            ->filter(static fn (Role $role): bool => $role->is_active !== false)
            ->flatMap(static fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    public function hasAnyPermission(PermissionEnum|string ...$permissions): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $granted = $this->permissionNames();
        foreach ($permissions as $permission) {
            $name = $permission instanceof PermissionEnum ? $permission->value : $permission;
            if (in_array($name, $granted, true)) {
                return true;
            }
        }

        return false;
    }

    public function hasPermission(PermissionEnum|string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $name = $permission instanceof PermissionEnum ? $permission->value : $permission;

        return in_array($name, $this->permissionNames(), true);
    }

    /** @return list<int> */
    public function accessibleOutletIds(): array
    {
        if ($this->outletIdCache !== null) {
            return $this->outletIdCache;
        }

        $query = $this->isSuperAdmin()
            ? Outlet::query()->withoutGlobalScopes()->where('organization_id', $this->organization_id)
            : $this->outlets()->withoutGlobalScopes();

        return $this->outletIdCache = $query->where('outlets.is_active', true)
            ->whereNull('outlets.deleted_at')
            ->pluck('outlets.id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function canAccessOutlet(int $outletId): bool
    {
        return in_array($outletId, $this->accessibleOutletIds(), true);
    }

    /** Highest-privilege role, used for audit snapshots and UI. */
    public function primaryRole(): ?Role
    {
        return $this->roles->sortByDesc('level')->first();
    }

    public function roleSnapshot(): string
    {
        return $this->roles->sortByDesc('level')->pluck('display_name')->implode(', ');
    }

    public function verifyPin(string $pin): bool
    {
        return $this->pin_hash !== null && Hash::check($pin, $this->pin_hash);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Login identifier may be a username or an email address.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereLogin(Builder $query, string $login): Builder
    {
        $login = mb_strtolower(trim($login));

        return $query->where(static function (Builder $q) use ($login): void {
            $q->whereRaw('LOWER(username) = ?', [$login])->orWhereRaw('LOWER(email) = ?', [$login]);
        });
    }
}
