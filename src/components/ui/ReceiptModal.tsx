import React from 'react';
import { CheckCircle2, Printer, PlusCircle } from 'lucide-react';
import { formatDateTime, formatIDR } from '../../lib/format';
import type { OrderRecord, OutletInfo } from '../../types/pos';
import { Button } from './Button';
import { Modal } from './Modal';

interface ReceiptModalProps {
  isOpen: boolean;
  order: OrderRecord | null;
  outlet?: OutletInfo | null;
  onClose: () => void;
  isPostCheckout?: boolean;
}

const METHOD_LABELS: Record<string, string> = {
  cash: 'Tunai (Cash)',
  qris: 'QRIS Dinamis',
  debit_card: 'Kartu Debit',
  credit_card: 'Kartu Kredit',
  e_wallet: 'E-Wallet',
  bank_transfer: 'Transfer Bank',
};

export const ReceiptModal: React.FC<ReceiptModalProps> = ({
  isOpen,
  order,
  outlet,
  onClose,
  isPostCheckout = false,
}) => {
  if (!isOpen || !order) return null;

  const grandTotalNum = parseFloat(order.grand_total || '0');
  const paidTotalNum = parseFloat(order.paid_total || '0');
  const changeTotalNum = parseFloat(order.change_total || '0');
  const netPaidNum = Math.max(0, paidTotalNum - changeTotalNum);
  const debtBalanceNum = Math.max(0, grandTotalNum - netPaidNum);

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={isPostCheckout ? 'Pembayaran Berhasil' : `Detail Struk • ${order.order_number}`}
      subtitle="Pratinjau Struk Thermal 80mm Standar Moka POS"
      icon={<CheckCircle2 className="w-5 h-5 text-emerald-600" />}
      maxWidth="md"
      footer={
        <div className="flex items-center justify-between gap-2">
          <Button
            variant="outline"
            icon={<Printer className="w-4 h-4" />}
            onClick={() => window.print()}
          >
            Cetak Struk (80mm)
          </Button>
          <Button
            variant="primary"
            icon={isPostCheckout ? <PlusCircle className="w-4 h-4" /> : undefined}
            onClick={onClose}
          >
            {isPostCheckout ? 'Transaksi Baru' : 'Tutup'}
          </Button>
        </div>
      }
    >
      <div className="space-y-4">
        {isPostCheckout && changeTotalNum > 0 && (
          <div className="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 flex items-center justify-between">
            <span className="text-xs font-bold text-emerald-800">Uang Kembalian Pelanggan</span>
            <span className="text-base font-extrabold text-emerald-700 tabular-nums">
              {formatIDR(order.change_total)}
            </span>
          </div>
        )}

        {debtBalanceNum > 0 && (
          <div className="p-3.5 rounded-xl bg-amber-50 border border-amber-200 flex items-center justify-between">
            <div>
              <span className="text-xs font-bold text-amber-900 block">
                Sisa Hutang / Tercatat di Piutang (AR)
              </span>
              <span className="text-[11px] text-amber-700">
                Dibayar: {formatIDR(netPaidNum)} dari {formatIDR(grandTotalNum)}
              </span>
            </div>
            <span className="text-base font-extrabold text-amber-700 tabular-nums">
              {formatIDR(debtBalanceNum)}
            </span>
          </div>
        )}

        {/* Thermal Receipt Paper Preview */}
        <div
          id="printable-thermal-receipt"
          className="mx-auto w-full max-w-[330px] bg-white border border-dashed border-slate-300 rounded-xl p-4 font-mono text-xs text-slate-900 shadow-xs space-y-3"
        >
          <div className="text-center border-b border-dashed border-slate-300 pb-2.5 space-y-0.5">
            <div className="font-extrabold text-sm tracking-tight uppercase">
              {outlet?.name ?? 'SiBotol Coffee & Roastery'}
            </div>
            {outlet?.address && (
              <div className="text-[10px] text-slate-500 leading-tight">{outlet.address}</div>
            )}
            {outlet?.phone && (
              <div className="text-[10px] text-slate-500">Telp: {outlet.phone}</div>
            )}
          </div>

          <div className="text-[11px] space-y-0.5 border-b border-dashed border-slate-300 pb-2.5">
            <div className="flex justify-between">
              <span className="text-slate-500">No. Struk</span>
              <span className="font-bold">{order.order_number}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-slate-500">Waktu</span>
              <span>{formatDateTime(order.completed_at ?? order.created_at)}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-slate-500">Kasir</span>
              <span>{order.cashier?.name ?? 'Kasir POS'}</span>
            </div>
            {order.terminal && (
              <div className="flex justify-between">
                <span className="text-slate-500">Terminal</span>
                <span>{order.terminal.code}</span>
              </div>
            )}
            {(order.customer_name || order.table_label) && (
              <div className="flex justify-between">
                <span className="text-slate-500">Pelanggan / Meja</span>
                <span className="font-bold">
                  {order.customer_name || 'Walk-in'}
                  {order.table_label ? ` (${order.table_label})` : ''}
                </span>
              </div>
            )}
          </div>

          {/* Items */}
          <div className="space-y-2 border-b border-dashed border-slate-300 pb-2.5">
            {order.items.map((item) => (
              <div key={item.id} className="space-y-0.5">
                <div className="font-bold text-slate-900">
                  {item.product_name} ({item.variant_name})
                </div>
                <div className="flex justify-between text-[11px] text-slate-600">
                  <span>
                    {parseFloat(item.quantity)} x {formatIDR(item.unit_price)}
                  </span>
                  <span className="font-bold text-slate-900 tabular-nums">
                    {formatIDR(item.line_total)}
                  </span>
                </div>
                {item.modifiers && item.modifiers.length > 0 && (
                  <div className="text-[10px] text-slate-500 pl-2">
                    + {item.modifiers.map((m) => m.name).join(', ')}
                  </div>
                )}
                {item.notes && (
                  <div className="text-[10px] text-slate-500 italic pl-2">
                    Catatan: {item.notes}
                  </div>
                )}
              </div>
            ))}
          </div>

          {/* Totals */}
          <div className="space-y-1 text-[11px] border-b border-dashed border-slate-300 pb-2.5 tabular-nums">
            <div className="flex justify-between">
              <span className="text-slate-500">Subtotal</span>
              <span>{formatIDR(order.subtotal)}</span>
            </div>
            {parseFloat(order.discount_total) > 0 && (
              <div className="flex justify-between text-rose-600">
                <span>Diskon</span>
                <span>-{formatIDR(order.discount_total)}</span>
              </div>
            )}
            {parseFloat(order.service_charge_total) > 0 && (
              <div className="flex justify-between">
                <span>Service ({parseFloat(order.service_charge_rate)}%)</span>
                <span>{formatIDR(order.service_charge_total)}</span>
              </div>
            )}
            <div className="flex justify-between">
              <span>PPN ({parseFloat(order.tax_rate)}%)</span>
              <span>{formatIDR(order.tax_total)}</span>
            </div>
            <div className="flex justify-between text-xs font-extrabold pt-1 border-t border-slate-200">
              <span>TOTAL</span>
              <span>{formatIDR(order.grand_total)}</span>
            </div>
          </div>

          {/* Payments */}
          <div className="space-y-1 text-[11px] border-b border-dashed border-slate-300 pb-2.5 tabular-nums">
            {order.payments && order.payments.length > 0 ? (
              order.payments.map((pay) => (
                <div key={pay.id} className="flex justify-between">
                  <span className="text-slate-600">
                    {METHOD_LABELS[pay.method] ?? pay.method.toUpperCase()}
                  </span>
                  <span className="font-semibold">
                    {formatIDR(pay.tendered_amount ?? pay.amount)}
                  </span>
                </div>
              ))
            ) : (
              <div className="flex justify-between">
                <span className="text-slate-600">Dibayar</span>
                <span className="font-semibold">{formatIDR(0)}</span>
              </div>
            )}
            {debtBalanceNum > 0 ? (
              <div className="flex justify-between font-bold text-amber-700 pt-0.5">
                <span>SISA HUTANG (PIUTANG)</span>
                <span>{formatIDR(debtBalanceNum)}</span>
              </div>
            ) : (
              <div className="flex justify-between font-bold pt-0.5">
                <span>Kembalian</span>
                <span>{formatIDR(order.change_total)}</span>
              </div>
            )}
          </div>

          <div className="text-center text-[10px] text-slate-500 pt-1">
            Terima kasih atas kunjungan Anda!
            <br />
            Powered by SiBotol POS
          </div>
        </div>
      </div>
    </Modal>
  );
};

