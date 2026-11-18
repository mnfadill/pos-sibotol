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
        Schema::create('outlet_inventories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('reorder_level', 15, 3)->default(0);
            $table->unsignedBigInteger('version')->default(0)->comment('Bumped on every change');
            $table->timestampTz('last_counted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['outlet_id', 'product_variant_id'], 'uq_outlet_inventories');
            $table->index(['outlet_id', 'product_id'], 'idx_outlet_inventories_product');
        });

        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('adjustment_number', 48);
            $table->string('type', 16)->comment('damaged | lost | expired | correction | opname');
            $table->string('status', 16)->default('completed');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete()->comment('Supervisor who entered PIN');
            $table->timestampTz('authorized_at', 6);
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampsTz();

            $table->unique(['outlet_id', 'adjustment_number'], 'uq_stock_adjustments_number');
            $table->index(['outlet_id', 'type', 'created_at'], 'idx_stock_adjustments_outlet_type');
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('system_quantity', 15, 3)->comment('Quantity on record at the moment of adjustment');
            $table->decimal('counted_quantity', 15, 3)->nullable()->comment('Physical count (opname only)');
            $table->decimal('quantity_change', 15, 3)->comment('Signed delta applied to inventory');
            $table->string('notes')->nullable();
            $table->timestampsTz();

            $table->index('stock_adjustment_id', 'idx_stock_adjustment_items_adj');
        });

        /*
         * Immutable stock ledger: every change to outlet_inventories.quantity
         * produces exactly one row here.
         */
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('type', 24)->comment('sale | void | refund | adjustment | opname | receive');
            $table->decimal('quantity_change', 15, 3);
            $table->decimal('quantity_before', 15, 3);
            $table->decimal('quantity_after', 15, 3);
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->index(['outlet_id', 'product_variant_id', 'created_at'], 'idx_stock_movements_variant');
            $table->index(['reference_type', 'reference_id'], 'idx_stock_movements_reference');
            $table->index(['outlet_id', 'created_at'], 'idx_stock_movements_outlet_created');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT chk_stock_adjustments_type CHECK (type IN ('damaged','lost','expired','correction','opname'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('outlet_inventories');
    }
};
