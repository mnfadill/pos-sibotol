import React, { useEffect, useState } from 'react';
import {
  ArrowDownLeft,
  ArrowUpRight,
  Eye,
  Lock,
  RefreshCw,
  Unlock,
  Wallet,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime, formatIDR } from '../../lib/format';
import type { CashierShift, ShiftLiveSummary } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card, StatCard } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { Modal } from '../ui/Modal';
import { Pagination } from '../ui/Pagination';
import { useToast } from '../ui/ToastProvider';

interface ShiftHistoryPageProps {
  activeShift: CashierShift | null;
  onOpenShiftModal: () => void;
}

export const ShiftHistoryPage: React.FC<ShiftHistoryPageProps> = ({
  activeShift,
  onOpenShiftModal,
}) => {
  const { toast } = useToast();
  const [shifts, setShifts] = useState<CashierShift[]>([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [loading, setLoading] = useState<boolean>(true);
  const [selectedShift, setSelectedShift] = useState<{
    shift: CashierShift;
    summary: ShiftLiveSummary;
  } | null>(null);

  useEffect(() => {
    fetchShifts(1);
  }, [activeShift?.id, activeShift?.status]);

  const fetchShifts = async (page = 1) => {
    setLoading(true);
    try {
      const res = await api.get<{
        data: CashierShift[];
        current_page: number;
        last_page: number;
        total: number;
      }>('/shifts', {
        params: { page, per_page: 15 },
      });
      setShifts(res.data.data);
      setMeta({
        current_page: res.data.current_page,
        last_page: res.data.last_page,
        total: res.data.total,
      });
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat riwayat shift',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  const handleInspectShift = async (shiftId: number) => {
    try {
      const res = await api.get<{ shift: CashierShift; summary: ShiftLiveSummary }>(
        `/shifts/${shiftId}`,
      );
      setSelectedShift(res.data);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat detail shift',
        description: extractErrorMessage(err),
      });
    }
  };

  const liveSummary = activeShift?.live_summary;

  return (
    <div className="p-5 space-y-5 max-w-[1600px] mx-auto">
      {/* Top Action Header */}
      <div className="flex flex-wrap items-center justify-between gap-3 bg-white p-4 rounded-xl border border-slate-200 shadow-2xs">
        <div>
          <div className="flex items-center gap-2">
            <h2 className="text-base font-extrabold text-slate-900">
              Manajemen Shift Kasir &amp; Rekonsiliasi Kas Laci
            </h2>
            <Badge variant={activeShift ? 'success' : 'warning'} dot>
              {activeShift ? `Shift Aktif: ${activeShift.shift_number}` : 'Shift Belum Dibuka'}
            </Badge>
          </div>
          <p className="text-xs text-slate-500 mt-0.5">
            Pantau saldo kas diharapkan (Expected Cash), mutasi Paid-In/Paid-Out, serta laporan selisih kas seluruh shift.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => fetchShifts(meta.current_page)}
            loading={loading}
            icon={<RefreshCw className="w-3.5 h-3.5" />}
          >
            Segarkan
          </Button>
          <Button
            variant={activeShift ? 'primary' : 'success'}
            size="sm"
            onClick={onOpenShiftModal}
            icon={activeShift ? <Wallet className="w-4 h-4" /> : <Unlock className="w-4 h-4" />}
          >
            {activeShift ? 'Kelola Kas / Tutup Shift Aktif' : 'Buka Shift Kasir Baru'}
          </Button>
        </div>
      </div>

      {/* Active Shift Live KPI Cards */}
      {activeShift && liveSummary && (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
          <StatCard
            label="Modal Kas Awal (Opening)"
            value={formatIDR(liveSummary.opening_cash)}
            subtext={`Dibuka: ${formatDateTime(activeShift.opened_at)}`}
            accent="blue"
            icon={<Unlock className="w-5 h-5" />}
          />
          <StatCard
            label="Penjualan Tunai Shift Ini"
            value={`+${formatIDR(liveSummary.total_cash_sales)}`}
            subtext={`Non-Tunai: ${formatIDR(liveSummary.total_non_cash_sales)}`}
            accent="emerald"
            icon={<Wallet className="w-5 h-5" />}
          />
          <StatCard
            label="Mutasi Paid-In / Paid-Out"
            value={`+${formatIDR(liveSummary.total_paid_in)}`}
            subtext={`Paid-Out: -${formatIDR(liveSummary.total_paid_out)}`}
            accent="amber"
            icon={<ArrowDownLeft className="w-5 h-5" />}
          />
          <StatCard
            label="Saldo Kas Diharapkan di Laci"
            value={formatIDR(liveSummary.expected_cash)}
            subtext={`${liveSummary.transaction_count} transaksi pada shift ini`}
            accent="violet"
            icon={<Lock className="w-5 h-5" />}
          />
        </div>
      )}

      {/* Historical Shifts Table */}
      <Card padding="none" className="overflow-hidden">
        <div className="p-4 border-b border-slate-200 flex items-center justify-between">
          <div>
            <h3 className="text-sm font-bold text-slate-900">
              Riwayat Shift &amp; Laporan Selisih Kas Outlet
            </h3>
            <p className="text-xs text-slate-500">
              Daftar seluruh shift kasir beserta perbandingan Kas Diharapkan vs Kas Aktual Fisik
            </p>
          </div>
        </div>

        {shifts.length === 0 && !loading ? (
          <EmptyState
            icon={<Wallet className="w-6 h-6" />}
            title="Belum Ada Riwayat Shift"
            description="Buka shift kasir untuk mulai mencatat saldo kas laci dan transaksi."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                  <th className="py-3 px-4">No. Shift</th>
                  <th className="py-3 px-4">Kasir &amp; Terminal</th>
                  <th className="py-3 px-4">Waktu Buka / Tutup</th>
                  <th className="py-3 px-4 text-right">Kas Awal</th>
                  <th className="py-3 px-4 text-right">Kas Diharapkan</th>
                  <th className="py-3 px-4 text-right">Kas Aktual</th>
                  <th className="py-3 px-4 text-right">Selisih (Discrepancy)</th>
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4 text-right">Detail</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {shifts.map((sh) => {
                  const diff = sh.cash_difference !== null && sh.cash_difference !== undefined
                    ? parseFloat(sh.cash_difference)
                    : null;

                  return (
                    <tr key={sh.id} className="hover:bg-slate-50/80">
                      <td className="py-3 px-4 font-mono font-bold text-blue-600">
                        {sh.shift_number}
                      </td>
                      <td className="py-3 px-4">
                        <div className="font-bold text-slate-900">
                          {sh.cashier?.name ?? `User #${sh.user_id}`}
                        </div>
                        <div className="text-[11px] text-slate-500 font-mono">
                          {sh.terminal?.code ?? `Terminal #${sh.pos_terminal_id}`}
                        </div>
                      </td>
                      <td className="py-3 px-4 text-[11px] text-slate-600">
                        <div>Buka: {formatDateTime(sh.opened_at)}</div>
                        {sh.closed_at && (
                          <div className="text-slate-400">
                            Tutup: {formatDateTime(sh.closed_at)}
                          </div>
                        )}
                      </td>
                      <td className="py-3 px-4 text-right font-semibold tabular-nums">
                        {formatIDR(sh.opening_cash)}
                      </td>
                      <td className="py-3 px-4 text-right font-semibold tabular-nums">
                        {sh.expected_cash !== null && sh.expected_cash !== undefined
                          ? formatIDR(sh.expected_cash)
                          : '-'}
                      </td>
                      <td className="py-3 px-4 text-right font-bold text-slate-900 tabular-nums">
                        {sh.actual_cash !== null && sh.actual_cash !== undefined
                          ? formatIDR(sh.actual_cash)
                          : '-'}
                      </td>
                      <td className="py-3 px-4 text-right font-bold tabular-nums">
                        {diff === null ? (
                          <span className="text-slate-400">-</span>
                        ) : diff === 0 ? (
                          <span className="text-emerald-600">Sesuai (Rp 0)</span>
                        ) : (
                          <span className={diff > 0 ? 'text-blue-600' : 'text-rose-600'}>
                            {diff > 0 ? '+' : ''}
                            {formatIDR(diff)}
                          </span>
                        )}
                      </td>
                      <td className="py-3 px-4">
                        <Badge variant={sh.status === 'open' ? 'success' : 'neutral'} dot>
                          {sh.status === 'open' ? 'TERBUKA' : 'DITUTUP'}
                        </Badge>
                      </td>
                      <td className="py-3 px-4 text-right">
                        <Button
                          variant="outline"
                          size="xs"
                          icon={<Eye className="w-3.5 h-3.5" />}
                          onClick={() => handleInspectShift(sh.id)}
                        >
                          Rincian
                        </Button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        <Pagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          onPageChange={fetchShifts}
        />
      </Card>

      {/* Shift Detail & Discrepancy Modal */}
      <Modal
        isOpen={selectedShift !== null}
        onClose={() => setSelectedShift(null)}
        title={
          selectedShift
            ? `Rincian Shift • ${selectedShift.shift.shift_number}`
            : 'Rincian Shift'
        }
        subtitle={
          selectedShift
            ? `Kasir: ${selectedShift.shift.cashier?.name ?? '-'} • Terminal: ${selectedShift.shift.terminal?.code ?? '-'}`
            : undefined
        }
        maxWidth="2xl"
      >
        {selectedShift && (
          <div className="space-y-4 text-xs">
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Modal Kas Awal</span>
                <div className="text-sm font-bold text-slate-900 mt-1 tabular-nums">
                  {formatIDR(selectedShift.summary.opening_cash)}
                </div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Penjualan Tunai</span>
                <div className="text-sm font-bold text-emerald-600 mt-1 tabular-nums">
                  +{formatIDR(selectedShift.summary.total_cash_sales)}
                </div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Penjualan Non-Tunai</span>
                <div className="text-sm font-bold text-blue-600 mt-1 tabular-nums">
                  {formatIDR(selectedShift.summary.total_non_cash_sales)}
                </div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Total Paid-In / Paid-Out</span>
                <div className="text-sm font-bold text-slate-900 mt-1 tabular-nums">
                  +{formatIDR(selectedShift.summary.total_paid_in)} / -
                  {formatIDR(selectedShift.summary.total_paid_out)}
                </div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Kas Diharapkan (Expected)</span>
                <div className="text-sm font-bold text-slate-900 mt-1 tabular-nums">
                  {formatIDR(selectedShift.summary.expected_cash)}
                </div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Kas Fisik Aktual</span>
                <div className="text-sm font-bold text-blue-600 mt-1 tabular-nums">
                  {selectedShift.summary.actual_cash !== null
                    ? formatIDR(selectedShift.summary.actual_cash)
                    : 'Masih Terbuka'}
                </div>
              </div>
            </div>

            {/* Cash Movements */}
            <div className="border border-slate-200 rounded-xl overflow-hidden">
              <div className="px-4 py-2.5 bg-slate-50 border-b border-slate-200 font-bold text-slate-800">
                Riwayat Mutasi Kas (Paid-In &amp; Paid-Out)
              </div>
              <div className="divide-y divide-slate-100">
                {!selectedShift.shift.cash_movements ||
                selectedShift.shift.cash_movements.length === 0 ? (
                  <div className="p-4 text-center text-slate-400">
                    Tidak ada mutasi kas manual pada shift ini.
                  </div>
                ) : (
                  selectedShift.shift.cash_movements.map((cm) => (
                    <div key={cm.id} className="px-4 py-2.5 flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        {cm.type === 'paid_in' ? (
                          <ArrowDownLeft className="w-4 h-4 text-emerald-600" />
                        ) : (
                          <ArrowUpRight className="w-4 h-4 text-amber-600" />
                        )}
                        <div>
                          <div className="font-semibold text-slate-900">{cm.reason}</div>
                          <div className="text-[10px] text-slate-400">
                            {formatDateTime(cm.created_at)}
                          </div>
                        </div>
                      </div>
                      <span
                        className={`font-bold tabular-nums ${
                          cm.type === 'paid_in' ? 'text-emerald-600' : 'text-amber-600'
                        }`}
                      >
                        {cm.type === 'paid_in' ? '+' : '-'}
                        {formatIDR(cm.amount)}
                      </span>
                    </div>
                  ))
                )}
              </div>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
};

