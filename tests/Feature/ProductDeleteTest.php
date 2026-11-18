<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_can_delete_product_without_transactions_permanently(): void
    {
        $user = User::query()->where('email', 'admin@sibotol.id')->firstOrFail();
        $this->assertNotNull($user);

        $category = Category::query()->firstOrFail();
        $brand = Brand::query()->first();

        // 1. Create a product via POST /api/v1/master-data/products
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/master-data/products', [
            'name' => 'Produk Tes Delete',
            'sku' => 'TEST-DEL-001',
            'barcode' => '8999999001',
            'category_id' => $category->id,
            'brand_id' => $brand?->id,
            'unit' => 'BTL',
            'cost_price' => 50000,
            'tier_a_price' => 75000,
            'tier_b_price' => 70000,
            'tier_c_price' => 68000,
            'tier_d_price' => 65000,
        ]);

        $response->assertStatus(201);
        $productId = $response->json('product.id');
        $this->assertNotNull($productId);

        // 2. Verify it shows up in products index
        $indexRes = $this->actingAs($user, 'sanctum')->getJson('/api/v1/master-data/products');
        $indexRes->assertStatus(200);
        $items = $indexRes->json('products');
        $this->assertTrue(collect($items)->contains('id', $productId));

        // 3. Delete the product
        $deleteRes = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/master-data/products/{$productId}");
        $deleteRes->assertStatus(200)
            ->assertJson([
                'deleted' => true,
            ]);

        // 4. Verify it no longer appears in products index
        $indexAfterRes = $this->actingAs($user, 'sanctum')->getJson('/api/v1/master-data/products');
        $itemsAfter = $indexAfterRes->json('products');
        $this->assertFalse(collect($itemsAfter)->contains('id', $productId));

        // 5. Verify it is marked deleted in DB and SKU was freed
        $deleted = Product::withoutGlobalScopes()->find($productId);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertFalse((bool) $deleted->is_active);
        $this->assertStringContainsString('__DELETED_', $deleted->sku);
    }

    public function test_deleting_product_with_orders_soft_deletes_and_hides_from_catalog(): void
    {
        $user = User::query()->where('email', 'admin@sibotol.id')->firstOrFail();
        $this->assertNotNull($user);

        $category = Category::query()->firstOrFail();

        // Create product
        $prodRes = $this->actingAs($user, 'sanctum')->postJson('/api/v1/master-data/products', [
            'name' => 'Produk Dengan Transaksi',
            'sku' => 'TEST-TX-001',
            'barcode' => '8999999002',
            'category_id' => $category->id,
            'unit' => 'BTL',
            'cost_price' => 100000,
            'tier_a_price' => 150000,
        ]);
        $prodRes->assertStatus(201);
        $productId = $prodRes->json('product.id');
        $product = Product::withoutGlobalScopes()->find($productId);
        $variant = $product->variants()->withoutGlobalScopes()->first();

        $outlet = Outlet::query()->firstOrFail();

        // Simulate an order item referencing this product
        $order = Order::query()->create([
            'organization_id' => $user->organization_id,
            'outlet_id' => $outlet->id,
            'pos_terminal_id' => 1,
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-' . time(),
            'status' => 'completed',
            'subtotal' => '150000.00',
            'discount_total' => '0.00',
            'tax_total' => '0.00',
            'grand_total' => '150000.00',
            'paid_total' => '150000.00',
            'change_total' => '0.00',
            'payment_status' => 'paid',
            'opened_at' => now(),
            'closed_at' => now(),
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'quantity' => '1.000',
            'original_unit_price' => '150000.00',
            'unit_price' => '150000.00',
            'line_total' => '150000.00',
        ]);

        // Delete the product
        $deleteRes = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/master-data/products/{$productId}");
        $deleteRes->assertStatus(200)
            ->assertJson([
                'deleted' => true,
            ]);

        // Verify it is NOT in products index
        $indexRes = $this->actingAs($user, 'sanctum')->getJson('/api/v1/master-data/products');
        $items = $indexRes->json('products');
        $this->assertFalse(collect($items)->contains('id', $productId));

        // Verify it is soft-deleted in database
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'is_active' => false,
        ]);
        $deletedProduct = Product::withoutGlobalScopes()->find($productId);
        $this->assertNotNull($deletedProduct->deleted_at);
        $this->assertStringContainsString('__DELETED_', $deletedProduct->sku);
    }

    public function test_can_delete_product_with_batches_and_stock_adjustments_like_product_2(): void
    {
        $user = User::query()->where('email', 'admin@sibotol.id')->firstOrFail();

        // Product 2 is seeded in DatabaseSeeder with inventory_batches, warehouse_inventories, and outlet_inventories
        $p2 = Product::withoutGlobalScopes()->find(2);
        $this->assertNotNull($p2);

        $deleteRes = $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/master-data/products/2');
        $deleteRes->assertStatus(200)
            ->assertJson([
                'deleted' => true,
            ]);

        // Verify it is no longer returned in products index
        $indexRes = $this->actingAs($user, 'sanctum')->getJson('/api/v1/master-data/products');
        $items = $indexRes->json('products');
        $this->assertFalse(collect($items)->contains('id', 2));

        $p2After = Product::withoutGlobalScopes()->find(2);
        $this->assertNotNull($p2After->deleted_at);
        $this->assertFalse((bool) $p2After->is_active);

        // Verify /api/v1/inventory does not return orphan rows with null product or variant
        $outlet = Outlet::query()->firstOrFail();
        $invRes = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->getJson('/api/v1/inventory')
            ->assertOk();

        foreach ($invRes->json('inventories') as $invRow) {
            $this->assertNotNull($invRow['product']);
            $this->assertNotNull($invRow['variant']);
        }

        // Verify /api/v1/procurement returns 200 OK with suppliers relationship
        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Outlet-Id', (string) $outlet->id)
            ->getJson('/api/v1/procurement')
            ->assertOk()
            ->assertJsonStructure(['suppliers', 'warehouses', 'purchase_orders']);
    }
}