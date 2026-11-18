<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountsReceivableController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CompanyDashboardController;
use App\Http\Controllers\Api\CustomerAndSalesOrderController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeShiftCheckinController;
use App\Http\Controllers\Api\GlobalActivityLogController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OutboxController;
use App\Http\Controllers\Api\ProcurementController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\ShiftManagementController;
use App\Http\Controllers\Api\TaxAndDiscountController;
use App\Http\Controllers\Api\TierManagementController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\WarehouseAndTransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public Authentication
    Route::post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');

    // Authenticated POS & Sub-Distributor Routes
    Route::middleware(['auth:sanctum', 'pos.context'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('/auth/session', [AuthController::class, 'selectSession'])->name('api.auth.session');
        Route::post('/auth/select-session', [AuthController::class, 'selectSession'])->name('api.auth.select_session');
        Route::post('/auth/select-outlet', [AuthController::class, 'selectSession'])->name('api.auth.select_outlet');
        Route::post('/auth/switch-outlet', [AuthController::class, 'selectSession'])->name('api.auth.switch_outlet');
        Route::post('/auth/clear-outlet', [AuthController::class, 'clearOutletContext'])->name('api.auth.clear_outlet');
        Route::post('/auth/outlets/{outletId}/favorite', [AuthController::class, 'toggleFavoriteOutlet'])->name('api.auth.favorite_outlet');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::post('/auth/verify-supervisor-pin', [AuthController::class, 'verifySupervisorPin'])->name('api.auth.verify_pin');

        // Company Consolidated Dashboard & Dynamic Outlet Creation (Super Admin / Owner)
        Route::get('/company/dashboard', [CompanyDashboardController::class, 'summary'])->name('api.company.dashboard');
        Route::post('/company/outlets', [CompanyDashboardController::class, 'createOutlet'])->name('api.company.outlets.store');

        // =====================================================================
        // MASTER DATA MANAGEMENT & ENTERPRISE INVENTORY ENGINE
        // =====================================================================
        // Master Data Hub Group (Prefixed with /master-data for UI Hub & API clarity)
        Route::prefix('master-data')->group(function (): void {
            Route::get('/overview', [MasterDataController::class, 'overview'])->name('api.master_data.overview');
            Route::get('/products', [MasterDataController::class, 'productsIndex'])->name('api.master_data.products.index');
            Route::post('/products', [MasterDataController::class, 'storeProduct'])->name('api.master_data.products.store');
            Route::post('/products/bulk-update', [MasterDataController::class, 'bulkUpdateProducts'])->name('api.master_data.products.bulk_update');
            Route::post('/products/import', [MasterDataController::class, 'importProducts'])->name('api.master_data.products.import');
            Route::get('/products/{id}', [MasterDataController::class, 'showProduct'])->name('api.master_data.products.show');
            Route::put('/products/{id}', [MasterDataController::class, 'updateProduct'])->name('api.master_data.products.update');
            Route::delete('/products/{id}', [MasterDataController::class, 'destroyProduct'])->name('api.master_data.products.destroy');
            Route::post('/products/{id}/archive', [MasterDataController::class, 'archiveProduct'])->name('api.master_data.products.archive');
            Route::post('/products/{id}/inventory-settings', [MasterDataController::class, 'saveProductInventorySetting'])->name('api.master_data.products.inventory_settings');
            Route::post('/products/{id}/suppliers', [MasterDataController::class, 'saveSupplierProduct'])->name('api.master_data.products.suppliers');
            Route::get('/products/{id}/units', [MasterDataController::class, 'productUnitsIndex'])->name('api.master_data.products.units.index');
            Route::post('/products/{id}/units', [MasterDataController::class, 'storeProductUnit'])->name('api.master_data.products.units.store');
            Route::put('/products/{id}/units/{unitId}', [MasterDataController::class, 'updateProductUnit'])->name('api.master_data.products.units.update');
            Route::delete('/products/{id}/units/{unitId}', [MasterDataController::class, 'destroyProductUnit'])->name('api.master_data.products.units.destroy');
            Route::get('/products/{id}/barcodes', [MasterDataController::class, 'productBarcodesIndex'])->name('api.master_data.products.barcodes.index');
            Route::post('/products/{id}/barcodes', [MasterDataController::class, 'storeProductBarcode'])->name('api.master_data.products.barcodes.store');
            Route::delete('/products/{id}/barcodes/{barcodeId}', [MasterDataController::class, 'destroyProductBarcode'])->name('api.master_data.products.barcodes.destroy');
            Route::get('/products/{id}/price-history', [MasterDataController::class, 'productPriceHistory'])->name('api.master_data.products.price_history');

            // Categories, Brands & Principals
            Route::get('/categories', [MasterDataController::class, 'categoriesIndex'])->name('api.master_data.categories.index');
            Route::post('/categories', [MasterDataController::class, 'storeCategory'])->name('api.master_data.categories.store');
            Route::put('/categories/{id}', [MasterDataController::class, 'updateCategory'])->name('api.master_data.categories.update');
            Route::get('/brands', [MasterDataController::class, 'brandsIndex'])->name('api.master_data.brands.index');
            Route::post('/brands', [MasterDataController::class, 'storeBrand'])->name('api.master_data.brands.store');
            Route::put('/brands/{id}', [MasterDataController::class, 'updateBrand'])->name('api.master_data.brands.update');
            Route::get('/principals', [MasterDataController::class, 'principalsIndex'])->name('api.master_data.principals.index');
            Route::post('/principals', [MasterDataController::class, 'storePrincipal'])->name('api.master_data.principals.store');
            Route::put('/principals/{id}', [MasterDataController::class, 'updatePrincipal'])->name('api.master_data.principals.update');

            // Units (UOM)
            Route::get('/units', [MasterDataController::class, 'unitsIndex'])->name('api.master_data.units.index');
            Route::post('/units', [MasterDataController::class, 'storeUnit'])->name('api.master_data.units.store');
            Route::put('/units/{id}', [MasterDataController::class, 'updateUnit'])->name('api.master_data.units.update');
            Route::delete('/units/{id}', [MasterDataController::class, 'destroyUnit'])->name('api.master_data.units.destroy');

            // Price Lists & 4 Customer Identity Master Tiers
            Route::get('/price-lists', [MasterDataController::class, 'priceListsIndex'])->name('api.master_data.price_lists.index');
            Route::post('/price-lists', [MasterDataController::class, 'storePriceList'])->name('api.master_data.price_lists.store');
            Route::put('/price-lists/{id}', [MasterDataController::class, 'updatePriceList'])->name('api.master_data.price_lists.update');
            Route::get('/customer-tiers', [MasterDataController::class, 'customerTiers'])->name('api.master_data.customer_tiers.index');
            Route::put('/pricing/update-tier-prices', [MasterDataController::class, 'updateTierPrices'])->name('api.master_data.pricing.update_tier_prices');
            Route::post('/pricing/resolve', [MasterDataController::class, 'resolvePrice'])->name('api.master_data.pricing.resolve');

            // Stock Ledger & Batches
            Route::get('/ledger', [MasterDataController::class, 'stockLedger'])->name('api.master_data.ledger');
            Route::get('/batches', [MasterDataController::class, 'batchesIndex'])->name('api.master_data.batches.index');
            Route::get('/batches/expiring', [MasterDataController::class, 'expiringBatches'])->name('api.master_data.batches.expiring');
            Route::post('/batches', [MasterDataController::class, 'storeBatch'])->name('api.master_data.batches.store');
            Route::put('/batches/{id}/status', [MasterDataController::class, 'updateBatchStatus'])->name('api.master_data.batches.status');
        });

        // Direct Route Aliases (For legacy / backward compatibility)
        Route::get('/products', [MasterDataController::class, 'productsIndex'])->name('api.products.index');
        Route::post('/products', [MasterDataController::class, 'storeProduct'])->name('api.products.store');
        Route::post('/products/bulk-update', [MasterDataController::class, 'bulkUpdateProducts'])->name('api.products.bulk_update');
        Route::post('/products/import', [MasterDataController::class, 'importProducts'])->name('api.products.import');
        Route::get('/products/{id}', [MasterDataController::class, 'showProduct'])->name('api.products.show');
        Route::put('/products/{id}', [MasterDataController::class, 'updateProduct'])->name('api.products.update');
        Route::delete('/products/{id}', [MasterDataController::class, 'destroyProduct'])->name('api.products.destroy');
        Route::post('/products/{id}/archive', [MasterDataController::class, 'archiveProduct'])->name('api.products.archive');
        Route::post('/products/{id}/inventory-settings', [MasterDataController::class, 'saveProductInventorySetting'])->name('api.products.inventory_settings');
        Route::post('/products/{id}/suppliers', [MasterDataController::class, 'saveSupplierProduct'])->name('api.products.suppliers');

        // Product Categories & Subcategories
        Route::get('/product-categories', [MasterDataController::class, 'categoriesIndex'])->name('api.product_categories.index');
        Route::post('/product-categories', [MasterDataController::class, 'storeCategory'])->name('api.product_categories.store');
        Route::put('/product-categories/{id}', [MasterDataController::class, 'updateCategory'])->name('api.product_categories.update');

        // Product Brands & Principals
        Route::get('/product-brands', [MasterDataController::class, 'brandsIndex'])->name('api.product_brands.index');
        Route::post('/product-brands', [MasterDataController::class, 'storeBrand'])->name('api.product_brands.store');
        Route::put('/product-brands/{id}', [MasterDataController::class, 'updateBrand'])->name('api.product_brands.update');
        Route::get('/principals', [MasterDataController::class, 'principalsIndex'])->name('api.principals.index');
        Route::post('/principals', [MasterDataController::class, 'storePrincipal'])->name('api.principals.store');
        Route::put('/principals/{id}', [MasterDataController::class, 'updatePrincipal'])->name('api.principals.update');

        // Units & Product Unit Conversions (Multi-UOM)
        Route::get('/units', [MasterDataController::class, 'unitsIndex'])->name('api.units.index');
        Route::post('/units', [MasterDataController::class, 'storeUnit'])->name('api.units.store');
        Route::put('/units/{id}', [MasterDataController::class, 'updateUnit'])->name('api.units.update');
        Route::get('/products/{id}/units', [MasterDataController::class, 'productUnitsIndex'])->name('api.products.units.index');
        Route::post('/products/{id}/units', [MasterDataController::class, 'storeProductUnit'])->name('api.products.units.store');
        Route::put('/products/{id}/units/{unitId}', [MasterDataController::class, 'updateProductUnit'])->name('api.products.units.update');

        // Multi-Barcodes
        Route::get('/products/{id}/barcodes', [MasterDataController::class, 'productBarcodesIndex'])->name('api.products.barcodes.index');
        Route::post('/products/{id}/barcodes', [MasterDataController::class, 'storeProductBarcode'])->name('api.products.barcodes.store');
        Route::delete('/products/{id}/barcodes/{barcodeId}', [MasterDataController::class, 'destroyProductBarcode'])->name('api.products.barcodes.destroy');

        // Price Lists, Price History & Unified Pricing Resolver
        Route::get('/price-lists', [MasterDataController::class, 'priceListsIndex'])->name('api.price_lists.index');
        Route::post('/price-lists', [MasterDataController::class, 'storePriceList'])->name('api.price_lists.store');
        Route::put('/price-lists/{id}', [MasterDataController::class, 'updatePriceList'])->name('api.price_lists.update');
        Route::get('/products/{id}/price-history', [MasterDataController::class, 'productPriceHistory'])->name('api.products.price_history');
        Route::post('/pricing/resolve', [MasterDataController::class, 'resolvePrice'])->name('api.pricing.resolve');

        // Stock Ledger (Central & Branch), Reservations, Batches & Expiry (FEFO)
        Route::get('/stock-ledger', [MasterDataController::class, 'stockLedger'])->name('api.stock_ledger.index');
        Route::get('/batches', [MasterDataController::class, 'batchesIndex'])->name('api.batches.index');
        Route::get('/batches/expiring', [MasterDataController::class, 'expiringBatches'])->name('api.batches.expiring');
        Route::post('/batches', [MasterDataController::class, 'storeBatch'])->name('api.batches.store');
        Route::put('/batches/{id}/status', [MasterDataController::class, 'updateBatchStatus'])->name('api.batches.status');

        // Procurement (Principal / Suppliers, Purchase Requests, Purchase Orders, Goods Receiving, Purchase Returns)
        Route::get('/procurement', [ProcurementController::class, 'index'])->name('api.procurement.index');
        Route::post('/procurement/suppliers', [ProcurementController::class, 'storeSupplier'])->name('api.procurement.suppliers.store');
        Route::post('/procurement/purchase-requests', [ProcurementController::class, 'storePurchaseRequest'])->name('api.procurement.pr.store');
        Route::post('/procurement/purchase-requests/{prId}/approve', [ProcurementController::class, 'approvePurchaseRequest'])->name('api.procurement.pr.approve');
        Route::post('/procurement/purchase-orders', [ProcurementController::class, 'storePurchaseOrder'])->name('api.procurement.po.store');
        Route::post('/procurement/purchase-orders/{poId}/receive', [ProcurementController::class, 'receiveGoods'])->name('api.procurement.po.receive');
        Route::post('/procurement/purchase-returns', [ProcurementController::class, 'storePurchaseReturn'])->name('api.procurement.returns.store');

        // Warehouses & Stock Transfer Approval/Receive
        Route::get('/warehouses', [WarehouseAndTransferController::class, 'index'])->name('api.warehouses.index');
        Route::post('/stock-transfers/{transferId}/approve-ship', [WarehouseAndTransferController::class, 'approveAndShip'])->name('api.transfers.approve_ship');
        Route::post('/stock-transfers/{transferId}/receive', [WarehouseAndTransferController::class, 'receiveTransfer'])->name('api.transfers.receive');

        // Dynamic Tier Creation & Product Pricing Configuration
        Route::get('/tiers', [TierManagementController::class, 'index'])->name('api.tiers.index');
        Route::post('/tiers', [TierManagementController::class, 'store'])->name('api.tiers.store');
        Route::get('/tiers/{id}', [TierManagementController::class, 'show'])->name('api.tiers.show');
        Route::put('/tiers/{id}', [TierManagementController::class, 'update'])->name('api.tiers.update');
        Route::delete('/tiers/{id}', [TierManagementController::class, 'destroy'])->name('api.tiers.destroy');
        Route::post('/tiers/{id}/products', [TierManagementController::class, 'assignProduct'])->name('api.tiers.assign_product');
        Route::delete('/tiers/{id}/products/{productId}', [TierManagementController::class, 'removeProduct'])->name('api.tiers.remove_product');
        Route::post('/tiers/{id}/bulk-prices', [TierManagementController::class, 'bulkUpdatePrices'])->name('api.tiers.bulk_prices');

        // Master Diskon, PPN (Tax Rate) & Service Charge Configuration
        Route::get('/pricing-rules', [TaxAndDiscountController::class, 'index'])->name('api.pricing_rules.index');
        Route::put('/pricing-rules/outlet-tax', [TaxAndDiscountController::class, 'updateOutletTaxConfig'])->name('api.pricing_rules.outlet_tax');
        Route::post('/pricing-rules/discounts', [TaxAndDiscountController::class, 'storeDiscount'])->name('api.pricing_rules.discounts.store');
        Route::put('/pricing-rules/discounts/{discount}', [TaxAndDiscountController::class, 'updateDiscount'])->name('api.pricing_rules.discounts.update');
        Route::delete('/pricing-rules/discounts/{discount}', [TaxAndDiscountController::class, 'destroyDiscount'])->name('api.pricing_rules.discounts.destroy');

        // Audit Trail (accessible to Supervisors, Outlet Managers, Finance, Super Admins)
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('api.audit.index');
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('api.audit.show');

        // Global User Activity Tracking Logs
        Route::get('/activity-logs', [GlobalActivityLogController::class, 'index'])->name('api.activity_logs.index');

        // =====================================================================
        // USER, ROLE & PERMISSION MANAGEMENT (RBAC)
        // =====================================================================
        Route::prefix('user-management')->group(function (): void {
            Route::get('/meta', [UserManagementController::class, 'meta'])
                ->middleware('permission:users.view,users.manage,roles.view,roles.manage')->name('api.um.meta');

            // Users
            Route::get('/users', [UserManagementController::class, 'index'])
                ->middleware('permission:users.view,users.manage')->name('api.um.users.index');
            Route::post('/users', [UserManagementController::class, 'store'])
                ->middleware('permission:users.create,users.manage')->name('api.um.users.store');
            Route::get('/users/{id}', [UserManagementController::class, 'show'])->whereNumber('id')
                ->middleware('permission:users.view,users.manage')->name('api.um.users.show');
            Route::put('/users/{id}', [UserManagementController::class, 'update'])->whereNumber('id')
                ->middleware('permission:users.edit,users.manage')->name('api.um.users.update');
            Route::delete('/users/{id}', [UserManagementController::class, 'destroy'])->whereNumber('id')
                ->middleware('permission:users.delete,users.manage')->name('api.um.users.destroy');
            Route::post('/users/{id}/restore', [UserManagementController::class, 'restore'])->whereNumber('id')
                ->middleware('permission:users.delete,users.manage')->name('api.um.users.restore');
            Route::post('/users/{id}/suspend', [UserManagementController::class, 'suspend'])->whereNumber('id')
                ->middleware('permission:users.manage')->name('api.um.users.suspend');
            Route::post('/users/{id}/activate', [UserManagementController::class, 'activate'])->whereNumber('id')
                ->middleware('permission:users.manage')->name('api.um.users.activate');
            Route::post('/users/{id}/reset-password', [UserManagementController::class, 'resetPassword'])->whereNumber('id')
                ->middleware('permission:users.edit,users.manage')->name('api.um.users.reset_password');

            // Roles
            Route::get('/roles', [UserManagementController::class, 'rolesIndex'])
                ->middleware('permission:roles.view,roles.manage,users.manage')->name('api.um.roles.index');
            Route::post('/roles', [UserManagementController::class, 'rolesStore'])
                ->middleware('permission:roles.create,roles.manage')->name('api.um.roles.store');
            Route::get('/roles/{id}', [UserManagementController::class, 'rolesShow'])->whereNumber('id')
                ->middleware('permission:roles.view,roles.manage')->name('api.um.roles.show');
            Route::put('/roles/{id}', [UserManagementController::class, 'rolesUpdate'])->whereNumber('id')
                ->middleware('permission:roles.edit,roles.manage')->name('api.um.roles.update');
            Route::delete('/roles/{id}', [UserManagementController::class, 'rolesDestroy'])->whereNumber('id')
                ->middleware('permission:roles.delete,roles.manage')->name('api.um.roles.destroy');

            // Permissions matrix
            Route::get('/permissions', [UserManagementController::class, 'permissionsIndex'])
                ->middleware('permission:roles.view,roles.manage')->name('api.um.permissions.index');

            // Activity log (auth + user/role events)
            Route::get('/activity-logs', [UserManagementController::class, 'activityLogs'])
                ->middleware('permission:audit.view,users.manage,activity_logs.view')->name('api.um.activity_logs');
        });

        // Operational Shift Definitions Management (CRUD)
        Route::get('/shifts/manage', [ShiftManagementController::class, 'index'])->name('api.shifts_manage.index');
        Route::post('/shifts/manage', [ShiftManagementController::class, 'store'])->name('api.shifts_manage.store');
        Route::put('/shifts/manage/{id}', [ShiftManagementController::class, 'update'])->name('api.shifts_manage.update');
        Route::delete('/shifts/manage/{id}', [ShiftManagementController::class, 'destroy'])->name('api.shifts_manage.destroy');

        // Employee Operational Shift Check-in, Switch & History
        Route::get('/employee-shifts/catalogue', [EmployeeShiftCheckinController::class, 'catalogue'])->name('api.employee_shifts.catalogue');
        Route::get('/employee-shifts/current', [EmployeeShiftCheckinController::class, 'current'])->name('api.employee_shifts.current');
        Route::post('/employee-shifts/check-in', [EmployeeShiftCheckinController::class, 'checkIn'])->name('api.employee_shifts.checkin');
        Route::post('/employee-shifts/switch', [EmployeeShiftCheckinController::class, 'switchShift'])->name('api.employee_shifts.switch');
        Route::get('/employee-shifts/history', [EmployeeShiftCheckinController::class, 'history'])->name('api.employee_shifts.history');
        Route::post('/employee-shifts/{id}/approve', [EmployeeShiftCheckinController::class, 'approve'])->name('api.employee_shifts.approve');

        // Transactional Outbox inspection & manual relay trigger
        Route::get('/outbox', [OutboxController::class, 'index'])->name('api.outbox.index');
        Route::post('/outbox/dispatch', [OutboxController::class, 'dispatchNow'])->name('api.outbox.dispatch');

        // Routes requiring an active Outlet Workspace
        Route::middleware(['pos.terminal'])->group(function (): void {
            // Operational Outlet Dashboard Summary
            Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('api.dashboard.summary');

            // Outlet Stock Transfer Request (Branch -> Central Warehouse)
            Route::post('/stock-transfers', [WarehouseAndTransferController::class, 'requestTransfer'])->name('api.transfers.request');

            // B2B Customers, Credit Control, Sales Orders, Cancel, Deliveries & Sales Returns
            Route::get('/customers', [CustomerAndSalesOrderController::class, 'customers'])->name('api.customers.index');
            Route::post('/customers', [CustomerAndSalesOrderController::class, 'storeCustomer'])->name('api.customers.store');
            Route::put('/customers/{customerId}', [CustomerAndSalesOrderController::class, 'updateCustomer'])->name('api.customers.update');
            Route::get('/sales-orders', [CustomerAndSalesOrderController::class, 'salesOrders'])->name('api.sales_orders.index');
            Route::post('/sales-orders', [CustomerAndSalesOrderController::class, 'storeSalesOrder'])->name('api.sales_orders.store');
            Route::post('/sales-orders/{salesOrderId}/cancel', [CustomerAndSalesOrderController::class, 'cancelSalesOrder'])->name('api.sales_orders.cancel');
            Route::post('/sales-orders/{salesOrderId}/fulfill', [CustomerAndSalesOrderController::class, 'fulfillAndInvoice'])->name('api.sales_orders.fulfill');
            Route::post('/sales-returns', [CustomerAndSalesOrderController::class, 'storeSalesReturn'])->name('api.sales_returns.store');

            // Accounts Receivable, Invoices & Customer Payment Allocations
            Route::get('/receivables', [AccountsReceivableController::class, 'index'])->name('api.receivables.index');
            Route::post('/receivables/payments', [AccountsReceivableController::class, 'recordPayment'])->name('api.receivables.payments.store');

            // Cashier Shift Management
            Route::get('/shifts/current', [ShiftController::class, 'current'])->name('api.shifts.current');
            Route::get('/shifts', [ShiftController::class, 'index'])->name('api.shifts.index');
            Route::get('/shifts/{shift}', [ShiftController::class, 'show'])->name('api.shifts.show');
            Route::post('/shifts/open', [ShiftController::class, 'open'])->name('api.shifts.open');

            Route::middleware(['pos.shift'])->group(function (): void {
                Route::post('/shifts/cash-movements', [ShiftController::class, 'cashMovement'])->name('api.shifts.cash_movement');
                Route::post('/shifts/close', [ShiftController::class, 'close'])->name('api.shifts.close');
            });

            // Product Catalog & Outlet Pricing Overrides
            Route::get('/catalog/categories', [CatalogController::class, 'categories'])->name('api.catalog.categories');
            Route::get('/catalog/products', [CatalogController::class, 'products'])->name('api.catalog.products');
            Route::get('/catalog/barcode/{code}', [CatalogController::class, 'lookupBarcode'])->name('api.catalog.barcode');
            Route::get('/barcodes/lookup/{code}', [CatalogController::class, 'lookupBarcode'])->name('api.barcodes.lookup');
            Route::patch('/catalog/variants/{variant}/outlet-setting', [CatalogController::class, 'updateOutletSetting'])
                ->name('api.catalog.outlet_setting');

            // Counter Sales Orders & Checkout Engine
            Route::get('/orders', [OrderController::class, 'index'])->name('api.orders.index');
            Route::get('/orders/held', [OrderController::class, 'heldOrders'])->name('api.orders.held');
            Route::get('/orders/{order}', [OrderController::class, 'show'])->name('api.orders.show');
            Route::post('/orders/calculate', [OrderController::class, 'calculate'])->name('api.orders.calculate');
            Route::post('/orders/qris-payload', [OrderController::class, 'generateQris'])->name('api.orders.qris');

            // Checkout, Hold, Resume, Cancel, Void & Refund require an open cashier shift
            Route::middleware(['pos.shift'])->group(function (): void {
                Route::post('/orders/hold', [OrderController::class, 'hold'])->name('api.orders.hold');
                Route::post('/orders/{order}/resume', [OrderController::class, 'resume'])->name('api.orders.resume');
                Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('api.orders.cancel');
                Route::post('/orders/{order}/void', [OrderController::class, 'void'])->name('api.orders.void');
                Route::post('/orders/{order}/refund', [OrderController::class, 'refund'])
                    ->middleware('pos.idempotent')
                    ->name('api.orders.refund');

                // Payment processing MUST include X-Idempotency-Key
                Route::post('/orders/checkout', [OrderController::class, 'checkout'])
                    ->middleware('pos.idempotent')
                    ->name('api.orders.checkout');
            });

            // Operational Inventory Control & Stock Opname
            Route::get('/inventory', [InventoryController::class, 'index'])->name('api.inventory.index');
            Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('api.inventory.movements');
            Route::get('/inventory/adjustments', [InventoryController::class, 'adjustments'])->name('api.inventory.adjustments');
            Route::post('/inventory/adjustments', [InventoryController::class, 'adjust'])->name('api.inventory.adjust');
            Route::post('/inventory/opname', [InventoryController::class, 'opname'])->name('api.inventory.opname');
        });
    });
});
