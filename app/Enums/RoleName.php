<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * System (seeded) roles. These are only the initial/default roles — additional
 * custom roles can be created dynamically via Role Management and are stored
 * in the `roles` table. Access checks MUST use permissions, not role names.
 */
enum RoleName: string
{
    case SuperAdmin = 'super_admin';
    case AdminOutlet = 'admin_outlet';
    case OutletManager = 'outlet_manager';
    case Cashier = 'cashier';
    case SalesRep = 'sales_rep';
    case WarehouseStaff = 'warehouse_staff';
    case ProcurementStaff = 'procurement_staff';
    case Finance = 'finance';
    case Viewer = 'viewer';

    // Legacy roles kept for backward compatibility with existing accounts
    case Owner = 'owner';
    case Supervisor = 'supervisor';

    public function displayName(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::AdminOutlet => 'Admin Outlet',
            self::OutletManager => 'Manager Outlet',
            self::Cashier => 'Kasir',
            self::SalesRep => 'Sales',
            self::WarehouseStaff => 'Warehouse Staff',
            self::ProcurementStaff => 'Procurement Staff',
            self::Finance => 'Finance / Accounting',
            self::Viewer => 'Viewer / Auditor',
            self::Owner => 'Owner / Management',
            self::Supervisor => 'Supervisor',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Akses penuh ke seluruh outlet, modul, dan pengaturan sistem.',
            self::AdminOutlet => 'Administrator outlet: mengelola user, master data, dan operasional outlet yang ditugaskan.',
            self::OutletManager => 'Manager outlet: memantau penjualan, stok, approval, dan tim outlet.',
            self::Cashier => 'Kasir: transaksi penjualan counter dan pengelolaan shift kas.',
            self::SalesRep => 'Sales B2B: pelanggan, sales order, dan piutang.',
            self::WarehouseStaff => 'Staff gudang: stok, stock opname, transfer, dan penerimaan barang.',
            self::ProcurementStaff => 'Staff pembelian: supplier, purchase request, dan purchase order.',
            self::Finance => 'Finance / Accounting: piutang, pembayaran, laporan, dan audit.',
            self::Viewer => 'Viewer / Auditor: akses baca saja untuk audit dan laporan.',
            self::Owner => 'Owner / Management: dashboard perusahaan dan seluruh outlet.',
            self::Supervisor => 'Supervisor: otorisasi void/refund dan kontrol operasional.',
        };
    }

    public function level(): int
    {
        return match ($this) {
            self::SuperAdmin => 100,
            self::Owner => 90,
            self::AdminOutlet => 80,
            self::OutletManager => 70,
            self::Finance => 60,
            self::Supervisor => 50,
            self::ProcurementStaff => 45,
            self::WarehouseStaff => 40,
            self::SalesRep => 30,
            self::Cashier => 10,
            self::Viewer => 5,
        };
    }

    /**
     * Default permission matrix used only when seeding. Roles can be edited afterwards.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        $cashier = [
            Permission::PosCheckout, Permission::OrdersView, Permission::OrdersCreate, Permission::OrdersHold,
            Permission::OrdersCancel, Permission::DiscountsApply, Permission::ShiftsOperate,
            Permission::CashMovementsCreate, Permission::ShiftCheckinCreate,
            Permission::InventoryView, Permission::CatalogView, Permission::ProductsView, Permission::CustomersView,
        ];

        $supervisor = [
            ...$cashier,
            Permission::OrdersVoid, Permission::OrdersRefund, Permission::OverridesAuthorize,
            Permission::ShiftsViewAll, Permission::InventoryAdjust, Permission::InventoryOpname,
            Permission::AuditView, Permission::WarehouseView, Permission::StockTransferRequest,
            Permission::StockTransferReceive, Permission::SalesOrdersView, Permission::SalesOrdersCreate,
            Permission::ReceivablesView,
        ];

        $warehouseStaff = [
            Permission::InventoryView, Permission::InventoryCreate, Permission::InventoryEdit,
            Permission::InventoryAdjust, Permission::InventoryOpname, Permission::InventoryExport,
            Permission::BatchesManage, Permission::CatalogView, Permission::ProductsView,
            Permission::WarehouseView, Permission::WarehouseManage,
            Permission::StockTransferRequest, Permission::StockTransferApprove, Permission::StockTransferReceive,
            Permission::ProcurementView, Permission::ProcurementReceive, Permission::ProcurementManage,
            Permission::SalesOrdersView, Permission::SalesOrdersFulfill, Permission::ShiftCheckinCreate,
        ];

        $procurementStaff = [
            Permission::ProcurementView, Permission::ProcurementCreate, Permission::ProcurementEdit,
            Permission::ProcurementCancel, Permission::ProcurementReceive, Permission::ProcurementExport,
            Permission::ProcurementManage, Permission::CatalogView, Permission::ProductsView,
            Permission::InventoryView, Permission::WarehouseView, Permission::PricingView,
            Permission::ShiftCheckinCreate,
        ];

        $salesRep = [
            Permission::CatalogView, Permission::ProductsView, Permission::InventoryView, Permission::PricingView,
            Permission::CustomersView, Permission::CustomersCreate, Permission::CustomersEdit, Permission::CustomersManage,
            Permission::SalesOrdersView, Permission::SalesOrdersCreate, Permission::SalesOrdersEdit,
            Permission::SalesOrdersCancel, Permission::ReceivablesView, Permission::OrdersView,
            Permission::ShiftCheckinCreate,
        ];

        $finance = [
            Permission::OrdersView, Permission::OrdersExport, Permission::ShiftsViewAll,
            Permission::CustomersView, Permission::CustomersManage, Permission::CustomersExport,
            Permission::CreditLimitApprove, Permission::SalesOrdersView, Permission::SalesOrdersExport,
            Permission::ReceivablesView, Permission::ReceivablesCreate, Permission::ReceivablesEdit,
            Permission::ReceivablesExport, Permission::ReceivablesManage,
            Permission::ProcurementView, Permission::ProcurementExport,
            Permission::AuditView, Permission::AuditExport, Permission::ActivityLogsView,
            Permission::OutletsSwitch, Permission::ShiftCheckinCreate,
        ];

        $viewer = [
            Permission::OrdersView, Permission::ShiftsViewAll, Permission::InventoryView,
            Permission::CatalogView, Permission::ProductsView, Permission::PricingView,
            Permission::ProcurementView, Permission::CustomersView, Permission::SalesOrdersView,
            Permission::ReceivablesView, Permission::WarehouseView, Permission::UsersView,
            Permission::RolesView, Permission::OutletsView, Permission::AuditView,
            Permission::ActivityLogsView, Permission::OutletsSwitch,
        ];

        $outletManager = [
            ...$supervisor,
            Permission::OrdersExport, Permission::OrdersManage, Permission::ShiftsManage,
            Permission::ShiftCheckinApprove, Permission::ShiftCheckinBackdate,
            Permission::InventoryExport, Permission::InventoryManage,
            Permission::CatalogOutletSettings, Permission::CatalogManage, Permission::WarehouseManage,
            Permission::StockTransferApprove, Permission::ProcurementView, Permission::ProcurementApprove,
            Permission::ProcurementManage, Permission::PurchaseRequestsApprove,
            Permission::CustomersManage, Permission::CreditLimitApprove, Permission::SalesOrdersApprove,
            Permission::SalesOrdersFulfill, Permission::SalesOrdersCancel, Permission::ReceivablesManage,
            Permission::PricingView, Permission::OutletsSwitch, Permission::ActivityLogsView,
            Permission::UsersView,
        ];

        $adminOutlet = [
            ...$outletManager,
            Permission::ProductsView, Permission::ProductsCreate, Permission::ProductsEdit,
            Permission::ProductsDelete, Permission::ProductsExport, Permission::ProductsManage,
            Permission::MasterDataManage, Permission::PricingCreate, Permission::PricingEdit,
            Permission::PricingManage, Permission::BatchesManage,
            Permission::UsersView, Permission::UsersCreate, Permission::UsersEdit, Permission::UsersDelete,
            Permission::UsersExport, Permission::UsersManage, Permission::RolesView, Permission::OutletsView,
            Permission::AuditExport,
        ];

        $owner = [
            ...$adminOutlet,
            Permission::CompanyDashboardView, Permission::OutletsManage, Permission::OutletsCreate,
            Permission::OutletsEdit, Permission::PricingApprove, Permission::PurchaseOrdersApprove,
        ];

        $list = match ($this) {
            self::Cashier => $cashier,
            self::Supervisor => $supervisor,
            self::WarehouseStaff => $warehouseStaff,
            self::ProcurementStaff => $procurementStaff,
            self::SalesRep => $salesRep,
            self::Finance => $finance,
            self::Viewer => $viewer,
            self::OutletManager => $outletManager,
            self::AdminOutlet => $adminOutlet,
            self::Owner => $owner,
            self::SuperAdmin => Permission::cases(),
        };

        // De-duplicate while preserving enum instances
        $unique = [];
        foreach ($list as $perm) {
            $unique[$perm->value] = $perm;
        }

        return array_values($unique);
    }
}
