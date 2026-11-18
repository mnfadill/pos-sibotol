import React, { useEffect, useState } from 'react';
import {
  ArrowRight,
  Building2,
  CreditCard,
  Globe,
  Package,
  RefreshCw,
  ShoppingBag,
  Store,
  TrendingUp,
  Truck,
  Warehouse,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime, formatIDR, formatQty } from '../../lib/format';
import type { CompanyDashboardResponse } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card, StatCard } from '../ui/Card';
import { useToast } from '../ui/ToastProvider';

interface Props {
  onSelectOutletWorkspace: (outletId: number) => void;
  onBackToOutletSelection?: () => void;
}

export const CompanyDashboardPage: React.FC<Props> = ({
  onSelectOutletWorkspace,
  onBackToOutletSelection,
}) => {
  const { toast } = useToast();
  const [data, setData] = useState<CompanyDashboardResponse | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [switchingOutletId, setSwitchingOutletId] = useState<number | null>(null);

  const fetchSummary = async () => {
    setLoading(true);
    try {
      const res = await api.get<CompanyDashboardResponse>('/company/dashboard');
      setData(res.data);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat Company Dashboard',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchSummary();
  }, []);

  const handleEnterOutlet = async (outletId: number) => {
    setSwitchingOutletId(outletId);
    try {
      onSelectOutletWorkspace(outletId);
    } finally {
      setSwitchingOutletId(null);
    }
  };

  const kpis = data?.kpis;

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      {/* Header Banner */}
      <div className="bg-gradient-to-r from-slate-900 via-blue-950 to-indigo-950 text-white rounded-2xl p-6 flex flex-wrap items-center justify-between gap-4 shadow-md">
        <div className="space-y-1">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/20 border border-blue-400/30 text-blue-200 text-xs font-bold">
            <Globe className="w-3.5 h-3.5" />
            Company Consolidated Dashboard (Lintas Seluruh Outlet)
          </div>
          <h1 className="text-2xl font-extrabold tracking-tight">
            Monitoring Konsolidasi Perusahaan & Gudang Pusat
          </h1>
          <p className="text-xs text-slate-300">
            Menampilkan agregasi performa penjualan Counter & B2B, Gudang Pusat vs Cabang, serta
            Piutang (AR) seluruh cabang.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          {onBackToOutletSelection && (
            <Button
              variant="outline"
              size="sm"
              onClick={onBackToOutletSelection}
              className="bg-slate-800 border-slate-700 text-white hover:bg-slate-700"
            >
              Halaman Pilih Outlet
            </Button>
          )}
          <Button
            variant="primary"
            size="sm"
            loading={loading}
            leftIcon={<RefreshCw className="w-4 h-4" />}
            onClick={fetchSummary}
          >
            Refresh Konsolidasi
          </Button>
        </div>
      </div>

      {/* Top Consolidated KPIs */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Total Penjualan Perusahaan"
          value={formatIDR(kpis?.total_company_revenue ?? 0)}
          subValue={`Counter: ${formatIDR(kpis?.total_counter_revenue ?? 0)} | B2B: ${formatIDR(kpis?.total_b2b_revenue ?? 0)}`}
          icon={<TrendingUp className="w-5 h-5" />}
          accent="blue"
        />
        <StatCard
          label="Total Outlet & Gudang Pusat"
          value={`${kpis?.total_outlets ?? 0} Outlet Cabang`}
          subValue={`${kpis?.total_central_warehouses ?? 1} Central Warehouse Aktif`}
          icon={<Building2 className="w-5 h-5" />}
          accent="emerald"
        />
        <StatCard
          label="Total Persediaan (Pusat + Cabang)"
          value={`${formatQty(kpis?.total_inventory_qty ?? 0)} Unit`}
          subValue={`Pusat: ${formatQty(kpis?.central_warehouse_stock_qty ?? 0)} | Cabang: ${formatQty(kpis?.branch_warehouses_stock_qty ?? 0)}`}
          icon={<Warehouse className="w-5 h-5" />}
          accent="indigo"
        />
        <StatCard
          label="Total Piutang Perusahaan (AR)"
          value={formatIDR(kpis?.total_receivables ?? 0)}
          subValue={`${kpis?.open_invoices_count ?? 0} Invoice Terbuka (${kpis?.overdue_invoices_count ?? 0} Overdue)`}
          icon={<CreditCard className="w-5 h-5" />}
          accent="amber"
        />
      </div>

      {/* Per-Outlet Performance Comparison Table */}
      <Card
        title="Perbandingan Performa Antar-Outlet & Gudang Cabang"
        subtitle="Klik 'Masuk Workspace' untuk langsung beralih ke konteks operasional outlet tersebut."
      >
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-xs font-bold uppercase tracking-wider text-slate-500 bg-slate-50">
                <th className="py-3 px-4">Outlet & Lokasi</th>
                <th className="py-3 px-4">Gudang Cabang</th>
                <th className="py-3 px-4 text-right">Counter Sales</th>
                <th className="py-3 px-4 text-right">B2B Sales Order</th>
                <th className="py-3 px-4 text-right">Total Omzet</th>
                <th className="py-3 px-4 text-right">Stok Cabang</th>
                <th className="py-3 px-4 text-right">Piutang (AR)</th>
                <th className="py-3 px-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {(data?.outlet_performance ?? []).map((row) => (
                <tr key={row.outlet_id} className="hover:bg-slate-50/80">
                  <td className="py-3.5 px-4">
                    <div className="flex items-center gap-2.5">
                      <div className="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                        <Store className="w-4 h-4" />
                      </div>
                      <div>
                        <div className="font-bold text-slate-900">{row.name}</div>
                        <div className="text-xs text-slate-500">
                          <span className="font-mono font-semibold text-slate-700">{row.code}</span>{' '}
                          • {row.city}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td className="py-3.5 px-4">
                    <Badge variant="info">{row.warehouse_code}</Badge>
                    <div className="text-xs text-slate-500 mt-0.5">{row.warehouse_name}</div>
                  </td>
                  <td className="py-3.5 px-4 text-right tabular-nums">
                    <div className="font-semibold text-slate-900">
                      {formatIDR(row.counter_sales_revenue)}
                    </div>
                    <div className="text-xs text-slate-400">{row.counter_orders_count} struk</div>
                  </td>
                  <td className="py-3.5 px-4 text-right tabular-nums">
                    <div className="font-semibold text-indigo-700">
                      {formatIDR(row.b2b_sales_revenue)}
                    </div>
                    <div className="text-xs text-slate-400">{row.b2b_orders_count} SO</div>
                  </td>
                  <td className="py-3.5 px-4 text-right tabular-nums font-extrabold text-slate-900">
                    {formatIDR(row.total_revenue)}
                  </td>
                  <td className="py-3.5 px-4 text-right tabular-nums">
                    <div className="font-semibold text-slate-800">
                      {formatQty(row.stock_units)} unit
                    </div>
                    {row.low_stock_count > 0 && (
                      <span className="text-[11px] text-amber-600 font-semibold">
                        {row.low_stock_count} stok kritis
                      </span>
                    )}
                  </td>
                  <td className="py-3.5 px-4 text-right tabular-nums font-bold text-amber-700">
                    {formatIDR(row.receivables_balance)}
                  </td>
                  <td className="py-3.5 px-4 text-right">
                    <Button
                      variant="primary"
                      size="xs"
                      loading={switchingOutletId === row.outlet_id}
                      rightIcon={<ArrowRight className="w-3.5 h-3.5" />}
                      onClick={() => handleEnterOutlet(row.outlet_id)}
                    >
                      Masuk Workspace
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      {/* Bottom 2 Columns: Active Stock Transfers & Consolidated Outstanding Invoices */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card
          title="Distribusi & Stock Transfer (Gudang Pusat → Cabang)"
          subtitle="Permintaan & pengiriman stok terbaru antar-gudang"
        >
          <div className="divide-y divide-slate-100">
            {(data?.pending_transfers ?? []).map((trf) => (
              <div key={trf.id} className="py-3 flex items-center justify-between gap-3">
                <div className="flex items-start gap-3">
                  <div className="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0 mt-0.5">
                    <Truck className="w-4 h-4" />
                  </div>
                  <div>
                    <div className="text-xs font-mono font-bold text-slate-900">
                      {trf.transfer_number}
                    </div>
                    <div className="text-xs text-slate-600">
                      {trf.source_warehouse?.code ?? 'WH-CENTRAL'} →{' '}
                      <span className="font-semibold text-slate-900">
                        {trf.destination_outlet?.name ?? trf.destination_warehouse?.name}
                      </span>
                    </div>
                    <div className="text-[11px] text-slate-400">
                      {formatDateTime(trf.requested_at)}
                    </div>
                  </div>
                </div>
                <Badge
                  variant={
                    trf.status === 'received'
                      ? 'success'
                      : trf.status === 'shipped'
                        ? 'info'
                        : 'warning'
                  }
                >
                  {trf.status.toUpperCase()}
                </Badge>
              </div>
            ))}
          </div>
        </Card>

        <Card
          title="Monitoring Invoice & Piutang Lintas Outlet"
          subtitle="Tagihan pelanggan B2B & tempo terbaru di seluruh cabang"
        >
          <div className="divide-y divide-slate-100">
            {(data?.recent_invoices ?? []).map((inv) => (
              <div key={inv.id} className="py-3 flex items-center justify-between gap-3">
                <div className="flex items-start gap-3">
                  <div className="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center shrink-0 mt-0.5">
                    <ShoppingBag className="w-4 h-4" />
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-mono font-bold text-slate-900">
                        {inv.invoice_number}
                      </span>
                      <Badge variant="neutral">{inv.outlet?.code}</Badge>
                    </div>
                    <div className="text-xs font-semibold text-slate-800">
                      {inv.customer?.name}
                    </div>
                    <div className="text-[11px] text-slate-400">Jatuh tempo: {inv.due_date}</div>
                  </div>
                </div>
                <div className="text-right">
                  <div className="text-xs font-bold text-slate-900 tabular-nums">
                    Sisa: {formatIDR(inv.balance_due)}
                  </div>
                  <Badge
                    variant={
                      inv.status === 'paid'
                        ? 'success'
                        : inv.status === 'overdue'
                          ? 'danger'
                          : 'warning'
                    }
                  >
                    {inv.status.toUpperCase()}
                  </Badge>
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>
    </div>
  );
};

