<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Idempotent seeder for the permission catalogue and system roles.
 * Safe to run on an existing database: `php artisan db:seed --class=RbacSeeder`.
 *
 * Existing role ↔ permission assignments of system roles are only initialised
 * when the role is first created (or when $resetPermissions is true), so admin
 * customisations made via Role Management are preserved.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        self::seedCatalogue(false);
    }

    /**
     * @return array<string, Role> keyed by role machine name
     */
    public static function seedCatalogue(bool $resetPermissions = false): array
    {
        $permissionModels = [];
        foreach (PermissionEnum::cases() as $perm) {
            $permissionModels[$perm->value] = Permission::query()->updateOrCreate(
                ['name' => $perm->value],
                [
                    'group' => $perm->group(),
                    'description' => $perm->description(),
                ],
            );
        }

        $roleModels = [];
        foreach (RoleName::cases() as $roleEnum) {
            $role = Role::query()->firstOrNew(['name' => $roleEnum->value]);
            $isNew = ! $role->exists;

            $role->fill([
                'display_name' => $roleEnum->displayName(),
                'description' => $roleEnum->description(),
                'level' => $roleEnum->level(),
                'is_system' => true,
                'is_active' => true,
            ]);
            $role->organization_id = null;
            $role->save();

            $permIds = array_map(
                static fn (PermissionEnum $p): int => $permissionModels[$p->value]->id,
                $roleEnum->defaultPermissions(),
            );

            if ($isNew || $resetPermissions || $roleEnum === RoleName::SuperAdmin) {
                $role->permissions()->sync($permIds);
            } else {
                // Ensure newly introduced permissions are granted without removing customisations
                $newIds = self::newPermissionIds($role, $permIds);
                if ($newIds !== []) {
                    $role->permissions()->syncWithoutDetaching($newIds);
                }
            }

            $roleModels[$roleEnum->value] = $role;
        }

        return $roleModels;
    }

    /**
     * Grant default permissions that are not yet attached to the role.
     *
     * @param  list<int>  $defaultIds
     * @return list<int>
     */
    private static function newPermissionIds(Role $role, array $defaultIds): array
    {
        $existingIds = $role->permissions()->pluck('permissions.id')->map(static fn ($id): int => (int) $id)->all();

        return array_values(array_diff($defaultIds, $existingIds));
    }
}
