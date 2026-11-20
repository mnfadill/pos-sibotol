<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasIndex('products', 'idx_products_org_active_cat_name')) {
                $table->index(['organization_id', 'is_active', 'category_id', 'name'], 'idx_products_org_active_cat_name');
            }
            if (! Schema::hasIndex('products', 'idx_products_brand_active')) {
                $table->index(['brand_id', 'is_active'], 'idx_products_brand_active');
            }
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            if (! Schema::hasIndex('product_variants', 'idx_pv_product_active_sort')) {
                $table->index(['product_id', 'is_active', 'sort_order'], 'idx_pv_product_active_sort');
            }
            if (! Schema::hasIndex('product_variants', 'idx_pv_barcode')) {
                $table->index('barcode', 'idx_pv_barcode');
            }
        });

        Schema::table('product_barcodes', function (Blueprint $table): void {
            if (! Schema::hasIndex('product_barcodes', 'idx_pb_org_barcode_active')) {
                $table->index(['organization_id', 'barcode', 'is_active'], 'idx_pb_org_barcode_active');
            }
        });

        Schema::table('product_units', function (Blueprint $table): void {
            if (! Schema::hasIndex('product_units', 'idx_pu_prod_active_factor')) {
                $table->index(['product_id', 'is_active', 'conversion_factor'], 'idx_pu_prod_active_factor');
            }
        });

        Schema::table('outlet_inventories', function (Blueprint $table): void {
            if (! Schema::hasIndex('outlet_inventories', 'idx_oi_outlet_variant')) {
                $table->index(['outlet_id', 'product_variant_id'], 'idx_oi_outlet_variant');
            }
        });

        Schema::table('product_outlet_settings', function (Blueprint $table): void {
            if (! Schema::hasIndex('product_outlet_settings', 'idx_pos_outlet_variant')) {
                $table->index(['outlet_id', 'product_variant_id'], 'idx_pos_outlet_variant');
            }
        });

        Schema::table('product_tier_prices', function (Blueprint $table): void {
            if (! Schema::hasIndex('product_tier_prices', 'idx_ptp_prod_status_eff')) {
                $table->index(['product_id', 'status', 'effective_from'], 'idx_ptp_prod_status_eff');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('idx_products_org_active_cat_name');
            $table->dropIndex('idx_products_brand_active');
        });
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex('idx_pv_product_active_sort');
            $table->dropIndex('idx_pv_barcode');
        });
        Schema::table('product_barcodes', function (Blueprint $table): void {
            $table->dropIndex('idx_pb_org_barcode_active');
        });
        Schema::table('product_units', function (Blueprint $table): void {
            $table->dropIndex('idx_pu_prod_active_factor');
        });
        Schema::table('outlet_inventories', function (Blueprint $table): void {
            $table->dropIndex('idx_oi_outlet_variant');
        });
        Schema::table('product_outlet_settings', function (Blueprint $table): void {
            $table->dropIndex('idx_pos_outlet_variant');
        });
        Schema::table('product_tier_prices', function (Blueprint $table): void {
            $table->dropIndex('idx_ptp_prod_status_eff');
        });
    }
};
