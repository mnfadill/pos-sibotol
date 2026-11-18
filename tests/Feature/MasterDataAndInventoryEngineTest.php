<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InventoryBatch;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\OutletInventory;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\PricingAndCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataAndInventoryEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_manual_stock_adjustment_creates_ledger_without_uom_constraint_error(): void
    {
        $supervisor = User::query()->where('email', 'admin@sibotol.id')->firstOrFail();
        $outlet = Outlet::query()->where('code', 'TNG-BSD')->firstOrFail();
        $variant = ProductVariant::query()->firstOrFail();

        // Ensure inventory exists
        $inventory = OutletInventory::query()->withoutGlobalScopes()->firstOrCreate(
            ['outlet_id' => $outlet->id, 'product_variant_id' => $variant->id],
            [
                'product_id' => $variant->product_id,
                'quantity' => '120.000',
                'reorder_level' => '10.000',
            ],
        );

        // Perform manual stock adjustment as reported in user error:
        // reason: expired, notes: kadaluwarsa, quantity: 1
        $response = $this->actingAs($supervisor, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->postJson('/api/v1/inventory/adjustments', [
                'type' => 'expired',
                'reason' => 'expired',
                'notes' => 'kadaluuarsa',
                'supervisor_pin' => '123456',
                'items' => [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => 1,
                        'notes' => 'kadaluuarsa',
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('adjustment.reason', 'expired');

        // Verify stock movement was recorded with valid uom_code
        $movement = StockMovement::query()->withoutGlobalScopes()
            ->where('outlet_id', $outlet->id)
            ->where('product_variant_id', $variant->id)
            ->where('type', 'adjustment')
            ->latest('id')
            ->first();

        $this->assertNotNull($movement);
        $this->assertNotEmpty($movement->uom_code);
        $this->assertEquals('-1.000', (string) $movement->quantity_change);
        $this->assertStringContainsString('expired', (string) $movement->notes);
    }

    public function test_multi_uom_and_conversion_in_product_units(): void
    {
        $org = Organization::query()->firstOrFail();
        $product = Product::query()->firstOrFail();
        $variant = $product->variants()->firstOrFail();

        // Base unit: PCS (1.000)
        $unitBase = ProductUnit::query()->updateOrCreate(
            ['product_variant_id' => $variant->id, 'unit_code' => 'BTL'],
            [
                'organization_id' => $org->id,
                'product_id' => $product->id,
                'unit_name' => 'Botol Satuan',
                'conversion_factor' => '1.000',
                'is_base' => true,
                'is_purchase_unit' => true,
                'is_sales_unit' => true,
                'selling_price' => '18000.00',
            ],
        );

        // Packaging unit: DUS (24.000)
        $unitDus = ProductUnit::query()->updateOrCreate(
            ['product_variant_id' => $variant->id, 'unit_code' => 'DUS'],
            [
                'organization_id' => $org->id,
                'product_id' => $product->id,
                'unit_name' => 'Karton Dus (24 Botol)',
                'conversion_factor' => '24.000',
                'is_base' => false,
                'is_purchase_unit' => true,
                'is_sales_unit' => true,
                'selling_price' => '400000.00',
            ],
        );

        $pricingService = app(PricingAndCostService::class);
        $resolved = $pricingService->resolveUnitConversion($variant, null, 'DUS', '2.000');

        $this->assertNotNull($resolved);
        $this->assertEquals('24.000', $resolved['conversion_factor']);
        $this->assertEquals('48.000', $resolved['base_quantity']);
    }

    public function test_multi_barcode_scanning_resolves_variant_and_packaging_unit(): void
    {
        $cashier = User::query()->where('email', 'kasir.jkt@sibotol.id')->firstOrFail();
        $outlet = Outlet::query()->where('code', 'JKT-SNP')->firstOrFail();
        $variant = ProductVariant::query()->firstOrFail();
        $org = Organization::query()->firstOrFail();

        $barcodeDus = '8999888777015';

        $unitDus = ProductUnit::query()->updateOrCreate(
            ['product_variant_id' => $variant->id, 'unit_code' => 'DUS'],
            [
                'organization_id' => $org->id,
                'product_id' => $variant->product_id,
                'unit_name' => 'Dus Karton',
                'conversion_factor' => '24.000',
                'is_base' => false,
                'is_sales_unit' => true,
                'barcode' => $barcodeDus,
            ],
        );

        ProductBarcode::query()->updateOrCreate(
            ['organization_id' => $org->id, 'barcode' => $barcodeDus],
            [
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'product_unit_id' => $unitDus->id,
                'barcode_type' => 'EAN13',
                'label' => 'Dus Barcode',
                'is_primary' => false,
                'is_active' => true,
            ],
        );

        $response = $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->getJson('/api/v1/catalog/barcode/' . $barcodeDus);

        $response->assertStatus(200);
        $response->assertJsonPath('variant.barcode', $barcodeDus);
        $response->assertJsonPath('variant.uom_code', 'DUS');
        $response->assertJsonPath('variant.conversion_factor', '24.000');
    }

    public function test_b2b_sales_order_reservation_and_cancellation(): void
    {
        $salesUser = User::query()->where('email', 'sales@sibotol.id')->firstOrFail();
        $outlet = Outlet::query()->where('code', 'TNG-BSD')->firstOrFail();
        $warehouse = Warehouse::query()->where('outlet_id', $outlet->id)->firstOrFail();
        $variant = ProductVariant::query()->firstOrFail();

        $customer = \App\Models\Customer::query()->where('outlet_id', $outlet->id)->firstOrFail();

        // Ensure stock exists in branch warehouse
        WarehouseInventory::query()->withoutGlobalScopes()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id],
            [
                'organization_id' => $outlet->organization_id,
                'outlet_id' => $outlet->id,
                'product_id' => $variant->product_id,
                'quantity' => '100.000',
                'reserved_quantity' => '0.000',
                'reorder_level' => '10.000',
            ],
        );

        // Create Sales Order for 10 units
        $response = $this->actingAs($salesUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->postJson('/api/v1/sales-orders', [
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'payment_type' => 'tempo',
                'expected_delivery_date' => now()->addDays(2)->toDateString(),
                'items' => [
                    [
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'uom_code' => 'PCS',
                        'quantity' => 10,
                        'unit_price' => 20000,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $soId = (int) $response->json('sales_order.id');

        // Verify active reservation created
        $reservation = StockReservation::query()
            ->where('reference_type', 'SalesOrder')
            ->where('reference_id', $soId)
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($reservation);
        $this->assertEquals('10.000', (string) $reservation->reserved_quantity);

        // Cancel Sales Order
        $cancelResponse = $this->actingAs($salesUser, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->postJson('/api/v1/sales-orders/' . $soId . '/cancel', [
                'reason' => 'Customer requested cancellation',
            ]);

        $cancelResponse->assertStatus(200);

        // Verify reservation is released
        $releasedReservation = StockReservation::query()
            ->where('reference_type', 'SalesOrder')
            ->where('reference_id', $soId)
            ->first();

        $this->assertEquals('released', $releasedReservation->status);
    }

    public function test_master_data_products_route_and_customer_tiers_endpoint(): void
    {
        $admin = User::query()->where('email', 'admin@sibotol.id')->firstOrFail();
        $outlet = Outlet::query()->where('code', 'TNG-BSD')->firstOrFail();

        // 1. Verify GET /api/v1/master-data/products returns 200 (was 404 before)
        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->getJson('/api/v1/master-data/products');

        $response->assertStatus(200);
        $response->assertJsonStructure(['products', 'meta']);

        // 2. Verify GET /api/v1/master-data/customer-tiers returns 4 customer tiers
        $tiersResponse = $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->getJson('/api/v1/master-data/customer-tiers');

        $tiersResponse->assertStatus(200);
        $tiers = $tiersResponse->json('tiers');
        $this->assertCount(4, $tiers);
        $this->assertEquals('A', $tiers[0]['tier_code']);
        $this->assertEquals('B', $tiers[1]['tier_code']);
        $this->assertEquals('C', $tiers[2]['tier_code']);
        $this->assertEquals('D', $tiers[3]['tier_code']);
    }

    public function test_counter_sales_customer_tiers_a_b_c_d_pricing_resolution(): void
    {
        $cashier = User::query()->where('email', 'kasir.tng@sibotol.id')->firstOrFail();
        $outlet = Outlet::query()->where('code', 'TNG-BSD')->firstOrFail();

        // Find a variant and ensure it has known pricing
        $variant = ProductVariant::query()->firstOrFail();
        $variant->update([
            'price' => '100000.00',
            'reseller_price' => '90000.00',
            'wholesale_price' => '80000.00',
            'cost_price' => '60000.00',
        ]);

        // Verify catalog/products includes tier_prices
        $catalogRes = $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->getJson('/api/v1/catalog/products?customer_tier=B');

        $catalogRes->assertStatus(200);
        $prods = $catalogRes->json('products');
        $this->assertNotEmpty($prods);

        $targetVariant = null;
        foreach ($prods as $p) {
            foreach ($p['variants'] as $v) {
                if ($v['id'] === $variant->id) {
                    $targetVariant = $v;
                    break 2;
                }
            }
        }

        $this->assertNotNull($targetVariant);
        $this->assertArrayHasKey('tier_prices', $targetVariant);
        $this->assertEquals('100000.00', $targetVariant['tier_prices']['A']);
        $this->assertEquals('95000.00', $targetVariant['tier_prices']['B']); // 5% discount
        $this->assertEquals('90000.00', $targetVariant['tier_prices']['C']); // reseller_price
        $this->assertEquals('80000.00', $targetVariant['tier_prices']['D']); // wholesale_price
        $this->assertEquals('95000.00', $targetVariant['effective_price']); // Selected tier B

        // Verify calculate cart with Customer D (Wholesale) uses 80000.00
        $calcResD = $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->postJson('/api/v1/orders/calculate', [
                'customer_tier' => 'D',
                'items' => [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => 2,
                    ],
                ],
            ]);

        $calcResD->assertStatus(200);
        $this->assertEquals('80000.00', $calcResD->json('calculation.items.0.unit_price'));
        $this->assertEquals('160000.00', $calcResD->json('calculation.subtotal'));

        // Verify calculate cart with Customer A (Retail) uses 100000.00
        $calcResA = $this->actingAs($cashier, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->postJson('/api/v1/orders/calculate', [
                'customer_tier' => 'A',
                'items' => [
                    [
                        'product_variant_id' => $variant->id,
                        'quantity' => 2,
                    ],
                ],
            ]);

        $calcResA->assertStatus(200);
        $this->assertEquals('100000.00', $calcResA->json('calculation.items.0.unit_price'));
        $this->assertEquals('200000.00', $calcResA->json('calculation.subtotal'));
    }

    public function test_one_product_multiple_selling_units_update_and_bulk_edit(): void
    {
        $admin = User::query()->where('email', 'admin@sibotol.id')->firstOrFail();
        $outlet = Outlet::query()->where('code', 'TNG-BSD')->firstOrFail();
        $category = \App\Models\Category::query()->firstOrFail();

        // 1. Create product with auto-created unit and multiple selling units (Botol = 25.000, Dus = 285.000)
        $createRes = $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->postJson('/api/v1/master-data/products', [
                'name' => 'Soju Original 360ml',
                'category_id' => $category->id,
                'unit' => 'BTL',
                'barcode' => '899777000101',
                'net_content' => 360,
                'net_content_unit' => 'ml',
                'abv' => 16.5,
                'cost_price' => 18000,
                'tier_a_price' => 25000,
                'tier_b_price' => 24000,
                'tier_c_price' => 23000,
                'tier_d_price' => 22000,
                'reorder_level' => 24,
                'units' => [
                    [
                        'unit_code' => 'PACK',
                        'unit_name' => 'Pack Isi 6 Botol',
                        'conversion_factor' => 6,
                        'barcode' => '899777000106',
                        'is_purchase_unit' => true,
                        'is_sales_unit' => true,
                        'purchase_cost' => 105000,
                        'tier_a_price' => 145000,
                        'tier_b_price' => 140000,
                        'tier_c_price' => 135000,
                        'tier_d_price' => 130000,
                    ],
                    [
                        'unit_code' => 'DUS',
                        'unit_name' => 'Dus Isi 12 Botol',
                        'conversion_factor' => 12,
                        'barcode' => '899777000112',
                        'is_purchase_unit' => true,
                        'is_sales_unit' => true,
                        'purchase_cost' => 205000,
                        'tier_a_price' => 285000,
                        'tier_b_price' => 275000,
                        'tier_c_price' => 268000,
                        'tier_d_price' => 260000,
                    ],
                ],
            ]);

        $createRes->assertCreated();
        $productId = (int) $createRes->json('product.id');
        $variantId = (int) $createRes->json('product.variants.0.id');
        $this->assertCount(3, $createRes->json('product.product_units')); // BTL (1), PACK (6), DUS (12)

        // 2. Verify checkout calculation for 1 DUS uses explicit Rp 285.000 (not 12 * 25.000 = 300.000) and converts to 12 BTL
        $calcDus = $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->postJson('/api/v1/orders/calculate', [
                'customer_tier' => 'A',
                'items' => [
                    [
                        'product_variant_id' => $variantId,
                        'uom_code' => 'DUS',
                        'quantity' => 1,
                    ],
                ],
            ])->assertOk();

        $this->assertEquals('285000.00', $calcDus->json('calculation.items.0.unit_price'));
        $this->assertEquals('12.000', $calcDus->json('calculation.items.0.base_quantity'));

        // 3. Update product packaging units directly from Product Update endpoint (add KRT = 24 BTL)
        $updateRes = $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->putJson("/api/v1/master-data/products/{$productId}", [
                'name' => 'Soju Original 360ml Premium',
                'tier_a_price' => 26000,
                'reorder_level' => 36,
                'units' => [
                    [
                        'unit_code' => 'DUS',
                        'unit_name' => 'Dus Isi 12 Botol',
                        'conversion_factor' => 12,
                        'barcode' => '899777000112',
                        'tier_a_price' => 290000,
                    ],
                    [
                        'unit_code' => 'KRT',
                        'unit_name' => 'Karton Isi 24 Botol',
                        'conversion_factor' => 24,
                        'barcode' => '899777000124',
                        'tier_a_price' => 560000,
                    ],
                ],
            ])->assertOk();

        $this->assertSame('Soju Original 360ml Premium', $updateRes->json('product.name'));
        $unitCodes = collect($updateRes->json('product.product_units'))->pluck('unit_code')->all();
        $this->assertContains('BTL', $unitCodes);
        $this->assertContains('DUS', $unitCodes);
        $this->assertContains('KRT', $unitCodes);
        $this->assertNotContains('PACK', $unitCodes);

        // 4. Bulk update products endpoint
        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->postJson('/api/v1/master-data/products/bulk-update', [
                'items' => [
                    [
                        'id' => $productId,
                        'cost_price' => 19000,
                        'tier_a_price' => 27000,
                        'reorder_level' => 48,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('updated_count', 1);
    }
}
