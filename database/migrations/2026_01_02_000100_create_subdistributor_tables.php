<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Additive columns on existing tables
        Schema::table('outlets', function (Blueprint $table): void {
            $table->string('type', 24)->default('branch')->after('code')->comment('head_office | branch');
            $table->string('city', 64)->nullable()->after('address');
            $table->string('province', 64)->nullable()->after('city');
            $table->string('region', 64)->nullable()->after('province');
        });

        Schema::table('user_outlets', function (Blueprint $table): void {
            $table->string('role_override', 64)->nullable()->after('is_default');
            $table->boolean('can_switch_outlet')->default(true)->after('role_override');
            $table->boolean('is_favorite')->default(false)->after('can_switch_outlet');
            $table->timestampTz('last_accessed_at')->nullable()->after('is_favorite');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('outlet_id')->index();
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('cost_price', 15, 2)->default(0)->after('price');
            $table->decimal('reseller_price', 15, 2)->nullable()->after('cost_price');
            $table->decimal('wholesale_price', 15, 2)->nullable()->after('reseller_price');
            $table->decimal('min_wholesale_qty', 15, 3)->default(10)->after('wholesale_price');
        });

        Schema::table('outlet_inventories', function (Blueprint $table): void {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('outlet_id')->index();
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('outlet_id')->index();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable()->after('user_id')->index();
            $table->string('order_type', 24)->default('counter')->after('status')->comment('counter | b2b');
            $table->string('payment_term', 24)->default('cash')->after('order_type')->comment('cash | tempo');
            $table->date('due_date')->nullable()->after('payment_term');
        });

        // 2. Warehouses (Central Warehouse + Branch Warehouses)
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 24)->default('branch')->comment('central | branch');
            $table->string('city', 64)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'code'], 'uq_warehouses_org_code');
            $table->index(['organization_id', 'type', 'is_active'], 'idx_warehouses_org_type');
            $table->index(['outlet_id', 'is_active'], 'idx_warehouses_outlet');
        });

        Schema::create('warehouse_inventories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('reserved_quantity', 15, 3)->default(0);
            $table->decimal('in_transit_quantity', 15, 3)->default(0);
            $table->decimal('reorder_level', 15, 3)->default(10);
            $table->timestampsTz();

            $table->unique(['warehouse_id', 'product_variant_id'], 'uq_warehouse_inventories');
            $table->index(['outlet_id', 'product_variant_id'], 'idx_warehouse_inventories_outlet');
        });

        Schema::create('user_warehouses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->unique(['user_id', 'warehouse_id'], 'uq_user_warehouses');
        });

        // 3. Procurement (Suppliers, Purchase Orders, Goods Receipts)
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('city', 64)->nullable();
            $table->string('address')->nullable();
            $table->unsignedSmallInteger('payment_term_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['organization_id', 'code'], 'uq_suppliers_org_code');
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('po_number', 48)->unique();
            $table->string('status', 24)->default('ordered')->comment('draft | ordered | partially_received | received | cancelled');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->string('payment_status', 24)->default('unpaid')->comment('unpaid | partial | paid');
            $table->string('supplier_invoice_number', 64)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'status'], 'idx_po_org_status');
            $table->index(['target_warehouse_id', 'status'], 'idx_po_warehouse_status');
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('product_name');
            $table->string('variant_name');
            $table->decimal('ordered_qty', 15, 3);
            $table->decimal('received_qty', 15, 3)->default(0);
            $table->decimal('damaged_qty', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestampsTz();
        });

        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gr_number', 48)->unique();
            $table->string('status', 24)->default('completed');
            $table->string('delivery_note_number', 64)->nullable();
            $table->timestampTz('received_at');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('received_qty', 15, 3);
            $table->decimal('accepted_qty', 15, 3);
            $table->decimal('damaged_qty', 15, 3)->default(0);
            $table->decimal('discrepancy_qty', 15, 3)->default(0);
            $table->string('notes')->nullable();
            $table->timestampsTz();
        });

        // 4. Stock Transfers (Central Warehouse -> Branch Warehouse / Outlet)
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('transfer_number', 48)->unique();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('source_outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignId('destination_outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('status', 24)->default('requested')
                ->comment('requested | approved | shipped | partially_received | received | rejected');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['destination_outlet_id', 'status'], 'idx_stock_transfers_dest_outlet');
            $table->index(['source_warehouse_id', 'status'], 'idx_stock_transfers_src_wh');
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('product_name');
            $table->string('variant_name');
            $table->decimal('requested_qty', 15, 3);
            $table->decimal('approved_qty', 15, 3)->default(0);
            $table->decimal('shipped_qty', 15, 3)->default(0);
            $table->decimal('received_qty', 15, 3)->default(0);
            $table->decimal('damaged_qty', 15, 3)->default(0);
            $table->decimal('discrepancy_qty', 15, 3)->default(0);
            $table->string('notes')->nullable();
            $table->timestampsTz();
        });

        // 5. Customers & Credit Control
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('customer_group', 32)->default('retailer')
                ->comment('retailer | wholesaler | reseller | business | walk_in');
            $table->string('pricing_tier', 32)->default('retail')
                ->comment('retail | reseller | wholesale | vip');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('city', 64)->nullable();
            $table->string('address')->nullable();
            $table->string('tax_number', 32)->nullable();
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->unsignedSmallInteger('payment_term_days')->default(0);
            $table->decimal('outstanding_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['organization_id', 'code'], 'uq_customers_org_code');
            $table->index(['outlet_id', 'customer_group'], 'idx_customers_outlet_group');
        });

        // 6. B2B Sales Orders & Deliveries
        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('so_number', 48)->unique();
            $table->string('status', 24)->default('confirmed')
                ->comment('draft | pending_approval | confirmed | picking | shipped | delivered | invoiced | cancelled');
            $table->string('payment_type', 24)->default('tempo')->comment('cash | transfer | qris | tempo');
            $table->unsignedSmallInteger('payment_term_days')->default(0);
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->boolean('credit_limit_warning')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->index(['outlet_id', 'status', 'created_at'], 'idx_sales_orders_outlet_status');
            $table->index(['customer_id', 'status'], 'idx_sales_orders_customer');
        });

        Schema::create('sales_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('product_name');
            $table->string('variant_name');
            $table->decimal('ordered_qty', 15, 3);
            $table->decimal('fulfilled_qty', 15, 3)->default(0);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->timestampsTz();
        });

        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_number', 48)->unique();
            $table->string('status', 24)->default('preparing')->comment('preparing | in_transit | delivered | returned');
            $table->string('driver_name', 96)->nullable();
            $table->string('vehicle_number', 32)->nullable();
            $table->string('recipient_name', 96)->nullable();
            $table->string('delivery_address')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['outlet_id', 'status'], 'idx_deliveries_outlet_status');
        });

        // 7. Invoices, Customer Payments & Accounts Receivable (AR)
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number', 48)->unique();
            $table->string('status', 24)->default('unpaid')->comment('unpaid | partial | paid | overdue | void');
            $table->date('issue_date');
            $table->date('due_date');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('balance_due', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['outlet_id', 'status', 'due_date'], 'idx_invoices_outlet_status_due');
            $table->index(['customer_id', 'status'], 'idx_invoices_customer_status');
        });

        Schema::create('customer_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('payment_number', 48)->unique();
            $table->string('method', 24)->default('bank_transfer')->comment('cash | bank_transfer | qris | giro');
            $table->decimal('amount', 15, 2);
            $table->decimal('allocated_amount', 15, 2)->default(0);
            $table->decimal('unallocated_amount', 15, 2)->default(0);
            $table->string('reference_number', 128)->nullable();
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->timestampsTz();

            $table->index(['outlet_id', 'payment_date'], 'idx_customer_payments_outlet_date');
            $table->index(['customer_id', 'payment_date'], 'idx_customer_payments_customer_date');
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestampsTz();

            $table->index(['invoice_id'], 'idx_payment_allocations_invoice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('user_warehouses');
        Schema::dropIfExists('warehouse_inventories');
        Schema::dropIfExists('warehouses');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['customer_id', 'order_type', 'payment_term', 'due_date']);
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropColumn(['warehouse_id']);
        });
        Schema::table('outlet_inventories', function (Blueprint $table): void {
            $table->dropColumn(['warehouse_id']);
        });
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn(['cost_price', 'reseller_price', 'wholesale_price', 'min_wholesale_qty']);
        });
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['warehouse_id']);
        });
        Schema::table('user_outlets', function (Blueprint $table): void {
            $table->dropColumn(['role_override', 'can_switch_outlet', 'is_favorite', 'last_accessed_at']);
        });
        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn(['type', 'city', 'province', 'region']);
        });
    }
};

