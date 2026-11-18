<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Master Units (PCS, BTL, PACK, DUS, KRT, LTR)
        if (!Schema::hasTable('units')) {
            Schema::create('units', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('code', 24);
                $table->string('name', 64);
                $table->string('unit_type', 24)->default('quantity')->comment('quantity | weight | volume | packaging');
                $table->string('description')->nullable();
                $table->boolean('is_base')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();

                $table->unique(['organization_id', 'code'], 'uq_units_org_code');
            });
        }

        // 2. Master Principals (Manufacturer / Brand Owner, distinct from Supplier)
        if (!Schema::hasTable('principals')) {
            Schema::create('principals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('code', 32);
                $table->string('name', 120);
                $table->string('contact_person', 96)->nullable();
                $table->string('phone', 32)->nullable();
                $table->string('email', 120)->nullable();
                $table->string('city', 64)->nullable();
                $table->string('address')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();

                $table->unique(['organization_id', 'code'], 'uq_principals_org_code');
            });
        }

        // 3. Master Brands
        if (!Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('principal_id')->nullable()->constrained('principals')->nullOnDelete();
                $table->string('code', 32);
                $table->string('name', 120);
                $table->string('description')->nullable();
                $table->string('image_url')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();

                $table->unique(['organization_id', 'code'], 'uq_brands_org_code');
            });
        }

        // 4. Principal <-> Supplier Many-to-Many Pivot
        if (!Schema::hasTable('principal_supplier')) {
            Schema::create('principal_supplier', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('principal_id')->constrained('principals')->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->boolean('is_primary')->default(true);
                $table->timestampsTz();

                $table->unique(['principal_id', 'supplier_id'], 'uq_principal_supplier');
            });
        }

        // 5. Additive Columns on Categories, Products, Product Variants
        Schema::table('categories', function (Blueprint $table): void {
            if (!Schema::hasColumn('categories', 'code')) {
                $table->string('code', 32)->nullable()->after('parent_id');
            }
            if (!Schema::hasColumn('categories', 'description')) {
                $table->string('description')->nullable()->after('slug');
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->after('category_id')->constrained('brands')->nullOnDelete();
            }
            if (!Schema::hasColumn('products', 'principal_id')) {
                $table->foreignId('principal_id')->nullable()->after('brand_id')->constrained('principals')->nullOnDelete();
            }
            if (!Schema::hasColumn('products', 'manufacturer')) {
                $table->string('manufacturer', 120)->nullable()->after('principal_id');
            }
            if (!Schema::hasColumn('products', 'product_type')) {
                $table->string('product_type', 24)->default('regular')->after('manufacturer')
                    ->comment('regular | bundle | package | non_inventory');
            }
            if (!Schema::hasColumn('products', 'short_description')) {
                $table->string('short_description')->nullable()->after('name');
            }
            if (!Schema::hasColumn('products', 'base_unit_id')) {
                $table->foreignId('base_unit_id')->nullable()->after('unit')->constrained('units')->nullOnDelete();
            }
            if (!Schema::hasColumn('products', 'default_barcode')) {
                $table->string('default_barcode', 64)->nullable()->after('base_unit_id');
            }
            if (!Schema::hasColumn('products', 'track_batch')) {
                $table->boolean('track_batch')->default(false)->after('track_stock');
            }
            if (!Schema::hasColumn('products', 'track_expiry')) {
                $table->boolean('track_expiry')->default(false)->after('track_batch');
            }
            if (!Schema::hasColumn('products', 'shelf_life_days')) {
                $table->unsignedSmallInteger('shelf_life_days')->nullable()->after('track_expiry');
            }
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            if (!Schema::hasColumn('product_variants', 'average_cost')) {
                $table->decimal('average_cost', 15, 2)->default(0)->after('cost_price');
            }
            if (!Schema::hasColumn('product_variants', 'last_purchase_cost')) {
                $table->decimal('last_purchase_cost', 15, 2)->default(0)->after('average_cost');
            }
            if (!Schema::hasColumn('product_variants', 'min_selling_price')) {
                $table->decimal('min_selling_price', 15, 2)->default(0)->after('wholesale_price');
            }
        });

        // 6. Product Units & Conversion Factors (1 PCS = 1, 1 PACK = 6, 1 DUS = 24)
        if (!Schema::hasTable('product_units')) {
            Schema::create('product_units', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->string('unit_code', 24);
                $table->string('unit_name', 64);
                $table->decimal('conversion_factor', 15, 3)->default(1.000)->comment('Multiplier to base unit');
                $table->boolean('is_base')->default(false);
                $table->boolean('is_purchase_unit')->default(true);
                $table->boolean('is_sales_unit')->default(true);
                $table->string('barcode', 64)->nullable();
                $table->decimal('selling_price', 15, 2)->default(0);
                $table->decimal('purchase_cost', 15, 2)->default(0);
                $table->decimal('min_purchase_qty', 15, 3)->default(1.000);
                $table->boolean('is_locked')->default(false)->comment('True once used in posted transactions');
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();

                $table->unique(['product_variant_id', 'unit_code'], 'uq_product_units_variant_code');
                $table->index(['organization_id', 'barcode'], 'idx_product_units_barcode');
            });
        }

        // 7. Multi-Barcode Management per Product / Variant / Unit
        if (!Schema::hasTable('product_barcodes')) {
            Schema::create('product_barcodes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_unit_id')->nullable()->constrained('product_units')->nullOnDelete();
                $table->string('barcode', 64);
                $table->string('barcode_type', 24)->default('EAN13')->comment('EAN13 | CODE128 | QR | INTERNAL');
                $table->string('label', 96)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();

                $table->unique(['organization_id', 'barcode'], 'uq_product_barcodes_org_code');
                $table->index(['product_variant_id', 'is_active'], 'idx_product_barcodes_variant');
            });
        }

        // 8. Price Lists, Price List Items & Price History
        if (!Schema::hasTable('price_lists')) {
            Schema::create('price_lists', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('code', 32);
                $table->string('name', 120);
                $table->string('type', 24)->default('wholesale')->comment('retail | wholesale | reseller | customer | outlet');
                $table->string('customer_tier', 24)->nullable()->comment('retail | reseller | wholesale | vip');
                $table->unsignedSmallInteger('priority')->default(10);
                $table->date('effective_from')->nullable();
                $table->date('effective_until')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();

                $table->unique(['organization_id', 'code'], 'uq_price_lists_org_code');
            });
        }

        if (!Schema::hasTable('price_list_items')) {
            Schema::create('price_list_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_unit_id')->nullable()->constrained('product_units')->nullOnDelete();
                $table->decimal('min_qty', 15, 3)->default(1.000);
                $table->decimal('price', 15, 2);
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->date('effective_from')->nullable();
                $table->date('effective_until')->nullable();
                $table->timestampsTz();

                $table->index(['price_list_id', 'product_variant_id'], 'idx_price_list_items_lookup');
            });
        }

        if (!Schema::hasTable('product_price_histories')) {
            Schema::create('product_price_histories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->string('price_type', 32)->comment('base_price | cost_price | wholesale_price | reseller_price | outlet_override | price_list');
            $table->decimal('old_price', 15, 2)->nullable();
            $table->decimal('new_price', 15, 2);
            $table->decimal('old_cost', 15, 2)->nullable();
            $table->decimal('new_cost', 15, 2)->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['product_variant_id', 'created_at'], 'idx_price_histories_variant');
            });
        }

        // 9. Product Inventory Settings & Batch / Expiry Management (FEFO)
        if (!Schema::hasTable('product_inventory_settings')) {
            Schema::create('product_inventory_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->cascadeOnDelete();
                $table->foreignId('outlet_id')->nullable()->constrained('outlets')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->decimal('min_stock', 15, 3)->default(10.000);
                $table->decimal('max_stock', 15, 3)->default(500.000);
                $table->decimal('reorder_point', 15, 3)->default(20.000);
                $table->decimal('safety_stock', 15, 3)->default(10.000);
                $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->boolean('allow_negative_stock')->default(false);
                $table->timestampsTz();

                $table->index(['product_variant_id', 'warehouse_id'], 'idx_prod_inv_settings_var_wh');
            });
        }

        if (!Schema::hasTable('inventory_batches')) {
            Schema::create('inventory_batches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->string('batch_number', 64);
                $table->string('lot_number', 64)->nullable();
                $table->date('manufacturing_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->decimal('initial_quantity', 15, 3)->default(0);
                $table->decimal('on_hand_quantity', 15, 3)->default(0);
                $table->decimal('reserved_quantity', 15, 3)->default(0);
                $table->decimal('damaged_quantity', 15, 3)->default(0);
                $table->decimal('quarantine_quantity', 15, 3)->default(0);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->string('status', 24)->default('available')->comment('available | quarantine | damaged | expired | depleted');
                $table->string('notes')->nullable();
                $table->timestampsTz();

                $table->unique(['warehouse_id', 'product_variant_id', 'batch_number'], 'uq_inv_batches_wh_var_batch');
                $table->index(['warehouse_id', 'product_variant_id', 'expiry_date'], 'idx_inv_batches_fefo');
            });
        }

        if (!Schema::hasTable('stock_reservations')) {
            Schema::create('stock_reservations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
                $table->string('reference_type', 48)->default('SalesOrder');
                $table->unsignedBigInteger('reference_id');
                $table->decimal('reserved_quantity', 15, 3);
                $table->decimal('consumed_quantity', 15, 3)->default(0);
                $table->decimal('released_quantity', 15, 3)->default(0);
                $table->string('status', 24)->default('active')->comment('active | consumed | released');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('reserved_at')->nullable();
                $table->timestampTz('consumed_at')->nullable();
                $table->timestampTz('released_at')->nullable();
                $table->string('notes')->nullable();
                $table->timestampsTz();

                $table->index(['reference_type', 'reference_id', 'status'], 'idx_stock_reservations_ref');
                $table->index(['warehouse_id', 'product_variant_id', 'status'], 'idx_stock_reservations_wh_var');
            });
        }

        // 10. Expand Warehouse Inventories & Stock Movements Ledger
        Schema::table('warehouse_inventories', function (Blueprint $table): void {
            if (!Schema::hasColumn('warehouse_inventories', 'damaged_quantity')) {
                $table->decimal('damaged_quantity', 15, 3)->default(0)->after('in_transit_quantity');
            }
            if (!Schema::hasColumn('warehouse_inventories', 'quarantine_quantity')) {
                $table->decimal('quarantine_quantity', 15, 3)->default(0)->after('damaged_quantity');
            }
            if (!Schema::hasColumn('warehouse_inventories', 'min_stock')) {
                $table->decimal('min_stock', 15, 3)->default(10)->after('reorder_level');
            }
            if (!Schema::hasColumn('warehouse_inventories', 'max_stock')) {
                $table->decimal('max_stock', 15, 3)->default(500)->after('min_stock');
            }
            if (!Schema::hasColumn('warehouse_inventories', 'safety_stock')) {
                $table->decimal('safety_stock', 15, 3)->default(10)->after('max_stock');
            }
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            if (!Schema::hasColumn('stock_movements', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('id')->index();
            }
            if (!Schema::hasColumn('stock_movements', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->nullable()->after('product_variant_id')->index();
            }
            if (!Schema::hasColumn('stock_movements', 'uom_code')) {
                $table->string('uom_code', 24)->default('PCS')->after('type');
            }
            if (!Schema::hasColumn('stock_movements', 'uom_quantity')) {
                $table->decimal('uom_quantity', 15, 3)->nullable()->after('uom_code');
            }
            if (!Schema::hasColumn('stock_movements', 'conversion_factor')) {
                $table->decimal('conversion_factor', 15, 3)->default(1.000)->after('uom_quantity');
            }
            if (!Schema::hasColumn('stock_movements', 'unit_cost')) {
                $table->decimal('unit_cost', 15, 2)->default(0)->after('quantity_after');
            }
        });

        // 11. Supplier Product Mapping & Enhanced Supplier/Customer Columns
        Schema::table('suppliers', function (Blueprint $table): void {
            if (!Schema::hasColumn('suppliers', 'principal_id')) {
                $table->foreignId('principal_id')->nullable()->after('organization_id')->constrained('principals')->nullOnDelete();
            }
            if (!Schema::hasColumn('suppliers', 'lead_time_days')) {
                $table->unsignedSmallInteger('lead_time_days')->default(3)->after('payment_term_days');
            }
            if (!Schema::hasColumn('suppliers', 'bank_name')) {
                $table->string('bank_name', 64)->nullable()->after('lead_time_days');
            }
            if (!Schema::hasColumn('suppliers', 'bank_account_number')) {
                $table->string('bank_account_number', 64)->nullable()->after('bank_name');
            }
            if (!Schema::hasColumn('suppliers', 'bank_account_name')) {
                $table->string('bank_account_name', 120)->nullable()->after('bank_account_number');
            }
            if (!Schema::hasColumn('suppliers', 'tax_number')) {
                $table->string('tax_number', 32)->nullable()->after('bank_account_name');
            }
        });

        if (!Schema::hasTable('supplier_products')) {
            Schema::create('supplier_products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
                $table->string('supplier_sku', 64)->nullable();
                $table->decimal('purchase_price', 15, 2)->default(0);
                $table->decimal('last_purchase_price', 15, 2)->default(0);
                $table->unsignedSmallInteger('lead_time_days')->default(3);
                $table->decimal('min_order_qty', 15, 3)->default(1.000);
                $table->boolean('is_default_supplier')->default(false);
                $table->timestampsTz();

                $table->unique(['supplier_id', 'product_variant_id'], 'uq_supplier_products_sup_var');
            });
        }

        Schema::table('customers', function (Blueprint $table): void {
            if (!Schema::hasColumn('customers', 'npwp')) {
                $table->string('npwp', 32)->nullable()->after('company_name');
            }
            if (!Schema::hasColumn('customers', 'customer_type')) {
                $table->string('customer_type', 32)->default('retail')->after('npwp')
                    ->comment('retail | wholesale | reseller | distributor | corporate');
            }
            if (!Schema::hasColumn('customers', 'contact_person')) {
                $table->string('contact_person', 96)->nullable()->after('customer_type');
            }
            if (!Schema::hasColumn('customers', 'sales_pic')) {
                $table->string('sales_pic', 96)->nullable()->after('contact_person');
            }
            if (!Schema::hasColumn('customers', 'shipping_address')) {
                $table->string('shipping_address')->nullable()->after('address');
            }
            if (!Schema::hasColumn('customers', 'customer_category')) {
                $table->string('customer_category', 64)->nullable()->after('customer_group');
            }
            if (!Schema::hasColumn('customers', 'price_list_id')) {
                $table->foreignId('price_list_id')->nullable()->after('pricing_tier')->constrained('price_lists')->nullOnDelete();
            }
            if (!Schema::hasColumn('customers', 'assigned_sales_id')) {
                $table->foreignId('assigned_sales_id')->nullable()->after('price_list_id')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('customers', 'deleted_at')) {
                $table->softDeletesTz();
            }
        });

        if (!Schema::hasTable('customer_addresses')) {
            Schema::create('customer_addresses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->string('label', 64)->default('Gudang / Toko Utama');
                $table->string('recipient_name', 96)->nullable();
                $table->string('phone', 32)->nullable();
                $table->string('address');
                $table->string('city', 64)->nullable();
                $table->string('postal_code', 16)->nullable();
                $table->boolean('is_default_shipping')->default(true);
                $table->boolean('is_default_billing')->default(true);
                $table->timestampsTz();
            });
        }

        // 12. Snapshot Unit Conversion, HPP & Batch on Transaction Item Tables
        Schema::table('order_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('order_items', 'product_unit_id')) {
                $table->unsignedBigInteger('product_unit_id')->nullable()->after('product_variant_id');
            }
            if (!Schema::hasColumn('order_items', 'uom_code')) {
                $table->string('uom_code', 24)->default('PCS')->after('sku');
            }
            if (!Schema::hasColumn('order_items', 'uom_quantity')) {
                $table->decimal('uom_quantity', 15, 3)->nullable()->after('uom_code');
            }
            if (!Schema::hasColumn('order_items', 'conversion_factor')) {
                $table->decimal('conversion_factor', 15, 3)->default(1.000)->after('uom_quantity');
            }
            if (!Schema::hasColumn('order_items', 'unit_cost')) {
                $table->decimal('unit_cost', 15, 2)->default(0)->after('unit_price');
            }
            if (!Schema::hasColumn('order_items', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->nullable()->after('unit_cost');
            }
        });

        Schema::table('sales_order_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('sales_order_items', 'product_unit_id')) {
                $table->unsignedBigInteger('product_unit_id')->nullable()->after('product_variant_id');
            }
            if (!Schema::hasColumn('sales_order_items', 'uom_code')) {
                $table->string('uom_code', 24)->default('PCS')->after('variant_name');
            }
            if (!Schema::hasColumn('sales_order_items', 'uom_quantity')) {
                $table->decimal('uom_quantity', 15, 3)->nullable()->after('uom_code');
            }
            if (!Schema::hasColumn('sales_order_items', 'conversion_factor')) {
                $table->decimal('conversion_factor', 15, 3)->default(1.000)->after('uom_quantity');
            }
            if (!Schema::hasColumn('sales_order_items', 'unit_cost')) {
                $table->decimal('unit_cost', 15, 2)->default(0)->after('unit_price');
            }
            if (!Schema::hasColumn('sales_order_items', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->nullable()->after('unit_cost');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('purchase_orders', 'purchase_request_id')) {
                $table->unsignedBigInteger('purchase_request_id')->nullable()->after('supplier_id');
            }
            if (!Schema::hasColumn('purchase_orders', 'additional_cost')) {
                $table->decimal('additional_cost', 15, 2)->default(0)->after('tax_total')
                    ->comment('Landed cost allocation: freight/handling');
            }
            if (!Schema::hasColumn('purchase_orders', 'approved_at')) {
                $table->timestampTz('approved_at')->nullable()->after('approved_by');
            }
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('purchase_order_items', 'product_unit_id')) {
                $table->unsignedBigInteger('product_unit_id')->nullable()->after('product_variant_id');
            }
            if (!Schema::hasColumn('purchase_order_items', 'uom_code')) {
                $table->string('uom_code', 24)->default('PCS')->after('variant_name');
            }
            if (!Schema::hasColumn('purchase_order_items', 'uom_quantity')) {
                $table->decimal('uom_quantity', 15, 3)->nullable()->after('uom_code');
            }
            if (!Schema::hasColumn('purchase_order_items', 'conversion_factor')) {
                $table->decimal('conversion_factor', 15, 3)->default(1.000)->after('uom_quantity');
            }
            if (!Schema::hasColumn('purchase_order_items', 'landed_unit_cost')) {
                $table->decimal('landed_unit_cost', 15, 2)->default(0)->after('unit_cost');
            }
        });

        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('goods_receipt_items', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->nullable()->after('product_variant_id');
            }
            if (!Schema::hasColumn('goods_receipt_items', 'batch_number')) {
                $table->string('batch_number', 64)->nullable()->after('batch_id');
            }
            if (!Schema::hasColumn('goods_receipt_items', 'lot_number')) {
                $table->string('lot_number', 64)->nullable()->after('batch_number');
            }
            if (!Schema::hasColumn('goods_receipt_items', 'manufacturing_date')) {
                $table->date('manufacturing_date')->nullable()->after('lot_number');
            }
            if (!Schema::hasColumn('goods_receipt_items', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('manufacturing_date');
            }
            if (!Schema::hasColumn('goods_receipt_items', 'uom_code')) {
                $table->string('uom_code', 24)->default('PCS')->after('expiry_date');
            }
            if (!Schema::hasColumn('goods_receipt_items', 'conversion_factor')) {
                $table->decimal('conversion_factor', 15, 3)->default(1.000)->after('uom_code');
            }
            if (!Schema::hasColumn('goods_receipt_items', 'unit_cost')) {
                $table->decimal('unit_cost', 15, 2)->default(0)->after('discrepancy_qty');
            }
            if (!Schema::hasColumn('goods_receipt_items', 'landed_unit_cost')) {
                $table->decimal('landed_unit_cost', 15, 2)->default(0)->after('unit_cost');
            }
        });

        Schema::table('stock_transfer_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('stock_transfer_items', 'batch_id')) {
                $table->unsignedBigInteger('batch_id')->nullable()->after('product_variant_id');
            }
            if (!Schema::hasColumn('stock_transfer_items', 'uom_code')) {
                $table->string('uom_code', 24)->default('PCS')->after('variant_name');
            }
            if (!Schema::hasColumn('stock_transfer_items', 'conversion_factor')) {
                $table->decimal('conversion_factor', 15, 3)->default(1.000)->after('uom_code');
            }
            if (!Schema::hasColumn('stock_transfer_items', 'unit_cost')) {
                $table->decimal('unit_cost', 15, 2)->default(0)->after('discrepancy_qty');
            }
        });

        // 13. Purchase Requests, Purchase Invoices, Purchase Returns & Sales Returns
        if (!Schema::hasTable('purchase_requests')) {
            Schema::create('purchase_requests', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->string('pr_number', 48)->unique();
                $table->string('status', 24)->default('pending_approval')->comment('draft | pending_approval | approved | rejected | converted_to_po');
                $table->date('request_date');
                $table->date('needed_by_date')->nullable();
                $table->decimal('estimated_total', 15, 2)->default(0);
                $table->text('notes')->nullable();
                $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('approved_at')->nullable();
                $table->timestampsTz();
            });
        }

        if (!Schema::hasTable('purchase_request_items')) {
            Schema::create('purchase_request_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
                $table->string('sku', 64);
                $table->string('product_name');
                $table->string('variant_name');
                $table->string('uom_code', 24)->default('PCS');
                $table->decimal('conversion_factor', 15, 3)->default(1.000);
                $table->decimal('requested_qty', 15, 3);
                $table->decimal('approved_qty', 15, 3)->nullable();
                $table->decimal('estimated_unit_cost', 15, 2)->default(0);
                $table->decimal('line_total', 15, 2)->default(0);
                $table->string('notes')->nullable();
                $table->timestampsTz();
            });
        }

        if (!Schema::hasTable('purchase_invoices')) {
            Schema::create('purchase_invoices', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
                $table->string('invoice_number', 64)->unique();
                $table->string('supplier_invoice_ref', 64)->nullable();
                $table->string('status', 24)->default('unpaid')->comment('unpaid | partial | paid');
                $table->date('issue_date');
                $table->date('due_date');
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('tax_total', 15, 2)->default(0);
                $table->decimal('additional_cost', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->decimal('paid_total', 15, 2)->default(0);
                $table->decimal('balance_due', 15, 2)->default(0);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestampsTz();
            });
        }

        if (!Schema::hasTable('purchase_returns')) {
            Schema::create('purchase_returns', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->string('return_number', 48)->unique();
                $table->string('status', 24)->default('completed');
                $table->date('return_date');
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->string('reason');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestampsTz();
            });
        }

        if (!Schema::hasTable('purchase_return_items')) {
            Schema::create('purchase_return_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
                $table->string('sku', 64);
                $table->string('product_name');
                $table->string('variant_name');
                $table->decimal('quantity', 15, 3);
                $table->decimal('unit_cost', 15, 2);
                $table->decimal('line_total', 15, 2);
                $table->timestampsTz();
            });
        }

        if (!Schema::hasTable('sales_returns')) {
            Schema::create('sales_returns', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->string('return_number', 48)->unique();
                $table->string('status', 24)->default('completed');
                $table->string('condition', 24)->default('good')->comment('good | damaged | expired');
                $table->date('return_date');
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->string('reason');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestampsTz();
            });
        }

        if (!Schema::hasTable('sales_return_items')) {
            Schema::create('sales_return_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
                $table->string('sku', 64);
                $table->string('product_name');
                $table->string('variant_name');
                $table->decimal('quantity', 15, 3);
                $table->decimal('unit_price', 15, 2);
                $table->decimal('line_total', 15, 2);
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_invoices');
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('inventory_batches');
        Schema::dropIfExists('product_inventory_settings');
        Schema::dropIfExists('product_price_histories');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('principal_supplier');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('principals');
        Schema::dropIfExists('units');
    }
};

