<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tiers Master Table
        Schema::create('tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->integer('display_order')->default(1);
            $table->string('color', 50)->default('#2563EB');
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status', 'display_order']);
        });

        // 2. Product Tier Assignments (Product membership per Tier)
        Schema::create('product_tier_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('tier_id')->constrained('tiers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();

            $table->unique(['organization_id', 'tier_id', 'product_id', 'product_variant_id'], 'uq_prod_tier_assign');
            $table->index(['tier_id', 'status']);
        });

        // 3. Product Tier Prices (Individual prices, effective dates & status per Tier)
        Schema::create('product_tier_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('tier_id')->constrained('tiers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained('product_units')->cascadeOnDelete();
            $table->decimal('price', 15, 2)->default(0.00);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('active'); // active, inactive
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'tier_id', 'status']);
            $table->index(['tier_id', 'product_variant_id', 'status', 'effective_from']);
        });

        // 4. Add tier_id to customers if not exists
        if (! Schema::hasColumn('customers', 'tier_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->foreignId('tier_id')->nullable()->after('pricing_tier')->constrained('tiers')->nullOnDelete();
            });
        }

        // 5. Seed baseline default Tiers for existing organizations
        $organizations = DB::table('organizations')->get();
        $today = date('Y-m-d');

        foreach ($organizations as $org) {
            $defaultTiers = [
                [
                    'organization_id' => $org->id,
                    'name' => 'Customer Umum / Retail (Tier 1)',
                    'code' => 'TIER_1',
                    'description' => 'Harga eceran umum / walk-in counter sales reguler',
                    'display_order' => 1,
                    'color' => '#2563EB',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'organization_id' => $org->id,
                    'name' => 'Member / Langganan (Tier 2)',
                    'code' => 'TIER_2',
                    'description' => 'Harga khusus member terdaftar & pelanggan setia',
                    'display_order' => 2,
                    'color' => '#059669',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'organization_id' => $org->id,
                    'name' => 'Mitra Warung / Reseller (Tier 3)',
                    'code' => 'TIER_3',
                    'description' => 'Tarif khusus reseller & toko kelontong mitra outlet',
                    'display_order' => 3,
                    'color' => '#D97706',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'organization_id' => $org->id,
                    'name' => 'Grosir VIP / Wholesale (Tier 4)',
                    'code' => 'TIER_4',
                    'description' => 'Tarif partai besar B2B / distributor utama',
                    'display_order' => 4,
                    'color' => '#7C3AED',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ];

            foreach ($defaultTiers as $tData) {
                $tierId = DB::table('tiers')->insertGetId($tData);

                // Populate assignments and prices from existing product_variants
                $variants = DB::table('product_variants')
                    ->join('products', 'products.id', '=', 'product_variants.product_id')
                    ->where('products.organization_id', $org->id)
                    ->select('product_variants.*', 'products.id as p_id')
                    ->get();

                foreach ($variants as $v) {
                    // Assignment
                    DB::table('product_tier_assignments')->insertOrIgnore([
                        'organization_id' => $org->id,
                        'tier_id' => $tierId,
                        'product_id' => $v->p_id,
                        'product_variant_id' => $v->id,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Determine tier price
                    $priceVal = (float) $v->price;
                    if ($tData['code'] === 'TIER_2') {
                        $priceVal = $v->member_price !== null && (float) $v->member_price > 0
                            ? (float) $v->member_price
                            : round($priceVal * 0.95, 2);
                    } elseif ($tData['code'] === 'TIER_3') {
                        $priceVal = $v->reseller_price !== null && (float) $v->reseller_price > 0
                            ? (float) $v->reseller_price
                            : round($priceVal * 0.90, 2);
                    } elseif ($tData['code'] === 'TIER_4') {
                        $priceVal = $v->wholesale_price !== null && (float) $v->wholesale_price > 0
                            ? (float) $v->wholesale_price
                            : round($priceVal * 0.85, 2);
                    }

                    DB::table('product_tier_prices')->insert([
                        'organization_id' => $org->id,
                        'tier_id' => $tierId,
                        'product_id' => $v->p_id,
                        'product_variant_id' => $v->id,
                        'product_unit_id' => null,
                        'price' => $priceVal,
                        'effective_from' => $today,
                        'effective_until' => null,
                        'status' => 'active',
                        'notes' => 'Baseline default migration price',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'tier_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropForeign(['tier_id']);
                $table->dropColumn('tier_id');
            });
        }

        Schema::dropIfExists('product_tier_prices');
        Schema::dropIfExists('product_tier_assignments');
        Schema::dropIfExists('tiers');
    }
};

