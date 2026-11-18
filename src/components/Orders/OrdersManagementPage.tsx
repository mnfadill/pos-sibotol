import React, { useEffect, useState } from 'react';
import {
  Ban,
  Eye,
  KeyRound,
  Printer,
  RefreshCw,
  RotateCcw,
  Search,
  ShoppingBag,
} from 'lucide-react';
import { api, extractErrorMessage, generateIdempotencyKey } from '../../lib/api';
import { formatDateTime, formatIDR } from '../../lib/format';
import type { OrderRecord, OutletInfo, PaymentMethodType } from '../../types/pos';
import { Badge, type BadgeVariant } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { Modal } from '../ui/Modal';
import { Pagination } from '../ui/Pagination';
import { ReceiptModal } from '../ui/ReceiptModal';
import { useToast } from '../ui/ToastProvider';

interface OrdersManagementPageProps {
  outlet?: OutletInfo | null;
}

const STATUS_TABS: Array<{ value: string; label: string }> = [
  { value: '', label: 'Semua Status' },
  { value: 'completed', label: 'Selesai (Paid)' },
  { value: 'held', label: 'Di-Hold (Draft)' },
  { value: 'partially_refunded', label: 'Refund Sebagian' },
  { value: 'refunded', label: 'Full Refund' },
  { value: 'voided', label: 'Dibatalkan (Void)' },
];

export const OrdersManagementPage: React.FC<OrdersManagementPageProps> = ({ outlet }) => {
  const { toast } = useToast();
  const [orders, setOrders] = useState<OrderRecord[]>([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [search, setSearch] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(true);

  const [selectedOrder, setSelectedOrder] = useState<OrderRecord | null>(null);
  const [actionMode, setActionMode] = useState<'view' | 'void' | 'refund' | 'cancel'>('view');
  const [receiptOrder, setReceiptOrder] = useState<OrderRecord | null>(null);

  const [reason, setReason] = useState<string>('');
  const [supervisorPin, setSupervisorPin] = useState<string>('');
  const [refundMethod, setRefundMethod] = useState<PaymentMethodType>('cash');
  const [restock, setRestock] = useState<boolean>(true);
  const [refundQtys, setRefundQtys] = useState<Record<number, number>>({});
  const [submitting, setSubmitting] = useState<boolean>(false);

  useEffect(() => {
    fetchOrders(1);
  }, [statusFilter]);

  const fetchOrders = async (page = 1) => {
    setLoading(true);
    try {
      const res = await api.get<{
        data: OrderRecord[];
        current_page: number;
        last_page: number;
        total: number;
      }>('/orders', {
        params: {
          page,
          status: statusFilter || undefined,
          search: search || undefined,
          per_page: 20,
        },
      });
      setOrders(res.data.data);
      setMeta({
        current_page: res.data.current_page,
        last_page: res.data.last_page,
        total: res.data.total,
      });
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat daftar transaksi',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  const openModal = (order: OrderRecord, mode: 'view' | 'void' | 'refund' | 'cancel') => {
    setSelectedOrder(order);
    setActionMode(mode);
    setReason('');
    setSupervisorPin('');
    const initialQtys: Record<number, number> = {};
    order.items.forEach((i) => {
      const rem = Math.max(0, parseFloat(i.quantity) - parseFloat(i.refunded_quantity));
      initialQtys[i.id] = rem;
    });
    setRefundQtys(initialQtys);
  };

  const handleActionSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedOrder) return;
    setSubmitting(true);

    try {
      if (actionMode === 'cancel') {
        await api.post(`/orders/${selectedOrder.id}/cancel`, { reason });
        toast({
          type: 'success',
          title: `Draft Bill ${selectedOrder.order_number} Dibatalkan`,
          description: 'Tercatat ke Audit Trail (ORDER_CANCELLED).',
        });
      } else if (actionMode === 'void') {
        await api.post(`/orders/${selectedOrder.id}/void`, {
          reason,
          supervisor_pin: supervisorPin,
        });
        toast({
          type: 'success',
          title: `Transaksi ${selectedOrder.order_number} Di-VOID`,
          description: 'Stok produk telah dikembalikan secara atomik ke inventori outlet.',
        });
      } else if (actionMode === 'refund') {
        const items = Object.entries(refundQtys)
          .filter(([, qty]) => qty > 0)
          .map(([orderItemId, quantity]) => ({
            order_item_id: Number(orderItemId),
            quantity,
          }));

        await api.post(
          `/orders/${selectedOrder.id}/refund`,
          {
            method: refundMethod,
            reason,
            restock,
            supervisor_pin: supervisorPin,
            items,
          },
          {
            headers: {
              'X-Idempotency-Key': generateIdempotencyKey('ref'),
            },
          },
        );
        toast({
          type: 'success',
          title: `Refund ${selectedOrder.order_number} Berhasil`,
          description: 'Pengembalian dana & stok tercatat di Audit Trail (REFUND_PROCESSED).',
        });
      }
      setSelectedOrder(null);
      await fetchOrders(meta.current_page);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memproses tindakan',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmitting(false);
    }
  };

  const renderStatusBadge = (status: OrderRecord['status']) => {
    const map: Record<OrderRecord['status'], { label: string; variant: BadgeVariant }> = {
      completed: { label: 'COMPLETED', variant: 'success' },
      held: { label: 'HELD (DRAFT)', variant: 'warning' },
      voided: { label: 'VOIDED', variant: 'danger' },
      cancelled: { label: 'CANCELLED', variant: 'neutral' },
      partially_refunded: { label: 'PARTIAL REFUND', variant: 'info' },
      refunded: { label: 'REFUNDED', variant: 'purple' },
    };
    const item = map[status];
    return <Badge variant={item.variant}>{item.label}</Badge>;
  };

  return (
    <div className="p-5 space-y-4 max-w-[1600px] mx-auto">
      {/* Header & Filter Bar */}
      <Card padding="sm" className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-base font-extrabold text-slate-900">
              Riwayat Transaksi, Cetak Struk, Void &amp; Refund
            </h2>
            <p className="text-xs text-slate-500">
              Kelola seluruh invoice outlet, cetak ulang struk thermal 80mm, atau proses Void &amp; Refund dengan PIN Supervisor.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <form
              onSubmit={(e) => {
                e.preventDefault();
                fetchOrders(1);
              }}
              className="flex items-center gap-2"
            >
              <div className="relative">
                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Cari No. Invoice, Pelanggan, Meja..."
                  className="pl-9 pr-3 py-2 rounded-lg bg-slate-50 border border-slate-200 text-xs text-slate-900 w-64 focus:bg-white focus:outline-none focus:border-blue-600"
                />
              </div>
              <Button type="submit" variant="primary" size="sm">
                Cari
              </Button>
            </form>

            <Button
              variant="outline"
              size="sm"
              onClick={() => fetchOrders(meta.current_page)}
              loading={loading}
              icon={<RefreshCw className="w-3.5 h-3.5" />}
            >
              Segarkan
            </Button>
          </div>
        </div>

        {/* Status Filter Pills */}
        <div className="flex items-center gap-1.5 overflow-x-auto pt-1">
          {STATUS_TABS.map((tab) => (
            <button
              key={tab.value}
              type="button"
              onClick={() => setStatusFilter(tab.value)}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer shrink-0 ${
                statusFilter === tab.value
                  ? 'bg-blue-600 text-white shadow-2xs'
                  : 'bg-slate-100 text-slate-600 hover:text-slate-900'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </Card>

      {/* Orders Table */}
      <Card padding="none" className="overflow-hidden">
        {orders.length === 0 && !loading ? (
          <EmptyState
            icon={<ShoppingBag className="w-6 h-6" />}
            title="Tidak Ada Data Transaksi"
            description="Belum ada transaksi yang sesuai dengan filter pencarian Anda."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                  <th className="py-3 px-4">No. Struk / Waktu</th>
                  <th className="py-3 px-4">Pelanggan &amp; Meja</th>
                  <th className="py-3 px-4">Kasir &amp; Terminal</th>
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4 text-right">Grand Total</th>
                  <th className="py-3 px-4 text-right">Dibayar / Refund</th>
                  <th className="py-3 px-4 text-right">Tindakan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {orders.map((ord) => (
                  <tr key={ord.id} className="hover:bg-slate-50/80 transition">
                    <td className="py-3 px-4">
                      <div className="font-mono font-bold text-blue-600">
                        {ord.order_number}
                      </div>
                      <div className="text-[11px] text-slate-400">
                        {formatDateTime(ord.created_at)}
                      </div>
                    </td>

                    <td className="py-3 px-4">
                      <div className="font-bold text-slate-900">
                        {ord.customer_name || 'Walk-in Guest'}
                      </div>
                      <div className="text-[11px] text-slate-500">
                        {ord.table_label ? `Meja: ${ord.table_label}` : 'Takeaway / Counter'}
                      </div>
                    </td>

                    <td className="py-3 px-4">
                      <div className="text-slate-800 font-medium">
                        {ord.cashier?.name ?? '-'}
                      </div>
                      <div className="text-[11px] text-slate-400 font-mono">
                        {ord.terminal?.code ?? '-'}
                      </div>
                    </td>

                    <td className="py-3 px-4">{renderStatusBadge(ord.status)}</td>

                    <td className="py-3 px-4 text-right font-extrabold text-slate-900 tabular-nums">
                      {formatIDR(ord.grand_total)}
                    </td>

                    <td className="py-3 px-4 text-right tabular-nums">
                      <div className="text-emerald-600 font-bold">
                        {formatIDR(ord.paid_total)}
                      </div>
                      {parseFloat(ord.refunded_total) > 0 && (
                        <div className="text-[11px] text-rose-600 font-semibold">
                          Refund: -{formatIDR(ord.refunded_total)}
                        </div>
                      )}
                    </td>

                    <td className="py-3 px-4">
                      <div className="flex items-center justify-end gap-1.5">
                        <Button
                          variant="outline"
                          size="xs"
                          icon={<Printer className="w-3.5 h-3.5" />}
                          onClick={() => setReceiptOrder(ord)}
                        >
                          Struk
                        </Button>

                        <Button
                          variant="outline"
                          size="xs"
                          icon={<Eye className="w-3.5 h-3.5" />}
                          onClick={() => openModal(ord, 'view')}
                        >
                          Detail
                        </Button>

                        {ord.status === 'held' && (
                          <Button
                            variant="danger"
                            size="xs"
                            icon={<Ban className="w-3.5 h-3.5" />}
                            onClick={() => openModal(ord, 'cancel')}
                          >
                            Batal
                          </Button>
                        )}

                        {ord.status === 'completed' && (
                          <Button
                            variant="danger"
                            size="xs"
                            icon={<Ban className="w-3.5 h-3.5" />}
                            onClick={() => openModal(ord, 'void')}
                          >
                            Void
                          </Button>
                        )}

                        {(ord.status === 'completed' ||
                          ord.status === 'partially_refunded') && (
                          <Button
                            variant="outline"
                            size="xs"
                            icon={<RotateCcw className="w-3.5 h-3.5 text-blue-600" />}
                            onClick={() => openModal(ord, 'refund')}
                          >
                            Refund
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <Pagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          onPageChange={fetchOrders}
        />
      </Card>

      {/* Order Detail / Void / Refund / Cancel Modal */}
      <Modal
        isOpen={selectedOrder !== null}
        onClose={() => setSelectedOrder(null)}
        title={
          selectedOrder
            ? `${
                actionMode === 'view'
                  ? 'Rincian Transaksi'
                  : actionMode === 'cancel'
                    ? 'Batalkan Draft Order (Cancel)'
                    : actionMode === 'void'
                      ? 'VOID Transaksi Selesai'
                      : 'Proses Refund Transaksi'
              } • ${selectedOrder.order_number}`
            : ''
        }
        subtitle="Seluruh perubahan status transaksi wajib menyertakan alasan dan tercatat ke Audit Trail"
        maxWidth="2xl"
      >
        {selectedOrder && (
          <form onSubmit={handleActionSubmit} className="space-y-4 text-xs">
            {/* Items Table */}
            <div className="bg-slate-50 border border-slate-200 rounded-xl p-3.5 space-y-2">
              <div className="font-bold text-slate-700 border-b border-slate-200 pb-2">
                Daftar Item Pesanan
              </div>
              {selectedOrder.items.map((item) => {
                const remaining = Math.max(
                  0,
                  parseFloat(item.quantity) - parseFloat(item.refunded_quantity),
                );
                return (
                  <div
                    key={item.id}
                    className="flex items-center justify-between gap-3 py-1.5 border-b border-slate-200/60 last:border-none"
                  >
                    <div>
                      <div className="font-bold text-slate-900">
                        {item.product_name} ({item.variant_name})
                      </div>
                      <div className="text-[11px] text-slate-500">
                        Qty: {parseFloat(item.quantity)} &bull; Harga:{' '}
                        {formatIDR(item.unit_price)}
                        {parseFloat(item.refunded_quantity) > 0 && (
                          <span className="text-rose-600 ml-2 font-semibold">
                            (Sudah Refund: {parseFloat(item.refunded_quantity)})
                          </span>
                        )}
                      </div>
                    </div>

                    {actionMode === 'refund' ? (
                      <div className="flex items-center gap-2">
                        <span className="text-[11px] text-slate-500">Qty Refund:</span>
                        <input
                          type="number"
                          min={0}
                          max={remaining}
                          value={refundQtys[item.id] ?? 0}
                          onChange={(e) =>
                            setRefundQtys((prev) => ({
                              ...prev,
                              [item.id]: Math.min(
                                remaining,
                                Math.max(0, parseFloat(e.target.value || '0')),
                              ),
                            }))
                          }
                          className="w-20 px-2.5 py-1.5 rounded-lg bg-white border border-slate-300 text-slate-900 font-bold text-center"
                        />
                      </div>
                    ) : (
                      <div className="font-extrabold text-slate-900 tabular-nums">
                        {formatIDR(item.line_total)}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>

            {actionMode !== 'view' && (
              <div className="space-y-3">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Alasan {actionMode.toUpperCase()} (Wajib untuk Audit Log)
                  </label>
                  <input
                    type="text"
                    required
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    placeholder="Contoh: Kesalahan input item kasir / permintaan pembatalan pelanggan"
                    className="w-full px-3.5 py-2.5 rounded-lg bg-white border border-slate-300 text-slate-900"
                  />
                </div>

                {actionMode === 'refund' && (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-semibold text-slate-700 mb-1">
                        Metode Pengembalian Dana
                      </label>
                      <select
                        value={refundMethod}
                        onChange={(e) => setRefundMethod(e.target.value as PaymentMethodType)}
                        className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-slate-900 font-semibold"
                      >
                        <option value="cash">Tunai (Cash Laci)</option>
                        <option value="qris">QRIS</option>
                        <option value="bank_transfer">Transfer Bank</option>
                      </select>
                    </div>

                    <div className="flex items-end pb-2">
                      <label className="flex items-center gap-2 cursor-pointer text-slate-700 font-semibold">
                        <input
                          type="checkbox"
                          checked={restock}
                          onChange={(e) => setRestock(e.target.checked)}
                          className="rounded border-slate-300"
                        />
                        <span>Kembalikan barang ke Stok Gudang (Restock)</span>
                      </label>
                    </div>
                  </div>
                )}

                {(actionMode === 'void' || actionMode === 'refund') && (
                  <div className="p-3.5 rounded-xl bg-amber-50 border border-amber-200 space-y-2">
                    <div className="flex items-center gap-1.5 text-amber-800 font-bold">
                      <KeyRound className="w-4 h-4 text-amber-600" />
                      <span>Otorisasi PIN Supervisor Wajib</span>
                    </div>
                    <input
                      type="password"
                      required
                      value={supervisorPin}
                      onChange={(e) => setSupervisorPin(e.target.value)}
                      placeholder="Masukkan PIN Supervisor..."
                      className="w-full px-3.5 py-2 rounded-lg bg-white border border-amber-300 text-slate-900 font-mono"
                    />
                  </div>
                )}

                <div className="flex justify-end gap-2 pt-2">
                  <Button variant="outline" onClick={() => setSelectedOrder(null)}>
                    Batal
                  </Button>
                  <Button
                    type="submit"
                    variant={actionMode === 'refund' ? 'primary' : 'danger'}
                    loading={submitting}
                  >
                    Konfirmasi {actionMode.toUpperCase()} Sekarang
                  </Button>
                </div>
              </div>
            )}
          </form>
        )}
      </Modal>

      <ReceiptModal
        isOpen={receiptOrder !== null}
        order={receiptOrder}
        outlet={outlet}
        onClose={() => setReceiptOrder(null)}
      />
    </div>
  );
};
