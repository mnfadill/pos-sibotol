import React, { useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  Boxes,
  ClipboardCheck,
  History,
  KeyRound,
  RefreshCw,
  Search,
  Sliders,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime, formatQty } from '../../lib/format';
import type { InventoryRecord, StockMovementRecord } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { useToast } from '../ui/ToastProvider';

export type InventorySubTab = 'stock' | 'adjust' | 'opname' | 'movements';

interface Props {
  initialTab?: InventorySubTab;
}

export const InventoryControlPage: React.FC<Props> = ({ initialTab = 'stock' }) => {
  const { toast } = useToast();
  const [subTab, setSubTab] = useState<InventorySubTab>(initialTab);

  useEffect(() => {
    setSubTab(initialTab);
  }, [initialTab]);
  const [inventories, setInventories] = useState<InventoryRecord[]>([]);
  const [movements, setMovements] = useState<StockMovementRecord[]>([]);
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [lowStockOnly, setLowStockOnly] = useState<boolean>(false);
  const [loading, setLoading] = useState<boolean>(true);
  const [submitting, setSubmitting] = useState<boolean>(false);

  // Manual Adjustment Form State
  const [adjType, setAdjType] = useState<'damaged' | 'lost' | 'expired' | 'correction'>(
    'damaged',
  );
  const [adjVariantId, setAdjVariantId] = useState<number>(0);
  const [adjQuantity, setAdjQuantity] = useState<string>('1');
  const [adjReason, setAdjReason] = useState<string>('');
  const [adjNotes, setAdjNotes] = useState<string>('');
  const [adjSupervisorPin, setAdjSupervisorPin] = useState<string>('');

  // Opname State
  const [opnameSearch, setOpnameSearch] = useState<string>('');
  const [showVarianceOnly, setShowVarianceOnly] = useState<boolean>(false);
  const [opnameCounts, setOpnameCounts] = useState<Record<number, string>>({});
  const [opnameNotes, setOpnameNotes] = useState<string>('');
  const [opnameSupervisorPin, setOpnameSupervisorPin] = useState<string>('');

  useEffect(() => {
    loadInventoryData();
  }, []);

  const loadInventoryData = async () => {
    setLoading(true);
    try {
      const [invRes, movRes] = await Promise.all([
        api.get<{ inventories: InventoryRecord[] }>('/inventory'),
        api.get<{ data: StockMovementRecord[] }>('/inventory/movements'),
      ]);

      const validInventories = (invRes.data.inventories ?? []).filter(
        (inv) => Boolean(inv.product) && Boolean(inv.variant),
      );
      setInventories(validInventories);
      setMovements(movRes.data.data ?? []);

      if (validInventories.length > 0 && adjVariantId === 0) {
        setAdjVariantId(validInventories[0].product_variant_id);
      }

      const countsMap: Record<number, string> = {};
      validInventories.forEach((inv) => {
        countsMap[inv.product_variant_id] = String(parseFloat(inv.quantity));
      });
      setOpnameCounts(countsMap);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat data inventori',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  const filteredInventories = useMemo(() => {
    return inventories.filter((inv) => {
      if (!inv.product || !inv.variant) return false;
      const qty = parseFloat(inv.quantity);
      const reorder = parseFloat(inv.reorder_level);
      if (lowStockOnly && qty > reorder) return false;
      if (!searchTerm.trim()) return true;
      const q = searchTerm.toLowerCase();
      return (
        (inv.product?.name ?? '').toLowerCase().includes(q) ||
        (inv.variant?.name ?? '').toLowerCase().includes(q) ||
        (inv.variant?.sku ?? '').toLowerCase().includes(q)
      );
    });
  }, [inventories, lowStockOnly, searchTerm]);

  const opnameRows = useMemo(() => {
    return inventories.filter((inv) => {
      if (!inv.product || !inv.variant) return false;
      const sysQty = parseFloat(inv.quantity);
      const countedQty = parseFloat(opnameCounts[inv.product_variant_id] ?? inv.quantity);
      const diff = countedQty - sysQty;
      if (showVarianceOnly && Math.abs(diff) < 0.0001) return false;
      if (!opnameSearch.trim()) return true;
      const q = opnameSearch.toLowerCase();
      return (
        (inv.product?.name ?? '').toLowerCase().includes(q) ||
        (inv.variant?.name ?? '').toLowerCase().includes(q) ||
        (inv.variant?.sku ?? '').toLowerCase().includes(q)
      );
    });
  }, [inventories, opnameCounts, opnameSearch, showVarianceOnly]);

  const varianceCount = useMemo(() => {
    return inventories.filter((inv) => {
      const sysQty = parseFloat(inv.quantity);
      const countedQty = parseFloat(opnameCounts[inv.product_variant_id] ?? inv.quantity);
      return Math.abs(countedQty - sysQty) >= 0.001;
    }).length;
  }, [inventories, opnameCounts]);

  const handleManualAdjustment = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      await api.post('/inventory/adjustments', {
        type: adjType,
        reason: adjReason,
        notes: adjNotes || null,
        supervisor_pin: adjSupervisorPin,
        items: [
          {
            product_variant_id: adjVariantId,
            quantity: parseFloat(adjQuantity || '0'),
          },
        ],
      });
      toast({
        type: 'success',
        title: 'Penyesuaian Stok Manual Berhasil',
        description: 'Tercatat ke Kartu Stok, Audit Log (STOCK_ADJUSTED) & CRM Outbox.',
      });
      setAdjReason('');
      setAdjNotes('');
      setAdjSupervisorPin('');
      await loadInventoryData();
      setSubTab('stock');
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menyimpan penyesuaian stok',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmitting(false);
    }
  };

  const handleStockOpname = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      const items = inventories.map((inv) => ({
        product_variant_id: inv.product_variant_id,
        counted_quantity: parseFloat(opnameCounts[inv.product_variant_id] ?? inv.quantity),
      }));
      await api.post('/inventory/opname', {
        notes: opnameNotes || 'Rekonsiliasi Stock Opname Outlet',
        supervisor_pin: opnameSupervisorPin,
        items,
      });
      toast({
        type: 'success',
        title: 'Stock Opname Berhasil Direkonsiliasi',
        description: 'Selisih fisik vs sistem tercatat di Audit Trail (STOCK_OPNAME_COMPLETED).',
      });
      setOpnameSupervisorPin('');
      await loadInventoryData();
      setSubTab('stock');
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memproses Stock Opname',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="p-5 space-y-4 max-w-[1600px] mx-auto">
      {/* Top Header & Navigation Tabs */}
      <Card padding="sm" className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-base font-extrabold text-slate-900">
              Manajemen Inventori Gudang, Kartu Stok &amp; Stock Opname
            </h2>
            <p className="text-xs text-slate-500">
              Pemotongan stok otomatis saat penjualan serta rekonsiliasi fisik dengan otorisasi PIN Supervisor.
            </p>
          </div>

          <Button
            variant="outline"
            size="sm"
            onClick={loadInventoryData}
            loading={loading}
            icon={<RefreshCw className="w-3.5 h-3.5" />}
          >
            Segarkan Data
          </Button>
        </div>

        <div className="flex flex-wrap gap-1.5 pt-1">
          <button
            type="button"
            onClick={() => setSubTab('stock')}
            className={`px-3.5 py-2 rounded-lg text-xs font-bold flex items-center gap-1.5 transition cursor-pointer ${
              subTab === 'stock'
                ? 'bg-blue-600 text-white shadow-2xs'
                : 'bg-slate-100 text-slate-600 hover:text-slate-900'
            }`}
          >
            <Boxes className="w-3.5 h-3.5" />
            <span>Stok Gudang &amp; Kartu Stok</span>
          </button>

          <button
            type="button"
            onClick={() => setSubTab('adjust')}
            className={`px-3.5 py-2 rounded-lg text-xs font-bold flex items-center gap-1.5 transition cursor-pointer ${
              subTab === 'adjust'
                ? 'bg-blue-600 text-white shadow-2xs'
                : 'bg-slate-100 text-slate-600 hover:text-slate-900'
            }`}
          >
            <Sliders className="w-3.5 h-3.5" />
            <span>Penyesuaian Manual (Rusak / Hilang / Expired)</span>
          </button>

          <button
            type="button"
            onClick={() => setSubTab('opname')}
            className={`px-3.5 py-2 rounded-lg text-xs font-bold flex items-center gap-1.5 transition cursor-pointer ${
              subTab === 'opname'
                ? 'bg-blue-600 text-white shadow-2xs'
                : 'bg-slate-100 text-slate-600 hover:text-slate-900'
            }`}
          >
            <ClipboardCheck className="w-3.5 h-3.5" />
            <span>Stock Opname &amp; Rekonsiliasi Fisik</span>
            {varianceCount > 0 && (
              <span className="px-1.5 py-0.2 rounded-full bg-amber-500 text-white text-[10px]">
                {varianceCount} Selisih
              </span>
            )}
          </button>

          <button
            type="button"
            onClick={() => setSubTab('movements')}
            className={`px-3.5 py-2 rounded-lg text-xs font-bold flex items-center gap-1.5 transition cursor-pointer ${
              subTab === 'movements'
                ? 'bg-blue-600 text-white shadow-2xs'
                : 'bg-slate-100 text-slate-600 hover:text-slate-900'
            }`}
          >
            <History className="w-3.5 h-3.5" />
            <span>Riwayat Mutasi Stok ({movements.length})</span>
          </button>
        </div>
      </Card>

      {/* TAB 4: DEDICATED FULL-WIDTH RIWAYAT MUTASI */}
      {subTab === 'movements' && (
        <Card
          title={`Riwayat Mutasi Persediaan (Stock Ledger — ${movements.length} Transaksi)`}
          subtitle="Seluruh pergerakan stok masuk, keluar, penjualan, transfer, dan penyesuaian tercatat dalam Stock UOM."
        >
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                  <th className="py-3 px-4">Waktu</th>
                  <th className="py-3 px-4">Tipe Mutasi</th>
                  <th className="py-3 px-4">Produk / Varian</th>
                  <th className="py-3 px-4 text-right">Perubahan Qty</th>
                  <th className="py-3 px-4 text-right">Saldo Sebelum</th>
                  <th className="py-3 px-4 text-right">Saldo Sesudah</th>
                  <th className="py-3 px-4">Keterangan / Petugas</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {movements.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="py-10 text-center text-slate-400">
                      Belum ada riwayat mutasi stok.
                    </td>
                  </tr>
                ) : (
                  movements.map((m) => {
                    const delta = parseFloat(m.quantity_change);
                    return (
                      <tr key={m.id} className="hover:bg-slate-50/70">
                        <td className="py-3 px-4 text-slate-500 whitespace-nowrap">
                          {formatDateTime(m.created_at)}
                        </td>
                        <td className="py-3 px-4">
                          <Badge variant={delta >= 0 ? 'success' : 'warning'} size="xs">
                            {(m.movement_type || m.type || 'MUTASI').toUpperCase()}
                          </Badge>
                        </td>
                        <td className="py-3 px-4">
                          <div className="font-bold text-slate-900">
                            {m.variant?.product?.name || m.variant?.name || `Varian #${m.product_variant_id ?? m.id}`}
                          </div>
                          <div className="text-[11px] font-mono text-slate-500">
                            {m.variant?.sku}
                          </div>
                        </td>
                        <td
                          className={`py-3 px-4 text-right font-extrabold tabular-nums ${
                            delta >= 0 ? 'text-emerald-700' : 'text-rose-600'
                          }`}
                        >
                          {delta >= 0 ? `+${formatQty(m.quantity_change)}` : formatQty(m.quantity_change)}
                        </td>
                        <td className="py-3 px-4 text-right tabular-nums text-slate-600">
                          {formatQty(m.quantity_before)}
                        </td>
                        <td className="py-3 px-4 text-right tabular-nums font-bold text-slate-900">
                          {formatQty(m.quantity_after)}
                        </td>
                        <td className="py-3 px-4 text-slate-600">
                          <div>{m.notes || '—'}</div>
                          {m.user && (
                            <div className="text-[11px] text-slate-400">oleh {m.user.name}</div>
                          )}
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* TAB 1: STOCK LEVELS & LEDGER MOVEMENTS */}
      {subTab === 'stock' && (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
          {/* Stock Levels Table (7 cols) */}
          <Card padding="none" className="lg:col-span-7 overflow-hidden">
            <div className="p-4 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
              <div className="text-sm font-bold text-slate-900">
                Posisi Stok Real-Time Outlet ({filteredInventories.length} Varian)
              </div>

              <div className="flex items-center gap-2">
                <div className="relative">
                  <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                  <input
                    type="text"
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    placeholder="Cari produk / SKU..."
                    className="pl-8 pr-3 py-1.5 rounded-lg bg-slate-50 border border-slate-200 text-xs text-slate-900 w-44"
                  />
                </div>
                <label className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={lowStockOnly}
                    onChange={(e) => setLowStockOnly(e.target.checked)}
                    className="rounded border-slate-300 text-blue-600"
                  />
                  <span>Stok Kritis</span>
                </label>
              </div>
            </div>

            {filteredInventories.length === 0 ? (
              <EmptyState
                icon={<Boxes className="w-6 h-6" />}
                title="Tidak Ada Varian Stok"
                description="Semua varian produk dalam kondisi aman atau tidak cocok dengan kata kunci."
              />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse text-xs">
                  <thead>
                    <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                      <th className="py-2.5 px-4">Produk &amp; Varian</th>
                      <th className="py-2.5 px-4">SKU</th>
                      <th className="py-2.5 px-4 text-right">Stok Sistem</th>
                      <th className="py-2.5 px-4 text-right">Batas Min</th>
                      <th className="py-2.5 px-4">Status</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {filteredInventories.map((inv) => {
                      const qty = parseFloat(inv.quantity);
                      const reorder = parseFloat(inv.reorder_level);
                      const isLow = qty <= reorder;

                      return (
                        <tr key={inv.id} className="hover:bg-slate-50/80">
                          <td className="py-3 px-4">
                            <div className="font-bold text-slate-900">{inv.product?.name ?? 'Produk'}</div>
                            <div className="text-[11px] text-slate-500">{inv.variant?.name ?? 'Standard'}</div>
                          </td>
                          <td className="py-3 px-4 font-mono text-slate-600">
                            {inv.variant?.sku ?? '-'}
                          </td>
                          <td className="py-3 px-4 text-right font-extrabold text-slate-900 tabular-nums">
                            {formatQty(qty)} {inv.product?.unit ?? 'PCS'}
                          </td>
                          <td className="py-3 px-4 text-right text-slate-500 tabular-nums">
                            {formatQty(reorder)}
                          </td>
                          <td className="py-3 px-4">
                            {isLow ? (
                              <Badge variant="danger">
                                <AlertTriangle className="w-3 h-3" />
                                Low Stock
                              </Badge>
                            ) : (
                              <Badge variant="success">Aman</Badge>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          {/* Stock Movements Ledger (5 cols) */}
          <Card padding="none" className="lg:col-span-5 overflow-hidden">
            <div className="p-4 border-b border-slate-200 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <History className="w-4 h-4 text-blue-600" />
                <span className="text-sm font-bold text-slate-900">
                  Kartu Mutasi Stok (Append-Only Ledger)
                </span>
              </div>
            </div>

            <div className="divide-y divide-slate-100 max-h-[540px] overflow-y-auto text-xs">
              {movements.map((mov) => {
                const delta = parseFloat(mov.quantity_change);
                return (
                  <div key={mov.id} className="p-3.5 flex items-center justify-between gap-2">
                    <div className="min-w-0">
                      <div className="font-bold text-slate-900 truncate">
                        {mov.variant?.product?.name} ({mov.variant?.name})
                      </div>
                      <div className="text-[11px] text-slate-500 truncate">
                        <span className="uppercase font-mono font-bold text-blue-600">
                          [{mov.type}]
                        </span>{' '}
                        {mov.notes}
                      </div>
                      <div className="text-[10px] text-slate-400 mt-0.5">
                        {formatDateTime(mov.created_at)} &bull; Oleh:{' '}
                        {mov.user?.name ?? 'System'}
                      </div>
                    </div>

                    <div className="text-right shrink-0 tabular-nums">
                      <div
                        className={`font-extrabold ${
                          delta >= 0 ? 'text-emerald-600' : 'text-rose-600'
                        }`}
                      >
                        {delta >= 0 ? `+${formatQty(delta)}` : formatQty(delta)}
                      </div>
                      <div className="text-[10px] text-slate-400">
                        {formatQty(mov.quantity_before)} &rarr; {formatQty(mov.quantity_after)}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </Card>
        </div>
      )}

      {/* TAB 2: MANUAL STOCK ADJUSTMENT */}
      {subTab === 'adjust' && (
        <Card className="max-w-2xl">
          <form onSubmit={handleManualAdjustment} className="space-y-4 text-xs">
            <div className="border-b border-slate-100 pb-3">
              <h3 className="text-sm font-bold text-slate-900">
                Formulir Penyesuaian Stok Manual (Damaged / Lost / Expired / Correction)
              </h3>
              <p className="text-xs text-slate-500 mt-0.5">
                Membutuhkan otorisasi PIN Supervisor dan otomatis dicatat ke Audit Log (<code className="font-mono">STOCK_ADJUSTED</code>).
              </p>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Kategori Penyesuaian
                </label>
                <select
                  value={adjType}
                  onChange={(e) =>
                    setAdjType(
                      e.target.value as 'damaged' | 'lost' | 'expired' | 'correction',
                    )
                  }
                  className="w-full px-3 py-2.5 rounded-lg bg-white border border-slate-300 text-slate-900 font-semibold"
                >
                  <option value="damaged">Barang Rusak / Pecah (Damaged — Kurangi Stok)</option>
                  <option value="lost">Barang Hilang / Selisih (Lost — Kurangi Stok)</option>
                  <option value="expired">Barang Kedaluwarsa (Expired — Kurangi Stok)</option>
                  <option value="correction">Koreksi Manual (+/- Sesuai Nilai)</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Pilih Varian Produk
                </label>
                <select
                  value={adjVariantId}
                  onChange={(e) => setAdjVariantId(Number(e.target.value))}
                  className="w-full px-3 py-2.5 rounded-lg bg-white border border-slate-300 text-slate-900 font-semibold"
                >
                  {inventories.map((inv) => (
                    <option key={inv.product_variant_id} value={inv.product_variant_id}>
                      {inv.product?.name ?? 'Produk'} ({inv.variant?.name ?? 'Standard'}) — Stok: {formatQty(inv.quantity)}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Jumlah Kuantitas ({adjType === 'correction' ? 'Bisa + atau -' : 'Angka Positif'})
                </label>
                <input
                  type="number"
                  step="1"
                  required
                  value={adjQuantity}
                  onChange={(e) => setAdjQuantity(e.target.value)}
                  className="w-full px-3.5 py-2.5 rounded-lg bg-white border border-slate-300 text-slate-900 font-bold"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Alasan Penyesuaian (Wajib)
                </label>
                <input
                  type="text"
                  required
                  value={adjReason}
                  onChange={(e) => setAdjReason(e.target.value)}
                  placeholder="Contoh: Botol pecah saat loading chiller"
                  className="w-full px-3.5 py-2.5 rounded-lg bg-white border border-slate-300 text-slate-900"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Catatan Tambahan (Opsional)
              </label>
              <input
                type="text"
                value={adjNotes}
                onChange={(e) => setAdjNotes(e.target.value)}
                placeholder="Keterangan kronologi atau nomor berita acara"
                className="w-full px-3.5 py-2.5 rounded-lg bg-white border border-slate-300 text-slate-900"
              />
            </div>

            <div className="p-4 rounded-xl bg-amber-50 border border-amber-200 space-y-2">
              <div className="flex items-center gap-1.5 text-amber-800 font-bold">
                <KeyRound className="w-4 h-4 text-amber-600" />
                <span>Otorisasi PIN Supervisor Wajib</span>
              </div>
              <input
                type="password"
                required
                value={adjSupervisorPin}
                onChange={(e) => setAdjSupervisorPin(e.target.value)}
                placeholder="Masukkan PIN Supervisor untuk mengesahkan penyesuaian stok..."
                className="w-full px-3.5 py-2 rounded-lg bg-white border border-amber-300 text-slate-900 font-mono"
              />
            </div>

            <Button type="submit" variant="primary" size="lg" fullWidth loading={submitting}>
              Proses Penyesuaian Stok Sekarang
            </Button>
          </form>
        </Card>
      )}

      {/* TAB 3: STOCK OPNAME RECONCILIATION */}
      {subTab === 'opname' && (
        <form onSubmit={handleStockOpname}>
          <Card padding="none" className="overflow-hidden">
            <div className="p-4 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
              <div>
                <h3 className="text-sm font-bold text-slate-900">
                  Rekonsiliasi Stock Opname Outlet
                </h3>
                <p className="text-xs text-slate-500">
                  Masukkan hasil hitung fisik gudang. Baris yang memiliki selisih akan ditandai otomatis.
                </p>
              </div>

              <div className="flex flex-wrap items-center gap-3">
                <div className="relative">
                  <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                  <input
                    type="text"
                    value={opnameSearch}
                    onChange={(e) => setOpnameSearch(e.target.value)}
                    placeholder="Filter produk / SKU..."
                    className="pl-8 pr-3 py-1.5 rounded-lg bg-slate-50 border border-slate-200 text-xs text-slate-900 w-48"
                  />
                </div>

                <label className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={showVarianceOnly}
                    onChange={(e) => setShowVarianceOnly(e.target.checked)}
                    className="rounded border-slate-300 text-blue-600"
                  />
                  <span>Tampilkan Hanya yang Berselisih ({varianceCount})</span>
                </label>
              </div>
            </div>

            <div className="overflow-x-auto max-h-[420px]">
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold sticky top-0">
                    <th className="py-2.5 px-4">Produk &amp; Varian</th>
                    <th className="py-2.5 px-4">SKU</th>
                    <th className="py-2.5 px-4 text-right">Stok Sistem</th>
                    <th className="py-2.5 px-4 text-right">Hitung Fisik Aktual</th>
                    <th className="py-2.5 px-4 text-right">Selisih (Variance)</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {opnameRows.map((inv) => {
                    const sysQty = parseFloat(inv.quantity);
                    const countedQty = parseFloat(
                      opnameCounts[inv.product_variant_id] ?? inv.quantity,
                    );
                    const diff = countedQty - sysQty;
                    const hasDiff = Math.abs(diff) >= 0.001;

                    return (
                      <tr
                        key={inv.id}
                        className={hasDiff ? 'bg-amber-50/50' : 'hover:bg-slate-50/80'}
                      >
                        <td className="py-2.5 px-4 font-bold text-slate-900">
                          {inv.product?.name ?? 'Produk'}{' '}
                          <span className="text-blue-600 font-semibold">
                            ({inv.variant?.name ?? 'Standard'})
                          </span>
                        </td>
                        <td className="py-2.5 px-4 font-mono text-slate-600">
                          {inv.variant?.sku ?? '-'}
                        </td>
                        <td className="py-2.5 px-4 text-right font-semibold text-slate-600 tabular-nums">
                          {formatQty(sysQty)} {inv.product?.unit ?? 'PCS'}
                        </td>
                        <td className="py-2.5 px-4 text-right">
                          <input
                            type="number"
                            min={0}
                            step="1"
                            value={opnameCounts[inv.product_variant_id] ?? ''}
                            onChange={(e) =>
                              setOpnameCounts((prev) => ({
                                ...prev,
                                [inv.product_variant_id]: e.target.value,
                              }))
                            }
                            className="w-28 px-2.5 py-1.5 rounded-lg bg-white border border-slate-300 text-slate-900 font-bold text-right tabular-nums focus:outline-none focus:border-blue-600"
                          />
                        </td>
                        <td className="py-2.5 px-4 text-right font-extrabold tabular-nums">
                          {!hasDiff ? (
                            <span className="text-slate-400">Sesuai (0)</span>
                          ) : (
                            <span className={diff > 0 ? 'text-emerald-600' : 'text-rose-600'}>
                              {diff > 0 ? `+${formatQty(diff)}` : formatQty(diff)}
                            </span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <div className="p-4 bg-slate-50 border-t border-slate-200 grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
              <div className="md:col-span-5">
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Catatan Rekonsiliasi Stock Opname
                </label>
                <input
                  type="text"
                  value={opnameNotes}
                  onChange={(e) => setOpnameNotes(e.target.value)}
                  placeholder="Contoh: Stock Opname penutupan shift malam"
                  className="w-full px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-xs text-slate-900"
                />
              </div>

              <div className="md:col-span-4">
                <label className="block text-xs font-semibold text-amber-800 mb-1">
                  PIN Supervisor Otorisasi Opname
                </label>
                <input
                  type="password"
                  required
                  value={opnameSupervisorPin}
                  onChange={(e) => setOpnameSupervisorPin(e.target.value)}
                  placeholder="Masukkan PIN Supervisor..."
                  className="w-full px-3.5 py-2 rounded-lg bg-white border border-amber-300 text-xs text-slate-900 font-mono"
                />
              </div>

              <div className="md:col-span-3">
                <Button
                  type="submit"
                  variant="primary"
                  fullWidth
                  loading={submitting}
                >
                  Simpan Stock Opname ({varianceCount} Selisih)
                </Button>
              </div>
            </div>
          </Card>
        </form>
      )}
    </div>
  );
};
