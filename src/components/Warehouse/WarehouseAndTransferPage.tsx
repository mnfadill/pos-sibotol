import React, { useEffect, useState } from 'react';
import {
  ArrowRightLeft,
  CheckCircle2,
  Package,
  Plus,
  RefreshCw,
  Send,
  Truck,
  Warehouse,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime, formatQty } from '../../lib/format';
import type { ProductCatalog, WarehouseInfo } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card, StatCard } from '../ui/Card';
import { Modal } from '../ui/Modal';
import { SearchableSelect } from '../ui/SearchableSelect';
import { useToast } from '../ui/ToastProvider';

interface WarehouseWithInventory extends WarehouseInfo {
  inventories?: Array<{
    id: number;
    product_variant_id: number;
    quantity: string;
    reserved_quantity: string;
    in_transit_quantity: string;
    reorder_level: string;
    variant?: {
      id: number;
      sku: string;
      name: string;
      product?: {
        id: number;
        name: string;
        unit: string;
      };
    };
  }>;
}

interface StockTransferRecord {
  id: number;
  transfer_number: string;
  status: string;
  requested_at: string;
  shipped_at?: string | null;
  received_at?: string | null;
  notes?: string | null;
  source_warehouse?: { id: number; code: string; name: string };
  destination_warehouse?: { id: number; code: string; name: string };
  destination_outlet?: { id: number; code: string; name: string; city?: string };
  items: Array<{
    id: number;
    product_variant_id: number;
    sku: string;
    product_name: string;
    variant_name: string;
    requested_qty: string;
    approved_qty: string;
    shipped_qty: string;
    received_qty: string;
    damaged_qty: string;
    discrepancy_qty: string;
  }>;
}

interface Props {
  activeOutletId?: number | null;
  activeOutletName?: string;
  initialTab?: 'transfers' | 'branch_stock' | 'central_stock';
}

export const WarehouseAndTransferPage: React.FC<Props> = ({
  activeOutletId,
  activeOutletName,
  initialTab = 'transfers',
}) => {
  const { toast } = useToast();
  const [centralWarehouses, setCentralWarehouses] = useState<WarehouseWithInventory[]>([]);
  const [branchWarehouse, setBranchWarehouse] = useState<WarehouseWithInventory | null>(null);
  const [transfers, setTransfers] = useState<StockTransferRecord[]>([]);
  const [products, setProducts] = useState<ProductCatalog[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [activeTab, setActiveTab] = useState<'transfers' | 'branch_stock' | 'central_stock'>(
    initialTab,
  );

  useEffect(() => {
    setActiveTab(initialTab);
  }, [initialTab]);

  // Request Stock Transfer Modal
  const [showRequestModal, setShowRequestModal] = useState<boolean>(false);
  const [selectedVariantId, setSelectedVariantId] = useState<number>(0);
  const [requestQty, setRequestQty] = useState<string>('30');
  const [requestNotes, setRequestNotes] = useState<string>(
    'Replenishment stok cabang dari Gudang Pusat',
  );
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [actionTransferId, setActionTransferId] = useState<number | null>(null);

  const fetchData = async () => {
    setLoading(true);
    try {
      const [whRes, prodRes] = await Promise.all([
        api.get<{
          central_warehouses: WarehouseWithInventory[];
          branch_warehouse: WarehouseWithInventory | null;
          transfers: StockTransferRecord[];
        }>('/warehouses'),
        api.get<{ products: ProductCatalog[] }>('/catalog/products'),
      ]);
      setCentralWarehouses(whRes.data.central_warehouses);
      setBranchWarehouse(whRes.data.branch_warehouse);
      setTransfers(whRes.data.transfers);
      setProducts(prodRes.data.products);

      const firstVar = prodRes.data.products[0]?.variants?.[0]?.id;
      if (firstVar && !selectedVariantId) {
        setSelectedVariantId(firstVar);
      }
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat data Gudang & Transfer',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, [activeOutletId]);

  const handleRequestTransfer = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedVariantId) return;
    setSubmitting(true);
    try {
      await api.post('/stock-transfers', {
        source_warehouse_id: centralWarehouses[0]?.id,
        notes: requestNotes,
        items: [
          {
            product_variant_id: selectedVariantId,
            requested_qty: Number(requestQty),
          },
        ],
      });
      toast({
        type: 'success',
        title: 'Permintaan Transfer Dibuat',
        description: 'Permintaan stok ke Gudang Pusat berhasil diajukan.',
      });
      setShowRequestModal(false);
      fetchData();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal mengajukan transfer',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmitting(false);
    }
  };

  const handleApproveAndShip = async (transferId: number) => {
    setActionTransferId(transferId);
    try {
      await api.post(`/stock-transfers/${transferId}/approve-ship`);
      toast({
        type: 'success',
        title: 'Stok Dikirim dari Gudang Pusat',
        description: 'Stok Gudang Pusat telah dikurangi dan berstatus In-Transit ke cabang.',
      });
      fetchData();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal mengirim stok',
        description: extractErrorMessage(err),
      });
    } finally {
      setActionTransferId(null);
    }
  };

  const handleReceiveTransfer = async (transferId: number) => {
    setActionTransferId(transferId);
    try {
      await api.post(`/stock-transfers/${transferId}/receive`);
      toast({
        type: 'success',
        title: 'Stok Diterima di Gudang Cabang',
        description: `Stok gudang ${branchWarehouse?.name ?? 'cabang'} berhasil bertambah.`,
      });
      fetchData();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menerima transfer',
        description: extractErrorMessage(err),
      });
    } finally {
      setActionTransferId(null);
    }
  };

  const centralWh = centralWarehouses[0] ?? null;
  const centralTotalStock = (centralWh?.inventories ?? []).reduce(
    (acc, r) => acc + parseFloat(r.quantity),
    0,
  );
  const branchTotalStock = (branchWarehouse?.inventories ?? []).reduce(
    (acc, r) => acc + parseFloat(r.quantity),
    0,
  );
  const branchInTransit = (branchWarehouse?.inventories ?? []).reduce(
    (acc, r) => acc + parseFloat(r.in_transit_quantity),
    0,
  );

  const allVariants = products.flatMap((p) =>
    p.variants.map((v) => ({
      id: v.id,
      label: `${p.name} - ${v.name} (${v.sku})`,
      productName: p.name,
      variantName: v.name,
      sku: v.sku,
      barcode: v.barcode ?? '',
      stock: `${formatQty(v.stock_quantity)} ${p.unit || 'PCS'}`,
    })),
  );

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      {/* Top Action Header */}
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-extrabold text-slate-900">
            Gudang Pusat, Gudang Cabang & Stock Transfer
          </h1>
          <p className="text-xs text-slate-500">
            Konteks Aktif: <b>{activeOutletName}</b> • Gudang Cabang:{' '}
            <b>{branchWarehouse ? `${branchWarehouse.name} (${branchWarehouse.code})` : '-'}</b>
          </p>
        </div>

        <div className="flex items-center gap-2.5">
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
            onClick={() => setShowRequestModal(true)}
          >
            Minta Stok dari Gudang Pusat
          </Button>
        </div>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <StatCard
          label={`Stok ${branchWarehouse?.name ?? 'Gudang Cabang'}`}
          value={`${formatQty(branchTotalStock)} Unit`}
          subValue={`Kode Gudang: ${branchWarehouse?.code ?? '-'}`}
          icon={<Warehouse className="w-5 h-5" />}
          accent="blue"
        />
        <StatCard
          label="Stok In-Transit (Dalam Pengiriman)"
          value={`${formatQty(branchInTransit)} Unit`}
          subValue="Menunggu penerimaan fisik di gudang cabang"
          icon={<Truck className="w-5 h-5" />}
          accent="amber"
        />
        <StatCard
          label={`Stok ${centralWh?.name ?? 'Central Warehouse'}`}
          value={`${formatQty(centralTotalStock)} Unit`}
          subValue={`Sumber distribusi utama (${centralWh?.code ?? 'WH-CENTRAL'})`}
          icon={<Package className="w-5 h-5" />}
          accent="emerald"
        />
      </div>

      {/* Navigation Tabs */}
      <div className="inline-flex rounded-xl bg-slate-200/70 p-1">
        {(
          [
            { id: 'transfers', label: `Alur Stock Transfer (${transfers.length})` },
            { id: 'branch_stock', label: `Persediaan ${branchWarehouse?.name ?? 'Gudang Cabang'}` },
            { id: 'central_stock', label: 'Persediaan Central Warehouse (Pusat)' },
          ] as const
        ).map((tab) => (
          <button
            key={tab.id}
            type="button"
            onClick={() => setActiveTab(tab.id)}
            className={`px-4 py-2 rounded-lg text-xs font-bold transition-all ${
              activeTab === tab.id
                ? 'bg-white text-blue-600 shadow-xs'
                : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {activeTab === 'transfers' && (
        <Card
          title="Daftar Permintaan & Pengiriman Stock Transfer"
          subtitle="Alur: Request dari Cabang → Approve & Ship oleh Gudang Pusat (In-Transit) → Receive di Cabang"
        >
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase text-slate-500">
                  <th className="py-3 px-4">No. Transfer</th>
                  <th className="py-3 px-4">Gudang Asal → Tujuan</th>
                  <th className="py-3 px-4">Rincian Barang</th>
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4 text-right">Tindakan Operasional</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {transfers.map((trf) => (
                  <tr key={trf.id} className="hover:bg-slate-50/70">
                    <td className="py-3.5 px-4">
                      <div className="font-mono font-bold text-slate-900">
                        {trf.transfer_number}
                      </div>
                      <div className="text-xs text-slate-400">
                        {formatDateTime(trf.requested_at)}
                      </div>
                    </td>
                    <td className="py-3.5 px-4">
                      <div className="flex items-center gap-1.5 text-xs font-semibold text-slate-800">
                        <Badge variant="neutral">{trf.source_warehouse?.code}</Badge>
                        <ArrowRightLeft className="w-3.5 h-3.5 text-slate-400" />
                        <Badge variant="info">{trf.destination_warehouse?.code}</Badge>
                      </div>
                      <div className="text-xs text-slate-500 mt-1">
                        Tujuan: <b>{trf.destination_outlet?.name}</b>
                      </div>
                    </td>
                    <td className="py-3.5 px-4">
                      {trf.items.map((item) => (
                        <div key={item.id} className="text-xs text-slate-700">
                          <b>{item.product_name}</b> ({item.variant_name}) — Diminta:{' '}
                          <b>{formatQty(item.requested_qty)}</b>
                          {parseFloat(item.shipped_qty) > 0 &&
                            ` | Dikirim: ${formatQty(item.shipped_qty)}`}
                          {parseFloat(item.received_qty) > 0 &&
                            ` | Diterima: ${formatQty(item.received_qty)}`}
                        </div>
                      ))}
                    </td>
                    <td className="py-3.5 px-4">
                      <Badge
                        variant={
                          trf.status === 'received'
                            ? 'success'
                            : trf.status === 'shipped'
                              ? 'info'
                              : 'warning'
                        }
                        dot
                      >
                        {trf.status.toUpperCase()}
                      </Badge>
                    </td>
                    <td className="py-3.5 px-4 text-right space-x-2">
                      {trf.status === 'requested' && (
                        <Button
                          variant="primary"
                          size="xs"
                          loading={actionTransferId === trf.id}
                          leftIcon={<Send className="w-3.5 h-3.5" />}
                          onClick={() => handleApproveAndShip(trf.id)}
                        >
                          Setujui & Kirim (Pusat)
                        </Button>
                      )}
                      {trf.status === 'shipped' && (
                        <Button
                          variant="success"
                          size="xs"
                          loading={actionTransferId === trf.id}
                          leftIcon={<CheckCircle2 className="w-3.5 h-3.5" />}
                          onClick={() => handleReceiveTransfer(trf.id)}
                        >
                          Terima Barang di Cabang
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {activeTab === 'branch_stock' && (
        <Card
          title={`Saldo Stok ${branchWarehouse?.name ?? 'Gudang Cabang'}`}
          subtitle="Tersinkronisasi langsung dengan Kasir Counter Sales dan B2B Sales Order pada outlet ini."
        >
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase text-slate-500">
                  <th className="py-3 px-4">SKU</th>
                  <th className="py-3 px-4">Produk & Varian</th>
                  <th className="py-3 px-4 text-right">Stok Tersedia</th>
                  <th className="py-3 px-4 text-right">Reserved (B2B SO)</th>
                  <th className="py-3 px-4 text-right">In-Transit</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(branchWarehouse?.inventories ?? []).map((inv) => (
                  <tr key={inv.id}>
                    <td className="py-3 px-4 font-mono text-xs font-bold text-slate-700">
                      {inv.variant?.sku}
                    </td>
                    <td className="py-3 px-4 font-semibold text-slate-900">
                      {inv.variant?.product?.name} — {inv.variant?.name}
                    </td>
                    <td className="py-3 px-4 text-right font-extrabold text-slate-900 tabular-nums">
                      {formatQty(inv.quantity)}
                    </td>
                    <td className="py-3 px-4 text-right text-amber-700 font-semibold tabular-nums">
                      {formatQty(inv.reserved_quantity)}
                    </td>
                    <td className="py-3 px-4 text-right text-blue-700 font-semibold tabular-nums">
                      {formatQty(inv.in_transit_quantity)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {activeTab === 'central_stock' && (
        <Card
          title={`Saldo Stok ${centralWh?.name ?? 'Central Warehouse'}`}
          subtitle="Persediaan utama perusahaan dari penerimaan Principal / Purchase Order."
        >
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase text-slate-500">
                  <th className="py-3 px-4">SKU</th>
                  <th className="py-3 px-4">Produk & Varian</th>
                  <th className="py-3 px-4 text-right">Stok Gudang Pusat</th>
                  <th className="py-3 px-4 text-right">Batas Minimum</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(centralWh?.inventories ?? []).map((inv) => (
                  <tr key={inv.id}>
                    <td className="py-3 px-4 font-mono text-xs font-bold text-slate-700">
                      {inv.variant?.sku}
                    </td>
                    <td className="py-3 px-4 font-semibold text-slate-900">
                      {inv.variant?.product?.name} — {inv.variant?.name}
                    </td>
                    <td className="py-3 px-4 text-right font-extrabold text-emerald-700 tabular-nums">
                      {formatQty(inv.quantity)}
                    </td>
                    <td className="py-3 px-4 text-right text-slate-500 tabular-nums">
                      {formatQty(inv.reorder_level)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* Modal Request Stock Transfer */}
      <Modal
        open={showRequestModal}
        onClose={() => setShowRequestModal(false)}
        title="Ajukan Permintaan Stock Transfer dari Gudang Pusat"
        description={`Barang akan dikirim dari ${centralWh?.name ?? 'WH-CENTRAL'} menuju ${branchWarehouse?.name ?? activeOutletName}.`}
      >
        <form onSubmit={handleRequestTransfer} className="space-y-4">
          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">
              Pilih Produk / Varian (Ketik Nama / SKU atau Klik Daftar)
            </label>
            <SearchableSelect
              value={selectedVariantId}
              onChange={(val) => setSelectedVariantId(val)}
              placeholder="Ketik nama barang / SKU atau pilih dari daftar..."
              options={allVariants.map((v) => ({
                value: v.id,
                label: `${v.productName} (${v.variantName})`,
                sublabel: `SKU: ${v.sku}`,
                badge: `Stok Cabang: ${v.stock}`,
                searchKeywords: `${v.productName} ${v.variantName} ${v.sku} ${v.barcode}`,
              }))}
            />
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">
              Kuantitas Permintaan (Unit)
            </label>
            <input
              type="number"
              min="1"
              required
              value={requestQty}
              onChange={(e) => setRequestQty(e.target.value)}
              className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
            />
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">Catatan</label>
            <input
              type="text"
              value={requestNotes}
              onChange={(e) => setRequestNotes(e.target.value)}
              className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
            />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" type="button" onClick={() => setShowRequestModal(false)}>
              Batal
            </Button>
            <Button variant="primary" type="submit" loading={submitting}>
              Kirim Permintaan Transfer
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  );
};

