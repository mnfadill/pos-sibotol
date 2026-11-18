<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized product catalog.
 *
 * Every product owns at least one variant (the "default" variant). The variant is
 * the sellable / stock-keeping unit: SKU, barcode, price, inventory and outlet
 * overrides all hang off product_variants, which keeps the model free of nullable
 * composite keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete()
                ->comment('Null = top-level category, otherwise subcategory');
            $table->string('name');
            $table->string('slug');
            $table->string('color', 16)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'slug'], 'uq_categories_org_slug');
            $table->index(['organization_id', 'parent_id', 'sort_order'], 'idx_categories_tree');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku', 64);
            $table->text('description')->nullable();
            $table->decimal('base_price', 15, 2);
            $table->string('unit', 16)->default('pcs');
            $table->boolean('track_stock')->default(true);
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'sku'], 'uq_products_org_sku');
            $table->index(['organization_id', 'category_id', 'is_active'], 'idx_products_catalog');
            $table->index(['organization_id', 'name'], 'idx_products_org_name');
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 64);
            $table->string('barcode', 64)->nullable();
            $table->decimal('price', 15, 2)->comment('Central selling price of the variant');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'sku'], 'uq_product_variants_org_sku');
            $table->index(['organization_id', 'barcode'], 'idx_product_variants_barcode');
            $table->index(['product_id', 'sort_order'], 'idx_product_variants_product');
        });

        Schema::create('product_modifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('group_name')->comment('e.g. "Sugar Level", "Toppings"');
            $table->string('name');
            $table->decimal('price', 15, 2)->default(0);
            $table->boolean('is_required')->default(false)->comment('Group must have one selection');
            $table->boolean('is_multiple')->default(false)->comment('Group allows multiple selections');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['product_id', 'group_name', 'sort_order'], 'idx_product_modifiers_group');
        });

        Schema::create('product_outlet_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(true)->comment('In Stock / Out of Stock toggle');
            $table->decimal('price_override', 15, 2)->nullable()->comment('Null = use central price');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['outlet_id', 'product_variant_id'], 'uq_product_outlet_settings');
            $table->index(['outlet_id', 'product_id'], 'idx_product_outlet_settings_product');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_outlet_settings');
        Schema::dropIfExists('product_modifiers');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
