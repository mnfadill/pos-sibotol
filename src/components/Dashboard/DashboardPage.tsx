import React, { useEffect, useState } from 'react';
import {
  Activity,
  AlertTriangle,
  ArrowRight,
  Briefcase,
  CreditCard,
  Package,
  Printer,
  Receipt,
  RefreshCw,
  ShoppingBag,
  ShoppingCart,
  TrendingUp,
  Truck,
  Warehouse,
  Wallet,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime, formatIDR, formatQty } from '../../lib/format';
import type {
  DashboardSummaryResponse,
  OrderRecord,
  OutletInfo,
} from '../../types/pos';
import type { ActiveTab } from '../Layout/AppSidebar';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card, StatCard } from '../ui/Card';
import { ReceiptModal } from '../ui/ReceiptModal';
import { useToast } from '../ui/ToastProvider';

interface DashboardPageProps {
  outlet: OutletInfo;
  onNavigate: (tab: ActiveTab) => void;
  onOpenShiftModal: () => void;
}

const PERIOD_OPTIONS: Array<{ value: DashboardSummaryResponse['period']; label: string }> = [
  { value: 'all', label: 'Semua Waktu' },
  { value: 'today', label: 'Hari Ini' },
  { value: '7d', label: '7 Hari Terakhir' },
  { value: '30d', label: '30 Hari Terakhir' },
];

export const DashboardPage: React.FC<DashboardPageProps> = ({
  outlet,
  onNavigate,
  onOpenShiftModal,
}) => {
  const { toast } = useToast();
  const [period, setPeriod] = useState<DashboardSummaryResponse['period']>('all');
  const [data, setData] = useState<DashboardSummaryResponse | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [receiptOrder, setReceiptOrder] = useState<OrderRecord | null>(null);

  useEffect(() => {
    fetchDashboard(period);
  }, [outlet.id, period]);

  const fetchDashboard = async (selectedPeriod: DashboardSummaryResponse['period']) => {
    setLoading(true);
    try {
      const res = await api.get<DashboardSummaryResponse>('/dashboard/summary', {
        params: { period: selectedPeriod },
      });
      setData(res.data);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat ringkasan dashboard',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  const kpis = data?.kpis;
  const shiftSummary = data?.active_shift_summary;

  return (
    <div className="p-5 space-y-5 max-w-[1600px] mx-auto">
      {/* Top Toolbar */}
      <div className="flex flex-wrap items-center justify-between gap-3 bg-white p-4 rounded-xl border border-slate-200 shadow-2xs">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-base font-extrabold text-slate-900">
              Dashboard Operasional Outlet &bull; {outlet.name}
            </h2>
            <Badge variant="primary">{outlet.code}</Badge>
            {data?.active_warehouse && (
              <Badge variant="neutral">
                <Warehouse className="w-3 h-3 mr-1 inline" />
                {data.active_warehouse.code} ({data.active_warehouse.name})
              </Badge>
            )}
          </div>
          <p className="text-xs text-slate-500 mt-0.5">
            Performa Counter Sales POS, B2B Sales Order, stok gudang cabang, dan piutang pelanggan khusus outlet ini.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex bg-slate-100 p-1 rounded-lg border border-slate-200">
            {PERIOD_OPTIONS.map((opt) => (
              <button
                key={opt.value}
                type="button"
                onClick={() => setPeriod(opt.value)}
                className={`px-3 py-1.5 rounded-md text-xs font-semibold transition cursor-pointer ${
                  period === opt.value
                    ? 'bg-white text-blue-600 shadow-2xs'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                {opt.label}
              </button>
            ))}
          </div>

          <Button
            variant="outline"
            size="sm"
            onClick={() => fetchDashboard(period)}
            loading={loading}
            icon={<RefreshCw className="w-3.5 h-3.5" />}
          >
            Segarkan
          </Button>

          <Button
            variant="outline"
            size="sm"
            onClick={() => onNavigate('sales_orders')}
            icon={<Briefcase className="w-3.5 h-3.5" />}
          >
            Sales Order B2B
          </Button>

          <Button
            variant="primary"
            size="sm"
            onClick={() => onNavigate('checkout')}
            icon={<ShoppingCart className="w-3.5 h-3.5" />}
          >
            Buka Kasir POS
          </Button>
        </div>
      </div>

      {/* Primary KPI Cards (Sub-Distributor + Counter POS) */}
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4">
        <StatCard
          label="Total Omzet Gabungan Outlet"
          value={formatIDR(kpis?.combined_outlet_revenue ?? kpis?.grand_total_revenue ?? 0)}
          subtext={
            <span>
              Counter POS:{' '}
              <strong className="text-slate-700">{formatIDR(kpis?.grand_total_revenue ?? 0)}</strong>
            </span>
          }
          accent="blue"
          icon={<TrendingUp className="w-5 h-5" />}
        />

        <StatCard
          label="Penjualan Counter (POS)"
          value={`${kpis?.completed_orders_count ?? 0} Order`}
          subtext={
            <span>
              Rata-rata:{' '}
              <strong className="text-slate-700">
                {formatIDR(kpis?.average_order_value ?? 0)}
              </strong>
            </span>
          }
          accent="emerald"
          icon={<ShoppingBag className="w-5 h-5" />}
          action={
            (kpis?.held_orders_count ?? 0) > 0 ? (
              <Badge variant="warning">{kpis?.held_orders_count} Di-Hold</Badge>
            ) : undefined
          }
        />

        <StatCard
          label="Penjualan Grosir (B2B SO)"
          value={formatIDR(kpis?.b2b_sales_total ?? 0)}
          subtext={`${kpis?.b2b_orders_count ?? 0} Sales Order • ${kpis?.active_deliveries_count ?? 0} Pengiriman`}
          accent="violet"
          icon={<Briefcase className="w-5 h-5" />}
          action={
            <button
              type="button"
              onClick={() => onNavigate('sales_orders')}
              className="text-xs font-bold text-blue-600 hover:text-blue-700 cursor-pointer"
            >
              Kelola SO &rarr;
            </button>
          }
        />

        <StatCard
          label="Piutang Pelanggan Outlet"
          value={formatIDR(kpis?.outlet_receivables_balance ?? 0)}
          subtext={
            (kpis?.outlet_open_invoices_count ?? 0) > 0
              ? `${kpis?.outlet_open_invoices_count} Invoice Belum Lunas`
              : 'Seluruh invoice lunas'
          }
          accent={(kpis?.outlet_open_invoices_count ?? 0) > 0 ? 'amber' : 'emerald'}
          icon={<Receipt className="w-5 h-5" />}
          action={
            <button
              type="button"
              onClick={() => onNavigate('receivables')}
              className="text-xs font-bold text-blue-600 hover:text-blue-700 cursor-pointer"
            >
              Monitor AR &rarr;
            </button>
          }
        />

        <StatCard
          label="Kas Shift & Stok Kritis"
          value={shiftSummary ? formatIDR(shiftSummary.expected_cash) : 'Shift Tutup'}
          subtext={`${kpis?.low_stock_count ?? 0} Varian Low Stock di Gudang Cabang`}
          accent={(kpis?.low_stock_count ?? 0) > 0 ? 'rose' : 'blue'}
          icon={<Wallet className="w-5 h-5" />}
          action={
            <button
              type="button"
              onClick={onOpenShiftModal}
              className="text-xs font-bold text-blue-600 hover:text-blue-700 cursor-pointer"
            >
              Shift &rarr;
            </button>
          }
        />
      </div>

      {/* Middle Row: Payment Method Breakdown & Top Selling Products */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        {/* Payment Method Breakdown (5 cols) */}
        <Card className="lg:col-span-5 flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
              <div className="flex items-center gap-2">
                <CreditCard className="w-4 h-4 text-blue-600" />
                <h3 className="text-sm font-bold text-slate-900">
                  Komposisi Metode Pembayaran Counter
                </h3>
              </div>
              <span className="text-[11px] text-slate-500">Settled Payments</span>
            </div>

            <div className="space-y-3.5">
              {(data?.payment_breakdown ?? []).map((pm) => (
                <div key={pm.method} className="space-y-1.5">
                  <div className="flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2">
                      <span className="font-semibold text-slate-800">{pm.label}</span>
                      <span className="text-[11px] text-slate-400">
                        ({pm.transaction_count}x)
                      </span>
                    </div>
                    <div className="flex items-center gap-2 tabular-nums">
                      <span className="font-bold text-slate-900">
                        {formatIDR(pm.total_amount)}
                      </span>
                      <span className="text-[11px] font-semibold text-blue-600 w-11 text-right">
                        {pm.percentage}%
                      </span>
                    </div>
                  </div>
                  <div className="w-full h-2 rounded-full bg-slate-100 overflow-hidden">
                    <div
                      className="h-full rounded-full bg-blue-600 transition-all duration-300"
                      style={{ width: `${Math.min(100, Math.max(0, pm.percentage))}%` }}
                    />
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="mt-5 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Total Diskon Diberikan: {formatIDR(kpis?.discount_total ?? 0)}</span>
            <span>Pajak &amp; Service: {formatIDR(kpis?.tax_and_service_total ?? 0)}</span>
          </div>
        </Card>

        {/* Top Products & Low Stock (7 cols) */}
        <div className="lg:col-span-7 grid grid-cols-1 md:grid-cols-2 gap-5">
          {/* Top Selling Products */}
          <Card padding="none" className="overflow-hidden flex flex-col">
            <div className="p-4 border-b border-slate-100 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Package className="w-4 h-4 text-emerald-600" />
                <h3 className="text-sm font-bold text-slate-900">Produk Terlaris</h3>
              </div>
              <button
                type="button"
                onClick={() => onNavigate('catalog')}
                className="text-xs font-semibold text-blue-600 hover:text-blue-700 cursor-pointer"
              >
                Katalog &rarr;
              </button>
            </div>

            <div className="divide-y divide-slate-100 flex-1">
              {(data?.top_products ?? []).length === 0 ? (
                <div className="p-8 text-center text-xs text-slate-400">
                  Belum ada data penjualan produk pada periode ini.
                </div>
              ) : (
                data?.top_products.map((prod, idx) => (
                  <div
                    key={prod.product_variant_id}
                    className="px-4 py-3 flex items-center justify-between gap-2 text-xs"
                  >
                    <div className="flex items-center gap-2.5 min-w-0">
                      <span className="w-6 h-6 rounded-lg bg-slate-100 text-slate-700 font-bold flex items-center justify-center shrink-0">
                        {idx + 1}
                      </span>
                      <div className="min-w-0">
                        <div className="font-bold text-slate-900 truncate">
                          {prod.product_name}
                        </div>
                        <div className="text-[11px] text-slate-500 truncate">
                          {prod.variant_name} &bull;{' '}
                          <span className="font-mono">{prod.sku}</span>
                        </div>
                      </div>
                    </div>

                    <div className="text-right shrink-0 tabular-nums">
                      <div className="font-bold text-slate-900">
                        {formatIDR(prod.total_revenue)}
                      </div>
                      <div className="text-[11px] text-emerald-600 font-semibold">
                        {prod.total_qty} terjual
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          </Card>

          {/* Low Stock Alerts */}
          <Card padding="none" className="overflow-hidden flex flex-col">
            <div className="p-4 border-b border-slate-100 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <AlertTriangle className="w-4 h-4 text-rose-600" />
                <h3 className="text-sm font-bold text-slate-900">Peringatan Stok Cabang</h3>
              </div>
              <button
                type="button"
                onClick={() => onNavigate('warehouse')}
                className="text-xs font-semibold text-blue-600 hover:text-blue-700 cursor-pointer"
              >
                Minta Transfer &rarr;
              </button>
            </div>

            <div className="divide-y divide-slate-100 flex-1">
              {(data?.low_stock_items ?? []).length === 0 ? (
                <div className="p-8 text-center text-xs text-emerald-600 font-medium">
                  Seluruh varian produk memiliki stok aman di atas batas minimum.
                </div>
              ) : (
                data?.low_stock_items.map((item) => (
                  <div
                    key={item.id}
                    className="px-4 py-3 flex items-center justify-between gap-2 text-xs"
                  >
                    <div className="min-w-0">
                      <div className="font-bold text-slate-900 truncate">
                        {item.product_name} ({item.variant_name})
                      </div>
                      <div className="text-[11px] text-slate-500 font-mono">
                        {item.sku} &bull; Min: {formatQty(item.reorder_level)} {item.unit}
                      </div>
                    </div>
                    <Badge variant="danger">
                      Sisa {formatQty(item.quantity)} {item.unit}
                    </Badge>
                  </div>
                ))
              )}
            </div>
          </Card>
        </div>
      </div>

      {/* Bottom Row: Recent Counter Orders, Recent B2B Sales Orders & Audit Feed */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        {/* Recent Counter Orders (5 cols) */}
        <Card padding="none" className="lg:col-span-5 overflow-hidden">
          <div className="p-4 border-b border-slate-100 flex items-center justify-between">
            <div>
              <h3 className="text-sm font-bold text-slate-900">Transaksi Counter POS Terkini</h3>
              <p className="text-[11px] text-slate-500">
                Penjualan langsung di kasir outlet
              </p>
            </div>
            <Button
              variant="ghost"
              size="xs"
              onClick={() => onNavigate('orders')}
              icon={<ArrowRight className="w-3.5 h-3.5" />}
            >
              Semua
            </Button>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                  <th className="py-2.5 px-4">No. Struk</th>
                  <th className="py-2.5 px-4">Pelanggan</th>
                  <th className="py-2.5 px-4 text-right">Total</th>
                  <th className="py-2.5 px-4 text-right">Struk</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(data?.recent_orders ?? []).map((ord) => (
                  <tr key={ord.id} className="hover:bg-slate-50/80">
                    <td className="py-3 px-4">
                      <div className="font-mono font-bold text-slate-900">
                        {ord.order_number}
                      </div>
                      <div className="text-[10px] text-slate-500">
                        {formatDateTime(ord.created_at)}
                      </div>
                    </td>
                    <td className="py-3 px-4">
                      <div className="font-semibold text-slate-800">
                        {ord.customer_name || 'Walk-in Guest'}
                      </div>
                      <Badge
                        variant={
                          ord.status === 'completed'
                            ? 'success'
                            : ord.status === 'held'
                              ? 'warning'
                              : 'danger'
                        }
                      >
                        {ord.status.toUpperCase()}
                      </Badge>
                    </td>
                    <td className="py-3 px-4 text-right font-bold text-slate-900 tabular-nums">
                      {formatIDR(ord.grand_total)}
                    </td>
                    <td className="py-3 px-4 text-right">
                      <Button
                        variant="outline"
                        size="xs"
                        icon={<Printer className="w-3.5 h-3.5" />}
                        onClick={() => setReceiptOrder(ord)}
                      >
                        Cetak
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>

        {/* Recent B2B Sales Orders (4 cols) */}
        <Card padding="none" className="lg:col-span-4 overflow-hidden">
          <div className="p-4 border-b border-slate-100 flex items-center justify-between">
            <div>
              <h3 className="text-sm font-bold text-slate-900 flex items-center gap-1.5">
                <Truck className="w-4 h-4 text-blue-600" />
                Sales Order B2B Terkini
              </h3>
              <p className="text-[11px] text-slate-500">
                Distribusi grosir &amp; tempo pelanggan B2B
              </p>
            </div>
            <Button
              variant="ghost"
              size="xs"
              onClick={() => onNavigate('sales_orders')}
              icon={<ArrowRight className="w-3.5 h-3.5" />}
            >
              Semua SO
            </Button>
          </div>

          <div className="divide-y divide-slate-100">
            {(data?.recent_sales_orders ?? []).length === 0 ? (
              <div className="p-8 text-center text-xs text-slate-400">
                Belum ada Sales Order B2B di outlet ini.
              </div>
            ) : (
              data?.recent_sales_orders?.map((so) => (
                <div
                  key={so.id}
                  className="px-4 py-3 flex items-center justify-between gap-2 text-xs hover:bg-slate-50/80"
                >
                  <div className="min-w-0">
                    <div className="flex items-center gap-1.5">
                      <span className="font-mono font-bold text-blue-700">
                        {so.so_number}
                      </span>
                      <Badge
                        variant={
                          so.status === 'invoiced' || so.status === 'delivered'
                            ? 'success'
                            : 'primary'
                        }
                      >
                        {so.status.toUpperCase()}
                      </Badge>
                    </div>
                    <div className="font-semibold text-slate-800 truncate mt-0.5">
                      {so.customer?.name}
                    </div>
                    <div className="text-[10px] text-slate-500">
                      Pembayaran: {so.payment_type.toUpperCase()}
                    </div>
                  </div>
                  <div className="text-right shrink-0">
                    <div className="font-bold text-slate-900 tabular-nums">
                      {formatIDR(so.grand_total)}
                    </div>
                    <div className="text-[10px] text-slate-400">
                      {formatDateTime(so.created_at)}
                    </div>
                  </div>
                </div>
              ))
            )}
          </div>
        </Card>

        {/* Recent Audit Feed (3 cols) */}
        <Card padding="none" className="lg:col-span-3 overflow-hidden">
          <div className="p-4 border-b border-slate-100 flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Activity className="w-4 h-4 text-blue-600" />
              <h3 className="text-sm font-bold text-slate-900">Log Operasional</h3>
            </div>
            <span className="text-[10px] text-slate-400">Audit</span>
          </div>

          <div className="divide-y divide-slate-100">
            {(data?.recent_activities ?? []).map((act) => (
              <div key={act.id} className="px-4 py-3 flex items-center justify-between gap-2 text-xs">
                <div className="min-w-0">
                  <div className="font-mono font-bold text-blue-600 text-[11px] truncate">
                    {act.action_event}
                  </div>
                  <div className="text-slate-700 font-medium truncate">
                    {act.actor_name_snapshot ?? 'System'}{' '}
                    <span className="text-slate-400 font-normal">
                      ({act.actor_role_snapshot ?? '-'})
                    </span>
                  </div>
                </div>
                <div className="text-right shrink-0 text-[10px] text-slate-400">
                  {formatDateTime(act.created_at)}
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <ReceiptModal
        isOpen={receiptOrder !== null}
        order={receiptOrder}
        outlet={outlet}
        onClose={() => setReceiptOrder(null)}
      />
    </div>
  );
};
