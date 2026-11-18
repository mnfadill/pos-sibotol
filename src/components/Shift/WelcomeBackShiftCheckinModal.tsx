import React, { useState, useEffect } from 'react';
import {
  Sun,
  Sunset,
  Moon,
  Clock,
  Calendar,
  AlertTriangle,
  UserCheck,
  Building2,
  ShieldAlert,
} from 'lucide-react';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import { Badge } from '../ui/Badge';
import { useToast } from '../ui/ToastProvider';
import { api, extractErrorMessage } from '../../lib/api';
import type { OperationalShift, UserSession } from '../../types/pos';

interface Props {
  isOpen: boolean;
  session: UserSession;
  onSuccess: () => void;
  onClose?: () => void;
  isMandatory?: boolean;
}

export function WelcomeBackShiftCheckinModal({
  isOpen,
  session,
  onSuccess,
  onClose,
  isMandatory = true,
}: Props) {
  const { toast } = useToast();
  const [shifts, setShifts] = useState<OperationalShift[]>([]);
  const [selectedShiftId, setSelectedShiftId] = useState<number | null>(null);

  // Default to today in YYYY-MM-DD
  const getTodayStr = () => {
    const d = new Date();
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  };

  const [workDate, setWorkDate] = useState<string>(getTodayStr());
  const [backdateReason, setBackdateReason] = useState<string>('');
  const [notes, setNotes] = useState<string>('');
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [loadingShifts, setLoadingShifts] = useState<boolean>(false);

  const todayStr = getTodayStr();
  const isBackdated = workDate < todayStr;
  const isFutureDate = workDate > todayStr;

  useEffect(() => {
    if (isOpen) {
      if (session.operational_shifts && session.operational_shifts.length > 0) {
        setShifts(session.operational_shifts);
        if (!selectedShiftId) {
          setSelectedShiftId(session.operational_shifts[0].id);
        }
      } else {
        fetchShifts();
      }
      setWorkDate(getTodayStr());
      setBackdateReason('');
      setNotes('');
    }
  }, [isOpen]);

  const fetchShifts = async () => {
    setLoadingShifts(true);
    try {
      const res = await api.get<{ shifts: OperationalShift[] }>('/employee-shifts/catalogue');
      setShifts(res.data.shifts);
      if (res.data.shifts.length > 0 && !selectedShiftId) {
        setSelectedShiftId(res.data.shifts[0].id);
      }
    } catch {
      // Fallback defaults
      setShifts([
        { id: 1, name: 'Shift Pagi', code: 'PAGI', start_time: '07:00', end_time: '15:00', is_overnight: false, display_order: 1 },
        { id: 2, name: 'Shift Sore', code: 'SORE', start_time: '15:00', end_time: '23:00', is_overnight: false, display_order: 2 },
        { id: 3, name: 'Shift Malam', code: 'MALAM', start_time: '23:00', end_time: '07:00', is_overnight: true, display_order: 3 },
      ]);
      setSelectedShiftId(1);
    } finally {
      setLoadingShifts(false);
    }
  };

  const handleCheckin = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!selectedShiftId) {
      toast({
        type: 'error',
        title: 'Pilih Shift Kerja',
        description: 'Silakan pilih salah satu shift kerja yang tersedia.',
      });
      return;
    }

    if (isFutureDate) {
      toast({
        type: 'error',
        title: 'Tanggal Tidak Valid',
        description: 'Tanggal kerja tidak boleh di masa mendatang.',
      });
      return;
    }

    if (isBackdated && backdateReason.trim().length < 5) {
      toast({
        type: 'error',
        title: 'Alasan Backdate Wajib Diisi',
        description: 'Mohon isi alasan backdate minimal 5 karakter.',
      });
      return;
    }

    setSubmitting(true);
    try {
      await api.post('/employee-shifts/check-in', {
        shift_id: selectedShiftId,
        work_date: workDate,
        is_backdated: isBackdated,
        backdate_reason: isBackdated ? backdateReason.trim() : null,
        notes: notes.trim() || null,
      });

      toast({
        type: 'success',
        title: 'Check-in Shift Berhasil',
        description: `Identitas operasional shift Anda telah aktif. Seluruh aktivitas transaksi akan otomatis tercatat.`,
      });

      onSuccess();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal Melakukan Check-in Shift',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmitting(false);
    }
  };

  const formatFriendlyDate = (dateStr: string) => {
    try {
      const parts = dateStr.split('-');
      if (parts.length === 3) {
        const d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        return d.toLocaleDateString('id-ID', {
          weekday: 'long',
          day: 'numeric',
          month: 'long',
          year: 'numeric',
        });
      }
    } catch {
      // fallback
    }
    return dateStr;
  };

  const getShiftIcon = (code: string) => {
    const c = code.toUpperCase();
    if (c.includes('PAGI') || c.includes('MORNING')) {
      return <Sun className="w-5 h-5 text-amber-500" />;
    }
    if (c.includes('SORE') || c.includes('SIANG') || c.includes('AFTERNOON')) {
      return <Sunset className="w-5 h-5 text-orange-500" />;
    }
    return <Moon className="w-5 h-5 text-indigo-500" />;
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={isMandatory ? () => {} : (onClose || (() => {}))}
      title="Welcome Back!"
      subtitle="Silakan pilih shift kerja sebelum memulai aktivitas operasional."
      maxWidth="lg"
      footer={null}
    >
      <form onSubmit={handleCheckin} className="space-y-5">
        {/* Employee & Outlet Identity Card */}
        <div className="bg-slate-50 border border-slate-200 rounded-xl p-4 flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-sm">
              <UserCheck className="w-5 h-5" />
            </div>
            <div>
              <div className="text-sm font-bold text-slate-900">{session.user.name}</div>
              <div className="text-xs text-slate-500 flex items-center gap-2">
                <span>@{session.user.username}</span>
                <span>•</span>
                <span className="capitalize">{session.user.role_display}</span>
              </div>
            </div>
          </div>

          <div className="text-right">
            <div className="text-xs text-slate-500 font-medium">Outlet Aktif</div>
            <div className="text-sm font-bold text-slate-800 flex items-center gap-1.5 justify-end">
              <Building2 className="w-4 h-4 text-slate-400" />
              {session.active_outlet?.name ?? 'Pilih Outlet'}
            </div>
          </div>
        </div>

        {/* Shift Selection Cards */}
        <div>
          <label className="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-2">
            Pilih Shift Kerja <span className="text-rose-500">*</span>
          </label>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            {shifts.map((s) => {
              const isSelected = selectedShiftId === s.id;
              return (
                <button
                  type="button"
                  key={s.id}
                  onClick={() => setSelectedShiftId(s.id)}
                  className={`p-3.5 rounded-xl border text-left transition-all relative ${
                    isSelected
                      ? 'border-blue-600 bg-blue-50/50 shadow-sm ring-2 ring-blue-500/20'
                      : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/60'
                  }`}
                >
                  <div className="flex items-center justify-between mb-1.5">
                    <span className="font-bold text-sm text-slate-900">{s.name}</span>
                    {getShiftIcon(s.code)}
                  </div>
                  <div className="text-xs font-mono text-slate-600 flex items-center gap-1">
                    <Clock className="w-3.5 h-3.5 text-slate-400" />
                    {s.start_time} – {s.end_time}
                  </div>
                  {s.is_overnight && (
                    <div className="mt-1.5">
                      <Badge variant="warning">Lintas Hari</Badge>
                    </div>
                  )}
                </button>
              );
            })}
          </div>
        </div>

        {/* Work Date Selection */}
        <div>
          <div className="flex items-center justify-between mb-1.5">
            <label className="block text-xs font-bold uppercase tracking-wider text-slate-600">
              Tanggal Kerja (Work Date) <span className="text-rose-500">*</span>
            </label>
            <span className="text-xs font-semibold text-blue-600">
              {formatFriendlyDate(workDate)}
            </span>
          </div>

          <div className="relative">
            <input
              type="date"
              max={todayStr}
              value={workDate}
              onChange={(e) => setWorkDate(e.target.value)}
              className="w-full h-10 px-3 pl-10 border border-slate-300 rounded-lg text-sm font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 bg-white"
              required
            />
            <Calendar className="w-4 h-4 text-slate-400 absolute left-3 top-3 pointer-events-none" />
          </div>
          <div className="text-[11px] text-slate-400 mt-1">
            Default mengikuti tanggal hari ini. Anda dapat memilih tanggal lampau jika terlupa mencatat shift sebelumnya.
          </div>
        </div>

        {/* Backdate Warning & Reason Requirement */}
        {isBackdated && (
          <div className="bg-amber-50 border border-amber-200 rounded-xl p-3.5 space-y-2.5">
            <div className="flex items-start gap-2.5">
              <AlertTriangle className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
              <div>
                <div className="text-xs font-bold text-amber-900">
                  Mode Backdate Check-in Aktif
                </div>
                <div className="text-xs text-amber-700">
                  Anda memilih tanggal lampau ({formatFriendlyDate(workDate)}). Mohon berikan alasan pencatatan susulan agar tercatat dalam riwayat audit.
                </div>
              </div>
            </div>

            <div>
              <label className="block text-xs font-bold text-amber-950 mb-1">
                Alasan Backdate <span className="text-rose-600">*</span>
              </label>
              <textarea
                rows={2}
                value={backdateReason}
                onChange={(e) => setBackdateReason(e.target.value)}
                placeholder="Contoh: Terlupa check-in kemarin karena kendala jaringan atau pergantian perangkat..."
                className="w-full text-xs p-2.5 border border-amber-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/30"
                required
              />
            </div>
          </div>
        )}

        {isFutureDate && (
          <div className="bg-rose-50 border border-rose-200 text-rose-700 p-3 rounded-xl text-xs flex items-center gap-2">
            <ShieldAlert className="w-4 h-4 text-rose-600 shrink-0" />
            <span>Tanggal kerja tidak boleh di masa mendatang.</span>
          </div>
        )}

        {/* Action Button */}
        <div className="pt-2 flex items-center justify-end gap-2.5 border-t border-slate-100">
          {!isMandatory && onClose && (
            <Button variant="outline" type="button" onClick={onClose} disabled={submitting}>
              Batal
            </Button>
          )}
          <Button
            variant="primary"
            type="submit"
            loading={submitting}
            disabled={!selectedShiftId || isFutureDate || (isBackdated && backdateReason.trim().length < 5)}
            className="w-full sm:w-auto px-6 h-10 font-bold"
          >
            Check-in Shift Kerja
          </Button>
        </div>
      </form>
    </Modal>
  );
}
