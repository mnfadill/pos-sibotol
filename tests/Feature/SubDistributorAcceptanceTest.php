<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Outlet;
use App\Models\OutletInventory;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubDistributorAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Covers Acceptance Scenarios 1 - 10 & 15:
     * 1. Super Admin login dan melihat semua outlet aktif.
     * 2. Super Admin memilih Outlet Tangerang.
     * 3. Sistem membuka workspace Outlet Tangerang.
     * 4. Transaksi kasir tercatat sebagai Outlet Tangerang.
     * 5. Stok berkurang dari gudang Outlet Tangerang.
     * 6. Super Admin berpindah ke Outlet Jakarta.
     * 7. Data dashboard dan stok berubah mengikuti Outlet Jakarta.
     * 8. Transaksi Outlet Tangerang tetap tidak berubah.
     * 9. Cashier Tangerang hanya dapat mengakses Outlet Tangerang.
     * 10. Cashier Tangerang tidak dapat memanipulasi outlet_id untuk mengakses Outlet Jakarta.
     * 15. Super Admin dapat melihat laporan konsolidasi seluruh outlet.
     */
    public function test_multi_outlet_login_selection_switching_and_strict_isolation(): void
    {
        $tangerang = Outlet::query()->withoutGlobalScopes()->where('code', 'TNG-BSD')->firstOrFail();
        $jakarta = Outlet::query()->withoutGlobalScopes()->where('code', 'JKT-SNP')->firstOrFail();
        $whTangerang = Warehouse::query()->withoutGlobalScopes()->where('code', 'WH-TNG')->firstOrFail();
        $whJakarta = Warehouse::query()->withoutGlobalScopes()->where('code', 'WH-JKT')->firstOrFail();
        $aren250 = ProductVariant::query()->withoutGlobalScopes()->where('sku', 'SBT-AREN-250')->firstOrFail();

        // Scenario 1: Super Admin logs in and sees all 4 active outlets (Tangerang, Jakarta, Bandung, Subang)
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'login' => 'admin',
            'password' => 'password123',
        ])->assertOk();

        $token = (string) $loginRes->json('token');
        $headers = ['Authorization' => 'Bearer '.$token];

        $availableCodes = collect($loginRes->json('session.available_outlets'))->pluck('code')->all();
        $this->assertContains('TNG-BSD', $availableCodes);
        $this->assertContains('JKT-SNP', $availableCodes);
        $this->assertContains('BDG-DGO', $availableCodes);
        $this->assertContains('SBG-KTA', $availableCodes);

        // Scenario 15: Super Admin can access Company Consolidated Dashboard across all outlets
        $companyDash = $this->withHeaders($headers)
            ->getJson('/api/v1/company/dashboard')
            ->assertOk();
        $this->assertGreaterThanOrEqual(4, (int) $companyDash->json('kpis.total_outlets'));

        // Scenario 2 & 3: Super Admin selects Outlet Tangerang -> system activates Outlet Tangerang & Branch Warehouse WH-TNG
        $selectTng = $this->withHeaders($headers)->postJson('/api/v1/auth/select-outlet', [
            'outlet_id' => $tangerang->id,
        ])->assertOk();

        $this->assertSame($tangerang->id, (int) $selectTng->json('session.active_outlet.id'));
        $this->assertSame('TNG-BSD', $selectTng->json('session.active_outlet.code'));
        $this->assertSame($whTangerang->id, (int) $selectTng->json('session.active_warehouse.id'));

        $tngStockBefore = (float) OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $tangerang->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');

        $jktStockBefore = (float) OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $jakarta->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');

        // Scenario 4 & 5: Open shift and complete a Counter Sales transaction in Outlet Tangerang
        $this->withHeaders($headers)->postJson('/api/v1/shifts/open', [
            'opening_cash' => 500000,
        ])->assertCreated();

        $calcRes = $this->withHeaders($headers)->postJson('/api/v1/orders/calculate', [
            'items' => [['product_variant_id' => $aren250->id, 'quantity' => 5]],
        ])->assertOk();
        $grandTotal = (float) $calcRes->json('calculation.grand_total');

        $checkoutRes = $this->withHeaders($headers + ['X-Idempotency-Key' => 'idem-tng-sale-1'])
            ->postJson('/api/v1/orders/checkout', [
                'customer_name' => 'Pelanggan Counter Tangerang',
                'items' => [['product_variant_id' => $aren250->id, 'quantity' => 5]],
                'payments' => [['method' => 'cash', 'amount' => $grandTotal, 'tendered_amount' => $grandTotal]],
            ])
            ->assertCreated();

        $tngOrderId = (int) $checkoutRes->json('order.id');
        $this->assertSame($tangerang->id, (int) $checkoutRes->json('order.outlet_id'));

        // Verify stock decreased in Tangerang Branch Warehouse & OutletInventory, while Jakarta remained untouched
        $tngStockAfter = (float) OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $tangerang->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');
        $tngWhStockAfter = (float) WarehouseInventory::query()->withoutGlobalScopes()
            ->where('warehouse_id', $whTangerang->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');
        $jktStockAfter = (float) OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $jakarta->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');

        $this->assertEqualsWithDelta($tngStockBefore - 5.0, $tngStockAfter, 0.001);
        $this->assertEqualsWithDelta($tngStockBefore - 5.0, $tngWhStockAfter, 0.001);
        $this->assertEqualsWithDelta($jktStockBefore, $jktStockAfter, 0.001);

        // Scenario 6 & 7: Super Admin switches to Outlet Jakarta -> Dashboard & Inventory reflect Jakarta
        $switchJkt = $this->withHeaders($headers)->postJson('/api/v1/auth/switch-outlet', [
            'outlet_id' => $jakarta->id,
        ])->assertOk();

        $this->assertSame($jakarta->id, (int) $switchJkt->json('session.active_outlet.id'));
        $this->assertSame($whJakarta->id, (int) $switchJkt->json('session.active_warehouse.id'));

        $jktDash = $this->withHeaders($headers)->getJson('/api/v1/dashboard/summary')->assertOk();
        $this->assertSame($jakarta->id, (int) $jktDash->json('outlet.id'));

        $jktInv = $this->withHeaders($headers)->getJson('/api/v1/inventory')->assertOk();
        $this->assertSame($jakarta->id, (int) $jktInv->json('outlet_id'));

        // Scenario 8: Historical transaction in Tangerang remains bound to Tangerang
        $this->assertDatabaseHas('orders', [
            'id' => $tngOrderId,
            'outlet_id' => $tangerang->id,
        ]);

        // Scenario 9 & 10: Cashier Tangerang logs in -> only sees Tangerang and cannot manipulate outlet_id to access Jakarta
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $cashierLogin = $this->postJson('/api/v1/auth/login', [
            'login' => 'kasir.tng',
            'password' => 'password123',
        ])->assertOk();

        $cashierOutlets = $cashierLogin->json('session.available_outlets');
        $this->assertCount(1, $cashierOutlets);
        $this->assertSame('TNG-BSD', $cashierOutlets[0]['code']);

        $cashierHeaders = ['Authorization' => 'Bearer '.$cashierLogin->json('token')];

        // Cashier selects Tangerang
        $this->withHeaders($cashierHeaders)->postJson('/api/v1/auth/select-outlet', [
            'outlet_id' => $tangerang->id,
        ])->assertOk();

        // Cashier attempts to switch to Jakarta -> 403
        $this->withHeaders($cashierHeaders)->postJson('/api/v1/auth/switch-outlet', [
            'outlet_id' => $jakarta->id,
        ])->assertStatus(403);

        // Cashier attempts to spoof X-Outlet-Id header to Jakarta -> 403 OUTLET_ACCESS_DENIED
        $this->withHeaders($cashierHeaders + ['X-Outlet-Id' => (string) $jakarta->id])
            ->getJson('/api/v1/inventory')
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // Cashier attempts to pass outlet_id of Jakarta in query/body -> 403 OUTLET_ACCESS_DENIED
        $this->withHeaders($cashierHeaders)
            ->getJson('/api/v1/orders?outlet_id='.$jakarta->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'OUTLET_ACCESS_DENIED');

        // Cashier attempts to access Company Consolidated Dashboard -> 403
        $this->withHeaders($cashierHeaders)
            ->getJson('/api/v1/company/dashboard')
            ->assertStatus(403);
    }

    /**
     * Covers Acceptance Scenarios 11, 12, 13, 14:
     * 11. Outlet Tangerang dapat meminta stok dari gudang pusat.
     * 12. Penerimaan transfer menambah stok gudang Tangerang.
     * 13. Sales Order B2B menggunakan outlet pemenuhan yang benar.
     * 14. Invoice dan piutang tercatat pada outlet yang benar.
     */
    public function test_stock_transfer_b2b_sales_order_and_accounts_receivable_workflow(): void
    {
        $tangerang = Outlet::query()->withoutGlobalScopes()->where('code', 'TNG-BSD')->firstOrFail();
        $centralWh = Warehouse::query()->withoutGlobalScopes()->where('code', 'WH-CENTRAL')->firstOrFail();
        $whTangerang = Warehouse::query()->withoutGlobalScopes()->where('code', 'WH-TNG')->firstOrFail();
        $aren250 = ProductVariant::query()->withoutGlobalScopes()->where('sku', 'SBT-AREN-250')->firstOrFail();
        $customerTng = Customer::query()->withoutGlobalScopes()->where('code', 'CUST-TNG-001')->firstOrFail();

        // Login as Super Admin and enter Outlet Tangerang Workspace
        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'admin',
            'password' => 'password123',
            'outlet_id' => $tangerang->id,
        ])->assertOk();

        $headers = ['Authorization' => 'Bearer '.$login->json('token')];

        $centralBefore = (float) WarehouseInventory::query()->withoutGlobalScopes()
            ->where('warehouse_id', $centralWh->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');

        $tngBefore = (float) OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $tangerang->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');

        // Scenario 11: Outlet Tangerang requests 30 units from Central Warehouse
        $transferReq = $this->withHeaders($headers)->postJson('/api/v1/stock-transfers', [
            'source_warehouse_id' => $centralWh->id,
            'notes' => 'Restock gudang cabang Tangerang',
            'items' => [
                ['product_variant_id' => $aren250->id, 'requested_qty' => 30],
            ],
        ])->assertCreated();

        $transferId = (int) $transferReq->json('transfer.id');
        $this->assertSame('requested', $transferReq->json('transfer.status'));
        $this->assertSame($tangerang->id, (int) $transferReq->json('transfer.destination_outlet_id'));

        // Central Warehouse approves & ships the transfer -> Central stock decreases by 30
        $this->withHeaders($headers)
            ->postJson("/api/v1/stock-transfers/{$transferId}/approve-ship")
            ->assertOk()
            ->assertJsonPath('transfer.status', 'shipped');

        $centralAfterShip = (float) WarehouseInventory::query()->withoutGlobalScopes()
            ->where('warehouse_id', $centralWh->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');
        $this->assertEqualsWithDelta($centralBefore - 30.0, $centralAfterShip, 0.001);

        // Scenario 12: Outlet Tangerang receives the transfer -> Branch Warehouse Tangerang stock increases by 30
        $this->withHeaders($headers)
            ->postJson("/api/v1/stock-transfers/{$transferId}/receive")
            ->assertOk()
            ->assertJsonPath('transfer.status', 'received');

        $tngAfterReceive = (float) OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $tangerang->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');
        $tngWhAfterReceive = (float) WarehouseInventory::query()->withoutGlobalScopes()
            ->where('warehouse_id', $whTangerang->id)
            ->where('product_variant_id', $aren250->id)
            ->value('quantity');

        $this->assertEqualsWithDelta($tngBefore + 30.0, $tngAfterReceive, 0.001);
        $this->assertEqualsWithDelta($tngBefore + 30.0, $tngWhAfterReceive, 0.001);

        // Scenario 13: Create a B2B Sales Order (Tempo 30 days) in Outlet Tangerang
        $soRes = $this->withHeaders($headers)->postJson('/api/v1/sales-orders', [
            'customer_id' => $customerTng->id,
            'payment_type' => 'tempo',
            'payment_term_days' => 30,
            'notes' => 'Order grosir B2B Tangerang',
            'items' => [
                ['product_variant_id' => $aren250->id, 'quantity' => 20],
            ],
        ])->assertCreated();

        $salesOrderId = (int) $soRes->json('sales_order.id');
        $this->assertSame($tangerang->id, (int) $soRes->json('sales_order.outlet_id'));
        $this->assertSame($whTangerang->id, (int) $soRes->json('sales_order.warehouse_id'));

        // Scenario 14: Fulfill B2B Sales Order -> creates Delivery, deducts Tangerang stock, issues Invoice & records AR on Tangerang
        $fulfillRes = $this->withHeaders($headers)
            ->postJson("/api/v1/sales-orders/{$salesOrderId}/fulfill", [
                'driver_name' => 'Pak Hendra',
                'vehicle_number' => 'B 9001 TNG',
            ])
            ->assertOk();

        $invoiceId = (int) $fulfillRes->json('invoice.id');
        $invoiceGrandTotal = (float) $fulfillRes->json('invoice.grand_total');
        $this->assertSame($tangerang->id, (int) $fulfillRes->json('invoice.outlet_id'));
        $this->assertSame('unpaid', $fulfillRes->json('invoice.status'));

        // Record a Partial Customer Payment against the Invoice, then test anti-overpayment
        $partialAmount = round($invoiceGrandTotal / 2, 2);
        $payRes = $this->withHeaders($headers)->postJson('/api/v1/receivables/payments', [
            'invoice_id' => $invoiceId,
            'amount' => $partialAmount,
            'method' => 'bank_transfer',
            'reference_number' => 'TRF-BCA-00912',
            'idempotency_key' => 'idem-ar-pay-1',
        ])->assertCreated();

        $this->assertSame('partial', $payRes->json('invoice.status'));

        // Attempting to pay more than the remaining balance_due must fail with INVOICE_OVERPAYMENT_NOT_ALLOWED
        $this->withHeaders($headers)->postJson('/api/v1/receivables/payments', [
            'invoice_id' => $invoiceId,
            'amount' => $invoiceGrandTotal,
            'method' => 'bank_transfer',
        ])->assertStatus(422)->assertJsonPath('code', 'INVOICE_OVERPAYMENT_NOT_ALLOWED');

        // Scenario 15+: Master Diskon, PPN (Tax Rate) & Service Charge configuration
        $rulesRes = $this->withHeaders($headers)->getJson('/api/v1/pricing-rules')->assertOk();
        $this->assertNotEmpty($rulesRes->json('discounts'));

        $this->withHeaders($headers)->putJson('/api/v1/pricing-rules/outlet-tax', [
            'outlet_id' => $tangerang->id,
            'apply_to_all_outlets' => false,
            'tax_rate' => 11.0,
            'service_charge_rate' => 2.5,
            'max_cashier_discount_percent' => 15.0,
        ])->assertOk();

        $this->assertSame('2.50', $tangerang->fresh()->service_charge_rate);

        // Also verify configuring PPN & Service Charge for another accessible outlet (e.g. Jakarta) while active on Tangerang
        $jakarta = Outlet::query()->withoutGlobalScopes()->where('code', 'JKT-SNP')->firstOrFail();
        $this->withHeaders($headers)->putJson('/api/v1/pricing-rules/outlet-tax', [
            'outlet_id' => $jakarta->id,
            'apply_to_all_outlets' => false,
            'tax_rate' => 12.0,
            'service_charge_rate' => 5.0,
            'max_cashier_discount_percent' => 10.0,
        ])->assertOk();

        $this->assertSame('12.00', $jakarta->fresh()->tax_rate);
        $this->assertSame('5.00', $jakarta->fresh()->service_charge_rate);

        $newDiscRes = $this->withHeaders($headers)->postJson('/api/v1/pricing-rules/discounts', [
            'code' => 'PROMO-SUBDIST-25K',
            'name' => 'Diskon Spesial Sub-Dist Rp 25.000',
            'type' => 'fixed',
            'value' => 25000,
            'min_purchase' => 200000,
            'applies_to' => 'both',
            'outlet_id' => $tangerang->id,
            'is_active' => true,
        ])->assertCreated();

        $this->assertSame('PROMO-SUBDIST-25K', $newDiscRes->json('discount.code'));
    }

    public function test_receive_purchase_order_goods_succeeds_when_variant_and_product_soft_deleted(): void
    {
        $tangerang = Outlet::query()->withoutGlobalScopes()->where('code', 'TNG-BSD')->firstOrFail();
        $whTangerang = Warehouse::query()->withoutGlobalScopes()->where('code', 'WH-TNG')->firstOrFail();
        $variant = ProductVariant::query()->withoutGlobalScopes()->with('product')->where('sku', 'SBT-AREN-250')->firstOrFail();
        $supplier = \App\Models\Supplier::query()->withoutGlobalScopes()->firstOrFail();

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'login' => 'admin',
            'password' => 'password123',
        ])->assertOk();
        $headers = ['Authorization' => 'Bearer '.(string) $loginRes->json('token')];

        $this->withHeaders($headers)->postJson('/api/v1/auth/select-outlet', [
            'outlet_id' => $tangerang->id,
        ])->assertOk();

        // 1. Create a Purchase Order
        $poRes = $this->withHeaders($headers)->postJson('/api/v1/procurement/purchase-orders', [
            'supplier_id' => $supplier->id,
            'target_warehouse_id' => $whTangerang->id,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'ordered_qty' => 10,
                    'unit_cost' => 15000,
                ],
            ],
        ])->assertCreated();

        $poId = (int) $poRes->json('purchase_order.id');

        // 2. Soft-delete BOTH the variant and its parent product before goods are received
        $variant->delete();
        $variant->product->delete();

        // 3. Receive goods for the PO — should succeed without "Attempt to read property 'product' on null"
        $receiveRes = $this->withHeaders($headers)
            ->postJson("/api/v1/procurement/purchase-orders/{$poId}/receive", [
                'delivery_note_number' => 'SJ-SOFTDEL-001',
            ])
            ->assertOk();

        $this->assertSame('received', $receiveRes->json('purchase_order.status'));
        $this->assertNotEmpty($receiveRes->json('goods_receipt.gr_number'));
    }
}

