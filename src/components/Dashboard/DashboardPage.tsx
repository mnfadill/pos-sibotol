import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  AlertCircle,
  AlertTriangle,
  ArrowDownRight,
  ArrowRight,
  ArrowRightLeft,
  ArrowUpRight,
  Briefcase,
  Building2,
  Calendar,
  CreditCard,
  PackagePlus,
  Printer,
  RefreshCw,
  ShoppingCart,
  Sliders,
  Store,
  Wallet,
  Warehouse,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime, formatIDR, formatQty } from '../../lib/format';
import type {
  DashboardSummaryResponse,
  OrderRecord,
  OutletInfo,
  UserSession,
} from '../../types/pos';
import type { ActiveTab } from '../Layout/AppSidebar';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { ReceiptModal } from '../ui/ReceiptModal';

interface DashboardPageProps {
  session: UserSession;
  onNavigate: (tab: ActiveTab) => void;
  onOpenShiftModal: () => void;
  onSwitchOutletWorkspace?: (outletId: number) => Promise<void> | void;
}

type PeriodOption = DashboardSummaryResponse['period'];
type ActivityTab = 'orders' | 'sales_orders' | 'receivables' | 'transfers';

const PERIOD_OPTIONS: Array<{ value: PeriodOption; label: string }> = [
  { value: 'today', label: 'Hari Ini' },
  { value: '7d', label: '7 Hari' },
  { value: '30d', label: '30 Hari' },
  { value: 'all', label: 'Semua' },
  { value: 'custom', label: 'Kustom' },
];

function formatCompactIDR(value: number): string {
  const abs = Math.abs(value);
  if (abs >= 1_000_000_000) return `Rp ${(value / 1_000_000_000).toFixed(1)} M`;
  if (abs >= 1_000_000) return `Rp ${(value / 1_000_000).toFixed(1)} jt`;
  if (abs >= 1_000) return `Rp ${Math.round(value / 1_000)} rb`;
  return `Rp ${Math.round(value)}`;
}

function getDefaultDateRange(): { from: string; to: string } {
  const today = new Date();
  const from = new Date(today);
  from.setDate(today.getDate() - 29);
  const toIso = (d: Date) => d.toISOString().slice(0, 10);
  return { from: toIso(from), to: toIso(today) };
}

/**
 * Clean, responsive SVG trend chart (Shopify Admin / Odoo style)
 * Displays daily total sales (Counter + B2B) with interactive hover inspection.
 */
const SalesTrendChart: React.FC<{
  points: DashboardSummaryResponse['sales_trend'];
}> = ({ points }) => {
  const [hoveredIdx, setHoveredIdx] = useState<number | null>(null);

  if (points.length === 0) {
    return (
      <div className="h-56 flex items-center justify-center text-xs text-slate-400">
        Belum ada data tren penjualan pada periode ini.
      </div>
    );
  }

  const maxVal = Math.max(1, ...points.map((p) => p.total_sales));
  const width = 760;
  const height = 220;
  const padLeft = 68;
  const padRight = 16;
  const padTop = 16;
  const padBottom = 30;
  const plotW = width - padLeft - padRight;
  const plotH = height - padTop - padBottom;

  const yTicks = [0, 0.25, 0.5, 0.75, 1];
  const stepX = points.length > 1 ? plotW / (points.length - 1) : plotW;
  const barWidth = Math.max(4, Math.min(22, (plotW / Math.max(1, points.length)) * 0.52));

  const xFor = (i: number) => (points.length === 1 ? padLeft + plotW / 2 : padLeft + i * stepX);
  const yFor = (val: number) => padTop + plotH - (val / maxVal) * plotH;

  const linePath = points
    .map((p, i) => `${i === 0 ? 'M' : 'L'} ${xFor(i).toFixed(1)} ${yFor(p.total_sales).toFixed(1)}`)
    .join(' ');

  const areaPath =
    points.length > 1
      ? `${linePath} L ${xFor(points.length - 1).toFixed(1)} ${(padTop + plotH).toFixed(1)} L ${xFor(0).toFixed(1)} ${(padTop + plotH).toFixed(1)} Z`
      : '';

  const labelInterval = Math.max(1, Math.ceil(points.length / 7));
  const hovered = hoveredIdx !== null ? points[hoveredIdx] : null;

  return (
    <div className="relative">
      <div className="flex items-center justify-between gap-2 mb-3 text-xs">
        <div className="flex items-center gap-4 text-slate-500">
          <span className="inline-flex items-center gap-1.5">
            <span className="w-2.5 h-2.5 rounded-xs bg-blue-600" />
            Counter POS
          </span>
          <span className="inline-flex items-center gap-1.5">
            <span className="w-2.5 h-2.5 rounded-xs bg-emerald-600" />
            B2B Sales Order
          </span>
        </div>

        {hovered ? (
          <div className="flex flex-wrap items-center gap-3 text-[11px] bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1 tabular-nums">
            <span className="font-semibold text-slate-700">{hovered.label}</span>
            <span className="text-slate-900 font-bold">{formatIDR(hovered.total_sales)}</span>
            <span className="text-slate-500">
              POS: {formatCompactIDR(hovered.counter_sales)} &middot; B2B:{' '}
              {formatCompactIDR(hovered.b2b_sales)} &middot; {hovered.transactions_count} trx
            </span>
          </div>
        ) : (
          <span className="text-[11px] text-slate-400">Arahkan kursor ke grafik untuk detail harian</span>
        )}
      </div>

      <svg
        viewBox={`0 0 ${width} ${height}`}
        className="w-full h-56 overflow-visible select-none"
        role="img"
        aria-label="Grafik tren penjualan"
      >
        <defs>
          <linearGradient id="salesTrendFill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="#2563EB" stopOpacity="0.14" />
            <stop offset="100%" stopColor="#2563EB" stopOpacity="0.0" />
          </linearGradient>
        </defs>

        {/* Horizontal gridlines & Y-axis labels */}
        {yTicks.map((ratio) => {
          const val = maxVal * ratio;
          const y = yFor(val);
          return (
            <g key={ratio}>
              <line
                x1={padLeft}
                x2={width - padRight}
                y1={y}
                y2={y}
                stroke="#E2E8F0"
                strokeDasharray={ratio === 0 ? undefined : '3 3'}
                strokeWidth="1"
              />
              <text
                x={padLeft - 8}
                y={y + 3.5}
                textAnchor="end"
                className="fill-slate-400 text-[10px] font-medium"
              >
                {formatCompactIDR(val)}
              </text>
            </g>
          );
        })}

        {/* Subtle area fill for total trend */}
        {areaPath && <path d={areaPath} fill="url(#salesTrendFill)" />}

        {/* Stacked daily bars (Counter + B2B) */}
        {points.map((p, i) => {
          const cx = xFor(i);
          const cHeight = (p.counter_sales / maxVal) * plotH;
          const bHeight = (p.b2b_sales / maxVal) * plotH;
          const isHover = hoveredIdx === i;

          return (
            <g
              key={p.date}
              onMouseEnter={() => setHoveredIdx(i)}
              onMouseLeave={() => setHoveredIdx(null)}
              className="cursor-pointer"
            >
              {/* Transparent hit zone */}
              <rect
                x={cx - Math.max(barWidth, 14) / 2}
                y={padTop}
                width={Math.max(barWidth, 14)}
                height={plotH}
                fill={isHover ? 'rgba(37, 99, 235, 0.05)' : 'transparent'}
              />

              {/* Counter POS bar */}
              {cHeight > 0 && (
                <rect
                  x={cx - barWidth / 2}
                  y={padTop + plotH - cHeight}
                  width={barWidth}
                  height={Math.max(1.5, cHeight)}
                  rx={2}
                  fill={isHover ? '#1D4ED8' : '#2563EB'}
                  opacity={isHover ? 1 : 0.85}
                />
              )}

              {/* B2B bar stacked on top */}
              {bHeight > 0 && (
                <rect
                  x={cx - barWidth / 2}
                  y={padTop + plotH - cHeight - bHeight}
                  width={barWidth}
                  height={Math.max(1.5, bHeight)}
                  rx={2}
                  fill={isHover ? '#047857' : '#059669'}
                  opacity={isHover ? 1 : 0.85}
                />
              )}

              {/* X-axis tick label */}
              {(i % labelInterval === 0 || i === points.length - 1) && (
                <text
                  x={cx}
                  y={height - 8}
                  textAnchor="middle"
                  className="fill-slate-400 text-[10px] font-medium"
                >
                  {p.label}
                </text>
              )}
            </g>
          );
        })}

        {/* Subtle connecting trend line */}
        {points.length > 1 && (
          <path
            d={linePath}
            fill="none"
            stroke="#1E40AF"
            strokeWidth="1.5"
            strokeLinejoin="round"
            strokeLinecap="round"
            opacity="0.45"
            pointerEvents="none"
          />
        )}
      </svg>
    </div>
  );
};

export const DashboardPage: React.FC<DashboardPageProps> = ({
  session,
  onNavigate,
  onOpenShiftModal,
  onSwitchOutletWorkspace,
}) => {
  const perms = session.user.permissions ?? [];
  const canViewAllOutlets = Boolean(
    session.user.is_super_admin ||
      session.user.can_view_company_dashboard ||
      perms.includes('company.dashboard.view')
  );
  const role = (session.user.role || '').toLowerCase();

  const defaultRange = useMemo(() => getDefaultDateRange(), []);

  // Super Admin defaults to 'all' (aggregated across all outlets); others lock to active outlet
  const [selectedScope, setSelectedScope] = useState<'all' | 'outlet'>(
    canViewAllOutlets ? 'all' : 'outlet'
  );
  const [selectedOutletId, setSelectedOutletId] = useState<number | null>(
    canViewAllOutlets ? null : (session.active_outlet?.id ?? null)
  );
  const [period, setPeriod] = useState<PeriodOption>('30d');
  const [dateFrom, setDateFrom] = useState<string>(defaultRange.from);
  const [dateTo, setDateTo] = useState<string>(defaultRange.to);

  const [data, setData] = useState<DashboardSummaryResponse | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [refreshing, setRefreshing] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [activityTab, setActivityTab] = useState<ActivityTab>('orders');
  const [receiptOrder, setReceiptOrder] = useState<OrderRecord | null>(null);
  const [switchingWorkspaceId, setSwitchingWorkspaceId] = useState<number | null>(null);

  const abortRef = useRef<AbortController | null>(null);

  // Sync non-super-admin scope if active outlet changes from top header
  useEffect(() => {
    if (!canViewAllOutlets && session.active_outlet?.id) {
      setSelectedScope('outlet');
      setSelectedOutletId(session.active_outlet.id);
    }
  }, [canViewAllOutlets, session.active_outlet?.id]);

  const fetchDashboard = async (forceRefresh = false) => {
    if (abortRef.current) {
      abortRef.current.abort();
    }
    const controller = new AbortController();
    abortRef.current = controller;

    if (data === null) {
      setLoading(true);
    } else {
      setRefreshing(true);
    }
    setError(null);

    try {
      const params: Record<string, string | number> = {
        scope: selectedScope,
        period,
      };
      if (selectedScope === 'outlet' && selectedOutletId) {
        params.outlet_id = selectedOutletId;
      }
      if (period === 'custom') {
        params.date_from = dateFrom;
        params.date_to = dateTo;
      }
      if (forceRefresh) {
        params.refresh = 1;
      }

      const res = await api.get<DashboardSummaryResponse>('/dashboard/summary', {
        params,
        signal: controller.signal,
      });
      setData(res.data);
    } catch (err: unknown) {
      if ((err as { name?: string })?.name === 'CanceledError' || (err as { code?: string })?.code === 'ERR_CANCELED') {
        return;
      }
      setError(extractErrorMessage(err));
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  useEffect(() => {
    fetchDashboard(false);
    return () => abortRef.current?.abort();
  }, [selectedScope, selectedOutletId, period, dateFrom, dateTo]);

  const handleOutletSelectChange = (value: string) => {
    if (value === 'all') {
      setSelectedScope('all');
      setSelectedOutletId(null);
    } else {
      const id = Number(value);
      if (Number.isFinite(id) && id > 0) {
        setSelectedScope('outlet');
        setSelectedOutletId(id);
      }
    }
  };

  const handleOpenOutletWorkspace = async (outletId: number) => {
    if (!onSwitchOutletWorkspace) return;
    setSwitchingWorkspaceId(outletId);
    try {
      await onSwitchOutletWorkspace(outletId);
      setSelectedScope('outlet');
      setSelectedOutletId(outletId);
    } finally {
      setSwitchingWorkspaceId(null);
    }
  };

  const sections = data?.access?.sections ?? {
    sales_analytics: role !== 'warehouse_staff',
    outlet_comparison: canViewAllOutlets && selectedScope === 'all',
    inventory_overview: canViewAllOutlets || ['outlet_manager', 'supervisor', 'warehouse_staff'].includes(role),
    receivables: canViewAllOutlets || ['outlet_manager', 'supervisor', 'finance', 'sales_rep'].includes(role),
    shift_summary: selectedScope === 'outlet',
    b2b_orders: role !== 'cashier',
    payment_breakdown: role !== 'warehouse_staff',
    audit_feed: canViewAllOutlets || ['outlet_manager', 'supervisor', 'finance'].includes(role),
  };

  // Role-tailored ERPNext-style operational shortcuts
  const quickShortcuts = useMemo(() => {
    const list: Array<{ label: string; tab?: ActiveTab; onClick?: () => void; icon: React.ReactNode; primary?: boolean }> = [];

    if (role === 'warehouse_staff') {
      list.push(
        { label: 'Transfer Stok', tab: 'warehouse', icon: <ArrowRightLeft className="w-3.5 h-3.5" />, primary: true },
        { label: 'Penyesuaian Stok', tab: 'inventory', icon: <Sliders className="w-3.5 h-3.5" /> },
        { label: 'Penerimaan PO', tab: 'procurement_receiving', icon: <PackagePlus className="w-3.5 h-3.5" /> },
        { label: 'Stok Gudang', tab: 'warehouse_stock', icon: <Warehouse className="w-3.5 h-3.5" /> }
      );
    } else if (role === 'cashier') {
      list.push(
        { label: 'Buka Kasir POS', tab: 'checkout', icon: <ShoppingCart className="w-3.5 h-3.5" />, primary: true },
        { label: 'Kelola Shift Kas', onClick: onOpenShiftModal, icon: <Wallet className="w-3.5 h-3.5" /> },
        { label: 'Riwayat Transaksi', tab: 'orders', icon: <Printer className="w-3.5 h-3.5" /> }
      );
    } else if (role === 'finance') {
      list.push(
        { label: 'Monitor Piutang', tab: 'receivables', icon: <CreditCard className="w-3.5 h-3.5" />, primary: true },
        { label: 'Sales Order B2B', tab: 'sales_orders', icon: <Briefcase className="w-3.5 h-3.5" /> },
        { label: 'Riwayat Transaksi', tab: 'orders', icon: <Printer className="w-3.5 h-3.5" /> }
      );
    } else if (role === 'sales_rep') {
      list.push(
        { label: 'Sales Order B2B', tab: 'sales_orders', icon: <Briefcase className="w-3.5 h-3.5" />, primary: true },
        { label: 'Monitor Piutang', tab: 'receivables', icon: <CreditCard className="w-3.5 h-3.5" /> },
        { label: 'Pelanggan', tab: 'master_customers', icon: <Store className="w-3.5 h-3.5" /> }
      );
    } else {
      list.push(
        { label: 'Buka Kasir POS', tab: 'checkout', icon: <ShoppingCart className="w-3.5 h-3.5" />, primary: true },
        { label: 'Sales Order B2B', tab: 'sales_orders', icon: <Briefcase className="w-3.5 h-3.5" /> },
        { label: 'Piutang (AR)', tab: 'receivables', icon: <CreditCard className="w-3.5 h-3.5" /> },
        { label: 'Transfer Stok', tab: 'warehouse', icon: <ArrowRightLeft className="w-3.5 h-3.5" /> },
        { label: 'Purchase Order', tab: 'procurement', icon: <PackagePlus className="w-3.5 h-3.5" /> }
      );
    }
    return list;
  }, [role, onOpenShiftModal]);

  const kpis = data?.kpis;
  const availableOutlets = data?.available_outlets ?? session.available_outlets ?? [];
  const activeScopeLabel =
    selectedScope === 'all'
      ? `Semua Outlet (${kpis?.total_outlets ?? availableOutlets.length} cabang)`
      : `${data?.outlet?.name ?? session.active_outlet?.name ?? 'Outlet'} (${data?.outlet?.code ?? session.active_outlet?.code ?? '-'})`;

  const receiptOutletFallback: OutletInfo =
    session.active_outlet ??
    (data?.outlet
      ? {
          id: data.outlet.id,
          code: data.outlet.code,
          name: data.outlet.name,
          address: '',
          city: data.outlet.city ?? '',
          tax_rate: '11.00',
          service_charge_rate: '0.00',
          max_cashier_discount_percent: '10.00',
          allow_negative_stock: false,
        }
      : {
          id: 0,
          code: 'SBT',
          name: 'SiBotol',
          address: '',
          city: '',
          tax_rate: '11.00',
          service_charge_rate: '0.00',
          max_cashier_discount_percent: '10.00',
          allow_negative_stock: false,
        });

  // Actionable operational alerts (only show if count > 0)
  const actionAlerts = useMemo(() => {
    if (!kpis) return [];
    const items: Array<{ label: string; tone: 'danger' | 'warning' | 'info'; tab: ActiveTab }> = [];
    if (sections.receivables && kpis.overdue_invoices_count > 0) {
      items.push({
        label: `${kpis.overdue_invoices_count} invoice jatuh tempo (${formatIDR(kpis.overdue_receivables_amount)})`,
        tone: 'danger',
        tab: 'receivables',
      });
    }
    if (sections.inventory_overview && kpis.low_stock_count > 0) {
      items.push({
        label: `${kpis.low_stock_count} varian stok menipis`,
        tone: 'warning',
        tab: 'inventory_stock',
      });
    }
    if (kpis.held_orders_count > 0) {
      items.push({
        label: `${kpis.held_orders_count} transaksi kasir di-hold`,
        tone: 'warning',
        tab: 'orders',
      });
    }
    if (sections.inventory_overview && kpis.pending_transfers_count > 0) {
      items.push({
        label: `${kpis.pending_transfers_count} transfer stok menunggu proses`,
        tone: 'info',
        tab: 'warehouse',
      });
    }
    return items;
  }, [kpis, sections]);

  const renderGrowthPill = (pct: number | null | undefined, label: string | null | undefined) => {
    if (pct === null || pct === undefined) return null;
    const isUp = pct >= 0;
    return (
      <span
        className={`inline-flex items-center gap-0.5 text-[11px] font-semibold tabular-nums ${
          isUp ? 'text-emerald-700' : 'text-rose-600'
        }`}
      >
        {isUp ? <ArrowUpRight className="w-3.5 h-3.5" /> : <ArrowDownRight className="w-3.5 h-3.5" />}
        {isUp ? '+' : ''}
        {pct.toFixed(1)}%
        {label && <span className="text-slate-400 font-normal ml-1">{label}</span>}
      </span>
    );
  };

  // Loading Skeleton (Initial)
  if (loading && !data) {
    return (
      <div className="p-5 max-w-[1440px] mx-auto space-y-5 animate-pulse">
        <div className="h-16 bg-white rounded-xl border border-slate-200" />
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {[1, 2, 3, 4].map((n) => (
            <div key={n} className="h-28 bg-white rounded-xl border border-slate-200" />
          ))}
        </div>
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
          <div className="lg:col-span-8 h-72 bg-white rounded-xl border border-slate-200" />
          <div className="lg:col-span-4 h-72 bg-white rounded-xl border border-slate-200" />
        </div>
      </div>
    );
  }

  return (
    <div className="p-5 max-w-[1440px] mx-auto space-y-5">
      {/* 1. CLEAN CONTEXT & FILTER HEADER (Shopify Admin style) */}
      <div className="bg-white rounded-xl border border-slate-200 p-4 space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-lg font-bold text-slate-900 tracking-tight">Dashboard</h1>
              <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md bg-slate-100 text-slate-700 text-xs font-medium">
                <Building2 className="w-3.5 h-3.5 text-slate-500" />
                {activeScopeLabel}
              </span>
              {data?.active_warehouse && selectedScope === 'outlet' && (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-slate-50 border border-slate-200 text-slate-600 text-[11px] font-medium">
                  <Warehouse className="w-3 h-3 text-slate-400" />
                  {data.active_warehouse.code}
                </span>
              )}
              {refreshing && (
                <span className="text-[11px] text-slate-400 font-medium">Memperbarui...</span>
              )}
            </div>
            <p className="text-xs text-slate-500 mt-0.5">
              {data?.date_from && data?.date_to
                ? `Periode ${data.date_from} s.d. ${data.date_to}`
                : 'Menampilkan seluruh riwayat transaksi'}
            </p>
          </div>

          {/* Controls: Outlet Selector + Period Filter + Refresh */}
          <div className="flex flex-wrap items-center gap-2">
            {(canViewAllOutlets || availableOutlets.length > 1) && (
              <div className="flex items-center gap-1.5">
                <select
                  aria-label="Pilih cakupan outlet"
                  value={selectedScope === 'all' ? 'all' : String(selectedOutletId ?? '')}
                  onChange={(e) => handleOutletSelectChange(e.target.value)}
                  className="h-8 pl-2.5 pr-7 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 cursor-pointer"
                >
                  {canViewAllOutlets && <option value="all">Semua Outlet</option>}
                  {availableOutlets.map((o) => (
                    <option key={o.id} value={String(o.id)}>
                      {o.name} ({o.code})
                    </option>
                  ))}
                </select>
              </div>
            )}

            <div className="inline-flex bg-slate-100 p-0.5 rounded-lg border border-slate-200">
              {PERIOD_OPTIONS.map((opt) => (
                <button
                  key={opt.value}
                  type="button"
                  onClick={() => setPeriod(opt.value)}
                  className={`px-2.5 py-1 rounded-md text-xs font-medium transition cursor-pointer ${
                    period === opt.value
                      ? 'bg-white text-slate-900 shadow-2xs font-semibold'
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
              onClick={() => fetchDashboard(true)}
              loading={refreshing}
              icon={<RefreshCw className="w-3.5 h-3.5" />}
            >
              Segarkan
            </Button>
          </div>
        </div>

        {/* Custom Date Picker Row (only when period === 'custom') */}
        {period === 'custom' && (
          <div className="flex flex-wrap items-center gap-2 pt-2 border-t border-slate-100 text-xs">
            <Calendar className="w-3.5 h-3.5 text-slate-400" />
            <span className="text-slate-500 font-medium">Rentang Tanggal:</span>
            <input
              type="date"
              value={dateFrom}
              onChange={(e) => setDateFrom(e.target.value)}
              className="h-7 px-2 rounded-md border border-slate-200 text-xs text-slate-700"
            />
            <span className="text-slate-400">s/d</span>
            <input
              type="date"
              value={dateTo}
              onChange={(e) => setDateTo(e.target.value)}
              className="h-7 px-2 rounded-md border border-slate-200 text-xs text-slate-700"
            />
          </div>
        )}

        {/* Role-aware Operational Shortcuts (ERPNext Workspace style) */}
        <div className="flex flex-wrap items-center justify-between gap-2 pt-2.5 border-t border-slate-100">
          <div className="flex flex-wrap items-center gap-1.5">
            {quickShortcuts.map((sc) => (
              <button
                key={sc.label}
                type="button"
                onClick={() => (sc.onClick ? sc.onClick() : sc.tab ? onNavigate(sc.tab) : undefined)}
                className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer ${
                  sc.primary
                    ? 'bg-blue-600 text-white hover:bg-blue-700'
                    : 'bg-slate-50 text-slate-700 border border-slate-200 hover:bg-slate-100 hover:text-slate-900'
                }`}
              >
                {sc.icon}
                {sc.label}
              </button>
            ))}
          </div>

          {selectedScope === 'outlet' &&
            selectedOutletId &&
            session.active_outlet?.id !== selectedOutletId &&
            onSwitchOutletWorkspace && (
              <button
                type="button"
                disabled={switchingWorkspaceId === selectedOutletId}
                onClick={() => handleOpenOutletWorkspace(selectedOutletId)}
                className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-700 cursor-pointer"
              >
                Aktifkan Workspace Outlet Ini
                <ArrowRight className="w-3.5 h-3.5" />
              </button>
            )}
        </div>
      </div>

      {/* Error State */}
      {error && (
        <div className="bg-rose-50 border border-rose-200 rounded-xl p-4 flex items-center justify-between gap-3 text-xs text-rose-800">
          <div className="flex items-center gap-2">
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
            <span>{error}</span>
          </div>
          <Button variant="outline" size="xs" onClick={() => fetchDashboard(true)}>
            Coba Lagi
          </Button>
        </div>
      )}

      {/* Actionable Operational Alerts Strip (only rendered when items need attention) */}
      {actionAlerts.length > 0 && (
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[11px] font-semibold uppercase tracking-wider text-slate-400 mr-1">
            Perlu Tindakan:
          </span>
          {actionAlerts.map((al) => (
            <button
              key={al.label}
              type="button"
              onClick={() => onNavigate(al.tab)}
              className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium border transition cursor-pointer ${
                al.tone === 'danger'
                  ? 'bg-rose-50 text-rose-700 border-rose-200 hover:bg-rose-100'
                  : al.tone === 'warning'
                    ? 'bg-amber-50 text-amber-800 border-amber-200 hover:bg-amber-100'
                    : 'bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100'
              }`}
            >
              <AlertTriangle className="w-3 h-3 shrink-0" />
              <span>{al.label}</span>
              <ArrowRight className="w-3 h-3 opacity-70" />
            </button>
          ))}
        </div>
      )}

      {/* 2. BUSINESS OVERVIEW — 4 Clean Primary KPI Cards (Shopify Admin style) */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* KPI 1: Total Penjualan */}
        <div className="bg-white rounded-xl border border-slate-200 p-4 flex flex-col justify-between">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Total Penjualan</span>
            {renderGrowthPill(kpis?.sales_growth_pct, data?.previous_period_label)}
          </div>
          <div className="mt-2 text-2xl font-bold text-slate-900 tracking-tight tabular-nums">
            {formatIDR(kpis?.total_sales ?? 0)}
          </div>
          <div className="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500 tabular-nums">
            <span>POS: {formatCompactIDR(Number(kpis?.grand_total_revenue ?? 0))}</span>
            {sections.b2b_orders && (
              <span>B2B: {formatCompactIDR(Number(kpis?.b2b_sales_total ?? 0))}</span>
            )}
          </div>
        </div>

        {/* KPI 2: Total Transaksi */}
        <div className="bg-white rounded-xl border border-slate-200 p-4 flex flex-col justify-between">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Total Transaksi</span>
            {renderGrowthPill(kpis?.transactions_growth_pct, data?.previous_period_label)}
          </div>
          <div className="mt-2 text-2xl font-bold text-slate-900 tracking-tight tabular-nums">
            {kpis?.total_transactions ?? 0}
          </div>
          <div className="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500 tabular-nums">
            <span>
              {kpis?.completed_orders_count ?? 0} POS
              {sections.b2b_orders ? ` · ${kpis?.b2b_orders_count ?? 0} SO` : ''}
            </span>
            <span>Rata-rata {formatCompactIDR(Number(kpis?.average_order_value ?? 0))}</span>
          </div>
        </div>

        {/* KPI 3: Total Piutang (or Active Shift for Cashier) */}
        {sections.receivables ? (
          <div className="bg-white rounded-xl border border-slate-200 p-4 flex flex-col justify-between">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-500">Total Piutang</span>
              {(kpis?.overdue_invoices_count ?? 0) > 0 ? (
                <span className="text-[11px] font-semibold text-rose-600">
                  {kpis?.overdue_invoices_count} jatuh tempo
                </span>
              ) : (
                <span className="text-[11px] font-medium text-emerald-600">Terkendali</span>
              )}
            </div>
            <div className="mt-2 text-2xl font-bold text-slate-900 tracking-tight tabular-nums">
              {formatIDR(kpis?.total_receivables ?? 0)}
            </div>
            <div className="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500">
              <span>{kpis?.open_invoices_count ?? 0} invoice terbuka</span>
              <button
                type="button"
                onClick={() => onNavigate('receivables')}
                className="font-semibold text-blue-600 hover:text-blue-700 cursor-pointer"
              >
                Detail &rarr;
              </button>
            </div>
          </div>
        ) : (
          <div className="bg-white rounded-xl border border-slate-200 p-4 flex flex-col justify-between">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-500">Kas Shift Aktif</span>
              <Badge variant={data?.active_shift_summary ? 'success' : 'neutral'}>
                {data?.active_shift_summary ? 'Buka' : 'Tutup'}
              </Badge>
            </div>
            <div className="mt-2 text-2xl font-bold text-slate-900 tracking-tight tabular-nums">
              {data?.active_shift_summary
                ? formatIDR(data.active_shift_summary.expected_cash)
                : 'Belum Dibuka'}
            </div>
            <div className="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500">
              <span>
                {kpis?.held_orders_count ? `${kpis.held_orders_count} order di-hold` : 'Tidak ada order hold'}
              </span>
              <button
                type="button"
                onClick={onOpenShiftModal}
                className="font-semibold text-blue-600 hover:text-blue-700 cursor-pointer"
              >
                Kelola &rarr;
              </button>
            </div>
          </div>
        )}

        {/* KPI 4: Stok Menipis */}
        <div className="bg-white rounded-xl border border-slate-200 p-4 flex flex-col justify-between">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Produk Stok Menipis</span>
            {(kpis?.low_stock_count ?? 0) > 0 ? (
              <span className="text-[11px] font-semibold text-amber-700">Perlu Restok</span>
            ) : (
              <span className="text-[11px] font-medium text-emerald-600">Aman</span>
            )}
          </div>
          <div className="mt-2 text-2xl font-bold text-slate-900 tracking-tight tabular-nums">
            {kpis?.low_stock_count ?? 0}{' '}
            <span className="text-sm font-medium text-slate-400">varian</span>
          </div>
          <div className="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500 tabular-nums">
            <span>Total stok: {formatQty(kpis?.total_inventory_qty ?? 0)} unit</span>
            <button
              type="button"
              onClick={() => onNavigate('inventory_stock')}
              className="font-semibold text-blue-600 hover:text-blue-700 cursor-pointer"
            >
              Cek Stok &rarr;
            </button>
          </div>
        </div>
      </div>

      {/* 3. SALES ANALYTICS — Trend Chart + Payment Breakdown / Shift Summary */}
      {sections.sales_analytics && (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
          {/* Sales Trend Chart (8 cols) */}
          <div className="lg:col-span-8 bg-white rounded-xl border border-slate-200 p-5">
            <div className="flex items-center justify-between gap-2 mb-4">
              <div>
                <h2 className="text-sm font-bold text-slate-900">Tren Penjualan</h2>
                <p className="text-xs text-slate-500">
                  Akumulasi omzet harian Counter POS dan B2B Sales Order
                </p>
              </div>
              <div className="text-right tabular-nums">
                <div className="text-xs text-slate-500">Net Sales POS</div>
                <div className="text-sm font-bold text-slate-900">
                  {formatIDR(kpis?.net_sales ?? 0)}
                </div>
              </div>
            </div>

            <SalesTrendChart points={data?.sales_trend ?? []} />
          </div>

          {/* Payment Method Breakdown (4 cols) */}
          <div className="lg:col-span-4 bg-white rounded-xl border border-slate-200 p-5 flex flex-col justify-between">
            <div>
              <div className="flex items-center justify-between mb-4">
                <div>
                  <h2 className="text-sm font-bold text-slate-900">Metode Pembayaran</h2>
                  <p className="text-xs text-slate-500">Pembayaran lunas Counter POS</p>
                </div>
              </div>

              {(data?.payment_breakdown ?? []).every((p) => p.transaction_count === 0) ? (
                <div className="py-10 text-center text-xs text-slate-400">
                  Belum ada pembayaran tercatat pada periode ini.
                </div>
              ) : (
                <div className="space-y-3">
                  {(data?.payment_breakdown ?? []).map((pm) => (
                    <div key={pm.method} className="space-y-1">
                      <div className="flex items-center justify-between text-xs">
                        <span className="font-medium text-slate-700">
                          {pm.label}{' '}
                          <span className="text-slate-400">({pm.transaction_count})</span>
                        </span>
                        <span className="font-semibold text-slate-900 tabular-nums">
                          {formatIDR(pm.total_amount)}{' '}
                          <span className="text-slate-400 font-normal">({pm.percentage}%)</span>
                        </span>
                      </div>
                      <div className="w-full h-1.5 rounded-full bg-slate-100 overflow-hidden">
                        <div
                          className="h-full rounded-full bg-blue-600 transition-all duration-300"
                          style={{ width: `${Math.min(100, Math.max(0, pm.percentage))}%` }}
                        />
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500 tabular-nums">
              <span>Diskon: {formatIDR(kpis?.discount_total ?? 0)}</span>
              <span>Pajak/Svc: {formatIDR(kpis?.tax_and_service_total ?? 0)}</span>
            </div>
          </div>
        </div>
      )}

      {/* 4. SUPER ADMIN: PER-OUTLET PERFORMANCE COMPARISON */}
      {sections.outlet_comparison && (data?.outlet_comparison ?? []).length > 0 && (
        <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
          <div className="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 className="text-sm font-bold text-slate-900">Performa Antar-Outlet</h2>
              <p className="text-xs text-slate-500">
                Perbandingan omzet, kontribusi penjualan, stok cabang, dan piutang pada periode aktif
              </p>
            </div>
            <span className="text-xs text-slate-400">
              Klik outlet untuk memfilter seluruh dashboard
            </span>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                  <th className="py-2.5 px-5">Outlet</th>
                  <th className="py-2.5 px-4 text-right">Counter POS</th>
                  <th className="py-2.5 px-4 text-right">B2B SO</th>
                  <th className="py-2.5 px-4 text-right">Total Omzet</th>
                  <th className="py-2.5 px-4 w-36">Kontribusi</th>
                  <th className="py-2.5 px-4 text-right">Stok</th>
                  <th className="py-2.5 px-4 text-right">Piutang</th>
                  <th className="py-2.5 px-5 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data?.outlet_comparison.map((row) => (
                  <tr key={row.outlet_id} className="hover:bg-slate-50/70">
                    <td className="py-3 px-5">
                      <div className="font-semibold text-slate-900">{row.name}</div>
                      <div className="text-[11px] text-slate-400">
                        <span className="font-mono text-slate-600">{row.code}</span> &middot;{' '}
                        {row.city} &middot; {row.warehouse_code}
                      </div>
                    </td>
                    <td className="py-3 px-4 text-right tabular-nums">
                      <div className="font-medium text-slate-800">
                        {formatIDR(row.counter_sales_revenue)}
                      </div>
                      <div className="text-[11px] text-slate-400">{row.counter_orders_count} trx</div>
                    </td>
                    <td className="py-3 px-4 text-right tabular-nums">
                      <div className="font-medium text-slate-800">
                        {formatIDR(row.b2b_sales_revenue)}
                      </div>
                      <div className="text-[11px] text-slate-400">{row.b2b_orders_count} SO</div>
                    </td>
                    <td className="py-3 px-4 text-right tabular-nums font-bold text-slate-900">
                      {formatIDR(row.total_revenue)}
                    </td>
                    <td className="py-3 px-4">
                      <div className="flex items-center gap-2">
                        <div className="flex-1 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                          <div
                            className="h-full rounded-full bg-blue-600"
                            style={{ width: `${Math.min(100, Math.max(0, row.revenue_share_pct))}%` }}
                          />
                        </div>
                        <span className="text-[11px] font-semibold text-slate-600 tabular-nums w-9 text-right">
                          {row.revenue_share_pct}%
                        </span>
                      </div>
                    </td>
                    <td className="py-3 px-4 text-right tabular-nums">
                      <div className="font-medium text-slate-800">{formatQty(row.stock_units)}</div>
                      {row.low_stock_count > 0 && (
                        <div className="text-[11px] text-amber-700 font-medium">
                          {row.low_stock_count} kritis
                        </div>
                      )}
                    </td>
                    <td className="py-3 px-4 text-right tabular-nums font-semibold text-slate-800">
                      {formatIDR(row.receivables_balance)}
                    </td>
                    <td className="py-3 px-5 text-right">
                      <button
                        type="button"
                        onClick={() => handleOutletSelectChange(String(row.outlet_id))}
                        className="text-xs font-semibold text-blue-600 hover:text-blue-700 cursor-pointer"
                      >
                        Lihat Detail &rarr;
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* 5. INVENTORY OVERVIEW — Warehouse Summary + Top Products + Low Stock */}
      {sections.inventory_overview && (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
          {/* Warehouse Stock Summary (3 cols) */}
          <div className="lg:col-span-3 bg-white rounded-xl border border-slate-200 p-5 flex flex-col justify-between">
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <h2 className="text-sm font-bold text-slate-900">Ringkasan Gudang</h2>
                <button
                  type="button"
                  onClick={() => onNavigate('warehouse_stock')}
                  className="text-xs font-semibold text-blue-600 hover:text-blue-700 cursor-pointer"
                >
                  Gudang &rarr;
                </button>
              </div>

              <div className="space-y-2.5">
                {canViewAllOutlets && (
                  <div className="p-3 rounded-lg bg-slate-50 border border-slate-100">
                    <div className="text-[11px] text-slate-500">Gudang Pusat</div>
                    <div className="text-lg font-bold text-slate-900 tabular-nums mt-0.5">
                      {formatQty(data?.warehouse_summary?.central_stock_qty ?? 0)}{' '}
                      <span className="text-xs font-normal text-slate-400">unit</span>
                    </div>
                  </div>
                )}

                <div className="p-3 rounded-lg bg-slate-50 border border-slate-100">
                  <div className="text-[11px] text-slate-500">
                    {selectedScope === 'all' ? 'Total Gudang Cabang' : 'Stok Gudang Outlet'}
                  </div>
                  <div className="text-lg font-bold text-slate-900 tabular-nums mt-0.5">
                    {formatQty(data?.warehouse_summary?.branch_stock_qty ?? 0)}{' '}
                    <span className="text-xs font-normal text-slate-400">unit</span>
                  </div>
                </div>
              </div>
            </div>

            <div className="mt-4 pt-3 border-t border-slate-100 space-y-1.5 text-xs">
              <div className="flex items-center justify-between text-slate-600">
                <span>Transfer aktif</span>
                <span className="font-semibold text-slate-900 tabular-nums">
                  {data?.warehouse_summary?.pending_transfers_count ?? 0}
                </span>
              </div>
              <div className="flex items-center justify-between text-slate-600">
                <span>Pengiriman B2B aktif</span>
                <span className="font-semibold text-slate-900 tabular-nums">
                  {data?.warehouse_summary?.active_deliveries_count ?? 0}
                </span>
              </div>
            </div>
          </div>

          {/* Top Selling Products (4 cols) */}
          <div className="lg:col-span-4 bg-white rounded-xl border border-slate-200 overflow-hidden flex flex-col">
            <div className="px-4 py-3.5 border-b border-slate-100 flex items-center justify-between">
              <h2 className="text-sm font-bold text-slate-900">Produk Terlaris</h2>
              <button
                type="button"
                onClick={() => onNavigate('master_products')}
                className="text-xs font-semibold text-blue-600 hover:text-blue-700 cursor-pointer"
              >
                Katalog &rarr;
              </button>
            </div>

            <div className="divide-y divide-slate-100 flex-1">
              {(data?.top_products ?? []).length === 0 ? (
                <div className="p-8 text-center text-xs text-slate-400">
                  Belum ada penjualan produk pada periode ini.
                </div>
              ) : (
                data?.top_products.map((prod, idx) => (
                  <div
                    key={prod.product_variant_id}
                    className="px-4 py-2.5 flex items-center justify-between gap-2 text-xs"
                  >
                    <div className="flex items-center gap-2.5 min-w-0">
                      <span className="w-5 h-5 rounded-md bg-slate-100 text-slate-600 font-semibold text-[11px] flex items-center justify-center shrink-0">
                        {idx + 1}
                      </span>
                      <div className="min-w-0">
                        <div className="font-semibold text-slate-900 truncate">
                          {prod.product_name}
                        </div>
                        <div className="text-[11px] text-slate-400 truncate">
                          {prod.variant_name} &middot; <span className="font-mono">{prod.sku}</span>
                        </div>
                      </div>
                    </div>
                    <div className="text-right shrink-0 tabular-nums">
                      <div className="font-semibold text-slate-900">
                        {formatIDR(prod.total_revenue)}
                      </div>
                      <div className="text-[11px] text-emerald-700 font-medium">
                        {prod.total_qty} terjual
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          {/* Low Stock Alerts (5 cols) */}
          <div className="lg:col-span-5 bg-white rounded-xl border border-slate-200 overflow-hidden flex flex-col">
            <div className="px-4 py-3.5 border-b border-slate-100 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <h2 className="text-sm font-bold text-slate-900">Stok Menipis</h2>
                {(kpis?.low_stock_count ?? 0) > 0 && (
                  <Badge variant="danger">{kpis?.low_stock_count}</Badge>
                )}
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
                <div className="p-8 text-center text-xs text-emerald-700 font-medium">
                  Seluruh varian produk memiliki stok aman di atas batas minimum.
                </div>
              ) : (
                data?.low_stock_items.slice(0, 6).map((item) => (
                  <div
                    key={item.id}
                    className="px-4 py-2.5 flex items-center justify-between gap-2 text-xs"
                  >
                    <div className="min-w-0">
                      <div className="font-semibold text-slate-900 truncate">
                        {item.product_name}{' '}
                        <span className="font-normal text-slate-500">({item.variant_name})</span>
                      </div>
                      <div className="text-[11px] text-slate-400 flex items-center gap-1.5">
                        <span className="font-mono">{item.sku}</span>
                        {selectedScope === 'all' && item.outlet_code && (
                          <span>&middot; {item.outlet_code}</span>
                        )}
                        <span>
                          &middot; Min {formatQty(item.reorder_level)} {item.unit}
                        </span>
                      </div>
                    </div>
                    <Badge variant="danger">
                      {formatQty(item.quantity)} {item.unit}
                    </Badge>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      )}

      {/* 6. OPERATIONAL ACTIVITIES — Compact Tabbed Table */}
      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div className="px-5 py-3.5 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-1">
            <button
              type="button"
              onClick={() => setActivityTab('orders')}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer ${
                activityTab === 'orders'
                  ? 'bg-slate-900 text-white'
                  : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              Transaksi Terbaru ({data?.recent_orders?.length ?? 0})
            </button>

            {sections.b2b_orders && (
              <button
                type="button"
                onClick={() => setActivityTab('sales_orders')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer ${
                  activityTab === 'sales_orders'
                    ? 'bg-slate-900 text-white'
                    : 'text-slate-600 hover:bg-slate-100'
                }`}
              >
                Sales Order B2B ({data?.recent_sales_orders?.length ?? 0})
              </button>
            )}

            {sections.receivables && (
              <button
                type="button"
                onClick={() => setActivityTab('receivables')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer ${
                  activityTab === 'receivables'
                    ? 'bg-slate-900 text-white'
                    : 'text-slate-600 hover:bg-slate-100'
                }`}
              >
                Piutang Jatuh Tempo ({data?.due_invoices?.length ?? 0})
              </button>
            )}

            {(sections.inventory_overview || sections.audit_feed) && (
              <button
                type="button"
                onClick={() => setActivityTab('transfers')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer ${
                  activityTab === 'transfers'
                    ? 'bg-slate-900 text-white'
                    : 'text-slate-600 hover:bg-slate-100'
                }`}
              >
                Transfer &amp; Aktivitas
              </button>
            )}
          </div>

          <button
            type="button"
            onClick={() =>
              onNavigate(
                activityTab === 'orders'
                  ? 'orders'
                  : activityTab === 'sales_orders'
                    ? 'sales_orders'
                    : activityTab === 'receivables'
                      ? 'receivables'
                      : 'warehouse'
              )
            }
            className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-700 cursor-pointer"
          >
            Lihat Semua
            <ArrowRight className="w-3.5 h-3.5" />
          </button>
        </div>

        {/* Tab 1: Recent Counter POS Transactions */}
        {activityTab === 'orders' && (
          <div className="overflow-x-auto">
            {(data?.recent_orders ?? []).length === 0 ? (
              <div className="p-8 text-center text-xs text-slate-400">
                Belum ada transaksi Counter POS.
              </div>
            ) : (
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                    <th className="py-2.5 px-5">No. Struk</th>
                    {selectedScope === 'all' && <th className="py-2.5 px-4">Outlet</th>}
                    <th className="py-2.5 px-4">Pelanggan</th>
                    <th className="py-2.5 px-4">Status</th>
                    <th className="py-2.5 px-4 text-right">Total</th>
                    <th className="py-2.5 px-5 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {data?.recent_orders.map((ord) => (
                    <tr key={ord.id} className="hover:bg-slate-50/70">
                      <td className="py-2.5 px-5">
                        <div className="font-mono font-semibold text-slate-900">
                          {ord.order_number}
                        </div>
                        <div className="text-[11px] text-slate-400">
                          {formatDateTime(ord.created_at)}
                        </div>
                      </td>
                      {selectedScope === 'all' && (
                        <td className="py-2.5 px-4">
                          <Badge variant="neutral">{ord.outlet?.code ?? '-'}</Badge>
                        </td>
                      )}
                      <td className="py-2.5 px-4 font-medium text-slate-700">
                        {ord.customer_name || 'Walk-in'}
                      </td>
                      <td className="py-2.5 px-4">
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
                      <td className="py-2.5 px-4 text-right font-semibold text-slate-900 tabular-nums">
                        {formatIDR(ord.grand_total)}
                      </td>
                      <td className="py-2.5 px-5 text-right">
                        <Button
                          variant="outline"
                          size="xs"
                          icon={<Printer className="w-3.5 h-3.5" />}
                          onClick={() => setReceiptOrder(ord)}
                        >
                          Struk
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}

        {/* Tab 2: Recent B2B Sales Orders */}
        {activityTab === 'sales_orders' && (
          <div className="overflow-x-auto">
            {(data?.recent_sales_orders ?? []).length === 0 ? (
              <div className="p-8 text-center text-xs text-slate-400">
                Belum ada Sales Order B2B.
              </div>
            ) : (
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                    <th className="py-2.5 px-5">No. SO</th>
                    {selectedScope === 'all' && <th className="py-2.5 px-4">Outlet</th>}
                    <th className="py-2.5 px-4">Pelanggan</th>
                    <th className="py-2.5 px-4">Pembayaran</th>
                    <th className="py-2.5 px-4">Status</th>
                    <th className="py-2.5 px-5 text-right">Total</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {data?.recent_sales_orders?.map((so) => (
                    <tr key={so.id} className="hover:bg-slate-50/70">
                      <td className="py-2.5 px-5">
                        <div className="font-mono font-semibold text-slate-900">{so.so_number}</div>
                        <div className="text-[11px] text-slate-400">
                          {formatDateTime(so.created_at)}
                        </div>
                      </td>
                      {selectedScope === 'all' && (
                        <td className="py-2.5 px-4">
                          <Badge variant="neutral">{so.outlet?.code ?? '-'}</Badge>
                        </td>
                      )}
                      <td className="py-2.5 px-4 font-medium text-slate-700">
                        {so.customer?.name ?? '-'}
                      </td>
                      <td className="py-2.5 px-4 text-slate-600 uppercase text-[11px] font-medium">
                        {so.payment_type}
                      </td>
                      <td className="py-2.5 px-4">
                        <Badge
                          variant={
                            so.status === 'invoiced' || so.status === 'delivered'
                              ? 'success'
                              : 'primary'
                          }
                        >
                          {so.status.toUpperCase()}
                        </Badge>
                      </td>
                      <td className="py-2.5 px-5 text-right font-semibold text-slate-900 tabular-nums">
                        {formatIDR(so.grand_total)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}

        {/* Tab 3: Open & Overdue Receivables */}
        {activityTab === 'receivables' && (
          <div className="overflow-x-auto">
            {(data?.due_invoices ?? []).length === 0 ? (
              <div className="p-8 text-center text-xs text-emerald-700 font-medium">
                Tidak ada piutang terbuka atau jatuh tempo.
              </div>
            ) : (
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                    <th className="py-2.5 px-5">No. Invoice</th>
                    {selectedScope === 'all' && <th className="py-2.5 px-4">Outlet</th>}
                    <th className="py-2.5 px-4">Pelanggan</th>
                    <th className="py-2.5 px-4">Jatuh Tempo</th>
                    <th className="py-2.5 px-4">Status</th>
                    <th className="py-2.5 px-5 text-right">Sisa Tagihan</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {data?.due_invoices?.map((inv) => (
                    <tr key={inv.id} className="hover:bg-slate-50/70">
                      <td className="py-2.5 px-5 font-mono font-semibold text-slate-900">
                        {inv.invoice_number}
                      </td>
                      {selectedScope === 'all' && (
                        <td className="py-2.5 px-4">
                          <Badge variant="neutral">{inv.outlet?.code ?? '-'}</Badge>
                        </td>
                      )}
                      <td className="py-2.5 px-4 font-medium text-slate-700">
                        {inv.customer?.name ?? '-'}
                      </td>
                      <td
                        className={`py-2.5 px-4 tabular-nums ${
                          inv.is_overdue ? 'text-rose-600 font-semibold' : 'text-slate-600'
                        }`}
                      >
                        {inv.due_date}
                      </td>
                      <td className="py-2.5 px-4">
                        <Badge variant={inv.is_overdue || inv.status === 'overdue' ? 'danger' : 'warning'}>
                          {inv.is_overdue ? 'OVERDUE' : inv.status.toUpperCase()}
                        </Badge>
                      </td>
                      <td className="py-2.5 px-5 text-right font-semibold text-slate-900 tabular-nums">
                        {formatIDR(inv.balance_due)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}

        {/* Tab 4: Pending Transfers & Recent Audit Activities */}
        {activityTab === 'transfers' && (
          <div className="grid grid-cols-1 lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x divide-slate-100">
            <div className="p-4">
              <div className="text-xs font-bold text-slate-700 mb-3">
                Transfer Stok Aktif ({data?.pending_transfers?.length ?? 0})
              </div>
              {(data?.pending_transfers ?? []).length === 0 ? (
                <div className="py-6 text-center text-xs text-slate-400">
                  Tidak ada transfer stok yang menunggu proses.
                </div>
              ) : (
                <div className="divide-y divide-slate-100">
                  {data?.pending_transfers?.map((trf) => (
                    <div key={trf.id} className="py-2.5 flex items-center justify-between gap-2 text-xs">
                      <div className="min-w-0">
                        <div className="font-mono font-semibold text-slate-900">
                          {trf.transfer_number}
                        </div>
                        <div className="text-[11px] text-slate-500 truncate">
                          {trf.source_warehouse?.code ?? 'WH-CENTRAL'} &rarr;{' '}
                          {trf.destination_outlet?.name ?? trf.destination_warehouse?.name ?? '-'}
                        </div>
                      </div>
                      <Badge variant={trf.status === 'shipped' ? 'info' : 'warning'}>
                        {trf.status.toUpperCase()}
                      </Badge>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="p-4">
              <div className="text-xs font-bold text-slate-700 mb-3">Aktivitas Terbaru</div>
              {(data?.recent_activities ?? []).length === 0 ? (
                <div className="py-6 text-center text-xs text-slate-400">
                  Belum ada aktivitas tercatat.
                </div>
              ) : (
                <div className="divide-y divide-slate-100">
                  {data?.recent_activities.map((act) => (
                    <div key={act.id} className="py-2.5 flex items-center justify-between gap-2 text-xs">
                      <div className="min-w-0">
                        <div className="font-mono font-semibold text-slate-800 text-[11px] truncate">
                          {act.action_event}
                        </div>
                        <div className="text-[11px] text-slate-500 truncate">
                          {act.actor_name_snapshot ?? 'System'} ({act.actor_role_snapshot ?? '-'})
                        </div>
                      </div>
                      <span className="text-[10px] text-slate-400 shrink-0 tabular-nums">
                        {formatDateTime(act.created_at)}
                      </span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      <ReceiptModal
        isOpen={receiptOrder !== null}
        order={receiptOrder}
        outlet={receiptOutletFallback}
        onClose={() => setReceiptOrder(null)}
      />
    </div>
  );
};
