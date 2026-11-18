import React, { useState } from 'react';
import {
  AlertTriangle,
  ArrowLeftRight,
  Bell,
  Building2,
  CheckCircle2,
  ChevronDown,
  Clock,
  LogOut,
  Menu,
  Monitor,
  ShieldAlert,
  Store,
  UserCheck,
  Wallet,
  Warehouse,
} from 'lucide-react';
import { formatIDR } from '../../lib/format';
import type { UserSession } from '../../types/pos';
import type { ActiveTab } from './AppSidebar';

export type { ActiveTab };

interface CashierHeaderBarProps {
  session: UserSession;
  activeTab: ActiveTab;
  heldOrdersCount: number;
  lowStockCount?: number;
  switchingOutlet?: boolean;
  onSelectTab: (tab: ActiveTab) => void;
  onOpenShiftModal: () => void;
  onOpenEmployeeShiftModal?: () => void;
  onQuickSwitchOutlet: (outletId: number) => void;
  onSwitchTerminal: () => void;
  onToggleSidebar?: () => void;
  onLogout: () => void;
}

const TAB_TITLES: Record<ActiveTab, { title: string; subtitle: string }> = {
  company_dashboard: {
    title: 'Dashboard Konsolidasi Perusahaan',
    subtitle: 'Konsolidasi lintas seluruh outlet, Gudang Pusat, dan Piutang Perusahaan',
  },
  dashboard: {
    title: 'Dashboard Operasional Outlet',
    subtitle: 'Ringkasan penjualan Counter, B2B Sales Order, Stok Gudang Cabang & Piutang Outlet',
  },
  checkout: {
    title: 'POS Kasir (Counter Sales)',
    subtitle: 'Transaksi cepat pelanggan datang langsung, scan barcode kemasan, hold & split payment',
  },
  shifts: {
    title: 'Manajemen Shift & Kas Laci',
    subtitle: 'Rekonsiliasi kas diharapkan vs aktual, kas masuk/keluar & riwayat shift',
  },
  // Master Data
  master_products: {
    title: 'Master Data → Produk & Multi-Satuan Kemasan',
    subtitle: 'Katalog produk lengkap, konversi satuan kemasan (Botol, Pack, Dus, Karton), harga & stok',
  },
  master_categories: {
    title: 'Master Data → Kategori Produk',
    subtitle: 'Pengelompokan jenis minuman (Whisky, Vodka, Beer, Soju, Wine, RTD, dll.)',
  },
  master_brands: {
    title: 'Master Data → Brand & Merk',
    subtitle: 'Daftar merk produk dan keterkaitan dengan Principal resmi',
  },
  master_units: {
    title: 'Master Data → Satuan & Kemasan',
    subtitle: 'Pengaturan kamus satuan dasar (Stock UOM) dan indeks konversi kemasan produk',
  },
  master_suppliers: {
    title: 'Master Data → Supplier & Principal',
    subtitle: 'Mitra pemasok utama, kontak PIC, kota, dan termin pembayaran (TOP)',
  },
  master_customers: {
    title: 'Master Data → Pelanggan Bisnis & Tier',
    subtitle: 'Daftar pelanggan ritel/reseller/grosir, penetapan tier harga, dan batas kredit (Credit Limit)',
  },
  master_pricing: {
    title: 'Master Harga 4 Tier (A, B, C, D)',
    subtitle: 'Penetapan harga nominal langsung per identitas pelanggan & margin HPP',
  },
  // Penjualan
  sales_orders: {
    title: 'Penjualan → B2B Sales Order & Pengiriman',
    subtitle: 'Pemesanan grosir multi-satuan kemasan, harga tier pelanggan, kontrol Credit Limit & surat jalan',
  },
  orders: {
    title: 'Penjualan → Riwayat Transaksi & Struk',
    subtitle: 'Riwayat pesanan kasir, cetak ulang struk, pembatalan, void & refund',
  },
  tier_management: {
    title: 'Penjualan → Tier Harga Pelanggan',
    subtitle: 'Atur Tier harga pelanggan dan konfigurasi harga khusus per Tier',
  },
  // Persediaan
  inventory_stock: {
    title: 'Persediaan → Posisi Stok Real-Time',
    subtitle: 'Stok per outlet & gudang dalam Satuan Dasar (Stock UOM) beserta konversi kemasan',
  },
  warehouse_stock: {
    title: 'Persediaan → Gudang Pusat & Gudang Cabang',
    subtitle: 'Inventori multi-gudang, stok dipesan (reserved), dan stok dalam perjalanan (in-transit)',
  },
  warehouse: {
    title: 'Persediaan → Transfer Stok Antar-Gudang',
    subtitle: 'Permintaan stok cabang, pengiriman Gudang Pusat & penerimaan barang transfer',
  },
  inventory: {
    title: 'Persediaan → Penyesuaian Stok & Stock Opname',
    subtitle: 'Penyesuaian barang rusak/hilang/expired dan rekonsiliasi fisik Stock Opname',
  },
  inventory_movements: {
    title: 'Persediaan → Riwayat Mutasi Stok (Stock Ledger)',
    subtitle: 'Jejak audit seluruh pergerakan stok masuk, keluar, penjualan, transfer, dan penyesuaian',
  },
  // Pengadaan
  procurement: {
    title: 'Pengadaan → Purchase Order (PO)',
    subtitle: 'Pemesanan barang ke Supplier/Principal menggunakan satuan kemasan pembelian',
  },
  procurement_receiving: {
    title: 'Pengadaan → Penerimaan Barang (Goods Receipt)',
    subtitle: 'Verifikasi penerimaan barang PO dan penambahan stok otomatis ke gudang tujuan',
  },
  procurement_history: {
    title: 'Pengadaan → Riwayat Pembelian',
    subtitle: 'Arsip Purchase Order yang telah selesai diterima di gudang',
  },
  // Keuangan
  finance_payments: {
    title: 'Keuangan → Pembayaran & Alokasi Tagihan',
    subtitle: 'Pencatatan pembayaran pelanggan (Tunai / Transfer) dan alokasi pelunasan invoice',
  },
  receivables: {
    title: 'Keuangan → Piutang (Accounts Receivable) & Aging',
    subtitle: 'Manajemen tagihan tempo B2B, jatuh tempo, dan laporan umur piutang pelanggan',
  },
  finance_reports: {
    title: 'Keuangan → Laporan & Konsolidasi',
    subtitle: 'Laporan omzet penjualan, nilai persediaan, dan ringkasan kinerja keuangan',
  },
  // Pengaturan
  catalog: {
    title: 'Pengaturan → Katalog & Harga Khusus Outlet',
    subtitle: 'Atur ketersediaan produk per outlet dan override harga jual spesifik cabang',
  },
  user_management: {
    title: 'Pengaturan → User Management',
    subtitle: 'Kelola akun pengguna, penempatan multi-outlet, suspend, dan reset password',
  },
  role_management: {
    title: 'Pengaturan → Role & Permission (RBAC)',
    subtitle: 'Kelola role dinamis, matriks permission granular per modul & log aktivitas keamanan',
  },
  pricing_rules: {
    title: 'Pengaturan → Konfigurasi PPN, Service Charge & Diskon',
    subtitle: 'Atur persentase PPN, Service Charge, batas diskon kasir & daftar voucher promo',
  },
  audit: {
    title: 'Pengaturan → Audit Trail & CRM Outbox',
    subtitle: 'Log aktivitas append-only beserta diff JSON & status sinkronisasi sistem',
  },
};

export const CashierHeaderBar: React.FC<CashierHeaderBarProps> = ({
  session,
  activeTab,
  heldOrdersCount,
  lowStockCount = 0,
  switchingOutlet = false,
  onSelectTab,
  onOpenShiftModal,
  onOpenEmployeeShiftModal,
  onQuickSwitchOutlet,
  onSwitchTerminal,
  onToggleSidebar,
  onLogout,
}) => {
  const [showNotif, setShowNotif] = useState(false);
  const [showOutletMenu, setShowOutletMenu] = useState(false);

  const { user, active_outlet, active_warehouse, active_terminal, active_shift, available_outlets } =
    session;
  const expectedCash =
    active_shift?.live_summary?.expected_cash ?? active_shift?.opening_cash ?? 0;

  const pageMeta = TAB_TITLES[activeTab] ?? TAB_TITLES.dashboard;
  const canSwitch = (user.can_switch_outlet ?? false) || available_outlets.length > 1;
  const notifCount =
    (heldOrdersCount > 0 ? 1 : 0) +
    (lowStockCount > 0 ? 1 : 0) +
    (!active_shift ? 1 : 0);

  return (
    <header className="bg-white border-b border-slate-200 sticky top-0 z-20">
      <div className="px-4 py-2.5 flex flex-wrap items-center justify-between gap-3">
        {/* Left: Sidebar Toggle, Explicit "SiBotol | Outlet Name" Badge & Page Title */}
        <div className="flex items-center gap-3 min-w-0">
          {onToggleSidebar && (
            <button
              type="button"
              onClick={onToggleSidebar}
              title="Toggle Sidebar Navigasi"
              className="p-2 rounded-lg text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition cursor-pointer"
            >
              <Menu className="w-5 h-5" />
            </button>
          )}

          {/* Active Outlet Identity Pill: SiBotol | Outlet Tangerang */}
          <div className="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-900 text-white text-xs font-extrabold tracking-tight shadow-xs">
            <Store className="w-3.5 h-3.5 text-blue-400" />
            <span>SiBotol</span>
            <span className="text-slate-500">|</span>
            <span className="text-blue-300">{active_outlet?.name ?? 'Semua Outlet'}</span>
            {active_warehouse && (
              <span className="ml-1 px-1.5 py-0.5 rounded bg-slate-800 text-[10px] font-mono text-emerald-300 border border-slate-700">
                {active_warehouse.code}
              </span>
            )}
          </div>

          <div className="min-w-0">
            <h1 className="text-sm font-extrabold text-slate-900 truncate">{pageMeta.title}</h1>
            <p className="text-[11px] text-slate-500 hidden lg:block truncate">
              {pageMeta.subtitle}
            </p>
          </div>
        </div>

        {/* Right: Switch Outlet Dropdown, Shift Drawer, Notifications, User Profile */}
        <div className="flex items-center gap-2 flex-wrap">
          {/* Switch Outlet Dropdown */}
          <div className="relative">
            <button
              type="button"
              onClick={() => (canSwitch ? setShowOutletMenu((prev) => !prev) : onSwitchTerminal())}
              disabled={switchingOutlet}
              title={
                canSwitch
                  ? 'Klik untuk berpindah Outlet (Switch Outlet) atau ganti Terminal'
                  : 'Outlet Aktif'
              }
              className="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-blue-50/80 hover:bg-blue-100/80 border border-blue-200 text-xs transition cursor-pointer"
            >
              <Building2 className="w-3.5 h-3.5 text-blue-600" />
              <span className="font-bold text-slate-900">
                {active_outlet?.name ?? 'Pilih Outlet'}
              </span>
              <span className="text-slate-300">|</span>
              <span className="flex items-center gap-1 text-[11px] font-mono font-semibold text-slate-600">
                <Monitor className="w-3 h-3 text-emerald-600" />
                {active_terminal?.code ?? '-'}
              </span>
              {canSwitch && <ChevronDown className="w-3.5 h-3.5 text-blue-600" />}
            </button>

            {showOutletMenu && (
              <>
                <div
                  className="fixed inset-0 z-30"
                  onClick={() => setShowOutletMenu(false)}
                />
                <div className="absolute right-0 mt-2 w-80 bg-white border border-slate-200 rounded-2xl shadow-xl z-40 overflow-hidden">
                  <div className="px-4 py-3 bg-slate-900 text-white flex items-center justify-between">
                    <div className="text-xs font-bold flex items-center gap-1.5">
                      <ArrowLeftRight className="w-3.5 h-3.5 text-blue-400" />
                      Switch Active Outlet Context
                    </div>
                    <span className="text-[10px] text-slate-400">
                      {available_outlets.length} Outlet Diizinkan
                    </span>
                  </div>

                  <div className="divide-y divide-slate-100 max-h-72 overflow-y-auto">
                    {available_outlets.map((o) => {
                      const isCurrent = o.id === active_outlet?.id;
                      return (
                        <button
                          key={o.id}
                          type="button"
                          onClick={() => {
                            setShowOutletMenu(false);
                            if (!isCurrent) {
                              onQuickSwitchOutlet(o.id);
                            }
                          }}
                          className={`w-full px-4 py-3 text-left flex items-center justify-between gap-2 transition cursor-pointer ${
                            isCurrent ? 'bg-blue-50/70' : 'hover:bg-slate-50'
                          }`}
                        >
                          <div>
                            <div className="text-xs font-bold text-slate-900 flex items-center gap-1.5">
                              <span>{o.name}</span>
                              <span className="font-mono text-[10px] px-1.5 py-0.2 rounded bg-slate-200 text-slate-700">
                                {o.code}
                              </span>
                            </div>
                            <div className="text-[11px] text-slate-500 flex items-center gap-1 mt-0.5">
                              <Warehouse className="w-3 h-3 text-blue-600" />
                              {o.warehouse?.name ?? o.city ?? 'Branch Warehouse'}
                            </div>
                          </div>
                          {isCurrent && (
                            <CheckCircle2 className="w-4 h-4 text-blue-600 shrink-0" />
                          )}
                        </button>
                      );
                    })}
                  </div>

                  <div className="p-2.5 bg-slate-50 border-t border-slate-200">
                    <button
                      type="button"
                      onClick={() => {
                        setShowOutletMenu(false);
                        onSwitchTerminal();
                      }}
                      className="w-full py-2 px-3 rounded-xl bg-white border border-slate-200 hover:bg-slate-100 text-xs font-bold text-slate-700 text-center cursor-pointer"
                    >
                      Buka Halaman Penuh Pemilihan Outlet &amp; Terminal
                    </button>
                  </div>
                </div>
              </>
            )}
          </div>

          {/* Operational Employee Shift Badge */}
          <button
            type="button"
            onClick={onOpenEmployeeShiftModal}
            title={session.active_employee_shift ? 'Shift Operasional Aktif (Klik untuk ubah/lihat)' : 'Belum Check-in Shift Operasional'}
            className={`flex items-center gap-2 px-3 py-1.5 rounded-xl border text-xs font-bold transition cursor-pointer ${
              session.active_employee_shift
                ? 'bg-blue-50 border-blue-200 text-blue-900 hover:bg-blue-100/80 shadow-xs'
                : 'bg-amber-50 border-amber-300 text-amber-900 hover:bg-amber-100 animate-pulse'
            }`}
          >
            <Clock className="w-3.5 h-3.5 text-blue-600 shrink-0" />
            <div className="text-left leading-tight">
              {session.active_employee_shift ? (
                <>
                  <div className="font-bold flex items-center gap-1">
                    <span>{session.user.username}</span>
                    <span>•</span>
                    <span>{session.active_employee_shift.shift_name}</span>
                  </div>
                  <div className="text-[10px] font-medium text-blue-600">
                    {session.active_employee_shift.work_date}
                    {session.active_employee_shift.is_backdated ? ' (Backdate)' : ''}
                  </div>
                </>
              ) : (
                <>
                  <div className="text-amber-800">Check-in Shift</div>
                  <div className="text-[10px] text-amber-600 font-medium">Klik untuk pilih shift</div>
                </>
              )}
            </div>
          </button>

          {/* Cashier Cash Drawer Shift Status Pill */}
          <button
            type="button"
            onClick={onOpenShiftModal}
            className={`flex items-center gap-2 px-3 py-1.5 rounded-xl border text-xs font-bold transition cursor-pointer ${
              active_shift
                ? 'bg-emerald-50 border-emerald-200 text-emerald-800 hover:bg-emerald-100/80'
                : 'bg-amber-50 border-amber-300 text-amber-800 hover:bg-amber-100'
            }`}
          >
            {active_shift ? (
              <>
                <Wallet className="w-3.5 h-3.5 text-emerald-600" />
                <div className="text-left leading-tight">
                  <div className="tabular-nums">Kas: {formatIDR(expectedCash)}</div>
                  <div className="text-[10px] font-medium text-emerald-600">
                    {active_shift.shift_number}
                  </div>
                </div>
              </>
            ) : (
              <>
                <ShieldAlert className="w-4 h-4 text-amber-600" />
                <span>Buka Shift</span>
              </>
            )}
          </button>

          {/* Notification Center Bell */}
          <div className="relative">
            <button
              type="button"
              onClick={() => setShowNotif((prev) => !prev)}
              title="Pusat Notifikasi Operasional"
              className="p-2 rounded-xl bg-slate-50 hover:bg-slate-100 border border-slate-200 text-slate-600 hover:text-slate-900 relative transition cursor-pointer"
            >
              <Bell className="w-4 h-4" />
              {notifCount > 0 && (
                <span className="w-4 h-4 rounded-full bg-rose-600 text-white text-[10px] font-bold flex items-center justify-center absolute -top-1 -right-1">
                  {notifCount}
                </span>
              )}
            </button>

            {showNotif && (
              <>
                <div className="fixed inset-0 z-30" onClick={() => setShowNotif(false)} />
                <div className="absolute right-0 mt-2 w-80 bg-white border border-slate-200 rounded-2xl shadow-xl z-40 overflow-hidden">
                  <div className="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                    <span className="text-xs font-bold text-slate-900">
                      Notifikasi Operasional Outlet
                    </span>
                    <span className="text-[11px] font-semibold text-slate-500">
                      {active_outlet?.code}
                    </span>
                  </div>

                  <div className="divide-y divide-slate-100 max-h-80 overflow-y-auto text-xs">
                    {!active_shift && (
                      <button
                        type="button"
                        onClick={() => {
                          setShowNotif(false);
                          onOpenShiftModal();
                        }}
                        className="w-full p-3.5 text-left hover:bg-amber-50/60 flex items-start gap-3 cursor-pointer"
                      >
                        <ShieldAlert className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                        <div>
                          <div className="font-bold text-slate-900">Shift Kasir Belum Dibuka</div>
                          <div className="text-[11px] text-slate-500 mt-0.5">
                            Masukkan saldo kas awal sebelum transaksi Counter Sales.
                          </div>
                        </div>
                      </button>
                    )}

                    {heldOrdersCount > 0 && (
                      <button
                        type="button"
                        onClick={() => {
                          setShowNotif(false);
                          onSelectTab('checkout');
                        }}
                        className="w-full p-3.5 text-left hover:bg-blue-50/60 flex items-start gap-3 cursor-pointer"
                      >
                        <Clock className="w-4 h-4 text-blue-600 shrink-0 mt-0.5" />
                        <div>
                          <div className="font-bold text-slate-900">
                            {heldOrdersCount} Pesanan Di-Hold (Saved Bill)
                          </div>
                          <div className="text-[11px] text-slate-500 mt-0.5">
                            Draft transaksi menunggu diselesaikan di outlet ini.
                          </div>
                        </div>
                      </button>
                    )}

                    {lowStockCount > 0 && (
                      <button
                        type="button"
                        onClick={() => {
                          setShowNotif(false);
                          onSelectTab('inventory');
                        }}
                        className="w-full p-3.5 text-left hover:bg-rose-50/60 flex items-start gap-3 cursor-pointer"
                      >
                        <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
                        <div>
                          <div className="font-bold text-slate-900">
                            {lowStockCount} Varian Produk Stok Menipis
                          </div>
                          <div className="text-[11px] text-slate-500 mt-0.5">
                            Ajukan Stock Transfer dari Gudang Pusat.
                          </div>
                        </div>
                      </button>
                    )}

                    {notifCount === 0 && (
                      <div className="p-6 text-center text-slate-500 text-xs">
                        Semua operasional outlet berjalan normal.
                      </div>
                    )}
                  </div>
                </div>
              </>
            )}
          </div>

          {/* Active User Profile Badge */}
          <div className="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-50 border border-slate-200 text-xs">
            <UserCheck className="w-3.5 h-3.5 text-blue-600" />
            <div className="text-left leading-tight">
              <div className="font-bold text-slate-900">{user.name}</div>
              <div className="text-[10px] text-slate-500">{user.role_display}</div>
            </div>
          </div>

          {/* Logout Button */}
          <button
            type="button"
            onClick={onLogout}
            title="Keluar Sesi POS"
            className="p-2 rounded-xl bg-slate-50 hover:bg-rose-50 border border-slate-200 hover:border-rose-200 text-slate-500 hover:text-rose-600 transition cursor-pointer"
          >
            <LogOut className="w-4 h-4" />
          </button>
        </div>
      </div>
    </header>
  );
};
