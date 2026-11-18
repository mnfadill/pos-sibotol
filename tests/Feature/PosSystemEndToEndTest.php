<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\OutletInventory;
use App\Models\PosTerminal;
use App\Models\ProductVariant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PosSystemEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_authentication_rbac_and_outlet_isolation(): void
    {
        $senopati = Outlet::query()->withoutGlobalScopes()->where('code', 'JKT-SNP')->firstOrFail();
        $dago = Outlet::query()->withoutGlobalScopes()->where('code', 'BDG-DGO')->firstOrFail();
        $terminalSnp1 = PosTerminal::query()->where('code', 'POS-SNP-01')->firstOrFail();
        $terminalDgo1 = PosTerminal::query()->where('code', 'POS-DGO-01')->firstOrFail();

        // 1. Cashier assigned to Senopati cannot log into Dago outlet
        $forbiddenLogin = $this->postJson('/api/v1/auth/login', [
            'login' => 'kasir.jkt',
            'password' => 'password',
            'outlet_id' => $dago->id,
            'pos_terminal_id' => $terminalDgo1->id,
        ]);
        $forbiddenLogin->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // 2. Cashier logs in without selecting terminal -> requires_terminal_selection = true
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'login' => 'kasir.jkt',
            'password' => 'password',
        ]);
        $loginRes->assertOk()
            ->assertJsonPath('session.requires_terminal_selection', true);

        $token = (string) $loginRes->json('token');

        // 3. Calling catalog before selecting Outlet + Terminal returns 409 OUTLET_CONTEXT_REQUIRED
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/catalog/products')
            ->assertStatus(409)
            ->assertJsonPath('code', 'OUTLET_CONTEXT_REQUIRED');

        // 4. Select Senopati + POS-SNP-01
        $sessionRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/session', [
                'outlet_id' => $senopati->id,
                'pos_terminal_id' => $terminalSnp1->id,
            ]);
        $sessionRes->assertOk()
            ->assertJsonPath('session.requires_terminal_selection', false)
            ->assertJsonPath('session.requires_open_shift', true)
            ->assertJsonPath('session.active_outlet.code', 'JKT-SNP')
            ->assertJsonPath('session.active_terminal.code', 'POS-SNP-01');
    }

    public function test_shift_lifecycle_checkout_split_payment_idempotency_and_stock_deduction(): void
    {
        $senopati = Outlet::query()->withoutGlobalScopes()->where('code', 'JKT-SNP')->firstOrFail();
        $terminal1 = PosTerminal::query()->where('code', 'POS-SNP-01')->firstOrFail();
        $aren250 = ProductVariant::query()->withoutGlobalScopes()->where('sku', 'SBT-AREN-250')->firstOrFail();

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'login' => 'kasir.jkt',
            'password' => 'password',
            'outlet_id' => $senopati->id,
            'pos_terminal_id' => $terminal1->id,
        ])->assertOk();

        $token = (string) $loginRes->json('token');
        $headers = ['Authorization' => 'Bearer '.$token];

        // 1. Checkout is blocked before opening shift
        $this->withHeaders($headers + ['X-Idempotency-Key' => 'idem-no-shift'])
            ->postJson('/api/v1/orders/checkout', [
                'items' => [['product_variant_id' => $aren250->id, 'quantity' => 1]],
                'payments' => [['method' => 'cash', 'amount' => 37296, 'tendered_amount' => 40000]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SHIFT_NOT_OPEN');

        // 2. Open Cashier Shift with Rp 500,000 opening cash
        $openRes = $this->withHeaders($headers)
            ->postJson('/api/v1/shifts/open', [
                'opening_cash' => 500000,
                'opening_notes' => 'Opening shift test',
            ])
            ->assertCreated();

        $this->assertSame('500000.00', $openRes->json('shift.opening_cash'));

        // 3. Record Paid-In (Rp 50,000) and Paid-Out (Rp 20,000)
        $this->withHeaders($headers)
            ->postJson('/api/v1/shifts/cash-movements', [
                'type' => 'paid_in',
                'amount' => 50000,
                'reason' => 'Small change top-up',
            ])
            ->assertCreated();

        $this->withHeaders($headers)
            ->postJson('/api/v1/shifts/cash-movements', [
                'type' => 'paid_out',
                'amount' => 20000,
                'reason' => 'Mineral water gallon',
            ])
            ->assertCreated();

        // 4. Calculate Cart for 2x SBT-AREN-250 (2 * 32,000 = 64,000; SC 5% = 3,200; PPN 11% on 67,200 = 7,392; Grand Total = 74,592)
        $calcRes = $this->withHeaders($headers)
            ->postJson('/api/v1/orders/calculate', [
                'items' => [['product_variant_id' => $aren250->id, 'quantity' => 2]],
            ])
            ->assertOk();

        $this->assertSame('64000.00', $calcRes->json('calculation.subtotal'));
        $this->assertSame('3200.00', $calcRes->json('calculation.service_charge_total'));
        $this->assertSame('7392.00', $calcRes->json('calculation.tax_total'));
        $this->assertSame('74592.00', $calcRes->json('calculation.grand_total'));

        // 5. Missing X-Idempotency-Key is rejected with 422
        $this->flushHeaders();
        $checkoutPayload = [
            'customer_name' => 'Bapak Andi',
            'items' => [
                ['product_variant_id' => $aren250->id, 'quantity' => 2, 'notes' => 'Less ice'],
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 50000, 'tendered_amount' => 60000],
                ['method' => 'qris', 'amount' => 24592, 'provider' => 'QRIS GoPay', 'reference_number' => 'QR-100200'],
            ],
        ];

        $this->withHeaders($headers)
            ->postJson('/api/v1/orders/checkout', $checkoutPayload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');

        // 6. Execute Split Payment Checkout with X-Idempotency-Key
        $stockBefore = (float) OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $senopati->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');

        $firstCheckout = $this->withHeaders($headers + ['X-Idempotency-Key' => 'idem-split-001'])
            ->postJson('/api/v1/orders/checkout', $checkoutPayload)
            ->assertCreated()
            ->assertHeader('X-Idempotent-Replay', 'false');

        $orderId = (int) $firstCheckout->json('order.id');
        $this->assertSame('74592.00', $firstCheckout->json('order.grand_total'));
        $this->assertSame('84592.00', $firstCheckout->json('order.paid_total'));
        $this->assertSame('10000.00', $firstCheckout->json('order.change_total'));
        $this->assertCount(2, $firstCheckout->json('order.payments'));

        $stockAfterFirst = (float) OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $senopati->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');

        $this->assertEqualsWithDelta($stockBefore - 2.0, $stockAfterFirst, 0.001);

        // 7. Replaying identical request with same X-Idempotency-Key returns cached response and does NOT deduct stock again
        $replayCheckout = $this->withHeaders($headers + ['X-Idempotency-Key' => 'idem-split-001'])
            ->postJson('/api/v1/orders/checkout', $checkoutPayload)
            ->assertCreated()
            ->assertHeader('X-Idempotent-Replay', 'true');

        $this->assertSame($orderId, (int) $replayCheckout->json('order.id'));

        $stockAfterReplay = (float) OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $senopati->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');

        $this->assertEqualsWithDelta($stockAfterFirst, $stockAfterReplay, 0.001);

        // 8. Reusing the same X-Idempotency-Key with a DIFFERENT payload returns 409
        $mutatedPayload = $checkoutPayload;
        $mutatedPayload['customer_name'] = 'Different Customer';
        $this->withHeaders($headers + ['X-Idempotency-Key' => 'idem-split-001'])
            ->postJson('/api/v1/orders/checkout', $mutatedPayload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_PAYLOAD_MISMATCH');

        // 9. Verify Transactional Outbox has the order.completed event
        $this->assertDatabaseHas('integration_outbox', [
            'aggregate_type' => 'Order',
            'event_type' => 'order.completed',
            'outlet_id' => $senopati->id,
        ]);

        // 10. Close Shift and verify Expected Cash vs Actual Cash & Discrepancy calculation
        // Expected Cash = Opening (500,000) + Cash Sales applied (50,000) + Paid-In (50,000) - Paid-Out (20,000) = 580,000
        $closeRes = $this->withHeaders($headers)
            ->postJson('/api/v1/shifts/close', [
                'actual_cash' => 575000, // Rp 5,000 shortage
                'closing_notes' => 'Selisih kurang Rp 5.000',
            ])
            ->assertOk();

        $this->assertSame('580000.00', $closeRes->json('discrepancy_report.expected_cash'));
        $this->assertSame('575000.00', $closeRes->json('discrepancy_report.actual_cash'));
        $this->assertSame('-5000.00', $closeRes->json('discrepancy_report.cash_difference'));
        $this->assertSame('shortage', $closeRes->json('discrepancy_report.closing_summary.discrepancy_status'));
    }

    public function test_hold_and_resume_across_terminals_and_supervisor_overrides(): void
    {
        $senopati = Outlet::query()->withoutGlobalScopes()->where('code', 'JKT-SNP')->firstOrFail();
        $terminal1 = PosTerminal::query()->where('code', 'POS-SNP-01')->firstOrFail();
        $terminal2 = PosTerminal::query()->where('code', 'POS-SNP-02')->firstOrFail();
        $aren250 = ProductVariant::query()->withoutGlobalScopes()->where('sku', 'SBT-AREN-250')->firstOrFail();

        // Cashier logs in on Terminal 1 & opens shift
        $login1 = $this->postJson('/api/v1/auth/login', [
            'login' => 'kasir.jkt',
            'password' => 'password',
            'outlet_id' => $senopati->id,
            'pos_terminal_id' => $terminal1->id,
        ])->assertOk();
        $headersT1 = ['Authorization' => 'Bearer '.$login1->json('token')];

        $this->withHeaders($headersT1)->postJson('/api/v1/shifts/open', ['opening_cash' => 300000])->assertCreated();

        // Hold an order on Terminal 1
        $holdRes = $this->withHeaders($headersT1)->postJson('/api/v1/orders/hold', [
            'customer_name' => 'Ibu Sinta',
            'table_label' => 'T-09',
            'items' => [['product_variant_id' => $aren250->id, 'quantity' => 1]],
        ])->assertCreated();

        $heldOrderId = (int) $holdRes->json('order.id');

        // Supervisor logs in on Terminal 2 & opens shift
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $login2 = $this->postJson('/api/v1/auth/login', [
            'login' => 'spv.jkt',
            'password' => 'password',
            'outlet_id' => $senopati->id,
            'pos_terminal_id' => $terminal2->id,
        ])->assertOk();
        $headersT2 = ['Authorization' => 'Bearer '.$login2->json('token')];

        $this->withHeaders($headersT2)->postJson('/api/v1/shifts/open', ['opening_cash' => 300000])->assertCreated();

        // Terminal 2 sees the held order and resumes it
        $resumeRes = $this->withHeaders($headersT2)
            ->postJson("/api/v1/orders/{$heldOrderId}/resume")
            ->assertOk();

        $this->assertSame($terminal2->id, (int) $resumeRes->json('order.locked_by_terminal_id'));

        // Checkout with 25% discount (> 10% threshold) on Terminal 2: fails without Supervisor PIN, succeeds with PIN 123456
        $this->withHeaders($headersT2 + ['X-Idempotency-Key' => 'idem-disc-fail'])
            ->postJson('/api/v1/orders/checkout', [
                'order_id' => $heldOrderId,
                'discount_type' => 'percent',
                'discount_value' => 25,
                'discount_reason' => 'Employee family discount',
                'items' => [['product_variant_id' => $aren250->id, 'quantity' => 1]],
                'payments' => [['method' => 'cash', 'amount' => 27972, 'tendered_amount' => 30000]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPERVISOR_PIN_REQUIRED');

        $this->withHeaders($headersT2 + ['X-Idempotency-Key' => 'idem-disc-ok'])
            ->postJson('/api/v1/orders/checkout', [
                'order_id' => $heldOrderId,
                'discount_type' => 'percent',
                'discount_value' => 25,
                'discount_reason' => 'Employee family discount',
                'supervisor_pin' => '123456',
                'items' => [['product_variant_id' => $aren250->id, 'quantity' => 1]],
                'payments' => [['method' => 'cash', 'amount' => 27972, 'tendered_amount' => 30000]],
            ])
            ->assertCreated();
    }

    public function test_inventory_adjustment_opname_and_append_only_audit_logs(): void
    {
        $senopati = Outlet::query()->withoutGlobalScopes()->where('code', 'JKT-SNP')->firstOrFail();
        $terminal1 = PosTerminal::query()->where('code', 'POS-SNP-01')->firstOrFail();
        $aren250 = ProductVariant::query()->withoutGlobalScopes()->where('sku', 'SBT-AREN-250')->firstOrFail();

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'login' => 'spv.jkt',
            'password' => 'password',
            'outlet_id' => $senopati->id,
            'pos_terminal_id' => $terminal1->id,
        ])->assertOk();
        $headers = ['Authorization' => 'Bearer '.$loginRes->json('token')];

        // 1. Manual Stock Adjustment with invalid PIN fails and records SUPERVISOR_AUTH_FAILED
        $this->withHeaders($headers)->postJson('/api/v1/inventory/adjustments', [
            'type' => 'damaged',
            'reason' => 'Dropped bottle',
            'supervisor_pin' => '999999',
            'items' => [['product_variant_id' => $aren250->id, 'quantity' => 3]],
        ])->assertStatus(403)->assertJsonPath('code', 'INVALID_SUPERVISOR_PIN');

        $this->assertDatabaseHas('audit_logs', [
            'action_event' => AuditAction::SUPERVISOR_AUTH_FAILED->value,
        ]);

        // 2. Manual Stock Adjustment with valid Supervisor PIN (123456)
        $this->withHeaders($headers)->postJson('/api/v1/inventory/adjustments', [
            'type' => 'damaged',
            'reason' => 'Dropped bottle during restock',
            'supervisor_pin' => '123456',
            'items' => [['product_variant_id' => $aren250->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action_event' => AuditAction::STOCK_ADJUSTED->value,
            'outlet_id' => $senopati->id,
        ]);

        // 3. Stock Opname reconciliation to exact count 100.000
        $this->withHeaders($headers)->postJson('/api/v1/inventory/opname', [
            'notes' => 'End of day physical stock opname',
            'supervisor_pin' => '123456',
            'items' => [['product_variant_id' => $aren250->id, 'counted_quantity' => 100]],
        ])->assertCreated();

        $currentStock = OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $senopati->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');

        $this->assertSame('100.000', $currentStock);

        // 4. Verify audit_logs is strictly APPEND-ONLY (both Eloquent and DB trigger reject mutations)
        $log = AuditLog::query()->withoutGlobalScopes()->firstOrFail();

        try {
            $log->update(['actor_name_snapshot' => 'Tampered']);
            $this->fail('Expected ImmutableRecordException on Eloquent update');
        } catch (ImmutableRecordException) {
            $this->assertTrue(true);
        }

        try {
            DB::table('audit_logs')->where('id', $log->id)->delete();
            $this->fail('Expected QueryException from database trigger on raw DELETE');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // 5. Verify Dashboard Summary endpoint returns outlet-isolated KPIs & operational breakdown
        $dashboardRes = $this->withHeaders($headers)
            ->getJson('/api/v1/dashboard/summary?period=today')
            ->assertOk()
            ->assertJsonStructure([
                'period',
                'kpis' => [
                    'gross_sales',
                    'discount_total',
                    'net_sales',
                    'tax_and_service_total',
                    'grand_total_revenue',
                    'refunded_total',
                    'completed_orders_count',
                    'average_order_value',
                    'held_orders_count',
                    'voided_and_refunded_count',
                    'low_stock_count',
                ],
                'active_shift_summary',
                'payment_breakdown',
                'top_products',
                'low_stock_items',
                'recent_orders',
                'recent_activities',
            ]);

        $this->assertSame('today', $dashboardRes->json('period'));
    }

    public function test_partial_payment_and_zero_payment_at_pos_checkout_creates_receivable_invoice(): void
    {
        $senopati = Outlet::query()->withoutGlobalScopes()->where('code', 'JKT-SNP')->firstOrFail();
        $terminal1 = PosTerminal::query()->where('code', 'POS-SNP-01')->firstOrFail();
        $aren250 = ProductVariant::query()->withoutGlobalScopes()->where('sku', 'SBT-AREN-250')->firstOrFail();

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'kasir.jkt',
            'password' => 'password',
            'outlet_id' => $senopati->id,
            'pos_terminal_id' => $terminal1->id,
        ])->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];

        $this->withHeaders($headers)->postJson('/api/v1/shifts/open', ['opening_cash' => 250000])->assertCreated();

        // Calculate cart total for 2x Aren 250ml
        $calcRes = $this->withHeaders($headers)->postJson('/api/v1/orders/calculate', [
            'items' => [['product_variant_id' => $aren250->id, 'quantity' => 2]],
        ])->assertOk();
        $grandTotal = (float) $calcRes->json('calculation.grand_total');
        $this->assertGreaterThan(20000, $grandTotal);

        // Customer only pays Rp 20,000 cash (rest goes to Hutang / Piutang)
        $partialCheckout = $this->withHeaders($headers + ['X-Idempotency-Key' => 'idem-partial-debt-01'])
            ->postJson('/api/v1/orders/checkout', [
                'customer_name' => 'Toko Berkah Hutang',
                'items' => [['product_variant_id' => $aren250->id, 'quantity' => 2]],
                'payments' => [
                    [
                        'method' => 'cash',
                        'amount' => $grandTotal, // even if frontend sends full amount with lower tendered_amount, or partial amount
                        'tendered_amount' => 20000,
                    ],
                ],
            ])
            ->assertCreated();

        $orderId = (int) $partialCheckout->json('order.id');
        $this->assertSame('20000.00', $partialCheckout->json('order.paid_total'));
        $this->assertSame('0.00', $partialCheckout->json('order.change_total'));

        $expectedBalanceDue = number_format($grandTotal - 20000, 2, '.', '');
        $this->assertDatabaseHas('invoices', [
            'order_id' => $orderId,
            'outlet_id' => $senopati->id,
            'status' => 'partial',
            'paid_total' => '20000.00',
            'balance_due' => $expectedBalanceDue,
        ]);

        // Also test 100% debt (Rp 0 paid at checkout)
        $zeroCheckout = $this->withHeaders($headers + ['X-Idempotency-Key' => 'idem-zero-debt-02'])
            ->postJson('/api/v1/orders/checkout', [
                'customer_name' => 'Toko Berkah Hutang',
                'items' => [['product_variant_id' => $aren250->id, 'quantity' => 1]],
                'payments' => [
                    [
                        'method' => 'cash',
                        'amount' => 0,
                        'tendered_amount' => 0,
                    ],
                ],
            ])
            ->assertCreated();

        $zeroOrderId = (int) $zeroCheckout->json('order.id');
        $this->assertSame('0.00', $zeroCheckout->json('order.paid_total'));
        $this->assertDatabaseHas('invoices', [
            'order_id' => $zeroOrderId,
            'outlet_id' => $senopati->id,
            'status' => 'unpaid',
            'paid_total' => '0.00',
        ]);
    }
}
