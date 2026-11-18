<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmployeeShiftCheckin;
use App\Models\Invoice;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Shift;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmployeeShiftAndActivityTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $cashierUser;
    private Outlet $outlet;
    private Warehouse $warehouse;
    private Shift $pagiShift;
    private Shift $soreShift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashierUser = User::query()->where('username', 'kasir.jkt')->firstOrFail();
        $this->outlet = $this->cashierUser->outlets()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('outlet_id', $this->outlet->id)->firstOrFail();

        $this->pagiShift = Shift::query()->where('code', 'PAGI')->firstOrFail();
        $this->soreShift = Shift::query()->where('code', 'SORE')->firstOrFail();
    }

    public function test_catalogue_returns_operational_shifts(): void
    {
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->getJson('/api/v1/employee-shifts/catalogue');

        $response->assertOk()
            ->assertJsonStructure(['shifts' => [['id', 'name', 'code', 'start_time', 'end_time']]]);

        $this->assertGreaterThanOrEqual(3, count($response->json('shifts')));
    }

    public function test_check_in_today_sets_active_shift(): void
    {
        $today = now('Asia/Jakarta')->toDateString();

        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->postJson('/api/v1/employee-shifts/check-in', [
                'shift_id' => $this->pagiShift->id,
                'work_date' => $today,
                'is_backdated' => false,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('checkin.shift_id', $this->pagiShift->id)
            ->assertJsonPath('checkin.status', 'active');

        $this->assertDatabaseHas('employee_shift_checkins', [
            'user_id' => $this->cashierUser->id,
            'outlet_id' => $this->outlet->id,
            'shift_id' => $this->pagiShift->id,
            'status' => 'active',
        ]);

        // Check /auth/me returns active_employee_shift
        $meResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->getJson('/api/v1/auth/me');

        $meResponse->assertOk()
            ->assertJsonPath('session.active_employee_shift.shift_code', 'PAGI')
            ->assertJsonPath('session.requires_employee_shift_checkin', false);
    }

    public function test_check_in_rejects_future_date(): void
    {
        $futureDate = now('Asia/Jakarta')->addDays(2)->toDateString();

        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->postJson('/api/v1/employee-shifts/check-in', [
                'shift_id' => $this->pagiShift->id,
                'work_date' => $futureDate,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'FUTURE_DATE_NOT_ALLOWED');
    }

    public function test_check_in_requires_reason_for_backdate(): void
    {
        $pastDate = now('Asia/Jakarta')->subDays(1)->toDateString();

        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->postJson('/api/v1/employee-shifts/check-in', [
                'shift_id' => $this->pagiShift->id,
                'work_date' => $pastDate,
                'is_backdated' => true,
                'backdate_reason' => '', // Empty
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'BACKDATE_REASON_REQUIRED');

        // Valid backdate checkin with reason
        $validResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->postJson('/api/v1/employee-shifts/check-in', [
                'shift_id' => $this->pagiShift->id,
                'work_date' => $pastDate,
                'is_backdated' => true,
                'backdate_reason' => 'Lupa check-in kemarin karena gangguan jaringan',
            ]);

        $validResponse->assertStatus(201)
            ->assertJsonPath('checkin.is_backdated', true);
    }

    public function test_switch_shift_updates_active_shift_and_creates_history(): void
    {
        $today = now('Asia/Jakarta')->toDateString();

        // 1. Initial check-in to Shift Pagi
        $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->postJson('/api/v1/employee-shifts/check-in', [
                'shift_id' => $this->pagiShift->id,
                'work_date' => $today,
            ])
            ->assertStatus(201);

        // 2. Switch to Shift Sore
        $switchResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->postJson('/api/v1/employee-shifts/switch', [
                'new_shift_id' => $this->soreShift->id,
                'reason' => 'Menggantikan rekan shift sore yang berhalangan',
            ]);

        $switchResponse->assertOk()
            ->assertJsonPath('checkin.shift_id', $this->soreShift->id);

        $this->assertDatabaseHas('shift_checkin_histories', [
            'action' => 'switch_shift',
            'user_id' => $this->cashierUser->id,
            'reason' => 'Menggantikan rekan shift sore yang berhalangan',
        ]);
    }

    public function test_transaction_stamps_active_shift_and_records_activity(): void
    {
        $today = now('Asia/Jakarta')->toDateString();

        // 1. Check in to Shift Pagi
        $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->postJson('/api/v1/employee-shifts/check-in', [
                'shift_id' => $this->pagiShift->id,
                'work_date' => $today,
            ])
            ->assertStatus(201);

        // 2. Prepare customer and product stock
        $customer = Customer::query()->where('outlet_id', $this->outlet->id)->firstOrFail();
        $variant = ProductVariant::query()->firstOrFail();

        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'product_variant_id' => $variant->id],
            [
                'organization_id' => $this->cashierUser->organization_id,
                'outlet_id' => $this->outlet->id,
                'product_id' => $variant->product_id,
                'quantity' => 100,
                'reserved_quantity' => 0,
            ]
        );

        // 3. Create Sales Order
        $soResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->postJson('/api/v1/sales-orders', [
                'customer_id' => $customer->id,
                'warehouse_id' => $this->warehouse->id,
                'payment_type' => 'tempo',
                'payment_term_days' => 14,
                'items' => [
                    [
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'quantity' => 2,
                    ],
                ],
            ]);

        $soResponse->assertStatus(201);
        $salesOrderId = (int) $soResponse->json('sales_order.id');

        $this->assertDatabaseHas('sales_orders', [
            'id' => $salesOrderId,
            'created_by' => $this->cashierUser->id,
            'shift_id' => $this->pagiShift->id,
        ]);

        // 4. Fulfill Sales Order and Issue Invoice
        $fulfillResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->postJson("/api/v1/sales-orders/{$salesOrderId}/fulfill", [
                'driver_name' => 'Budi Supir',
                'vehicle_number' => 'B 1234 SBT',
            ]);

        $fulfillResponse->assertOk();

        // 5. Verify Invoice has shift_id and created_by
        $invoice = Invoice::query()->where('sales_order_id', $salesOrderId)->firstOrFail();
        $this->assertEquals($this->cashierUser->id, $invoice->created_by);
        $this->assertEquals($this->pagiShift->id, $invoice->shift_id);

        // 6. Verify Accounts Receivable endpoint exposes creator and shift
        $arResponse = $this->actingAs($this->cashierUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $this->outlet->id)
            ->getJson('/api/v1/receivables');

        $arResponse->assertOk();
        $invoices = $arResponse->json('invoices');
        $found = false;
        foreach ($invoices as $inv) {
            if ($inv['id'] === $invoice->id) {
                $found = true;
                $this->assertNotNull($inv['creator']);
                $this->assertEquals($this->cashierUser->username, $inv['creator']['username']);
                $this->assertNotNull($inv['shift']);
                $this->assertEquals('Shift Pagi', $inv['shift']['name']);
                break;
            }
        }
        $this->assertTrue($found, 'Invoice with shift and creator info must be present in receivables');

        // 7. Verify Global Activity Log was recorded
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->cashierUser->id,
            'username_snapshot' => $this->cashierUser->username,
            'shift_name_snapshot' => 'Shift Pagi',
            'module_name' => 'invoices',
            'action_type' => 'create',
        ]);
    }
}
