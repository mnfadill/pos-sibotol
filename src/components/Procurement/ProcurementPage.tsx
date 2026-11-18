import React, { useEffect, useMemo, useState } from 'react';
import {
  CheckCircle2,
  FileText,
  Layers,
  PackagePlus,
  Plus,
  RefreshCw,
  Truck,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatIDR, formatQty } from '../../lib/format';
import type { ProductCatalog, ProductUnit, WarehouseInfo } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { Modal } from '../ui/Modal';
import { useToast } from '../ui/ToastProvider';

interface SupplierRecord {
  id: number;
  code: string;
  name: string;
  contact_person?: string | null;
  phone?: string | null;
  city?: string | null;
  payment_term_days: number;
}

interface PurchaseOrderRecord {
  id: number;
  po_number: string;
  status: string;
  order_date: string;
  expected_date?: string | null;
  grand_total: string;
  supplier?: SupplierRecord;
  target_warehouse?: WarehouseInfo;
  items: Array<{
    id: number;
    sku: string;
    product_name: string;
    variant_name: string;
    uom_code?: string;
    conversion_factor?: string;
    ordered_qty: string;
    received_qty: string;
    damaged_qty: string;
    unit_cost: string;
    line_total: string;
  }>;
}

export type ProcurementSubTab = 'po' | 'receiving' | 'history';

interface Props {
  initialTab?: ProcurementSubTab;
}

export const ProcurementPage: React.FC<Props> = ({ initialTab = 'po' }) => {
  const { toast } = useToast();
  const [subTab, setSubTab] = useState<ProcurementSubTab>(initialTab);
  const [suppliers, setSuppliers] = useState<SupplierRecord[]>([]);
  const [warehouses, setWarehouses] = useState<WarehouseInfo[]>([]);
  const [purchaseOrders, setPurchaseOrders] = useState<PurchaseOrderRecord[]>([]);
  const [products, setProducts] = useState<ProductCatalog[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [receivingPoId, setReceivingPoId] = useState<number | null>(null);

  // Create PO Modal
  const [showPoModal, setShowPoModal] = useState<boolean>(false);
  const [supplierId, setSupplierId] = useState<number>(0);
  const [warehouseId, setWarehouseId] = useState<number>(0);
  const [variantId, setVariantId] = useState<number>(0);
  const [selectedUomCode, setSelectedUomCode] = useState<string>('BTL');
  const [orderedQty, setOrderedQty] = useState<string>('10');
  const [unitCost, setUnitCost] = useState<string>('20000');
  const [notes, setNotes] = useState<string>('Pengadaan stok dari Principal');
  const [submitting, setSubmitting] = useState<boolean>(false);

  useEffect(() => {
    setSubTab(initialTab);
  }, [initialTab]);

  const fetchData = async () => {
    setLoading(true);
    try {
      const [procRes, prodRes] = await Promise.all([
        api.get<{
          suppliers: SupplierRecord[];
          warehouses: WarehouseInfo[];
          purchase_orders: PurchaseOrderRecord[];
        }>('/procurement'),
        api.get<{ products: ProductCatalog[] }>('/catalog/products'),
      ]);

      setSuppliers(procRes.data.suppliers);
      setWarehouses(procRes.data.warehouses);
      setPurchaseOrders(procRes.data.purchase_orders);
      setProducts(prodRes.data.products);

      if (procRes.data.suppliers[0] && !supplierId) setSupplierId(procRes.data.suppliers[0].id);
      if (procRes.data.warehouses[0] && !warehouseId) setWarehouseId(procRes.data.warehouses[0].id);
      const firstProd = prodRes.data.products[0];
      const firstVar = firstProd?.variants?.[0];
      if (firstVar && !variantId) {
        setVariantId(firstVar.id);
        setSelectedUomCode((firstProd.unit || 'BTL').toUpperCase());
        setUnitCost(String(Number(firstVar.cost_price || 20000)));
      }
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat data Pengadaan',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, []);

  const allVariants = useMemo(
    () =>
      products.flatMap((p) =>
        p.variants.map((v) => ({
          id: v.id,
          product: p,
          variant: v,
          baseUnit: (p.unit || 'BTL').toUpperCase(),
          label: `${p.name} (${v.sku})`,
          units: (v.units || p.product_units || []) as ProductUnit[],
        })),
      ),
    [products],
  );

  const selectedEntry = useMemo(
    () => allVariants.find((x) => x.id === variantId) ?? allVariants[0] ?? null,
    [allVariants, variantId],
  );

  const availableUnitsForSelected = useMemo(() => {
    if (!selectedEntry) return [];
    const baseCode = selectedEntry.baseUnit;
    const baseCost = Number(selectedEntry.variant.cost_price || 0);
    const list: Array<{ code: string; name: string; factor: number; cost: number; unitId?: number }> = [
      { code: baseCode, name: `${baseCode} (Satuan Dasar)`, factor: 1, cost: baseCost },
    ];
    selectedEntry.units
      .filter((u) => !u.is_base && u.is_purchase_unit !== false && u.unit_code.toUpperCase() !== baseCode)
      .forEach((u) => {
        const f = Number(u.conversion_factor || 1);
        list.push({
          unitId: u.id,
          code: u.unit_code.toUpperCase(),
          name: `${u.unit_code.toUpperCase()} — ${u.unit_name} (1 ${u.unit_code.toUpperCase()} = ${f} ${baseCode})`,
          factor: f,
          cost: Number(u.purchase_cost ?? baseCost * f),
        });
      });
    return list;
  }, [selectedEntry]);

  const currentUnitMeta = useMemo(
    () =>
      availableUnitsForSelected.find((u) => u.code === selectedUomCode) ??
      availableUnitsForSelected[0] ?? { code: 'BTL', name: 'BTL', factor: 1, cost: 0 },
    [availableUnitsForSelected, selectedUomCode],
  );

  const handleSelectVariant = (newVarId: number) => {
    setVariantId(newVarId);
    const entry = allVariants.find((x) => x.id === newVarId);
    if (entry) {
      const baseCode = entry.baseUnit;
      setSelectedUomCode(baseCode);
      setUnitCost(String(Number(entry.variant.cost_price || 0)));
    }
  };

  const handleSelectUom = (code: string) => {
    setSelectedUomCode(code);
    const found = availableUnitsForSelected.find((u) => u.code === code);
    if (found) {
      setUnitCost(String(found.cost));
    }
  };

  const handleCreatePo = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      await api.post('/procurement/purchase-orders', {
        supplier_id: supplierId,
        target_warehouse_id: warehouseId,
        notes,
        items: [
          {
            product_variant_id: variantId,
            product_unit_id: currentUnitMeta.unitId,
            uom_code: currentUnitMeta.code,
            ordered_qty: Number(orderedQty),
            unit_cost: Number(unitCost),
          },
        ],
      });
      toast({
        type: 'success',
        title: 'Purchase Order Dibuat',
        description: 'PO berhasil diterbitkan ke Principal / Supplier.',
      });
      setShowPoModal(false);
      fetchData();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal membuat PO',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmitting(false);
    }
  };

  const handleReceivePo = async (poId: number) => {
    setReceivingPoId(poId);
    try {
      await api.post(`/procurement/purchase-orders/${poId}/receive`);
      toast({
        type: 'success',
        title: 'Penerimaan Barang (Goods Receipt) Selesai',
        description:
          'Barang diterima, dikonversi otomatis ke Stock UOM, dan stok gudang tujuan telah ditambahkan.',
      });
      fetchData();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menerima barang PO',
        description: extractErrorMessage(err),
      });
    } finally {
      setReceivingPoId(null);
    }
  };

  const visiblePurchaseOrders = useMemo(() => {
    if (subTab === 'receiving') {
      return purchaseOrders.filter((po) => po.status !== 'received' && po.status !== 'cancelled');
    }
    if (subTab === 'history') {
      return purchaseOrders.filter((po) => po.status === 'received');
    }
    return purchaseOrders;
  }, [purchaseOrders, subTab]);

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-lg font-extrabold text-slate-900">
            Pengadaan: Purchase Order, Penerimaan Barang &amp; Riwayat Pembelian
          </h1>
          <p className="text-xs text-slate-500">
            Pemesanan barang ke Supplier/Principal dalam satuan kemasan (Dus/Karton/Botol) dengan
            konversi stok gudang otomatis saat penerimaan.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex rounded-xl bg-slate-100 p-1 border border-slate-200 text-xs">
            {(
              [
                { id: 'po', label: `Purchase Order (${purchaseOrders.length})` },
                {
                  id: 'receiving',
                  label: `Penerimaan Barang (${
                    purchaseOrders.filter((p) => p.status !== 'received' && p.status !== 'cancelled')
                      .length
                  })`,
                },
                {
                  id: 'history',
                  label: `Riwayat Pembelian (${
                    purchaseOrders.filter((p) => p.status === 'received').length
                  })`,
                },
              ] as const
            ).map((t) => (
              <button
                key={t.id}
                type="button"
                onClick={() => setSubTab(t.id)}
                className={`px-3 py-1.5 rounded-lg font-bold transition cursor-pointer ${
                  subTab === t.id
                    ? 'bg-white text-slate-900 shadow-2xs'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                {t.label}
              </button>
            ))}
          </div>

          <Button
            variant="outline"
            size="sm"
            loading={loading}
            leftIcon={<RefreshCw className="w-4 h-4" />}
            onClick={fetchData}
          >
            Refresh
          </Button>
          <Button
            variant="primary"
            size="sm"
            leftIcon={<Plus className="w-4 h-4" />}
            onClick={() => setShowPoModal(true)}
          >
            Buat Purchase Order (PO)
          </Button>
        </div>
      </div>

      {/* Purchase Orders Table */}
      <Card
        title={
          subTab === 'receiving'
            ? 'Daftar PO Menunggu Penerimaan Barang (Goods Receipt)'
            : subTab === 'history'
              ? 'Riwayat Pembelian Selesai Diterima'
              : 'Daftar Seluruh Purchase Order (PO)'
        }
        subtitle="Klik 'Terima Barang (Goods Receive)' ketika barang tiba di gudang untuk menambah stok secara otomatis."
      >
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50 text-[11px] font-bold uppercase text-slate-500">
                <th className="py-3 px-4">No. PO &amp; Tanggal</th>
                <th className="py-3 px-4">Supplier / Principal</th>
                <th className="py-3 px-4">Gudang Tujuan</th>
                <th className="py-3 px-4">Item &amp; Satuan Kemasan</th>
                <th className="py-3 px-4 text-right">Total Nilai PO</th>
                <th className="py-3 px-4">Status</th>
                <th className="py-3 px-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {visiblePurchaseOrders.length === 0 ? (
                <tr>
                  <td colSpan={7} className="py-10 text-center text-slate-400">
                    Tidak ada dokumen Purchase Order pada tab ini.
                  </td>
                </tr>
              ) : (
                visiblePurchaseOrders.map((po) => (
                  <tr key={po.id} className="hover:bg-slate-50/70">
                    <td className="py-3.5 px-4">
                      <div className="font-mono font-bold text-slate-900 flex items-center gap-1.5">
                        <FileText className="w-3.5 h-3.5 text-blue-600" />
                        {po.po_number}
                      </div>
                      <div className="text-[11px] text-slate-400">Tgl Order: {po.order_date}</div>
                    </td>
                    <td className="py-3.5 px-4 font-semibold text-slate-800">
                      {po.supplier?.name}
                    </td>
                    <td className="py-3.5 px-4">
                      <Badge variant="info" size="xs">
                        {po.target_warehouse?.code}
                      </Badge>
                      <div className="text-[11px] text-slate-500 mt-0.5">
                        {po.target_warehouse?.name}
                      </div>
                    </td>
                    <td className="py-3.5 px-4">
                      {po.items.map((item) => {
                        const uom = (item.uom_code || 'BTL').toUpperCase();
                        const factor = Number(item.conversion_factor || 1);
                        const baseTotal = Number(item.ordered_qty) * factor;
                        return (
                          <div key={item.id} className="text-xs text-slate-700">
                            <b>{item.product_name}</b> &mdash; Order:{' '}
                            <b>
                              {formatQty(item.ordered_qty)} {uom}
                            </b>{' '}
                            {factor > 1 && (
                              <span className="text-[11px] text-blue-700 font-semibold">
                                (= {baseTotal} unit dasar)
                              </span>
                            )}{' '}
                            | Diterima: <b>{formatQty(item.received_qty)}</b>
                          </div>
                        );
                      })}
                    </td>
                    <td className="py-3.5 px-4 text-right font-extrabold text-slate-900 tabular-nums">
                      {formatIDR(po.grand_total)}
                    </td>
                    <td className="py-3.5 px-4">
                      <Badge
                        variant={
                          po.status === 'received'
                            ? 'success'
                            : po.status === 'partially_received'
                              ? 'warning'
                              : 'info'
                        }
                        dot
                        size="xs"
                      >
                        {po.status.toUpperCase()}
                      </Badge>
                    </td>
                    <td className="py-3.5 px-4 text-right">
                      {po.status !== 'received' && po.status !== 'cancelled' && (
                        <Button
                          variant="success"
                          size="xs"
                          loading={receivingPoId === po.id}
                          leftIcon={<CheckCircle2 className="w-3.5 h-3.5" />}
                          onClick={() => handleReceivePo(po.id)}
                        >
                          Terima Barang (Goods Receive)
                        </Button>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </Card>

      {/* Modal Buat Purchase Order */}
      <Modal
        open={showPoModal}
        onClose={() => setShowPoModal(false)}
        title="Buat Purchase Order (PO) ke Supplier / Principal"
        description="Pilih satuan pembelian (misal Dus, Karton, atau Botol). Saat diterima, stok otomatis dikonversi ke Satuan Dasar."
      >
        <form onSubmit={handleCreatePo} className="space-y-4 text-xs">
          <div>
            <label className="block font-bold text-slate-700 mb-1">Principal / Supplier</label>
            <select
              value={supplierId}
              onChange={(e) => setSupplierId(Number(e.target.value))}
              className="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white font-semibold"
            >
              {suppliers.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name} ({s.code})
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block font-bold text-slate-700 mb-1">
              Gudang Tujuan Penerimaan (Gudang Pusat / Gudang Cabang)
            </label>
            <select
              value={warehouseId}
              onChange={(e) => setWarehouseId(Number(e.target.value))}
              className="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white font-semibold"
            >
              {warehouses.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name} ({w.code} — {w.type.toUpperCase()})
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block font-bold text-slate-700 mb-1">Produk</label>
            <select
              value={variantId}
              onChange={(e) => handleSelectVariant(Number(e.target.value))}
              className="w-full px-3 py-2.5 rounded-xl border border-slate-300 bg-white font-semibold"
            >
              {allVariants.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.label}
                </option>
              ))}
            </select>
          </div>

          {/* Purchase Packaging Unit Selection */}
          <div>
            <label className="block font-bold text-slate-700 mb-1 flex items-center gap-1.5">
              <Layers className="w-3.5 h-3.5 text-blue-600" />
              Satuan / Kemasan Pembelian (Purchase UOM)
            </label>
            <select
              value={currentUnitMeta.code}
              onChange={(e) => handleSelectUom(e.target.value)}
              className="w-full px-3 py-2.5 rounded-xl border border-blue-400 bg-blue-50/40 font-bold text-blue-950"
            >
              {availableUnitsForSelected.map((u) => (
                <option key={u.code} value={u.code}>
                  {u.name}
                </option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block font-bold text-slate-700 mb-1">
                Jumlah Order (dalam {currentUnitMeta.code})
              </label>
              <input
                type="number"
                min="1"
                required
                value={orderedQty}
                onChange={(e) => setOrderedQty(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-slate-300 font-bold"
              />
            </div>
            <div>
              <label className="block font-bold text-slate-700 mb-1">
                Harga Beli per {currentUnitMeta.code} (Rp)
              </label>
              <input
                type="number"
                min="0"
                required
                value={unitCost}
                onChange={(e) => setUnitCost(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-slate-300 font-bold"
              />
            </div>
          </div>

          {/* Automatic Stock Conversion Preview */}
          {selectedEntry && (
            <div className="p-3 rounded-xl bg-emerald-50 border border-emerald-200 flex items-center justify-between text-emerald-900">
              <div>
                <div className="font-bold">Preview Konversi Stok Masuk:</div>
                <div className="text-[11px]">
                  {Number(orderedQty) || 0} {currentUnitMeta.code} &times; {currentUnitMeta.factor}{' '}
                  {selectedEntry.baseUnit} ={' '}
                  <b>
                    {(Number(orderedQty) || 0) * currentUnitMeta.factor} {selectedEntry.baseUnit}
                  </b>
                </div>
              </div>
              <div className="text-right">
                <div className="text-[11px] text-emerald-700">Subtotal Item</div>
                <div className="font-extrabold">
                  {formatIDR((Number(orderedQty) || 0) * (Number(unitCost) || 0))}
                </div>
              </div>
            </div>
          )}

          <div>
            <label className="block font-bold text-slate-700 mb-1">Catatan PO</label>
            <input
              type="text"
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              className="w-full px-3 py-2 rounded-xl border border-slate-300"
            />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" type="button" onClick={() => setShowPoModal(false)}>
              Batal
            </Button>
            <Button
              variant="primary"
              type="submit"
              loading={submitting}
              leftIcon={<PackagePlus className="w-4 h-4" />}
            >
              Terbitkan Purchase Order
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  );
};
