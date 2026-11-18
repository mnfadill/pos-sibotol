<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/user-management';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $username): User
    {
        return User::query()->withTrashed()->where('username', $username)->firstOrFail()->fresh();
    }

    private function role(RoleName $role): Role
    {
        return Role::query()->where('name', $role->value)->firstOrFail();
    }

    private function outletIdOf(string $username): int
    {
        return (int) $this->user($username)->outlets()->withoutGlobalScopes()->value('outlets.id');
    }

    /** Creates an Admin Outlet assigned only to the Senopati outlet (same as kasir.jkt). */
    private function createAdminOutlet(): User
    {
        $res = $this->actingAs($this->user('admin'), 'sanctum')->postJson(self::BASE.'/users', [
            'name' => 'Admin Senopati',
            'username' => 'admin.senopati',
            'email' => 'admin.senopati@sibotol.id',
            'password' => 'Rahasia123',
            'role_ids' => [$this->role(RoleName::AdminOutlet)->id],
            'outlet_ids' => [$this->outletIdOf('kasir.jkt')],
        ]);
        $res->assertCreated();

        return $this->user('admin.senopati');
    }

    public function test_seeds_nine_initial_roles_and_granular_permissions(): void
    {
        foreach ([
            RoleName::SuperAdmin, RoleName::AdminOutlet, RoleName::OutletManager, RoleName::Cashier,
            RoleName::SalesRep, RoleName::WarehouseStaff, RoleName::ProcurementStaff, RoleName::Finance, RoleName::Viewer,
        ] as $r) {
            $this->assertDatabaseHas('roles', ['name' => $r->value, 'is_system' => true]);
        }

        foreach (['users.view', 'users.create', 'users.edit', 'users.delete', 'users.export', 'users.manage', 'procurement.approve', 'procurement.cancel'] as $p) {
            $this->assertDatabaseHas('permissions', ['name' => $p]);
        }
    }

    public function test_super_admin_can_crud_user_with_hashed_password_and_audit(): void
    {
        $admin = $this->user('admin');
        $outletId = $this->outletIdOf('kasir.jkt');

        $res = $this->actingAs($admin, 'sanctum')->postJson(self::BASE.'/users', [
            'name' => 'Kasir Baru',
            'username' => 'kasir.baru',
            'email' => 'kasir.baru@sibotol.id',
            'password' => 'Password123',
            'role_ids' => [$this->role(RoleName::Cashier)->id],
            'outlet_ids' => [$outletId],
        ]);
        $res->assertCreated()->assertJsonPath('user.status', 'ACTIVE');
        $id = (int) $res->json('user.id');

        $created = User::query()->findOrFail($id);
        $this->assertNotSame('Password123', $created->password);
        $this->assertTrue(password_verify('Password123', $created->password));
        $this->assertDatabaseHas('audit_logs', ['action_event' => 'USER_CREATED', 'entity_id' => (string) $id]);

        // Search & detail
        $this->actingAs($admin, 'sanctum')->getJson(self::BASE.'/users?search=kasir.baru')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($admin, 'sanctum')->getJson(self::BASE.'/users/'.$id)
            ->assertOk()->assertJsonPath('user.username', 'kasir.baru');

        // Edit
        $this->actingAs($admin, 'sanctum')->putJson(self::BASE.'/users/'.$id, ['name' => 'Kasir Baru Edit'])
            ->assertOk()->assertJsonPath('user.name', 'Kasir Baru Edit');
        $this->assertDatabaseHas('audit_logs', ['action_event' => 'USER_UPDATED', 'entity_id' => (string) $id]);

        // Soft delete
        $this->actingAs($admin, 'sanctum')->deleteJson(self::BASE.'/users/'.$id)->assertOk();
        $this->assertSoftDeleted('users', ['id' => $id]);
        $this->actingAs($admin, 'sanctum')->getJson(self::BASE.'/users?status=DELETED&search=kasir.baru')
            ->assertOk()->assertJsonPath('data.0.status', 'DELETED');

        // Restore → suspended
        $this->actingAs($admin, 'sanctum')->postJson(self::BASE.'/users/'.$id.'/restore')
            ->assertOk()->assertJsonPath('status', 'SUSPENDED');
    }

    public function test_suspend_revokes_sessions_and_blocks_login_then_activate(): void
    {
        $admin = $this->user('admin');
        $kasir = $this->user('kasir.jkt');
        $kasir->createToken('device-a');
        $kasir->createToken('device-b');
        $this->assertSame(2, $kasir->tokens()->count());

        $this->actingAs($admin, 'sanctum')->postJson(self::BASE.'/users/'.$kasir->id.'/suspend', ['reason' => 'Investigasi'])
            ->assertOk();

        $this->assertSame(0, $kasir->tokens()->count());
        $this->assertFalse($kasir->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action_event' => 'USER_SUSPENDED', 'entity_id' => (string) $kasir->id]);

        $this->postJson('/api/v1/auth/login', ['login' => 'kasir.jkt', 'password' => 'password'])
            ->assertStatus(403)->assertJsonPath('code', 'USER_SUSPENDED');

        $this->actingAs($admin, 'sanctum')->postJson(self::BASE.'/users/'.$kasir->id.'/activate')
            ->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->assertDatabaseHas('audit_logs', ['action_event' => 'USER_ACTIVATED', 'entity_id' => (string) $kasir->id]);
    }

    public function test_cannot_suspend_or_delete_last_super_admin_or_self(): void
    {
        $admin = $this->user('admin');

        // Self protection
        $this->actingAs($admin, 'sanctum')->postJson(self::BASE.'/users/'.$admin->id.'/suspend')
            ->assertStatus(403)->assertJsonPath('code', 'SELF_SUSPEND_FORBIDDEN');
        $this->actingAs($admin, 'sanctum')->deleteJson(self::BASE.'/users/'.$admin->id)
            ->assertStatus(403)->assertJsonPath('code', 'SELF_DELETE_FORBIDDEN');

        // Create a second super admin → with 2 active super admins, suspending one is allowed
        $this->actingAs($admin, 'sanctum')->postJson(self::BASE.'/users', [
            'name' => 'Super Kedua',
            'username' => 'super.dua',
            'email' => 'super.dua@sibotol.id',
            'password' => 'Password123',
            'role_ids' => [$this->role(RoleName::SuperAdmin)->id],
            'outlet_ids' => [],
        ])->assertCreated();
        $second = $this->user('super.dua');

        $this->actingAs($this->user('admin'), 'sanctum')->postJson(self::BASE.'/users/'.$second->id.'/suspend')->assertOk();

        // Now `admin` is the last ACTIVE super admin. Even another super admin account cannot suspend/delete/demote him.
        $service = app(\App\Services\UserManagementService::class);
        $other = $this->createThrowawaySuperAdmin();

        foreach ([
            fn () => $service->suspendUser($other, $this->user('admin')),
            fn () => $service->deleteUser($other, $this->user('admin')),
            fn () => $service->updateUser($other, $this->user('admin'), ['role_ids' => [$this->role(RoleName::Cashier)->id], 'outlet_ids' => [$this->outletIdOf('kasir.jkt')]]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected LAST_SUPER_ADMIN exception');
            } catch (\App\Exceptions\BusinessRuleException $e) {
                $this->assertSame('LAST_SUPER_ADMIN', $e->errorCode);
            }
        }

        $this->assertTrue($this->user('admin')->is_active);
        $this->assertTrue($this->user('admin')->isSuperAdmin());
    }

    /** A super admin that is NOT active, so `admin` remains the only active one. */
    private function createThrowawaySuperAdmin(): User
    {
        $u = User::query()->create([
            'organization_id' => $this->user('admin')->organization_id,
            'name' => 'Inactive SA',
            'username' => 'inactive.sa',
            'email' => 'inactive.sa@sibotol.id',
            'password' => 'Password123',
            'is_active' => false,
        ]);
        $u->roles()->sync([$this->role(RoleName::SuperAdmin)->id]);

        return $u->fresh();
    }

    public function test_user_cannot_change_own_role(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum')->putJson(self::BASE.'/users/'.$admin->id, [
            'role_ids' => [$this->role(RoleName::Cashier)->id],
        ])->assertStatus(403)->assertJsonPath('code', 'SELF_ROLE_CHANGE_FORBIDDEN');
    }

    public function test_permission_middleware_blocks_users_without_permission(): void
    {
        $kasir = $this->user('kasir.jkt');
        $this->actingAs($kasir, 'sanctum')->getJson(self::BASE.'/users')
            ->assertStatus(403)->assertJsonPath('code', 'PERMISSION_DENIED');
        $this->actingAs($kasir, 'sanctum')->postJson(self::BASE.'/roles', ['display_name' => 'Hack'])
            ->assertStatus(403);
    }

    public function test_admin_outlet_is_isolated_to_assigned_outlet(): void
    {
        $adminOutlet = $this->createAdminOutlet();

        $res = $this->actingAs($adminOutlet, 'sanctum')->getJson(self::BASE.'/users?per_page=100');
        $res->assertOk();
        $usernames = collect($res->json('data'))->pluck('username')->all();

        $this->assertContains('kasir.jkt', $usernames);
        $this->assertNotContains('kasir.bdg', $usernames);   // other outlet
        $this->assertNotContains('admin', $usernames);       // super admin hidden
        $this->assertNotContains('manager.jkt', $usernames); // also assigned to another outlet

        // Direct object access is also blocked at API level
        $this->actingAs($adminOutlet, 'sanctum')->getJson(self::BASE.'/users/'.$this->user('kasir.bdg')->id)
            ->assertNotFound();
        $this->actingAs($adminOutlet, 'sanctum')->postJson(self::BASE.'/users/'.$this->user('kasir.bdg')->id.'/suspend')
            ->assertNotFound();

        // Cannot assign a user to an outlet outside own scope
        $this->actingAs($adminOutlet, 'sanctum')->postJson(self::BASE.'/users', [
            'name' => 'Kasir Luar',
            'username' => 'kasir.luar',
            'email' => 'kasir.luar@sibotol.id',
            'password' => 'Password123',
            'role_ids' => [$this->role(RoleName::Cashier)->id],
            'outlet_ids' => [$this->outletIdOf('kasir.bdg')],
        ])->assertStatus(403)->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // But can create a cashier in own outlet
        $this->actingAs($adminOutlet, 'sanctum')->postJson(self::BASE.'/users', [
            'name' => 'Kasir Dalam',
            'username' => 'kasir.dalam',
            'email' => 'kasir.dalam@sibotol.id',
            'password' => 'Password123',
            'role_ids' => [$this->role(RoleName::Cashier)->id],
            'outlet_ids' => [$this->outletIdOf('kasir.jkt')],
        ])->assertCreated();
    }

    public function test_admin_outlet_cannot_escalate_privileges(): void
    {
        $adminOutlet = $this->createAdminOutlet();
        $kasir = $this->user('kasir.jkt');

        // Cannot grant Super Admin role
        $this->actingAs($adminOutlet, 'sanctum')->putJson(self::BASE.'/users/'.$kasir->id, [
            'role_ids' => [$this->role(RoleName::SuperAdmin)->id],
        ])->assertStatus(403)->assertJsonPath('code', 'ROLE_ESCALATION_FORBIDDEN');

        // Cannot create a role carrying permissions he doesn't hold
        $foreign = Permission::query()->where('name', 'company.dashboard.view')->firstOrFail();
        $this->actingAs($adminOutlet, 'sanctum')->postJson(self::BASE.'/roles', [
            'display_name' => 'Role Curang',
            'level' => 10,
            'permission_ids' => [$foreign->id],
        ])->assertStatus(403);

        // Cannot edit system roles (incl. his own)
        $this->actingAs($adminOutlet, 'sanctum')->putJson(self::BASE.'/roles/'.$this->role(RoleName::AdminOutlet)->id, [
            'permission_ids' => [$foreign->id],
        ])->assertStatus(403);
    }

    public function test_dynamic_role_crud_and_cannot_delete_role_in_use(): void
    {
        $admin = $this->user('admin');
        $view = Permission::query()->where('name', 'orders.view')->firstOrFail();
        $export = Permission::query()->where('name', 'orders.export')->firstOrFail();

        $res = $this->actingAs($admin, 'sanctum')->postJson(self::BASE.'/roles', [
            'display_name' => 'Kasir Senior',
            'description' => 'Kasir dengan akses export',
            'level' => 15,
            'permission_ids' => [$view->id],
        ]);
        $res->assertCreated();
        $roleId = (int) $res->json('role_id');
        $this->assertDatabaseHas('audit_logs', ['action_event' => 'ROLE_CREATED']);

        $this->actingAs($admin, 'sanctum')->putJson(self::BASE.'/roles/'.$roleId, [
            'permission_ids' => [$view->id, $export->id],
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action_event' => 'ROLE_PERMISSIONS_UPDATED']);
        $this->assertSame(2, Role::query()->findOrFail($roleId)->permissions()->count());

        // Assign to a user → cannot delete
        $kasir = $this->user('kasir.jkt');
        $this->actingAs($admin, 'sanctum')->putJson(self::BASE.'/users/'.$kasir->id, ['role_ids' => [$roleId]])->assertOk();
        $this->assertTrue($kasir->fresh()->hasPermission('orders.export'));

        $this->actingAs($admin, 'sanctum')->deleteJson(self::BASE.'/roles/'.$roleId)
            ->assertStatus(422)->assertJsonPath('code', 'ROLE_IN_USE');

        // Unassign → can delete
        $this->actingAs($admin, 'sanctum')->putJson(self::BASE.'/users/'.$kasir->id, ['role_ids' => [$this->role(RoleName::Cashier)->id]])->assertOk();
        $this->actingAs($admin, 'sanctum')->deleteJson(self::BASE.'/roles/'.$roleId)->assertOk();
        $this->assertDatabaseMissing('roles', ['id' => $roleId]);

        // Super admin role is locked
        $this->actingAs($admin, 'sanctum')->deleteJson(self::BASE.'/roles/'.$this->role(RoleName::SuperAdmin)->id)
            ->assertStatus(422)->assertJsonPath('code', 'SUPER_ADMIN_ROLE_LOCKED');
    }

    public function test_reset_password_generates_temp_password_and_revokes_sessions(): void
    {
        $admin = $this->user('admin');
        $kasir = $this->user('kasir.jkt');
        $kasir->createToken('device');

        $res = $this->actingAs($admin, 'sanctum')->postJson(self::BASE.'/users/'.$kasir->id.'/reset-password', ['mode' => 'generate']);
        $res->assertOk();
        $temp = (string) $res->json('temporary_password');
        $this->assertSame(12, strlen($temp));
        $this->assertTrue(password_verify($temp, $kasir->fresh()->password));
        $this->assertSame(0, $kasir->tokens()->count());

        $this->actingAs($admin, 'sanctum')->postJson(self::BASE.'/users/'.$kasir->id.'/reset-password', [
            'mode' => 'manual', 'password' => 'BaruSekali99', 'password_confirmation' => 'BaruSekali99',
        ])->assertOk()->assertJsonPath('temporary_password', null);
        $this->assertTrue(password_verify('BaruSekali99', $kasir->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action_event' => 'USER_PASSWORD_RESET', 'entity_id' => (string) $kasir->id]);
    }

    public function test_permission_matrix_and_activity_log_endpoints(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin, 'sanctum')->postJson(self::BASE.'/users/'.$this->user('kasir.jkt')->id.'/suspend')->assertOk();

        $this->actingAs($admin, 'sanctum')->getJson(self::BASE.'/permissions')
            ->assertOk()->assertJsonStructure(['actions', 'modules' => [['module', 'label', 'permissions']], 'roles']);

        $logs = $this->actingAs($admin, 'sanctum')->getJson(self::BASE.'/activity-logs?category=users');
        $logs->assertOk();
        $this->assertContains('USER_SUSPENDED', collect($logs->json('data'))->pluck('action_event')->all());

        $this->actingAs($admin, 'sanctum')->getJson(self::BASE.'/meta')
            ->assertOk()->assertJsonStructure(['stats' => ['total', 'active', 'suspended', 'deleted'], 'outlets', 'roles']);
        $this->assertGreaterThan(0, AuditLog::query()->withoutGlobalScopes()->count());
    }
}
