import React, { useState } from 'react';
import { Clock, RefreshCw, Sun, Sunset, Moon } from 'lucide-react';
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
  onClose: () => void;
}

export function SwitchShiftModal({ isOpen, session, onSuccess, onClose }: Props) {
  const { toast } = useToast();
  const [selectedShiftId, setSelectedShiftId] = useState<number | null>(null);
  const [reason, setReason] = useState<string>('');
  const [submitting, setSubmitting] = useState<boolean>(false);

  const currentShift = session.active_employee_shift;
  const availableShifts = (session.operational_shifts ?? []).filter(
    (s) => s.id !== currentShift?.shift_id
  );

  const handleSwitch = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!selectedShiftId) {
      toast({
        type: 'error',
        title: 'Pilih Shift Baru',
        description: 'Silakan tentukan shift tujuan perpindahan.',
      });
      return;
    }

    if (reason.trim().length < 5) {
      toast({
        type: 'error',
        title: 'Alasan Wajib Diisi',
        description: 'Mohon isi alasan perpindahan shift minimal 5 karakter.',
      });
      return;
    }

    setSubmitting(true);
    try {
      await api.post('/employee-shifts/switch', {
        new_shift_id: selectedShiftId,
        reason: reason.trim(),
      });

      toast({
        type: 'success',
        title: 'Shift Berhasil Dialihkan',
        description: 'Pergantian shift telah dicatat dalam riwayat audit.',
      });

      onSuccess();
      onClose();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal Beralih Shift',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmitting(false);
    }
  };

  const getShiftIcon = (code: string) => {
    const c = code.toUpperCase();
    if (c.includes('PAGI') || c.includes('MORNING')) {
      return <Sun className="w-4 h-4 text-amber-500" />;
    }
    if (c.includes('SORE') || c.includes('SIANG') || c.includes('AFTERNOON')) {
      return <Sunset className="w-4 h-4 text-orange-500" />;
    }
    return <Moon className="w-4 h-4 text-indigo-500" />;
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Beralih Shift Kerja Operasional"
      subtitle="Pindahkan identitas operasional ke shift lain dengan pencatatan audit trail."
      maxWidth="md"
      footer={null}
    >
      <form onSubmit={handleSwitch} className="space-y-4">
        {/* Current Shift Banner */}
        <div className="bg-slate-50 border border-slate-200 rounded-lg p-3 text-xs flex items-center justify-between">
          <div>
            <div className="text-slate-500 font-medium">Shift Saat Ini:</div>
            <div className="text-sm font-bold text-slate-800">
              {currentShift?.shift_name ?? 'Belum ada shift'}
            </div>
          </div>
          <Badge variant="info">
            Tgl Kerja: {currentShift?.work_date ?? '-'}
          </Badge>
        </div>

        {/* New Shift Selection */}
        <div>
          <label className="block text-xs font-bold uppercase text-slate-600 mb-2">
            Pilih Shift Baru <span className="text-rose-500">*</span>
          </label>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
            {availableShifts.map((s) => {
              const isSelected = selectedShiftId === s.id;
              return (
                <button
                  type="button"
                  key={s.id}
                  onClick={() => setSelectedShiftId(s.id)}
                  className={`p-3 rounded-lg border text-left transition-all ${
                    isSelected
                      ? 'border-blue-600 bg-blue-50/50 ring-2 ring-blue-500/20'
                      : 'border-slate-200 hover:border-slate-300 bg-white'
                  }`}
                >
                  <div className="flex items-center justify-between">
                    <span className="font-bold text-xs text-slate-900">{s.name}</span>
                    {getShiftIcon(s.code)}
                  </div>
                  <div className="text-[11px] font-mono text-slate-500 flex items-center gap-1 mt-1">
                    <Clock className="w-3 h-3 text-slate-400" />
                    {s.start_time} - {s.end_time}
                  </div>
                </button>
              );
            })}
          </div>
        </div>

        {/* Reason Input */}
        <div>
          <label className="block text-xs font-bold text-slate-700 mb-1">
            Alasan Pergantian Shift <span className="text-rose-500">*</span>
          </label>
          <textarea
            rows={2}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Contoh: Menggantikan rekan yang izin sakit atau pergantian jadwal tim..."
            className="w-full text-xs p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/30"
            required
          />
        </div>

        <div className="pt-2 flex items-center justify-end gap-2 border-t border-slate-100">
          <Button variant="outline" type="button" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button
            variant="primary"
            type="submit"
            loading={submitting}
            disabled={!selectedShiftId || reason.trim().length < 5}
            leftIcon={<RefreshCw className="w-4 h-4" />}
          >
            Konfirmasi Pergantian
          </Button>
        </div>
      </form>
    </Modal>
  );
}
