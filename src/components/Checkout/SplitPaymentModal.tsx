import React, { useEffect, useMemo, useState } from 'react';
import {
  Banknote,
  CreditCard,
  KeyRound,
  Landmark,
  Plus,
  QrCode,
  ShieldCheck,
  Smartphone,
  Split,
  Trash2,
} from 'lucide-react';
import { api, extractErrorMessage, generateIdempotencyKey } from '../../lib/api';
import { formatIDR } from '../../lib/format';
import type { OrderRecord, PaymentLegInput, PaymentMethodType } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';

interface Props {
  isOpen: boolean;
  grandTotal: number;
  requiresSupervisorPin: boolean;
  onClose: () => void;
  onSubmitCheckout: (
    payments: PaymentLegInput[],
    idempotencyKey: string,
    supervisorPin?: string,
  ) => Promise<OrderRecord>;
  onOrderCompleted: (order: OrderRecord) => void;
}

const PAYMENT_METHODS: Array<{
  value: PaymentMethodType;
  label: string;
  sublabel: string;
  icon: React.FC<{ className?: string }>;
}> = [
  { value: 'cash', label: 'Tunai (Cash)', sublabel: 'Hitung kembalian otomatis', icon: Banknote },
  { value: 'qris', label: 'QRIS Dinamis', sublabel: 'EMVCo Standar Indonesia', icon: QrCode },
  { value: 'debit_card', label: 'Kartu Debit', sublabel: 'EDC BCA / Mandiri / BNI', icon: CreditCard },
  { value: 'credit_card', label: 'Kartu Kredit', sublabel: 'Visa / Mastercard / JCB', icon: CreditCard },
  { value: 'e_wallet', label: 'E-Wallet', sublabel: 'GoPay / OVO / ShopeePay', icon: Smartphone },
  { value: 'bank_transfer', label: 'Transfer Bank', sublabel: 'Virtual Account / Instan', icon: Landmark },
];

const PROVIDER_PRESETS: Partial<Record<PaymentMethodType, string[]>> = {
  debit_card: ['EDC BCA', 'EDC Mandiri', 'EDC BNI', 'EDC BRI'],
  credit_card: ['EDC BCA', 'EDC Mandiri', 'Visa / Mastercard'],
  e_wallet: ['GoPay', 'OVO', 'ShopeePay', 'DANA'],
  bank_transfer: ['BCA Transfer', 'Mandiri Livin', 'BRI Brimo', 'BNI Mobile'],
};

export const SplitPaymentModal: React.FC<Props> = ({
  isOpen,
  grandTotal,
  requiresSupervisorPin,
  onClose,
  onSubmitCheckout,
  onOrderCompleted,
}) => {
  const [isSplitMode, setIsSplitMode] = useState<boolean>(false);
  const [legs, setLegs] = useState<PaymentLegInput[]>([]);
  const [idempotencyKey, setIdempotencyKey] = useState<string>('');
  const [supervisorPin, setSupervisorPin] = useState<string>('');
  const [qrisPreview, setQrisPreview] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const roundedGrandTotal = Math.round(grandTotal);

  // Quick cash suggestions based on grandTotal (Moka POS style)
  const cashDenominations = useMemo(() => {
    const base = roundedGrandTotal;
    const candidates = [
      base,
      Math.ceil(base / 10000) * 10000,
      Math.ceil(base / 50000) * 50000,
      Math.ceil(base / 100000) * 100000,
      base + 50000,
      200000,
      500000,
    ];
    return Array.from(new Set(candidates.filter((v) => v >= base))).sort((a, b) => a - b).slice(0, 5);
  }, [roundedGrandTotal]);

  useEffect(() => {
    if (isOpen) {
      setIsSplitMode(false);
      setLegs([
        {
          id: 'leg-1',
          method: 'cash',
          amount: roundedGrandTotal,
          tendered_amount: roundedGrandTotal,
          provider: '',
          reference_number: '',
          card_last4: '',
          qris_payload: '',
        },
      ]);
      setIdempotencyKey(generateIdempotencyKey('pay'));
      setSupervisorPin('');
      setQrisPreview(null);
      setError(null);
    }
  }, [isOpen, roundedGrandTotal]);

  const appliedSum = legs.reduce((acc, l) => acc + (Number(l.amount) || 0), 0);
  const remainingToAllocate = roundedGrandTotal - appliedSum;
  const remainingDebt = Math.max(0, remainingToAllocate);
  const isOverAllocated = remainingToAllocate < 0;

  const totalChange = legs.reduce((acc, l) => {
    if (l.method === 'cash') {
      return acc + Math.max(0, (Number(l.tendered_amount) || 0) - (Number(l.amount) || 0));
    }
    return acc;
  }, 0);

  const parseRawNominal = (rawValue: string): number => {
    const digitsOnly = rawValue.replace(/\D/g, '').replace(/^0+/, '');
    if (!digitsOnly) return 0;
    const parsed = parseInt(digitsOnly, 10);
    return Number.isNaN(parsed) ? 0 : parsed;
  };

  const updateLeg = (id: string, patch: Partial<PaymentLegInput>) => {
    setLegs((prev) =>
      prev.map((leg) => {
        if (leg.id !== id) return leg;
        const updated = { ...leg, ...patch };
        if (updated.method !== 'cash') {
          updated.tendered_amount = updated.amount;
        } else if (!isSplitMode) {
          if (patch.tendered_amount !== undefined && patch.amount === undefined) {
            updated.amount = Math.min(roundedGrandTotal, Math.max(0, updated.tendered_amount));
          } else if (patch.amount !== undefined && patch.tendered_amount === undefined) {
            updated.tendered_amount = updated.amount;
          }
        } else if (patch.tendered_amount !== undefined && patch.amount === undefined && updated.tendered_amount < updated.amount) {
          updated.amount = Math.max(0, updated.tendered_amount);
        } else if (patch.amount !== undefined && patch.tendered_amount === undefined && updated.tendered_amount < updated.amount) {
          updated.tendered_amount = updated.amount;
        }
        return updated;
      }),
    );
  };

  const handleSelectSingleMethod = async (method: PaymentMethodType) => {
    const legId = legs[0]?.id ?? 'leg-1';
    const ref =
      method === 'qris'
        ? `QR-${Date.now().toString().slice(-6)}`
        : method !== 'cash'
          ? `REF-${Date.now().toString().slice(-6)}`
          : '';
    const defaultProvider =
      method === 'qris'
        ? 'QRIS Standar Indonesia'
        : PROVIDER_PRESETS[method]?.[0] ?? '';

    const newLeg: PaymentLegInput = {
      id: legId,
      method,
      amount: roundedGrandTotal,
      tendered_amount: roundedGrandTotal,
      provider: defaultProvider,
      reference_number: ref,
      card_last4: '',
      qris_payload: '',
    };

    setLegs([newLeg]);
    setQrisPreview(null);

    if (method === 'qris') {
      await handleGenerateQris(newLeg);
    }
  };

  const handleAddSplitLeg = () => {
    setIsSplitMode(true);
    const rem = Math.max(0, remainingToAllocate);
    setLegs((prev) => [
      ...prev,
      {
        id: `leg-${Date.now()}`,
        method: 'qris',
        amount: rem,
        tendered_amount: rem,
        provider: 'QRIS Standar Indonesia',
        reference_number: `QR-${Date.now().toString().slice(-6)}`,
        card_last4: '',
        qris_payload: '',
      },
    ]);
  };

  const handleRemoveLeg = (id: string) => {
    if (legs.length <= 1) return;
    const next = legs.filter((l) => l.id !== id);
    setLegs(next);
    if (next.length === 1) {
      setIsSplitMode(false);
    }
  };

  const handleGenerateQris = async (leg: PaymentLegInput) => {
    try {
      const res = await api.post<{ qris_payload: string }>('/orders/qris-payload', {
        amount: leg.amount,
        reference: leg.reference_number || `QR-${Date.now().toString().slice(-6)}`,
      });
      updateLeg(leg.id, { qris_payload: res.data.qris_payload });
      setQrisPreview(res.data.qris_payload);
    } catch (err) {
      setError(extractErrorMessage(err));
    }
  };

  const handleProcessPayment = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const order = await onSubmitCheckout(
        legs,
        idempotencyKey,
        supervisorPin ? supervisorPin : undefined,
      );
      onClose();
      onOrderCompleted(order);
    } catch (err) {
      setError(extractErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  const primaryLeg = legs[0];

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Pembayaran Tagihan (Moka Fast Charge)"
      subtitle={
        <span className="inline-flex items-center gap-2">
          <span>Idempotency Key:</span>
          <code className="px-1.5 py-0.5 rounded bg-slate-100 border border-slate-200 text-blue-600 font-mono text-[10px]">
            {idempotencyKey}
          </code>
        </span>
      }
      maxWidth="3xl"
    >
      <form onSubmit={handleProcessPayment} className="space-y-5">
        {error && (
          <div className="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold">
            {error}
          </div>
        )}

        {/* Top Summary Banner & Mode Toggle */}
        <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 flex flex-wrap items-center justify-between gap-4">
          <div className="flex flex-wrap items-center gap-6">
            <div>
              <div className="text-[11px] font-semibold uppercase text-slate-400">
                Total Tagihan
              </div>
              <div className="text-xl font-extrabold text-slate-900 tabular-nums">
                {formatIDR(roundedGrandTotal)}
              </div>
            </div>

            <div className="pl-6 border-l border-slate-200">
              <div className="text-[11px] font-semibold uppercase text-slate-400">
                Nominal Dibayar
              </div>
              <div className="text-lg font-extrabold text-blue-600 tabular-nums">
                {formatIDR(appliedSum)}
              </div>
            </div>

            {remainingDebt > 0 ? (
              <div className="pl-6 border-l border-slate-200">
                <div className="text-[11px] font-semibold uppercase text-amber-600">
                  Sisa Hutang (Masuk Piutang)
                </div>
                <div className="text-lg font-extrabold text-amber-600 tabular-nums">
                  {formatIDR(remainingDebt)}
                </div>
              </div>
            ) : (
              <div className="pl-6 border-l border-slate-200">
                <div className="text-[11px] font-semibold uppercase text-slate-400">
                  Kembalian Tunai
                </div>
                <div className="text-lg font-extrabold text-emerald-600 tabular-nums">
                  {formatIDR(totalChange)}
                </div>
              </div>
            )}

            {isOverAllocated && (
              <div className="pl-6 border-l border-slate-200">
                <div className="text-[11px] font-semibold uppercase text-rose-600">
                  Kelebihan Alokasi Non-Tunai
                </div>
                <div className="text-lg font-extrabold text-rose-600 tabular-nums">
                  +{formatIDR(Math.abs(remainingToAllocate))}
                </div>
              </div>
            )}
          </div>

          <Button
            type="button"
            variant={isSplitMode ? 'primary' : 'outline'}
            size="sm"
            icon={<Split className="w-3.5 h-3.5" />}
            onClick={() => {
              if (!isSplitMode) {
                const half = Math.floor(roundedGrandTotal / 2);
                const rem = roundedGrandTotal - half;
                setIsSplitMode(true);
                setLegs([
                  {
                    ...primaryLeg,
                    id: 'leg-1',
                    method: 'cash',
                    amount: half,
                    tendered_amount: half,
                  },
                  {
                    id: 'leg-2',
                    method: 'qris',
                    amount: rem,
                    tendered_amount: rem,
                    provider: 'QRIS Standar Indonesia',
                    reference_number: `QR-${Date.now().toString().slice(-6)}`,
                    card_last4: '',
                    qris_payload: '',
                  },
                ]);
              } else {
                setIsSplitMode(false);
                handleSelectSingleMethod('cash');
              }
            }}
          >
            {isSplitMode ? 'Mode Split Aktif (Kembali ke 1 Metode)' : 'Split Pembayaran'}
          </Button>
        </div>

        {/* MODE 1: SINGLE PAYMENT (MOKA FAST CHARGE) */}
        {!isSplitMode && primaryLeg && (
          <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
            {/* Left Method Selector (5 cols) */}
            <div className="md:col-span-5 space-y-2">
              <label className="block text-xs font-bold uppercase tracking-wider text-slate-400">
                1. Pilih Metode Pembayaran
              </label>
              <div className="space-y-1.5">
                {PAYMENT_METHODS.map((pm) => {
                  const Icon = pm.icon;
                  const active = primaryLeg.method === pm.value;
                  return (
                    <button
                      key={pm.value}
                      type="button"
                      onClick={() => handleSelectSingleMethod(pm.value)}
                      className={`w-full p-3 rounded-xl border text-left flex items-center gap-3 transition cursor-pointer ${
                        active
                          ? 'bg-blue-50 border-blue-600 text-blue-900 shadow-2xs'
                          : 'bg-white border-slate-200 text-slate-700 hover:border-slate-300'
                      }`}
                    >
                      <div
                        className={`w-9 h-9 rounded-lg flex items-center justify-center shrink-0 ${
                          active ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600'
                        }`}
                      >
                        <Icon className="w-4 h-4" />
                      </div>
                      <div className="min-w-0">
                        <div className="text-xs font-bold">{pm.label}</div>
                        <div className="text-[10px] text-slate-500 truncate">{pm.sublabel}</div>
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Right Payment Details (7 cols) */}
            <div className="md:col-span-7 bg-slate-50 border border-slate-200 rounded-xl p-4 flex flex-col justify-between space-y-4">
              {primaryLeg.method === 'cash' && (
                <div className="space-y-4">
                  <div>
                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">
                      2. Pilih Nominal Tunai Cepat (1-Klik)
                    </label>
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                      {cashDenominations.map((denom, idx) => {
                        const isSelected = primaryLeg.tendered_amount === denom;
                        return (
                          <button
                            key={denom}
                            type="button"
                            onClick={() =>
                              updateLeg(primaryLeg.id, { tendered_amount: denom })
                            }
                            className={`py-2.5 px-3 rounded-xl border text-xs font-bold transition cursor-pointer tabular-nums ${
                              isSelected
                                ? 'bg-blue-600 border-blue-600 text-white shadow-xs'
                                : 'bg-white border-slate-300 text-slate-800 hover:border-blue-400'
                            }`}
                          >
                            {idx === 0 ? `Uang Pas (${formatIDR(denom)})` : formatIDR(denom)}
                          </button>
                        );
                      })}
                      <button
                        type="button"
                        onClick={() =>
                          updateLeg(primaryLeg.id, { tendered_amount: 0, amount: 0 })
                        }
                        className={`py-2.5 px-3 rounded-xl border text-xs font-bold transition cursor-pointer tabular-nums ${
                          primaryLeg.tendered_amount === 0
                            ? 'bg-amber-600 border-amber-600 text-white shadow-xs'
                            : 'bg-amber-50 border-amber-300 text-amber-800 hover:border-amber-500'
                        }`}
                      >
                        Full Hutang (Rp 0)
                      </button>
                    </div>
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      Atau Ketik Uang Tunai Diterima (IDR) — Bisa Kurang Bayar / Hutang
                    </label>
                    <input
                      type="text"
                      inputMode="numeric"
                      placeholder="0"
                      value={primaryLeg.tendered_amount === 0 ? '' : String(primaryLeg.tendered_amount)}
                      onChange={(e) =>
                        updateLeg(primaryLeg.id, {
                          tendered_amount: parseRawNominal(e.target.value),
                        })
                      }
                      className="w-full px-3.5 py-2.5 rounded-lg bg-white border border-slate-300 text-base font-extrabold text-slate-900 tabular-nums focus:outline-none focus:border-blue-600"
                    />
                  </div>

                  {remainingDebt > 0 ? (
                    <div className="p-3.5 rounded-xl bg-amber-50 border border-amber-200 flex items-center justify-between">
                      <div>
                        <span className="text-xs font-bold text-amber-900 block">
                          Kurang Bayar / Sisa Hutang (Masuk Piutang)
                        </span>
                        <span className="text-[11px] text-amber-700">
                          Otomatis dicatat sebagai tagihan piutang pelanggan
                        </span>
                      </div>
                      <span className="text-lg font-extrabold text-amber-700 tabular-nums">
                        {formatIDR(remainingDebt)}
                      </span>
                    </div>
                  ) : (
                    <div className="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 flex items-center justify-between">
                      <span className="text-xs font-bold text-emerald-800">
                        Uang Kembalian (Change)
                      </span>
                      <span className="text-lg font-extrabold text-emerald-700 tabular-nums">
                        {formatIDR(totalChange)}
                      </span>
                    </div>
                  )}
                </div>
              )}

              {primaryLeg.method === 'qris' && (
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <div>
                      <div className="text-xs font-bold text-slate-900">
                        QRIS Dinamis Standar Indonesia (EMVCo)
                      </div>
                      <div className="text-[11px] text-slate-500">
                        Ketik nominal dibayar (bisa bayar sebagian / sisanya masuk hutang)
                      </div>
                    </div>
                    <Button
                      type="button"
                      variant="outline"
                      size="xs"
                      icon={<QrCode className="w-3.5 h-3.5" />}
                      onClick={() => handleGenerateQris(primaryLeg)}
                    >
                      Refresh Payload
                    </Button>
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      Nominal Dibayar via QRIS (IDR)
                    </label>
                    <input
                      type="text"
                      inputMode="numeric"
                      placeholder="0"
                      value={primaryLeg.amount === 0 ? '' : String(primaryLeg.amount)}
                      onChange={(e) => {
                        const val = Math.min(roundedGrandTotal, parseRawNominal(e.target.value));
                        updateLeg(primaryLeg.id, { amount: val, tendered_amount: val });
                      }}
                      className="w-full px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-sm font-extrabold text-slate-900 tabular-nums focus:outline-none focus:border-blue-600"
                    />
                  </div>

                  <div className="p-3 rounded-xl bg-white border border-slate-200 space-y-2">
                    <div className="flex items-center justify-between text-xs">
                      <span className="text-slate-500">No. Referensi QRIS</span>
                      <input
                        type="text"
                        value={primaryLeg.reference_number}
                        onChange={(e) =>
                          updateLeg(primaryLeg.id, { reference_number: e.target.value })
                        }
                        className="px-2.5 py-1 rounded border border-slate-300 text-xs font-mono font-bold text-slate-900 w-40 text-right"
                      />
                    </div>
                    {(qrisPreview || primaryLeg.qris_payload) && (
                      <div className="p-2.5 rounded-lg bg-slate-50 border border-slate-200 font-mono text-[10px] text-slate-600 break-all">
                        {qrisPreview || primaryLeg.qris_payload}
                      </div>
                    )}
                  </div>

                  {remainingDebt > 0 && (
                    <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 flex items-center justify-between">
                      <span className="text-xs font-bold text-amber-800">
                        Sisa Hutang (Masuk Piutang)
                      </span>
                      <span className="text-sm font-extrabold text-amber-700 tabular-nums">
                        {formatIDR(remainingDebt)}
                      </span>
                    </div>
                  )}
                </div>
              )}

              {primaryLeg.method !== 'cash' && primaryLeg.method !== 'qris' && (
                <div className="space-y-3">
                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      Nominal Dibayar (IDR) — Bisa Bayar Sebagian / Sisanya Hutang
                    </label>
                    <input
                      type="text"
                      inputMode="numeric"
                      placeholder="0"
                      value={primaryLeg.amount === 0 ? '' : String(primaryLeg.amount)}
                      onChange={(e) => {
                        const val = Math.min(roundedGrandTotal, parseRawNominal(e.target.value));
                        updateLeg(primaryLeg.id, { amount: val, tendered_amount: val });
                      }}
                      className="w-full px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-sm font-extrabold text-slate-900 tabular-nums focus:outline-none focus:border-blue-600"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                      Pilih Penyedia / EDC / Wallet
                    </label>
                    <div className="flex flex-wrap gap-1.5 mb-2">
                      {(PROVIDER_PRESETS[primaryLeg.method] ?? []).map((preset) => (
                        <button
                          key={preset}
                          type="button"
                          onClick={() => updateLeg(primaryLeg.id, { provider: preset })}
                          className={`px-2.5 py-1 rounded-lg text-xs font-semibold border cursor-pointer ${
                            primaryLeg.provider === preset
                              ? 'bg-blue-600 border-blue-600 text-white'
                              : 'bg-white border-slate-300 text-slate-700'
                          }`}
                        >
                          {preset}
                        </button>
                      ))}
                    </div>
                    <input
                      type="text"
                      value={primaryLeg.provider}
                      onChange={(e) =>
                        updateLeg(primaryLeg.id, { provider: e.target.value })
                      }
                      placeholder="Nama Bank / EDC / Provider"
                      className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs text-slate-900"
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-semibold text-slate-700 mb-1">
                        No. Referensi / Approval Code
                      </label>
                      <input
                        type="text"
                        value={primaryLeg.reference_number}
                        onChange={(e) =>
                          updateLeg(primaryLeg.id, { reference_number: e.target.value })
                        }
                        placeholder="Contoh: APPR-88291"
                        className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs font-mono text-slate-900"
                      />
                    </div>

                    {(primaryLeg.method === 'debit_card' ||
                      primaryLeg.method === 'credit_card') && (
                      <div>
                        <label className="block text-xs font-semibold text-slate-700 mb-1">
                          4 Digit Terakhir Kartu
                        </label>
                        <input
                          type="text"
                          maxLength={4}
                          value={primaryLeg.card_last4}
                          onChange={(e) =>
                            updateLeg(primaryLeg.id, { card_last4: e.target.value })
                          }
                          placeholder="4242"
                          className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs font-mono text-slate-900"
                        />
                      </div>
                    )}
                  </div>

                  {remainingDebt > 0 && (
                    <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 flex items-center justify-between">
                      <span className="text-xs font-bold text-amber-800">
                        Sisa Hutang (Masuk Piutang)
                      </span>
                      <span className="text-sm font-extrabold text-amber-700 tabular-nums">
                        {formatIDR(remainingDebt)}
                      </span>
                    </div>
                  )}
                </div>
              )}

              <div className="pt-3 border-t border-slate-200 flex items-center justify-between text-[11px] text-slate-500">
                <span className="inline-flex items-center gap-1">
                  <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
                  Transaksi dilindungi DB Transaction &amp; Idempotency Key
                </span>
                <Badge variant={remainingDebt > 0 ? 'warning' : 'success'}>
                  {remainingDebt > 0 ? 'Sebagian / Masuk Piutang' : 'Siap Diproses'}
                </Badge>
              </div>
            </div>
          </div>
        )}

        {/* MODE 2: MULTI-LEG SPLIT PAYMENT */}
        {isSplitMode && (
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-bold text-slate-700">
                Daftar Komponen Split Payment ({legs.length} Metode)
              </span>
              <Button
                type="button"
                variant="outline"
                size="xs"
                icon={<Plus className="w-3.5 h-3.5" />}
                onClick={handleAddSplitLeg}
              >
                Tambah Metode Lain
              </Button>
            </div>

            {legs.map((leg, index) => (
              <div
                key={leg.id}
                className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 space-y-3"
              >
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-blue-600">
                    Pembayaran #{index + 1}
                  </span>
                  {legs.length > 1 && (
                    <button
                      type="button"
                      onClick={() => handleRemoveLeg(leg.id)}
                      className="text-slate-400 hover:text-rose-600 cursor-pointer"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  )}
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-12 gap-3">
                  <div className="sm:col-span-4">
                    <label className="block text-[11px] font-semibold text-slate-600 mb-1">
                      Metode
                    </label>
                    <select
                      value={leg.method}
                      onChange={(e) =>
                        updateLeg(leg.id, { method: e.target.value as PaymentMethodType })
                      }
                      className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs font-semibold text-slate-900"
                    >
                      {PAYMENT_METHODS.map((m) => (
                        <option key={m.value} value={m.value}>
                          {m.label}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div className="sm:col-span-4">
                    <label className="block text-[11px] font-semibold text-slate-600 mb-1">
                      Nominal Alokasi (IDR)
                    </label>
                    <input
                      type="text"
                      inputMode="numeric"
                      placeholder="0"
                      value={leg.amount === 0 ? '' : String(leg.amount)}
                      onChange={(e) =>
                        updateLeg(leg.id, { amount: parseRawNominal(e.target.value) })
                      }
                      className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs font-bold text-slate-900 tabular-nums"
                    />
                  </div>

                  {leg.method === 'cash' ? (
                    <div className="sm:col-span-4">
                      <label className="block text-[11px] font-semibold text-slate-600 mb-1">
                        Uang Tunai Diterima
                      </label>
                      <input
                        type="text"
                        inputMode="numeric"
                        placeholder="0"
                        value={leg.tendered_amount === 0 ? '' : String(leg.tendered_amount)}
                        onChange={(e) =>
                          updateLeg(leg.id, {
                            tendered_amount: parseRawNominal(e.target.value),
                          })
                        }
                        className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs font-bold text-emerald-700 tabular-nums"
                      />
                    </div>
                  ) : (
                    <div className="sm:col-span-4">
                      <label className="block text-[11px] font-semibold text-slate-600 mb-1">
                        No. Referensi / Provider
                      </label>
                      <div className="flex gap-1.5">
                        <input
                          type="text"
                          value={leg.reference_number}
                          onChange={(e) =>
                            updateLeg(leg.id, { reference_number: e.target.value })
                          }
                          placeholder="Ref / Approval"
                          className="w-full px-2.5 py-2 rounded-lg bg-white border border-slate-300 text-xs font-mono text-slate-900"
                        />
                        {leg.method === 'qris' && (
                          <Button
                            type="button"
                            variant="outline"
                            size="xs"
                            onClick={() => handleGenerateQris(leg)}
                          >
                            QR
                          </Button>
                        )}
                      </div>
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}

        {/* Supervisor PIN Authorization Box (when required) */}
        {requiresSupervisorPin && (
          <div className="p-4 rounded-xl bg-amber-50 border border-amber-200 space-y-2">
            <div className="flex items-center gap-2 text-xs font-bold text-amber-800">
              <KeyRound className="w-4 h-4 text-amber-600" />
              <span>Otorisasi Supervisor Diperlukan (Diskon Melebihi Batas / Override Harga)</span>
            </div>
            <input
              type="password"
              required
              value={supervisorPin}
              onChange={(e) => setSupervisorPin(e.target.value)}
              placeholder="Masukkan PIN Supervisor untuk mengesahkan transaksi..."
              className="w-full px-3.5 py-2 rounded-lg bg-white border border-amber-300 text-xs font-mono text-slate-900 focus:outline-none focus:border-amber-600"
            />
          </div>
        )}

        <div className="flex items-center justify-end gap-2.5 pt-2 border-t border-slate-200">
          <Button type="button" variant="outline" onClick={onClose}>
            Batal
          </Button>
          <Button
            type="submit"
            variant="primary"
            size="lg"
            loading={loading}
            disabled={isOverAllocated}
          >
            {remainingDebt > 0
              ? `Selesaikan & Catat Hutang (${formatIDR(remainingDebt)})`
              : `Selesaikan Pembayaran (${formatIDR(roundedGrandTotal)})`}
          </Button>
        </div>
      </form>
    </Modal>
  );
};
