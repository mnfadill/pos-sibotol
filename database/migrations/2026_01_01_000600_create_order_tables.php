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
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_terminal_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete()->comment('Cashier who created the order');
            $table->string('order_number', 48);
            $table->string('status', 24)->default('held');

            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->string('crm_customer_ref', 64)->nullable()->comment('Opaque CRM reference, never a FK');
            $table->string('table_label', 32)->nullable();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('discount_type', 16)->nullable()->comment('percent | fixed');
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->string('discount_reason')->nullable();
            $table->foreignId('discount_authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('service_charge_rate', 5, 2)->default(0);
            $table->decimal('service_charge_total', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('change_total', 15, 2)->default(0);
            $table->decimal('refunded_total', 15, 2)->default(0);
            $table->text('notes')->nullable();

            // Hold / resume across terminals of the same outlet.
            $table->timestampTz('held_at', 6)->nullable();
            $table->foreignId('locked_by_terminal_id')->nullable()->constrained('pos_terminals')->nullOnDelete();
            $table->timestampTz('locked_at', 6)->nullable();

            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('cancelled_at', 6)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestampTz('voided_at', 6)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('void_authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['outlet_id', 'order_number'], 'uq_orders_outlet_number');
            $table->index(['outlet_id', 'status', 'created_at'], 'idx_orders_outlet_status_created');
            $table->index(['cashier_shift_id', 'status'], 'idx_orders_shift_status');
            $table->index(['outlet_id', 'completed_at'], 'idx_orders_outlet_completed');
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('variant_name');
            $table->string('sku', 64);
            $table->decimal('quantity', 15, 3);
            $table->decimal('original_unit_price', 15, 2)->comment('Effective outlet price before override');
            $table->decimal('unit_price', 15, 2)->comment('Price actually charged per unit (excl. modifiers)');
            $table->foreignId('price_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('price_override_reason')->nullable();
            $table->jsonb('modifiers')->nullable()->comment('Snapshot: [{id, group_name, name, price}]');
            $table->decimal('modifiers_total', 15, 2)->default(0)->comment('Per-unit modifiers total');
            $table->decimal('line_total', 15, 2);
            $table->decimal('refunded_quantity', 15, 3)->default(0);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index('order_id', 'idx_order_items_order');
            $table->index(['product_variant_id', 'created_at'], 'idx_order_items_variant');
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('method', 24);
            $table->string('status', 16)->default('completed');
            $table->decimal('amount', 15, 2)->comment('Amount applied to the order');
            $table->decimal('tendered_amount', 15, 2)->nullable()->comment('Cash handed over by customer');
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->string('provider', 64)->nullable()->comment('e.g. GoPay, OVO, BCA, Visa');
            $table->string('reference_number', 128)->nullable()->comment('Approval code / RRN / transfer ref');
            $table->string('card_last4', 4)->nullable();
            $table->text('qris_payload')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('paid_at', 6)->nullable();
            $table->timestampsTz();

            $table->index(['order_id'], 'idx_payments_order');
            $table->index(['cashier_shift_id', 'method', 'status'], 'idx_payments_shift_method');
            $table->index(['outlet_id', 'paid_at'], 'idx_payments_outlet_paid');
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_terminal_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->string('refund_number', 48);
            $table->string('method', 24);
            $table->decimal('amount', 15, 2);
            $table->boolean('restock')->default(true);
            $table->string('reason');
            $table->timestampsTz();

            $table->unique(['outlet_id', 'refund_number'], 'uq_refunds_outlet_number');
            $table->index(['cashier_shift_id', 'method'], 'idx_refunds_shift_method');
        });

        Schema::create('refund_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('amount', 15, 2);
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_status CHECK (status IN ('held','completed','cancelled','voided','partially_refunded','refunded'))");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT chk_orders_totals CHECK (grand_total >= 0 AND paid_total >= 0 AND discount_total >= 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_qty CHECK (quantity > 0 AND refunded_quantity >= 0 AND refunded_quantity <= quantity)');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_method CHECK (method IN ('cash','qris','debit_card','credit_card','e_wallet','bank_transfer'))");
            DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount > 0)');
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT chk_refunds_amount CHECK (amount > 0)');
            // Fast lookup of held drafts per outlet (cross-terminal resume list).
            DB::statement("CREATE INDEX idx_orders_outlet_held ON orders (outlet_id, held_at DESC) WHERE status = 'held'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
