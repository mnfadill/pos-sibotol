<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductTierAssignment;
use App\Models\ProductTierPrice;
use App\Models\ProductVariant;
use App\Models\Tier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TierManagementAndPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }
    public function test_tier_creation_wizard_stores_tier_and_product_prices_atomically(): void
    {
        $org = Organization::query()->first() ?? Organization::factory()->create();
        $user = User::query()->where('organization_id', $org->id)->first();
        if ($user === null) {
            $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'super_admin']);
        }

        $products = Product::query()->where('organization_id', $org->id)->with('variants')->take(3)->get();
        $this->assertGreaterThanOrEqual(1, $products->count());

        $today = CarbonImmutable::today()->toDateString();
        $payloadProducts = [];
        $expectedPrices = [10000.00, 9000.00, 5000.00];

        foreach ($products as $idx => $p) {
            $v = $p->variants->first();
            $priceVal = $expectedPrices[$idx] ?? 7500.00;
            $payloadProducts[] = [
                'product_id' => $p->id,
                'product_variant_id' => $v?->id,
                'price' => $priceVal,
                'effective_from' => $today,
                'status' => 'active',
                'notes' => 'Setup harga tier Distributor Premium',
            ];
        }

        $response = $this->actingAs($user)
            ->postJson('/api/v1/tiers', [
                'name' => 'Distributor Premium',
                'code' => 'DIST_PREMIUM',
                'description' => 'Tier distributor VIP dengan kontrak harga khusus volume tinggi',
                'display_order' => 5,
                'color' => '#10B981',
                'status' => 'active',
                'products' => $payloadProducts,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('tier.name', 'Distributor Premium');
        $response->assertJsonPath('tier.code', 'DIST_PREMIUM');

        // Verify Database
        $tier = Tier::query()->where('organization_id', $org->id)->where('code', 'DIST_PREMIUM')->first();
        $this->assertNotNull($tier);
        $this->assertSame('Distributor Premium', $tier->name);

        // Verify Assignments
        $assignmentsCount = ProductTierAssignment::query()->where('tier_id', $tier->id)->count();
        $this->assertSame(count($payloadProducts), $assignmentsCount);

        // Verify Prices
        $prices = ProductTierPrice::query()->where('tier_id', $tier->id)->get();
        $this->assertSame(count($payloadProducts), $prices->count());
        $this->assertEquals(10000.00, (float) $prices->firstWhere('product_id', $products[0]->id)->price);
    }

    public function test_counter_sales_uses_dynamic_tier_pricing_for_customer(): void
    {
        $org = Organization::query()->first() ?? Organization::factory()->create();
        $outlet = Outlet::query()->where('organization_id', $org->id)->first() ?? Outlet::factory()->create(['organization_id' => $org->id]);
        $user = User::query()->where('organization_id', $org->id)->first();
        $this->actingAs($user);

        $tier = Tier::query()->firstOrCreate(
            ['organization_id' => $org->id, 'code' => 'DIST_PREMIUM'],
            [
                'name' => 'Distributor Premium',
                'description' => 'Tier distributor VIP',
                'display_order' => 5,
                'color' => '#10B981',
                'status' => 'active',
            ]
        );
        $product = Product::query()->where('organization_id', $org->id)->with('variants')->first();
        $variant = $product->variants->first();

        ProductTierAssignment::query()->firstOrCreate([
            'organization_id' => $org->id,
            'tier_id' => $tier->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ], ['status' => 'active']);

        ProductTierPrice::query()->firstOrCreate([
            'organization_id' => $org->id,
            'tier_id' => $tier->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ], [
            'price' => 10000.00,
            'effective_from' => CarbonImmutable::today()->toDateString(),
            'status' => 'active',
        ]);

        // Customer with Tier
        $customer = Customer::query()->create([
            'organization_id' => $org->id,
            'outlet_id' => $outlet->id,
            'code' => 'CUST-DIST-01',
            'name' => 'Toko Mitra Premium Sejahtera',
            'customer_type' => 'b2b',
            'pricing_tier' => 'wholesale',
            'tier_id' => $tier->id,
            'is_active' => true,
        ]);

        $pricingService = app(\App\Services\PricingAndCostService::class);
        $resolvedPrice = $pricingService->resolveSellingPrice(
            variant: $variant,
            outlet: $outlet,
            customer: $customer,
            customerTier: $tier->code,
        );

        $this->assertEquals(10000.00, (float) $resolvedPrice['unit_price']);
        $this->assertSame('tier_dist_premium', $resolvedPrice['price_source']);
    }

    public function test_open_shift_with_custom_date_and_time_for_staff(): void
    {
        $user = \App\Models\User::query()->where('username', 'kasir.jkt')->firstOrFail();
        $outlet = $user->outlets()->firstOrFail();
        $terminal = \App\Models\PosTerminal::query()->where('outlet_id', $outlet->id)->firstOrFail();

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'login' => 'kasir.jkt',
            'password' => 'password',
            'outlet_id' => $outlet->id,
            'pos_terminal_id' => $terminal->id,
            'device_name' => 'Test POS Terminal',
        ])->assertOk();

        $token = $loginRes->json('token');
        $headers = [
            'Authorization' => "Bearer {$token}",
            'X-Outlet-Id' => (string) $outlet->id,
            'X-Terminal-Id' => (string) $terminal->id,
        ];

        // Custom date & time (e.g. 2026-11-10 08:30:00)
        $customDate = '2026-11-10 08:30:00';
        $openRes = $this->withHeaders($headers)
            ->postJson('/api/v1/shifts/open', [
                'opening_cash' => 500000,
                'opening_notes' => 'Shift check-in pagi tanggal kustom',
                'opened_at' => $customDate,
            ])
            ->assertCreated();

        $shift = $openRes->json('shift');
        $this->assertEquals(500000.00, (float) $shift['opening_cash']);
        $this->assertStringContainsString('2026-11-10', $shift['opened_at']);
        $this->assertStringContainsString('08:30:00', $shift['opened_at']);
    }
}
