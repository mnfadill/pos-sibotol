<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Granular Permission catalogue for RBAC.
 * Values double as Gate abilities, e.g. `can:users.manage`.
 */
enum Permission: string
{
    // POS & Checkout
    case PosCheckout = 'pos.checkout';
    case DiscountsApply = 'discounts.apply';
    case OverridesAuthorize = 'overrides.authorize';

    // Orders (Counter Sales)
    case OrdersView = 'orders.view';
    case OrdersCreate = 'orders.create';
    case OrdersHold = 'orders.hold';
    case OrdersCancel = 'orders.cancel';
    case OrdersVoid = 'orders.void';
    case OrdersRefund = 'orders.refund';
    case OrdersExport = 'orders.export';
    case OrdersManage = 'orders.manage';

    // Shifts & Cash
    case ShiftsOperate = 'shifts.operate';
    case ShiftsViewAll = 'shifts.view_all';
    case ShiftsManage = 'shifts.manage';
    case CashMovementsCreate = 'cash_movements.create';
    case ShiftCheckinCreate = 'shift_checkin.create';
    case ShiftCheckinBackdate = 'shift_checkin.backdate';
    case ShiftCheckinApprove = 'shift_checkin.approve';

    // Inventory & Batches
    case InventoryView = 'inventory.view';
    case InventoryCreate = 'inventory.create';
    case InventoryEdit = 'inventory.edit';
    case InventoryAdjust = 'inventory.adjust';
    case InventoryOpname = 'inventory.opname';
    case InventoryExport = 'inventory.export';
    case InventoryManage = 'inventory.manage';
    case BatchesManage = 'batches.manage';

    // Catalog & Products Master
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';
    case CatalogOutletSettings = 'catalog.outlet_settings';
    case ProductsView = 'products.view';
    case ProductsCreate = 'products.create';
    case ProductsEdit = 'products.edit';
    case ProductsDelete = 'products.delete';
    case ProductsExport = 'products.export';
    case ProductsManage = 'products.manage';
    case MasterDataManage = 'master_data.manage';

    // Pricing & Tiers
    case PricingView = 'pricing.view';
    case PricingCreate = 'pricing.create';
    case PricingEdit = 'pricing.edit';
    case PricingManage = 'pricing.manage';
    case PricingApprove = 'pricing.approve';

    // Procurement
    case ProcurementView = 'procurement.view';
    case ProcurementCreate = 'procurement.create';
    case ProcurementEdit = 'procurement.edit';
    case ProcurementDelete = 'procurement.delete';
    case ProcurementApprove = 'procurement.approve';
    case ProcurementCancel = 'procurement.cancel';
    case ProcurementReceive = 'procurement.receive';
    case ProcurementExport = 'procurement.export';
    case ProcurementManage = 'procurement.manage';
    case PurchaseRequestsApprove = 'purchase_requests.approve';
    case PurchaseOrdersApprove = 'purchase_orders.approve';

    // Customers & B2B Sales Orders
    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersEdit = 'customers.edit';
    case CustomersDelete = 'customers.delete';
    case CustomersManage = 'customers.manage';
    case CustomersExport = 'customers.export';
    case CreditLimitApprove = 'customers.credit_approve';

    case SalesOrdersView = 'sales_orders.view';
    case SalesOrdersCreate = 'sales_orders.create';
    case SalesOrdersEdit = 'sales_orders.edit';
    case SalesOrdersDelete = 'sales_orders.delete';
    case SalesOrdersCancel = 'sales_orders.cancel';
    case SalesOrdersApprove = 'sales_orders.approve';
    case SalesOrdersFulfill = 'sales_orders.fulfill';
    case SalesOrdersExport = 'sales_orders.export';
    case SalesOrdersManage = 'sales_orders.manage';

    // Receivables (AR) & Finance
    case ReceivablesView = 'receivables.view';
    case ReceivablesCreate = 'receivables.create';
    case ReceivablesEdit = 'receivables.edit';
    case ReceivablesExport = 'receivables.export';
    case ReceivablesManage = 'receivables.manage';

    // Warehouse & Stock Transfer
    case WarehouseView = 'warehouse.view';
    case WarehouseCreate = 'warehouse.create';
    case WarehouseEdit = 'warehouse.edit';
    case WarehouseManage = 'warehouse.manage';
    case StockTransferRequest = 'stock_transfer.request';
    case StockTransferApprove = 'stock_transfer.approve';
    case StockTransferReceive = 'stock_transfer.receive';

    // User & Role Management
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersEdit = 'users.edit';
    case UsersDelete = 'users.delete';
    case UsersExport = 'users.export';
    case UsersManage = 'users.manage';

    case RolesView = 'roles.view';
    case RolesCreate = 'roles.create';
    case RolesEdit = 'roles.edit';
    case RolesDelete = 'roles.delete';
    case RolesManage = 'roles.manage';

    // Outlets & Multi-Tenant
    case OutletsView = 'outlets.view';
    case OutletsCreate = 'outlets.create';
    case OutletsEdit = 'outlets.edit';
    case OutletsManage = 'outlets.manage';
    case OutletsSwitch = 'outlets.switch';
    case CompanyDashboardView = 'company.dashboard.view';

    // Audit & Activity Logs
    case AuditView = 'audit.view';
    case AuditExport = 'audit.export';
    case ActivityLogsView = 'activity_logs.view';

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    public function action(): string
    {
        $parts = explode('.', $this->value);

        return $parts[1] ?? 'general';
    }

    public function description(): string
    {
        return ucwords(str_replace(['.', '_'], ' ', $this->value));
    }
}
