import React, { useState } from 'react';
import {
  ArrowRightLeft,
  BarChart3,
  Boxes,
  Building2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ClipboardCheck,
  ClipboardList,
  CreditCard,
  FileCheck2,
  History,
  KeyRound,
  Layers,
  LayoutDashboard,
  Package,
  PackagePlus,
  PackageSearch,
  Percent,
  ReceiptText,
  Settings,
  Shield,
  ShoppingBag,
  ShoppingCart,
  Sliders,
  Store,
  Tag,
  Truck,
  Users,
  Wallet,
  Warehouse,
} from 'lucide-react';
import type { UserSession } from '../../types/pos';

export type ActiveTab =
  // Dashboard & POS Operational
  | 'dashboard'
  | 'checkout'
  | 'shifts'
  // Master Data
  | 'master_products'
  | 'master_categories'
  | 'master_brands'
  | 'master_units'
  | 'master_suppliers'
  | 'master_customers'
  | 'master_pricing'
  // Penjualan
  | 'sales_orders'
  | 'orders'
  | 'tier_management'
  // Persediaan
  | 'inventory_stock'
  | 'warehouse_stock'
  | 'warehouse'
  | 'inventory'
  | 'inventory_movements'
  // Pengadaan
  | 'procurement'
  | 'procurement_receiving'
  | 'procurement_history'
  // Keuangan
  | 'finance_payments'
  | 'receivables'
  | 'finance_reports'
  // Pengaturan
  | 'catalog'
  | 'user_management'
  | 'role_management'
  | 'pricing_rules'
  | 'audit';

interface AppSidebarProps {
  session: UserSession;
  activeTab: ActiveTab;
  collapsed: boolean;
  heldOrdersCount: number;
  lowStockCount: number;
  onSelectTab: (tab: ActiveTab) => void;
  onToggleCollapse: () => void;
}

type NavGroupKey =
  | 'dashboard'
  | 'master_data'
  | 'penjualan'
  | 'persediaan'
  | 'pengadaan'
  | 'keuangan'
  | 'pengaturan';

interface NavItem {
  id: ActiveTab;
  label: string;
  shortLabel: string;
  icon: React.FC<{ className?: string }>;
  badge?: number;
  badgeColor?: string;
  group: NavGroupKey;
}

const GROUP_HEADERS: Array<{ key: NavGroupKey; label: string }> = [
  { key: 'dashboard', label: 'Dashboard & POS' },
  { key: 'master_data', label: 'Master Data' },
  { key: 'penjualan', label: 'Penjualan' },
  { key: 'persediaan', label: 'Persediaan' },
  { key: 'pengadaan', label: 'Pengadaan' },
  { key: 'keuangan', label: 'Keuangan' },
  { key: 'pengaturan', label: 'Pengaturan' },
];

export const AppSidebar: React.FC<AppSidebarProps> = ({
  session,
  activeTab,
  collapsed,
  heldOrdersCount,
  lowStockCount,
  onSelectTab,
  onToggleCollapse,
}) => {
  const perms = session.user.permissions ?? [];
  const isSuper = session.user.is_super_admin;

  const canViewUserManagement =
    isSuper ||
    perms.includes('users.view') ||
    perms.includes('users.manage') ||
    perms.includes('roles.view') ||
    perms.includes('roles.manage');
  const canViewAudit =
    isSuper ||
    perms.includes('audit.view') ||
    session.user.role === 'supervisor' ||
    session.user.role === 'outlet_manager' ||
    session.user.role === 'finance';

  const [collapsedGroups, setCollapsedGroups] = useState<Record<string, boolean>>({});

  const toggleGroup = (key: NavGroupKey) => {
    setCollapsedGroups((prev) => ({ ...prev, [key]: !prev[key] }));
  };

  const navItems: NavItem[] = [
    // 1. DASHBOARD & POS
    {
      id: 'dashboard',
      label: 'Dashboard',
      shortLabel: 'Dashboard',
      icon: LayoutDashboard,
      group: 'dashboard',
    },
    {
      id: 'checkout',
      label: 'POS Kasir (Counter)',
      shortLabel: 'Kasir',
      icon: ShoppingCart,
      group: 'dashboard',
    },
    {
      id: 'shifts',
      label: 'Shift & Kas Laci',
      shortLabel: 'Shift',
      icon: Wallet,
      group: 'dashboard',
    },

    // 2. MASTER DATA
    {
      id: 'master_products',
      label: 'Produk',
      shortLabel: 'Produk',
      icon: Package,
      group: 'master_data',
    },
    {
      id: 'master_categories',
      label: 'Kategori',
      shortLabel: 'Kategori',
      icon: Boxes,
      group: 'master_data',
    },
    {
      id: 'master_brands',
      label: 'Brand',
      shortLabel: 'Brand',
      icon: Tag,
      group: 'master_data',
    },
    {
      id: 'master_units',
      label: 'Satuan & Kemasan',
      shortLabel: 'Satuan',
      icon: Layers,
      group: 'master_data',
    },
    {
      id: 'master_suppliers',
      label: 'Supplier',
      shortLabel: 'Supplier',
      icon: Truck,
      group: 'master_data',
    },
    {
      id: 'master_customers',
      label: 'Pelanggan',
      shortLabel: 'Pelanggan',
      icon: Users,
      group: 'master_data',
    },

    // 3. PENJUALAN
    {
      id: 'sales_orders',
      label: 'Sales Order',
      shortLabel: 'Sales Order',
      icon: ShoppingBag,
      group: 'penjualan',
    },
    {
      id: 'orders',
      label: 'Riwayat Transaksi',
      shortLabel: 'Transaksi',
      icon: ReceiptText,
      badge: heldOrdersCount > 0 ? heldOrdersCount : undefined,
      badgeColor: 'bg-amber-500 text-white',
      group: 'penjualan',
    },
    {
      id: 'tier_management',
      label: 'Tier Harga',
      shortLabel: 'Tier Harga',
      icon: Percent,
      group: 'penjualan',
    },

    // 4. PERSEDIAAN
    {
      id: 'inventory_stock',
      label: 'Stok',
      shortLabel: 'Stok',
      icon: PackageSearch,
      badge: lowStockCount > 0 ? lowStockCount : undefined,
      badgeColor: 'bg-rose-500 text-white',
      group: 'persediaan',
    },
    {
      id: 'warehouse_stock',
      label: 'Gudang',
      shortLabel: 'Gudang',
      icon: Warehouse,
      group: 'persediaan',
    },
    {
      id: 'warehouse',
      label: 'Transfer Stok',
      shortLabel: 'Transfer',
      icon: ArrowRightLeft,
      group: 'persediaan',
    },
    {
      id: 'inventory',
      label: 'Penyesuaian Stok',
      shortLabel: 'Penyesuaian',
      icon: Sliders,
      group: 'persediaan',
    },
    {
      id: 'inventory_movements',
      label: 'Riwayat Mutasi',
      shortLabel: 'Mutasi',
      icon: History,
      group: 'persediaan',
    },

    // 5. PENGADAAN
    {
      id: 'procurement',
      label: 'Purchase Order',
      shortLabel: 'PO',
      icon: PackagePlus,
      group: 'pengadaan',
    },
    {
      id: 'procurement_receiving',
      label: 'Penerimaan Barang',
      shortLabel: 'Penerimaan',
      icon: ClipboardCheck,
      group: 'pengadaan',
    },
    {
      id: 'procurement_history',
      label: 'Riwayat Pembelian',
      shortLabel: 'Riwayat PO',
      icon: FileCheck2,
      group: 'pengadaan',
    },

    // 6. KEUANGAN
    {
      id: 'finance_payments',
      label: 'Pembayaran',
      shortLabel: 'Pembayaran',
      icon: Wallet,
      group: 'keuangan',
    },
    {
      id: 'receivables',
      label: 'Piutang',
      shortLabel: 'Piutang',
      icon: CreditCard,
      group: 'keuangan',
    },
    {
      id: 'finance_reports',
      label: 'Laporan',
      shortLabel: 'Laporan',
      icon: BarChart3,
      group: 'keuangan',
    },

    // 7. PENGATURAN
    {
      id: 'catalog',
      label: 'Outlet',
      shortLabel: 'Outlet',
      icon: Building2,
      group: 'pengaturan',
    },
    ...(canViewUserManagement
      ? [
          {
            id: 'user_management' as const,
            label: 'User Management',
            shortLabel: 'Users',
            icon: Users,
            group: 'pengaturan' as const,
          },
          {
            id: 'role_management' as const,
            label: 'Role & Permission',
            shortLabel: 'RBAC',
            icon: Shield,
            group: 'pengaturan' as const,
          },
        ]
      : []),
    {
      id: 'pricing_rules',
      label: 'Konfigurasi',
      shortLabel: 'Konfigurasi',
      icon: Settings,
      group: 'pengaturan',
    },
    ...(canViewAudit
      ? [
          {
            id: 'audit' as const,
            label: 'Audit Trail',
            shortLabel: 'Audit',
            icon: ClipboardList,
            group: 'pengaturan' as const,
          },
        ]
      : []),
  ];

  const renderItem = (item: NavItem) => {
    const Icon = item.icon;
    const isActive = activeTab === item.id;

    return (
      <button
        key={item.id}
        type="button"
        title={collapsed ? item.label : undefined}
        onClick={() => onSelectTab(item.id)}
        className={`w-full flex items-center ${
          collapsed ? 'justify-center px-2 py-2' : 'justify-between px-3 py-2'
        } rounded-xl text-xs font-semibold transition-all cursor-pointer relative ${
          isActive
            ? 'bg-emerald-600 text-white shadow-xs'
            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
        }`}
      >
        <div className="flex items-center gap-2.5 min-w-0">
          <Icon className={`w-4 h-4 shrink-0 ${isActive ? 'text-white' : 'text-slate-500'}`} />
          {!collapsed && <span className="truncate">{item.label}</span>}
        </div>

        {item.badge !== undefined && (
          <span
            className={`${
              collapsed
                ? 'absolute -top-1 -right-1 px-1.5 py-0.2 text-[9px]'
                : 'px-1.5 py-0.5 text-[10px]'
            } rounded-full font-bold ${
              isActive
                ? 'bg-white text-emerald-700'
                : item.badgeColor ?? 'bg-emerald-100 text-emerald-700'
            }`}
          >
            {item.badge}
          </span>
        )}
      </button>
    );
  };

  return (
    <aside
      className={`${
        collapsed ? 'w-16' : 'w-64'
      } sticky top-0 h-screen max-h-screen bg-white border-r border-slate-200 flex flex-col shrink-0 overflow-hidden transition-all duration-200 select-none z-30`}
    >
      {/* Top Brand + Active Outlet Identity (fixed at top) */}
      <div
        className={`shrink-0 flex items-center ${
          collapsed ? 'justify-center' : 'justify-between'
        } p-3 border-b border-slate-100`}
      >
        <div className="flex items-center gap-2.5 min-w-0">
          <div className="w-9 h-9 rounded-xl bg-emerald-600 flex items-center justify-center text-white shadow-xs shrink-0">
            <Store className="w-5 h-5" />
          </div>
          {!collapsed && (
            <div className="min-w-0">
              <div className="font-extrabold text-sm text-slate-900 tracking-tight truncate">
                SiBotol Backoffice
              </div>
              <div className="text-[11px] font-bold text-emerald-600 truncate">
                {session.active_outlet?.name ?? 'Pilih Outlet'}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Scrollable Menu Groups */}
      <nav
        aria-label="Navigasi Utama"
        className="flex-1 min-h-0 overflow-y-auto overscroll-contain p-3 space-y-3 sidebar-scroll"
      >
        {GROUP_HEADERS.map((group, idx) => {
          const items = navItems.filter((i) => i.group === group.key);
          if (items.length === 0) return null;
          const isGroupCollapsed = Boolean(collapsedGroups[group.key]);

          return (
            <div
              key={group.key}
              className={`space-y-1 ${idx > 0 ? 'pt-2 border-t border-slate-100' : ''}`}
            >
              {!collapsed && (
                <button
                  type="button"
                  onClick={() => toggleGroup(group.key)}
                  className="w-full px-2.5 pb-0.5 flex items-center justify-between text-[10px] font-extrabold uppercase tracking-wider text-slate-400 hover:text-slate-700 cursor-pointer"
                >
                  <span>{group.label}</span>
                  <ChevronDown
                    className={`w-3 h-3 transition-transform ${
                      isGroupCollapsed ? '-rotate-90' : ''
                    }`}
                  />
                </button>
              )}
              {(!isGroupCollapsed || collapsed) && items.map(renderItem)}
            </div>
          );
        })}
      </nav>

      {/* Bottom Collapse Toggle (fixed at bottom) */}
      <div className="shrink-0 p-3 border-t border-slate-200 bg-slate-50/60">
        <button
          type="button"
          onClick={onToggleCollapse}
          className="w-full flex items-center justify-center gap-2 py-2 px-3 rounded-lg text-xs font-semibold text-slate-600 hover:text-slate-900 hover:bg-slate-200/60 transition cursor-pointer"
        >
          {collapsed ? (
            <ChevronRight className="w-4 h-4" />
          ) : (
            <>
              <ChevronLeft className="w-4 h-4" />
              <span>Sembunyikan Menu</span>
            </>
          )}
        </button>
      </div>
    </aside>
  );
};
