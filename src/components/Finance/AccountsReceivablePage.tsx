import React, { useEffect, useState } from 'react';
import {
  AlertTriangle,
  CalendarClock,
  CheckCircle2,
  CreditCard,
  DollarSign,
  FileText,
  Info,
  RefreshCw,
  User,
  Clock,
  Building2,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatIDR } from '../../lib/format';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card, StatCard } from '../ui/Card';
import { Modal } from '../ui/Modal';
import { useToast } from '../ui/ToastProvider';

interface InvoiceRecord {
  id: number;
  invoice_number: string;
  status: 'unpaid' | 'partial' | 'paid' | 'overdue' | 'void';
  issue_date: string;
  due_date: string;
  grand_total: string;
  paid_total: string;
  balance_due: string;
  notes?: string | null;
  work_date?: string | null;
  created_at?: string;
  updated_at?: string;
  creator?: {
    id: number;
    name: string;
    username: string;
  } | null;
  updater?: {
    id: number;
    name: string;
    username: string;
  } | null;
  shift?: {
    id: number;
    name: string;
    code: string;
    start_time?: string;
    end_time?: string;
  } | null;
  employee_shift_checkin?: {
    id: number;
    work_date: string;
    checked_in_at: string;
    is_backdated: boolean;
    backdate_reason?: string | null;
  } | null;
  customer?: {
    id: number;
    code: string;
    name: string;
    customer_group: string;
    credit_limit: string;
    outstanding_balance: string;
  };
  outlet?: {
    id: number;
    code: string;
    name: string;
  };
  sales_order?: {
    id: number;
    so_number: string;
  } | null;
}

interface PaymentRecord {
  id: number;
  payment_number: string;
  method: string;
  amount: string;
  payment_date: string;
  reference_number?: string | null;
  customer?: { code: string; name: string };
  outlet?: { code: string; name: string };
}

interface AgingSummary {
  current_not_due: string;
  days_1_30: string;
  days_31_60: string;
  days_61_90: string;
  days_over_90: string;
  total_outstanding: string;
  overdue_total: string;
  overdue_count: number;
}

interface Props {
  activeOutletId?: number | null;
  activeOutletName?: string;
  canViewCompanyScope?: boolean;
}

export const AccountsReceivablePage: React.FC<Props> = ({
  activeOutletId,
  activeOutletName,
  canViewCompanyScope,
}) => {
  const { toast } = useToast();
  const [companyScope, setCompanyScope] = useState<boolean>(false);
  const [aging, setAging] = useState<AgingSummary | null>(null);
  const [invoices, setInvoices] = useState<InvoiceRecord[]>([]);
  const [payments, setPayments] = useState<PaymentRecord[]>([]);
  const [loading, setLoading] = useState<boolean>(true);

  // Payment Modal
  const [selectedInvoice, setSelectedInvoice] = useState<InvoiceRecord | null>(null);
  const [detailInvoice, setDetailInvoice] = useState<InvoiceRecord | null>(null);
  const [payAmount, setPayAmount] = useState<string>('');
  const [payMethod, setPayMethod] = useState<string>('bank_transfer');
  const [payRef, setPayRef] = useState<string>('');
  const [payNotes, setPayNotes] = useState<string>('');
  const [submittingPay, setSubmittingPay] = useState<boolean>(false);

  const fetchData = async () => {
    setLoading(true);
    try {
      const res = await api.get<{
        aging_summary: AgingSummary;
        invoices: InvoiceRecord[];
        payments: PaymentRecord[];
      }>('/receivables', {
        params: { company_scope: companyScope ? 1 : 0 },
      });
      setAging(res.data.aging_summary);
      setInvoices(res.data.invoices);
      setPayments(res.data.payments);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat data Piutang (AR)',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, [activeOutletId, companyScope]);

  const openPaymentModal = (inv: InvoiceRecord) => {
    setSelectedInvoice(inv);
    setPayAmount(inv.balance_due);
    setPayRef(`PAY-${inv.invoice_number}`);
    setPayNotes(`Pelunasan / cicilan ${inv.invoice_number}`);
  };

  const handleRecordPayment = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedInvoice) return;
    setSubmittingPay(true);
    try {
      await api.post('/receivables/payments', {
        invoice_id: selectedInvoice.id,
        amount: Number(payAmount),
        method: payMethod,
        reference_number: payRef || undefined,
        notes: payNotes || undefined,
        idempotency_key: `ar-pay-${selectedInvoice.id}-${Date.now()}`,
      });
      toast({
        type: 'success',
        title: 'Pembayaran Piutang Tercatat',
        description: `Alokasi pembayaran pada invoice ${selectedInvoice.invoice_number} berhasil disimpan.`,
      });
      setSelectedInvoice(null);
      fetchData();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal mencatat pembayaran',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmittingPay(false);
    }
  };

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-extrabold text-slate-900">
            Accounts Receivable (AR), Invoice & Monitoring Umur Piutang
          </h1>
          <p className="text-xs text-slate-500">
            Cakupan Data:{' '}
            <b>{companyScope ? 'Konsolidasi Seluruh Outlet Perusahaan' : activeOutletName}</b> •
            Mencegah pembayaran ganda & pembayaran melebihi sisa saldo tagihan.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          {canViewCompanyScope && (
            <div className="inline-flex rounded-xl bg-slate-200/70 p-1">
              <button
                type="button"
                onClick={() => setCompanyScope(false)}
                className={`px-3 py-1.5 rounded-lg text-xs font-bold ${
                  !companyScope ? 'bg-white text-blue-600 shadow-xs' : 'text-slate-600'
                }`}
              >
                Outlet Aktif
              </button>
              <button
                type="button"
                onClick={() => setCompanyScope(true)}
                className={`px-3 py-1.5 rounded-lg text-xs font-bold ${
                  companyScope ? 'bg-white text-blue-600 shadow-xs' : 'text-slate-600'
                }`}
              >
                Seluruh Perusahaan
              </button>
            </div>
          )}

          <Button
            variant="outline"
            size="sm"
            loading={loading}
            leftIcon={<RefreshCw className="w-4 h-4" />}
            onClick={fetchData}
          >
            Refresh
          </Button>
        </div>
      </div>

      {/* AR Aging Summary Buckets */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Total Saldo Piutang (AR)"
          value={formatIDR(aging?.total_outstanding ?? 0)}
          subValue="Seluruh tagihan belum lunas"
          icon={<CreditCard className="w-5 h-5" />}
          accent="blue"
        />
        <StatCard
          label="Belum Jatuh Tempo (Current)"
          value={formatIDR(aging?.current_not_due ?? 0)}
          subValue="Dalam masa termin aktif"
          icon={<CalendarClock className="w-5 h-5" />}
          accent="emerald"
        />
        <StatCard
          label="Overdue 1 – 30 Hari"
          value={formatIDR(aging?.days_1_30 ?? 0)}
          subValue={`Overdue 31-60 Hr: ${formatIDR(aging?.days_31_60 ?? 0)}`}
          icon={<AlertTriangle className="w-5 h-5" />}
          accent="amber"
        />
        <StatCard
          label="Total Piutang Jatuh Tempo (Overdue)"
          value={formatIDR(aging?.overdue_total ?? 0)}
          subValue={`${aging?.overdue_count ?? 0} Invoice melewati jatuh tempo`}
          icon={<DollarSign className="w-5 h-5" />}
          accent="rose"
        />
      </div>

      {/* Invoices Table */}
      <Card
        title="Daftar Invoice Penjualan Tempo & Alokasi Pembayaran"
        subtitle="Mendukung pembayaran penuh (Full Settlement) maupun cicilan bertahap (Partial Payment)."
      >
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-sm">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase text-slate-500">
                <th className="py-3 px-4">No. Invoice</th>
                <th className="py-3 px-4">Outlet</th>
                <th className="py-3 px-4">Pelanggan</th>
                <th className="py-3 px-4">Tgl Terbit / Jatuh Tempo</th>
                <th className="py-3 px-4 text-right">Total Tagihan</th>
                <th className="py-3 px-4 text-right">Sudah Dibayar</th>
                <th className="py-3 px-4 text-right">Sisa Piutang</th>
                <th className="py-3 px-4">Status</th>
                <th className="py-3 px-4">Input By</th>
                <th className="py-3 px-4">Shift</th>
                <th className="py-3 px-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {invoices.map((inv) => (
                <tr key={inv.id} className="hover:bg-slate-50/70">
                  <td className="py-3.5 px-4">
                    <div className="font-mono font-bold text-slate-900 flex items-center gap-1.5">
                      <FileText className="w-3.5 h-3.5 text-blue-600" />
                      {inv.invoice_number}
                    </div>
                    {inv.sales_order && (
                      <div className="text-[11px] font-mono text-slate-400">
                        Ref: {inv.sales_order.so_number}
                      </div>
                    )}
                  </td>
                  <td className="py-3.5 px-4">
                    <Badge variant="neutral">{inv.outlet?.code}</Badge>
                  </td>
                  <td className="py-3.5 px-4">
                    <div className="font-bold text-slate-900">{inv.customer?.name}</div>
                    <div className="text-xs text-slate-400">{inv.customer?.code}</div>
                  </td>
                  <td className="py-3.5 px-4 text-xs">
                    <div>Terbit: {inv.issue_date}</div>
                    <div className="font-semibold text-slate-700">Jatuh Tempo: {inv.due_date}</div>
                  </td>
                  <td className="py-3.5 px-4 text-right font-semibold text-slate-800 tabular-nums">
                    {formatIDR(inv.grand_total)}
                  </td>
                  <td className="py-3.5 px-4 text-right font-semibold text-emerald-700 tabular-nums">
                    {formatIDR(inv.paid_total)}
                  </td>
                  <td className="py-3.5 px-4 text-right font-extrabold text-rose-700 tabular-nums">
                    {formatIDR(inv.balance_due)}
                  </td>
                  <td className="py-3.5 px-4">
                    <Badge
                      variant={
                        inv.status === 'paid'
                          ? 'success'
                          : inv.status === 'overdue'
                            ? 'danger'
                            : inv.status === 'partial'
                              ? 'info'
                              : 'warning'
                      }
                      dot
                    >
                      {inv.status.toUpperCase()}
                    </Badge>
                  </td>
                  <td className="py-3.5 px-4 text-xs">
                    <div className="font-semibold text-slate-800 flex items-center gap-1">
                      <User className="w-3 h-3 text-slate-400" />
                      {inv.creator?.username ?? '-'}
                    </div>
                    <div className="text-[11px] text-slate-400">
                      {inv.creator?.name ?? 'Data Lama'}
                    </div>
                  </td>
                  <td className="py-3.5 px-4 text-xs">
                    {inv.shift ? (
                      <div>
                        <Badge variant="info">{inv.shift.name}</Badge>
                        {inv.work_date && (
                          <div className="text-[10px] text-slate-400 mt-0.5">{inv.work_date}</div>
                        )}
                      </div>
                    ) : (
                      <span className="text-slate-400">-</span>
                    )}
                  </td>
                  <td className="py-3.5 px-4 text-right">
                    <div className="flex items-center justify-end gap-1.5">
                      <Button
                        variant="outline"
                        size="xs"
                        leftIcon={<Info className="w-3.5 h-3.5" />}
                        onClick={() => setDetailInvoice(inv)}
                      >
                        Detail
                      </Button>
                      {parseFloat(inv.balance_due) > 0 && inv.status !== 'paid' && (
                        <Button
                          variant="primary"
                          size="xs"
                          leftIcon={<CheckCircle2 className="w-3.5 h-3.5" />}
                          onClick={() => openPaymentModal(inv)}
                        >
                          Catat Bayar
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      {/* Customer Payment History */}
      <Card
        title="Riwayat Penerimaan Pembayaran Piutang Pelanggan"
        subtitle="Seluruh pembayaran teralokasi langsung ke nomor invoice terkait."
      >
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-sm">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase text-slate-500">
                <th className="py-3 px-4">No. Pembayaran</th>
                <th className="py-3 px-4">Tanggal</th>
                <th className="py-3 px-4">Pelanggan</th>
                <th className="py-3 px-4">Metode & Referensi</th>
                <th className="py-3 px-4 text-right">Nominal Diterima</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {payments.map((p) => (
                <tr key={p.id}>
                  <td className="py-3 px-4 font-mono text-xs font-bold text-slate-900">
                    {p.payment_number}
                  </td>
                  <td className="py-3 px-4 text-xs text-slate-600">{p.payment_date}</td>
                  <td className="py-3 px-4 font-semibold text-slate-800">{p.customer?.name}</td>
                  <td className="py-3 px-4 text-xs">
                    <Badge variant="info">{p.method.toUpperCase()}</Badge>{' '}
                    <span className="text-slate-500 ml-1">{p.reference_number || ''}</span>
                  </td>
                  <td className="py-3 px-4 text-right font-extrabold text-emerald-700 tabular-nums">
                    {formatIDR(p.amount)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      {/* Modal Catat Pembayaran Invoice */}
      <Modal
        open={selectedInvoice !== null}
        onClose={() => setSelectedInvoice(null)}
        title={`Catat Pembayaran Piutang — ${selectedInvoice?.invoice_number ?? ''}`}
        description={`Pelanggan: ${selectedInvoice?.customer?.name ?? ''} • Sisa Tagihan: ${formatIDR(selectedInvoice?.balance_due ?? 0)}`}
      >
        <form onSubmit={handleRecordPayment} className="space-y-4">
          <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between text-xs">
            <span className="font-semibold text-slate-600">Sisa Saldo Invoice Saat Ini:</span>
            <span className="text-base font-extrabold text-slate-900 tabular-nums">
              {formatIDR(selectedInvoice?.balance_due ?? 0)}
            </span>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Nominal Pembayaran (Rp)
              </label>
              <input
                type="number"
                step="0.01"
                min="1"
                max={selectedInvoice?.balance_due}
                required
                value={payAmount}
                onChange={(e) => setPayAmount(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm font-bold"
              />
              <div className="flex gap-1.5 mt-1.5">
                <button
                  type="button"
                  onClick={() => setPayAmount(selectedInvoice?.balance_due ?? '0')}
                  className="text-[11px] px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-semibold"
                >
                  Lunasi Penuh (100%)
                </button>
                <button
                  type="button"
                  onClick={() =>
                    setPayAmount(
                      String(Math.round(parseFloat(selectedInvoice?.balance_due ?? '0') / 2)),
                    )
                  }
                  className="text-[11px] px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-semibold"
                >
                  Cicil 50%
                </button>
              </div>
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Metode Pembayaran
              </label>
              <select
                value={payMethod}
                onChange={(e) => setPayMethod(e.target.value)}
                className="w-full px-3 py-2.5 rounded-xl border border-slate-300 text-sm bg-white"
              >
                <option value="bank_transfer">Transfer Bank (VA / RTGS)</option>
                <option value="qris">QRIS</option>
                <option value="cash">Tunai (Cash)</option>
                <option value="giro">Giro / Cek</option>
              </select>
            </div>
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">
              Nomor Referensi Bank / Bukti Transfer
            </label>
            <input
              type="text"
              value={payRef}
              onChange={(e) => setPayRef(e.target.value)}
              className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
            />
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">Catatan</label>
            <input
              type="text"
              value={payNotes}
              onChange={(e) => setPayNotes(e.target.value)}
              className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
            />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" type="button" onClick={() => setSelectedInvoice(null)}>
              Batal
            </Button>
            <Button variant="primary" type="submit" loading={submittingPay}>
              Simpan & Alokasikan Pembayaran
            </Button>
          </div>
        </form>
      </Modal>

      {/* Invoice Detail Modal: Transaction Information & Input Information */}
      <Modal
        isOpen={detailInvoice !== null}
        onClose={() => setDetailInvoice(null)}
        title={`Detail Invoice: ${detailInvoice?.invoice_number ?? ''}`}
        subtitle="Rincian informasi transaksi dan identitas operasional karyawan yang mencatat tagihan."
        maxWidth="lg"
        footer={
          <div className="flex justify-end">
            <Button variant="outline" onClick={() => setDetailInvoice(null)}>
              Tutup
            </Button>
          </div>
        }
      >
        {detailInvoice && (
          <div className="space-y-5 text-sm">
            {/* Section 1: Transaction Information */}
            <div>
              <h4 className="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3 flex items-center gap-1.5 border-b pb-1.5">
                <FileText className="w-4 h-4 text-blue-600" />
                Transaction Information (Informasi Transaksi)
              </h4>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-slate-50 rounded-xl p-3.5 border border-slate-200">
                <div>
                  <div className="text-xs text-slate-400">Nomor Invoice</div>
                  <div className="font-mono font-bold text-slate-900">{detailInvoice.invoice_number}</div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Ref Sales Order</div>
                  <div className="font-mono font-semibold text-slate-800">
                    {detailInvoice.sales_order?.so_number ?? '-'}
                  </div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Pelanggan</div>
                  <div className="font-bold text-slate-900">
                    {detailInvoice.customer?.name} ({detailInvoice.customer?.code})
                  </div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Outlet</div>
                  <div className="font-semibold text-slate-800">
                    {detailInvoice.outlet?.name} ({detailInvoice.outlet?.code})
                  </div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Tgl Terbit / Jatuh Tempo</div>
                  <div className="font-medium text-slate-700">
                    {detailInvoice.issue_date} → <span className="font-bold text-slate-900">{detailInvoice.due_date}</span>
                  </div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Status Tagihan</div>
                  <div>
                    <Badge
                      variant={
                        detailInvoice.status === 'paid'
                          ? 'success'
                          : detailInvoice.status === 'overdue'
                            ? 'danger'
                            : detailInvoice.status === 'partial'
                              ? 'info'
                              : 'warning'
                      }
                      dot
                    >
                      {detailInvoice.status.toUpperCase()}
                    </Badge>
                  </div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Total Nilai Tagihan</div>
                  <div className="font-bold text-slate-900 text-base">
                    {formatIDR(detailInvoice.grand_total)}
                  </div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Sisa Piutang (Balance Due)</div>
                  <div className="font-extrabold text-rose-600 text-base">
                    {formatIDR(detailInvoice.balance_due)}
                  </div>
                </div>
                {detailInvoice.notes && (
                  <div className="sm:col-span-2">
                    <div className="text-xs text-slate-400">Catatan Invoice</div>
                    <div className="text-xs text-slate-600 italic">{detailInvoice.notes}</div>
                  </div>
                )}
              </div>
            </div>

            {/* Section 2: Input Information */}
            <div>
              <h4 className="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3 flex items-center gap-1.5 border-b pb-1.5">
                <User className="w-4 h-4 text-emerald-600" />
                Input Information (Informasi Pencatatan & Shift)
              </h4>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-emerald-50/50 rounded-xl p-3.5 border border-emerald-200">
                <div>
                  <div className="text-xs text-slate-400">Input By (Username)</div>
                  <div className="font-bold text-slate-900 flex items-center gap-1">
                    <User className="w-3.5 h-3.5 text-emerald-600" />
                    {detailInvoice.creator ? `@${detailInvoice.creator.username}` : '- (Data Lama)'}
                  </div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Nama Karyawan (Employee Name)</div>
                  <div className="font-semibold text-slate-800">
                    {detailInvoice.creator?.name ?? 'Data Historis'}
                  </div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Shift Kerja Operasional</div>
                  <div className="font-semibold text-slate-900 flex items-center gap-1">
                    <Clock className="w-3.5 h-3.5 text-blue-500" />
                    {detailInvoice.shift ? (
                      <span>
                        {detailInvoice.shift.name} ({detailInvoice.shift.start_time} - {detailInvoice.shift.end_time})
                      </span>
                    ) : (
                      <span className="text-slate-400">-</span>
                    )}
                  </div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Tanggal Kerja (Work Date)</div>
                  <div className="font-semibold text-slate-800">
                    {detailInvoice.work_date ?? detailInvoice.employee_shift_checkin?.work_date ?? '-'}
                  </div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Waktu Dibuat (Created At)</div>
                  <div className="text-xs text-slate-700">
                    {detailInvoice.created_at ?? '-'}
                  </div>
                </div>
                <div>
                  <div className="text-xs text-slate-400">Terakhir Diperbarui Oleh</div>
                  <div className="text-xs text-slate-700">
                    {detailInvoice.updater ? `@${detailInvoice.updater.username} (${detailInvoice.updater.name})` : '-'}
                  </div>
                </div>
                {detailInvoice.employee_shift_checkin?.is_backdated && (
                  <div className="sm:col-span-2 bg-amber-100/70 border border-amber-300 rounded-lg p-2.5 text-xs text-amber-900">
                    <div className="font-bold flex items-center gap-1">
                      <AlertTriangle className="w-3.5 h-3.5 text-amber-700" />
                      Pencatatan Melalui Backdated Shift
                    </div>
                    <div className="text-amber-800 mt-0.5">
                      Alasan: {detailInvoice.employee_shift_checkin.backdate_reason ?? '-'}
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
};

