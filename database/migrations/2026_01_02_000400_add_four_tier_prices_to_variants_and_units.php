<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            if (!Schema::hasColumn('product_variants', 'member_price')) {
                $table->decimal('member_price', 15, 2)->nullable()->after('price');
            }
        });

        Schema::table('product_units', function (Blueprint $table): void {
            if (!Schema::hasColumn('product_units', 'member_price')) {
                $table->decimal('member_price', 15, 2)->nullable()->after('selling_price');
            }
            if (!Schema::hasColumn('product_units', 'reseller_price')) {
                $table->decimal('reseller_price', 15, 2)->nullable()->after('member_price');
            }
            if (!Schema::hasColumn('product_units', 'wholesale_price')) {
                $table->decimal('wholesale_price', 15, 2)->nullable()->after('reseller_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            if (Schema::hasColumn('product_variants', 'member_price')) {
                $table->dropColumn('member_price');
            }
        });

        Schema::table('product_units', function (Blueprint $table): void {
            $cols = [];
            if (Schema::hasColumn('product_units', 'member_price')) {
                $cols[] = 'member_price';
            }
            if (Schema::hasColumn('product_units', 'reseller_price')) {
                $cols[] = 'reseller_price';
            }
            if (Schema::hasColumn('product_units', 'wholesale_price')) {
                $cols[] = 'wholesale_price';
            }
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};

