import React, { useEffect, useState } from 'react';
import {
  AlertTriangle,
  ArrowDownLeft,
  ArrowUpRight,
  Calendar,
  CheckCircle2,
  Clock,
  Lock,
  Sparkles,
  Unlock,
  UserCheck,
  Wallet,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime, formatIDR } from '../../lib/format';
import type { CashierShift, ShiftLiveSummary, UserSession } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import { useToast } from '../ui/ToastProvider';

interface Props {
  isOpen: boolean;
  activeShift: CashierShift | null;
  mandatoryOpen?: boolean;
  user?: UserSession['user'] | null;
  onClose: () => void;
  onShiftChanged: () => void;
}

const PRESET_OPENING_AMOUNTS = [300000, 500000, 1000000, 1500000];

export const ShiftManagementModal: React.FC<Props> = ({
  isOpen,
  activeShift,
  mandatoryOpen = false,
  user,
  onClose,
  onShiftChanged,
}) => {
  const { toast } = useToast();
  const [tab, setTab] = useState<'summary' | 'movement' | 'close'>('summary');
  const [shiftDetail, setShiftDetail] = useState<CashierShift | null>(activeShift);
  const [summary, setSummary] = useState<ShiftLiveSummary | null>(
    activeShift?.live_summary ?? null,
  );
  const [openingCash, setOpeningCash] = useState<string>('500000');
  const [openingNotes, setOpeningNotes] = useState<string>('');

  const getTodayDateString = () => {
    const d = new Date();
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  };

  const getTodayTimeString = () => {
    const d = new Date();
    const hours = String(d.getHours()).padStart(2, '0');
    const minutes = String(d.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
  };

  const [shiftDate, setShiftDate] = useState<string>(getTodayDateString());
  const [shiftTime, setShiftTime] = useState<string>(getTodayTimeString());

  // Cash Movement state
  const [movementType, setMovementType] = useState<'paid_in' | 'paid_out'>('paid_in');
  const [movementAmount, setMovementAmount] = useState<string>('');
  const [movementReason, setMovementReason] = useState<string>('');
  const [movementNotes, setMovementNotes] = useState<string>('');

  // Close shift state
  const [actualCash, setActualCash] = useState<string>('');
  const [closingNotes, setClosingNotes] = useState<string>('');
  const [discrepancyReport, setDiscrepancyReport] = useState<Record<string, unknown> | null>(null);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (isOpen && activeShift) {
      fetchCurrentShift();
    } else {
      setShiftDetail(null);
      setSummary(null);
      if (isOpen && !activeShift) {
        setShiftDate(getTodayDateString());
        setShiftTime(getTodayTimeString());
      }
    }
  }, [isOpen, activeShift?.id]);

  const fetchCurrentShift = async () => {
    try {
      const res = await api.get<{ shift: CashierShift | null; summary: ShiftLiveSummary | null }>(
        '/shifts/current',
      );
      setShiftDetail(res.data.shift);
      setSummary(res.data.summary);
      if (res.data.summary) {
        setActualCash(String(Math.round(parseFloat(res.data.summary.expected_cash))));
      }
    } catch {
      // ignore
    }
  };

  const handleOpenShift = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const formattedOpenedAt = `${shiftDate} ${shiftTime}:00`;
      await api.post('/shifts/open', {
        opening_cash: parseFloat(openingCash || '0'),
        opening_notes: openingNotes || null,
        opened_at: formattedOpenedAt,
        shift_date: shiftDate,
      });
      setDiscrepancyReport(null);
      toast({
        type: 'success',
        title: 'Check-in Shift Berhasil',
        description: `Shift tanggal ${shiftDate} pukul ${shiftTime} WIB dengan modal kas awal ${formatIDR(openingCash)} telah dicatat.`,
      });
      onShiftChanged();
      onClose();
    } catch (err) {
      setError(extractErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  const handleRecordMovement = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const res = await api.post<{
        shift: CashierShift;
        summary: ShiftLiveSummary;
      }>('/shifts/cash-movements', {
        type: movementType,
        amount: parseFloat(movementAmount || '0'),
        reason: movementReason,
        notes: movementNotes || null,
      });
      setShiftDetail(res.data.shift);
      setSummary(res.data.summary);
      setMovementAmount('');
      setMovementReason('');
      setMovementNotes('');
      setTab('summary');
      toast({
        type: 'success',
        title:
          movementType === 'paid_in'
            ? 'Kas Masuk (Paid-In) Tercatat'
            : 'Kas Keluar (Paid-Out) Tercatat',
        description: 'Saldo kas diharapkan di laci otomatis diperbarui.',
      });
      onShiftChanged();
    } catch (err) {
      setError(extractErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  const handleCloseShift = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const res = await api.post<{
        shift: CashierShift;
        discrepancy_report: Record<string, unknown>;
      }>('/shifts/close', {
        actual_cash: parseFloat(actualCash || '0'),
        closing_notes: closingNotes || null,
      });
      setDiscrepancyReport(res.data.discrepancy_report);
      toast({
        type: 'info',
        title: 'Shift Kasir Telah Ditutup',
        description: 'Laporan rekonsiliasi selisih kas telah dibuat.',
      });
      onShiftChanged();
    } catch (err) {
      setError(extractErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  const expectedNum = parseFloat(summary?.expected_cash ?? '0');
  const actualNum = parseFloat(actualCash || '0');
  const liveDifference = actualNum - expectedNum;

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      hideCloseButton={mandatoryOpen && !activeShift && !discrepancyReport}
      title={
        discrepancyReport
          ? 'Laporan Rekonsiliasi & Selisih Kas (Shift Closed)'
          : activeShift
            ? `Manajemen Shift Kasir • ${activeShift.shift_number}`
            : user?.role === 'cashier' || (!user?.is_super_admin && user?.role !== 'owner')
              ? 'Check-in Shift Operasional Pegawai'
              : 'Buka Shift Kasir (Opening Cash Drawer)'
      }
      subtitle={
        activeShift
          ? `Dibuka pada ${formatDateTime(activeShift.opened_at)}`
          : 'Pegawai wajib melakukan check-in shift serta menentukan tanggal dan saldo modal kas laci'
      }
      icon={<Wallet className="w-5 h-5" />}
      maxWidth="2xl"
    >
      <div className="space-y-5">
        {error && (
          <div className="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
            <AlertTriangle className="w-4 h-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        {/* CASE 1: Discrepancy Report After Closing Shift */}
        {discrepancyReport ? (
          <div className="space-y-4">
            <div className="p-4 rounded-xl bg-emerald-50 border border-emerald-200 flex items-center gap-3">
              <CheckCircle2 className="w-6 h-6 text-emerald-600 shrink-0" />
              <div className="text-xs">
                <div className="font-bold text-slate-900">
                  Shift {String(discrepancyReport.shift_number)} Telah Ditutup &amp; Direkonsiliasi
                </div>
                <div className="text-emerald-700">
                  Audit Log <code className="font-mono">SHIFT_CLOSED</code> dan event Outbox telah disimpan permanen.
                </div>
              </div>
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Modal Kas Awal</span>
                <div className="text-sm font-bold text-slate-900 mt-1 tabular-nums">
                  {formatIDR(String(discrepancyReport.opening_cash))}
                </div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Penjualan Tunai</span>
                <div className="text-sm font-bold text-emerald-600 mt-1 tabular-nums">
                  +{formatIDR(String(discrepancyReport.total_cash_sales))}
                </div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Penjualan Non-Tunai</span>
                <div className="text-sm font-bold text-blue-600 mt-1 tabular-nums">
                  {formatIDR(String(discrepancyReport.total_non_cash_sales))}
                </div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Paid-In / Paid-Out</span>
                <div className="text-sm font-bold text-slate-900 mt-1 tabular-nums">
                  +{formatIDR(String(discrepancyReport.total_paid_in))} / -
                  {formatIDR(String(discrepancyReport.total_paid_out))}
                </div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Kas Diharapkan (Expected)</span>
                <div className="text-sm font-bold text-slate-900 mt-1 tabular-nums">
                  {formatIDR(String(discrepancyReport.expected_cash))}
                </div>
              </div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-slate-500">Kas Fisik Aktual</span>
                <div className="text-sm font-bold text-blue-600 mt-1 tabular-nums">
                  {formatIDR(String(discrepancyReport.actual_cash))}
                </div>
              </div>
            </div>

            <div
              className={`p-4 rounded-xl border flex items-center justify-between ${
                parseFloat(String(discrepancyReport.cash_difference)) === 0
                  ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                  : 'bg-rose-50 border-rose-200 text-rose-800'
              }`}
            >
              <span className="text-xs font-bold uppercase tracking-wider">
                Selisih Kas (Cash Discrepancy)
              </span>
              <span className="text-lg font-extrabold tabular-nums">
                {formatIDR(String(discrepancyReport.cash_difference))}
              </span>
            </div>

            <Button
              variant="primary"
              fullWidth
              onClick={() => {
                setDiscrepancyReport(null);
                onClose();
              }}
            >
              Selesai &amp; Tutup Laporan
            </Button>
          </div>
        ) : !activeShift ? (
          /* CASE 2: No Open Shift -> Open Shift / Check-in Form */
          <form onSubmit={handleOpenShift} className="space-y-4">
            {/* Employee Information Card */}
            {user && (
              <div className="p-3.5 rounded-xl bg-blue-50/80 border border-blue-200/80 flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-black text-sm shrink-0">
                    {user.name.slice(0, 2).toUpperCase()}
                  </div>
                  <div>
                    <div className="text-xs font-bold text-slate-900 flex items-center gap-2">
                      <span>{user.name}</span>
                      <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-md bg-blue-100 text-blue-800">
                        {user.role_display || user.role}
                      </span>
                    </div>
                    <div className="text-[11px] text-slate-600 mt-0.5">
                      Check-in shift operasional pegawai • Pastikan tanggal dan saldo modal kas sesuai.
                    </div>
                  </div>
                </div>
                <div className="hidden sm:flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white border border-blue-200 text-blue-700 text-xs font-bold">
                  <UserCheck className="w-3.5 h-3.5" />
                  <span>Akun Pegawai</span>
                </div>
              </div>
            )}

            {/* Date and Time Configuration Card (Tanggal, Bulan, Tahun) */}
            <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-3">
              <div className="flex items-center justify-between">
                <div className="text-xs font-bold uppercase tracking-wider text-slate-700 flex items-center gap-1.5">
                  <Calendar className="w-4 h-4 text-blue-600" />
                  <span>Atur Tanggal &amp; Waktu Check-in Shift</span>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    setShiftDate(getTodayDateString());
                    setShiftTime(getTodayTimeString());
                  }}
                  className="text-[11px] font-bold text-blue-600 hover:text-blue-800 flex items-center gap-1 cursor-pointer transition"
                >
                  <Clock className="w-3.5 h-3.5" />
                  <span>Set Tanggal &amp; Jam Sekarang</span>
                </button>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-[11px] font-bold text-slate-600 mb-1">
                    Tanggal Check-in (Tanggal / Bulan / Tahun)
                  </label>
                  <input
                    type="date"
                    required
                    value={shiftDate}
                    onChange={(e) => setShiftDate(e.target.value)}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-300 text-sm font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-[11px] font-bold text-slate-600 mb-1">
                    Waktu / Jam Check-in
                  </label>
                  <input
                    type="time"
                    required
                    value={shiftTime}
                    onChange={(e) => setShiftTime(e.target.value)}
                    className="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-300 text-sm font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>

              <div className="text-[11px] text-slate-500 flex items-center gap-1.5 pt-0.5">
                <span className="w-2 h-2 rounded-full bg-emerald-500" />
                <span>
                  Efektif Shift:{' '}
                  <strong className="text-slate-800">
                    {(() => {
                      try {
                        const parsed = new Date(`${shiftDate}T${shiftTime}`);
                        if (!isNaN(parsed.getTime())) {
                          return `${parsed.toLocaleDateString('id-ID', {
                            weekday: 'long',
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                          })}, ${shiftTime} WIB`;
                        }
                      } catch {
                        // ignore
                      }
                      return `${shiftDate} ${shiftTime}`;
                    })()}
                  </strong>
                </span>
              </div>
            </div>

            {/* Opening Cash Input */}
            <div>
              <label className="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">
                Saldo Kas Awal Laci (Opening Cash Balance — IDR)
              </label>
              <input
                type="number"
                min={0}
                step="1000"
                required
                value={openingCash}
                onChange={(e) => setOpeningCash(e.target.value)}
                className="w-full px-4 py-3 rounded-xl bg-white border border-slate-300 text-lg font-extrabold text-slate-900 tabular-nums focus:outline-none focus:border-blue-600"
              />
              <div className="flex flex-wrap gap-2 mt-2.5">
                {PRESET_OPENING_AMOUNTS.map((amt) => (
                  <button
                    type="button"
                    key={amt}
                    onClick={() => setOpeningCash(String(amt))}
                    className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition cursor-pointer ${
                      Number(openingCash) === amt
                        ? 'bg-blue-600 border-blue-600 text-white'
                        : 'bg-slate-50 border-slate-200 text-slate-700 hover:bg-slate-100'
                    }`}
                  >
                    {formatIDR(amt)}
                  </button>
                ))}
              </div>
            </div>

            {/* Notes Input */}
            <div>
              <label className="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1.5">
                Catatan Pembukaan Shift (Opsional)
              </label>
              <input
                type="text"
                value={openingNotes}
                onChange={(e) => setOpeningNotes(e.target.value)}
                placeholder="Contoh: Modal kasir pagi pecahan Rp 50rb &amp; Rp 10rb"
                className="w-full px-3.5 py-2.5 rounded-lg bg-white border border-slate-300 text-xs text-slate-900 focus:outline-none focus:border-blue-600"
              />
            </div>

            <Button
              type="submit"
              variant="primary"
              size="lg"
              fullWidth
              loading={loading}
              icon={<Unlock className="w-4 h-4" />}
            >
              Check-in &amp; Buka Shift Kasir ({formatIDR(openingCash || 0)})
            </Button>
          </form>
        ) : (
          /* CASE 3: Active Shift Open -> Tabs */
          <div className="space-y-4">
            <div className="flex gap-1.5 bg-slate-100 p-1 rounded-xl border border-slate-200">
              <button
                type="button"
                onClick={() => setTab('summary')}
                className={`flex-1 py-2 rounded-lg text-xs font-bold transition cursor-pointer ${
                  tab === 'summary'
                    ? 'bg-white text-blue-600 shadow-2xs'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                Ringkasan Kas Real-Time
              </button>
              <button
                type="button"
                onClick={() => setTab('movement')}
                className={`flex-1 py-2 rounded-lg text-xs font-bold transition cursor-pointer ${
                  tab === 'movement'
                    ? 'bg-white text-blue-600 shadow-2xs'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                Paid-In / Paid-Out Kas
              </button>
              <button
                type="button"
                onClick={() => setTab('close')}
                className={`flex-1 py-2 rounded-lg text-xs font-bold transition cursor-pointer ${
                  tab === 'close'
                    ? 'bg-rose-600 text-white shadow-2xs'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                Tutup Shift &amp; Rekonsiliasi
              </button>
            </div>

            {tab === 'summary' && summary && (
              <div className="space-y-4">
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
                  <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200">
                    <span className="text-slate-500">Modal Kas Awal</span>
                    <div className="text-sm font-bold text-slate-900 mt-1 tabular-nums">
                      {formatIDR(summary.opening_cash)}
                    </div>
                  </div>
                  <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200">
                    <span className="text-slate-500">Penjualan Tunai (Cash)</span>
                    <div className="text-sm font-bold text-emerald-600 mt-1 tabular-nums">
                      +{formatIDR(summary.total_cash_sales)}
                    </div>
                  </div>
                  <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200">
                    <span className="text-slate-500">Penjualan Non-Tunai</span>
                    <div className="text-sm font-bold text-blue-600 mt-1 tabular-nums">
                      {formatIDR(summary.total_non_cash_sales)}
                    </div>
                  </div>
                  <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200">
                    <span className="text-slate-500">Kas Masuk (Paid-In)</span>
                    <div className="text-sm font-bold text-emerald-600 mt-1 tabular-nums">
                      +{formatIDR(summary.total_paid_in)}
                    </div>
                  </div>
                  <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200">
                    <span className="text-slate-500">Kas Keluar (Paid-Out)</span>
                    <div className="text-sm font-bold text-amber-600 mt-1 tabular-nums">
                      -{formatIDR(summary.total_paid_out)}
                    </div>
                  </div>
                  <div className="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200">
                    <span className="text-emerald-800 font-semibold">
                      Kas Diharapkan di Laci
                    </span>
                    <div className="text-base font-extrabold text-emerald-700 mt-1 tabular-nums">
                      {formatIDR(summary.expected_cash)}
                    </div>
                  </div>
                </div>

                {/* Cash Movements List */}
                <div className="border border-slate-200 rounded-xl overflow-hidden">
                  <div className="px-4 py-2.5 bg-slate-50 border-b border-slate-200 text-xs font-bold text-slate-700">
                    Mutasi Kas Shift Ini (Paid-In &amp; Paid-Out)
                  </div>
                  <div className="divide-y divide-slate-100 max-h-44 overflow-y-auto text-xs">
                    {!shiftDetail?.cash_movements || shiftDetail.cash_movements.length === 0 ? (
                      <div className="p-4 text-center text-slate-400">
                        Belum ada mutasi Paid-In atau Paid-Out pada shift ini.
                      </div>
                    ) : (
                      shiftDetail.cash_movements.map((cm) => (
                        <div key={cm.id} className="px-4 py-2.5 flex items-center justify-between">
                          <div className="flex items-center gap-2">
                            <Badge variant={cm.type === 'paid_in' ? 'success' : 'warning'}>
                              {cm.type === 'paid_in' ? 'PAID-IN' : 'PAID-OUT'}
                            </Badge>
                            <span className="text-slate-800 font-semibold">{cm.reason}</span>
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

            {tab === 'movement' && (
              <form onSubmit={handleRecordMovement} className="space-y-4">
                <div className="grid grid-cols-2 gap-3">
                  <button
                    type="button"
                    onClick={() => setMovementType('paid_in')}
                    className={`p-3 rounded-xl border text-xs font-bold flex items-center justify-center gap-2 cursor-pointer ${
                      movementType === 'paid_in'
                        ? 'bg-emerald-50 border-emerald-600 text-emerald-800'
                        : 'bg-white border-slate-200 text-slate-600'
                    }`}
                  >
                    <ArrowDownLeft className="w-4 h-4 text-emerald-600" />
                    <span>Paid-In (Tambah Kas Laci)</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => setMovementType('paid_out')}
                    className={`p-3 rounded-xl border text-xs font-bold flex items-center justify-center gap-2 cursor-pointer ${
                      movementType === 'paid_out'
                        ? 'bg-amber-50 border-amber-600 text-amber-800'
                        : 'bg-white border-slate-200 text-slate-600'
                    }`}
                  >
                    <ArrowUpRight className="w-4 h-4 text-amber-600" />
                    <span>Paid-Out (Biaya Operasional)</span>
                  </button>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Nominal Kas (IDR)
                  </label>
                  <input
                    type="number"
                    min={1000}
                    step="500"
                    required
                    value={movementAmount}
                    onChange={(e) => setMovementAmount(e.target.value)}
                    placeholder="Contoh: 50000"
                    className="w-full px-3.5 py-2.5 rounded-lg bg-white border border-slate-300 text-sm font-bold text-slate-900"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Alasan Mutasi Kas (Wajib)
                  </label>
                  <input
                    type="text"
                    required
                    value={movementReason}
                    onChange={(e) => setMovementReason(e.target.value)}
                    placeholder={
                      movementType === 'paid_in'
                        ? 'Contoh: Tambahan uang kembalian pecahan kecil'
                        : 'Contoh: Pembelian es batu darurat & galon air'
                    }
                    className="w-full px-3.5 py-2.5 rounded-lg bg-white border border-slate-300 text-xs text-slate-900"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Catatan Tambahan (Opsional)
                  </label>
                  <input
                    type="text"
                    value={movementNotes}
                    onChange={(e) => setMovementNotes(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-xs text-slate-900"
                  />
                </div>

                <Button type="submit" variant="primary" size="lg" fullWidth loading={loading}>
                  Simpan Mutasi {movementType === 'paid_in' ? 'Paid-In' : 'Paid-Out'}
                </Button>
              </form>
            )}

            {tab === 'close' && (
              <form onSubmit={handleCloseShift} className="space-y-4">
                <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between">
                  <div>
                    <div className="text-xs text-slate-500">
                      Saldo Kas Diharapkan (Expected Cash)
                    </div>
                    <div className="text-base font-extrabold text-slate-900 tabular-nums">
                      {formatIDR(expectedNum)}
                    </div>
                  </div>
                  <div className="text-right">
                    <div className="text-xs text-slate-500">Estimasi Selisih Kas</div>
                    <div
                      className={`text-base font-extrabold tabular-nums ${
                        liveDifference === 0
                          ? 'text-emerald-600'
                          : liveDifference > 0
                            ? 'text-blue-600'
                            : 'text-rose-600'
                      }`}
                    >
                      {liveDifference > 0 ? '+' : ''}
                      {formatIDR(liveDifference)}
                    </div>
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Uang Fisik Aktual di Laci Kasir (Actual Cash — IDR)
                  </label>
                  <input
                    type="number"
                    min={0}
                    required
                    value={actualCash}
                    onChange={(e) => setActualCash(e.target.value)}
                    className="w-full px-3.5 py-2.5 rounded-lg bg-white border border-slate-300 text-base font-extrabold text-slate-900 tabular-nums"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Catatan Penutupan Shift
                  </label>
                  <input
                    type="text"
                    value={closingNotes}
                    onChange={(e) => setClosingNotes(e.target.value)}
                    placeholder="Contoh: Perhitungan fisik sesuai dengan laporan kasir"
                    className="w-full px-3.5 py-2.5 rounded-lg bg-white border border-slate-300 text-xs text-slate-900"
                  />
                </div>

                <Button
                  type="submit"
                  variant="danger"
                  size="lg"
                  fullWidth
                  loading={loading}
                  icon={<Lock className="w-4 h-4" />}
                >
                  Tutup Shift &amp; Buat Laporan Rekonsiliasi Kas
                </Button>
              </form>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
};
