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
            if (!Schema::hasColumn('products', 'net_content')) {
                $table->decimal('net_content', 10, 2)->nullable()->after('unit')
                    ->comment('Volume / net content e.g. 700.00, 750.00, 330.00');
            }
            if (!Schema::hasColumn('products', 'net_content_unit')) {
                $table->string('net_content_unit', 16)->default('ml')->after('net_content')
                    ->comment('ml | L | cl');
            }
            if (!Schema::hasColumn('products', 'abv')) {
                $table->decimal('abv', 5, 2)->nullable()->after('net_content_unit')
                    ->comment('Alcohol By Volume percentage e.g. 40.00, 4.90');
            }
            if (!Schema::hasColumn('products', 'packaging_type')) {
                $table->string('packaging_type', 64)->nullable()->after('abv')
                    ->comment('Glass Bottle | Can | Keg | Box');
            }
            if (!Schema::hasColumn('products', 'country_of_origin')) {
                $table->string('country_of_origin', 64)->nullable()->after('packaging_type');
            }
            if (!Schema::hasColumn('products', 'is_returnable_packaging')) {
                $table->boolean('is_returnable_packaging')->default(false)->after('country_of_origin');
            }
        });

        Schema::table('units', function (Blueprint $table): void {
            if (!Schema::hasColumn('units', 'is_purchase_allowed')) {
                $table->boolean('is_purchase_allowed')->default(true)->after('is_base');
            }
            if (!Schema::hasColumn('units', 'is_sales_allowed')) {
                $table->boolean('is_sales_allowed')->default(true)->after('is_purchase_allowed');
            }
            if (!Schema::hasColumn('units', 'is_transfer_allowed')) {
                $table->boolean('is_transfer_allowed')->default(true)->after('is_sales_allowed');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('order_items', 'base_quantity')) {
                $table->decimal('base_quantity', 15, 3)->nullable()->after('conversion_factor');
            }
        });

        Schema::table('sales_order_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('sales_order_items', 'base_quantity')) {
                $table->decimal('base_quantity', 15, 3)->nullable()->after('conversion_factor');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_order_items', 'base_quantity')) {
                $table->dropColumn('base_quantity');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'base_quantity')) {
                $table->dropColumn('base_quantity');
            }
        });

        Schema::table('units', function (Blueprint $table): void {
            $cols = [];
            if (Schema::hasColumn('units', 'is_purchase_allowed')) {
                $cols[] = 'is_purchase_allowed';
            }
            if (Schema::hasColumn('units', 'is_sales_allowed')) {
                $cols[] = 'is_sales_allowed';
            }
            if (Schema::hasColumn('units', 'is_transfer_allowed')) {
                $cols[] = 'is_transfer_allowed';
            }
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            $cols = [];
            if (Schema::hasColumn('products', 'net_content')) {
                $cols[] = 'net_content';
            }
            if (Schema::hasColumn('products', 'net_content_unit')) {
                $cols[] = 'net_content_unit';
            }
            if (Schema::hasColumn('products', 'abv')) {
                $cols[] = 'abv';
            }
            if (Schema::hasColumn('products', 'packaging_type')) {
                $cols[] = 'packaging_type';
            }
            if (Schema::hasColumn('products', 'country_of_origin')) {
                $cols[] = 'country_of_origin';
            }
            if (Schema::hasColumn('products', 'is_returnable_packaging')) {
                $cols[] = 'is_returnable_packaging';
            }
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};

