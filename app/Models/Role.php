<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @property int $id
 * @property int|null $organization_id  null = system role shared by all organizations
 * @property string $name
 * @property string $display_name
 * @property string|null $description
 * @property int $level
 * @property bool $is_system
 * @property bool $is_active
 */
class Role extends Model
{
    protected $fillable = ['organization_id', 'name', 'display_name', 'description', 'level', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_has_permissions');
    }

    /** @return MorphToMany<User, $this> */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(User::class, 'model', 'model_has_roles');
    }

    public function isSuperAdminRole(): bool
    {
        return $this->name === RoleName::SuperAdmin->value;
    }

    /**
     * Roles visible to an organization: system roles + its own custom roles.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, int $organizationId): Builder
    {
        return $query->where(static function (Builder $q) use ($organizationId): void {
            $q->whereNull('organization_id')->orWhere('organization_id', $organizationId);
        });
    }
}
