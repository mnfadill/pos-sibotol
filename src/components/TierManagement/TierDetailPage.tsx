import React, { useEffect, useState } from 'react';
import {
  AlertTriangle,
  ArrowLeft,
  Building2,
  Calendar,
  CheckCircle2,
  Coins,
  DollarSign,
  Edit,
  History,
  Layers,
  Package,
  Plus,
  RefreshCw,
  Search,
  Tag,
  Trash2,
  UserCheck,
  Users,
  X,
} from 'lucide-react';
import { api } from '../../lib/api';
import type { Customer, DynamicTier, MasterProductItem, ProductTierItem, TierPriceHistoryItem } from '../../types/pos';

interface TierDetailPageProps {
  tierId: number;
  onBack: () => void;
}

export const TierDetailPage: React.FC<TierDetailPageProps> = ({ tierId, onBack }) => {
  const [activeTab, setActiveTab] = useState<'general' | 'products' | 'customers' | 'history'>('products');
  const [tier, setTier] = useState<DynamicTier | null>(null);
  const [products, setProducts] = useState<ProductTierItem[]>([]);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [history, setHistory] = useState<TierPriceHistoryItem[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [saving, setSaving] = useState<boolean>(false);
  const [actionMessage, setActionMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Search filter
  const [searchProduct, setSearchProduct] = useState<string>('');

  // General Edit Form State
  const [generalForm, setGeneralForm] = useState({
    name: '',
    code: '',
    description: '',
    display_order: 1,
    color: '#2563EB',
    status: 'active' as 'active' | 'inactive',
  });

  // Modal: Add Product to Tier
  const [showAddModal, setShowAddModal] = useState<boolean>(false);
  const [allMasterProducts, setAllMasterProducts] = useState<MasterProductItem[]>([]);
  const [addForm, setAddForm] = useState({
    product_id: '',
    price: '',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_until: '',
    notes: 'Penambahan produk ke Tier',
  });

  // Modal: Edit Price
  const [showEditPriceModal, setShowEditPriceModal] = useState<boolean>(false);
  const [editingItem, setEditingItem] = useState<ProductTierItem | null>(null);
  const [editPriceForm, setEditPriceForm] = useState({
    price: '',
    effective_from: new Date().toISOString().slice(0, 10),
    effective_until: '',
    notes: 'Perubahan harga produk dalam Tier',
  });

  const loadTierDetail = async () => {
    setLoading(true);
    try {
      const res = await api.get<{
        tier: DynamicTier;
        products: ProductTierItem[];
        customers: Customer[];
        price_history: TierPriceHistoryItem[];
      }>(`/tiers/${tierId}`);

      const t = res.data.tier;
      setTier(t);
      setProducts(res.data.products || []);
      setCustomers(res.data.customers || []);
      setHistory(res.data.price_history || []);

      setGeneralForm({
        name: t.name,
        code: t.code,
        description: t.description || '',
        display_order: t.display_order,
        color: t.color,
        status: t.status,
      });
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal memuat rincian Tier.',
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadTierDetail();
  }, [tierId]);

  // Load master products for Add Modal
  const openAddModal = async () => {
    try {
      const res = await api.get<any>('/master-data/products?per_page=200');
      const list: MasterProductItem[] = res.data?.data || res.data?.products || [];
      // Filter out products already in this tier
      const existingIds = new Set(products.map((p) => p.product_id));
      const available = list.filter((p) => !existingIds.has(p.id));
      setAllMasterProducts(available);

      if (available.length > 0) {
        const first = available[0];
        const defaultSell = Number(first.variants?.[0]?.price || first.base_price || 0);
        setAddForm({
          product_id: String(first.id),
          price: String(defaultSell),
          effective_from: new Date().toISOString().slice(0, 10),
          effective_until: '',
          notes: 'Penambahan produk ke Tier',
        });
      }
      setShowAddModal(true);
    } catch {
      alert('Gagal memuat katalog master produk.');
    }
  };

  // Submit Add Product
  const handleSaveAddProduct = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!addForm.product_id || !addForm.price) return;

    setSaving(true);
    try {
      await api.post(`/tiers/${tierId}/products`, {
        product_id: Number(addForm.product_id),
        price: Number(addForm.price),
        effective_from: addForm.effective_from,
        effective_until: addForm.effective_until || null,
        notes: addForm.notes,
      });

      setActionMessage({ type: 'success', text: 'Produk dan harga berhasil ditambahkan ke Tier ini!' });
      setShowAddModal(false);
      await loadTierDetail();
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal menambahkan produk ke Tier.',
      });
    } finally {
      setSaving(false);
    }
  };

  // Open Edit Price Modal
  const openEditPriceModal = (item: ProductTierItem) => {
    setEditingItem(item);
    setEditPriceForm({
      price: String(item.current_price || item.variant?.price || 0),
      effective_from: new Date().toISOString().slice(0, 10),
      effective_until: item.effective_until || '',
      notes: 'Penyesuaian harga jual Tier',
    });
    setShowEditPriceModal(true);
  };

  // Submit Edit Price
  const handleSaveEditPrice = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingItem) return;

    setSaving(true);
    try {
      await api.post(`/tiers/${tierId}/products`, {
        product_id: editingItem.product_id,
        product_variant_id: editingItem.product_variant_id,
        price: Number(editPriceForm.price),
        effective_from: editPriceForm.effective_from,
        effective_until: editPriceForm.effective_until || null,
        notes: editPriceForm.notes,
      });

      setActionMessage({
        type: 'success',
        text: `Harga untuk "${editingItem.product.name}" berhasil diperbarui.`,
      });
      setShowEditPriceModal(false);
      await loadTierDetail();
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal memperbarui harga.',
      });
    } finally {
      setSaving(false);
    }
  };

  // Remove Product from Tier
  const handleRemoveProduct = async (item: ProductTierItem) => {
    if (!confirm(`Hapus "${item.product.name}" dari Tier ini? Customer di Tier ini tidak akan dapat membeli produk ini.`)) {
      return;
    }

    try {
      await api.delete(`/tiers/${tierId}/products/${item.product_id}`);
      setActionMessage({ type: 'success', text: `Produk "${item.product.name}" berhasil dihapus dari Tier.` });
      await loadTierDetail();
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal menghapus produk dari Tier.',
      });
    }
  };

  // Save General Info
  const handleSaveGeneral = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      await api.put(`/tiers/${tierId}`, generalForm);
      setActionMessage({ type: 'success', text: 'Informasi Tier berhasil diperbarui!' });
      await loadTierDetail();
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal memperbarui Tier.',
      });
    } finally {
      setSaving(false);
    }
  };

  const filteredProducts = products.filter(
    (p) =>
      Boolean(p.product) &&
      ((p.product?.name ?? '').toLowerCase().includes(searchProduct.toLowerCase()) ||
        (p.product?.sku ?? '').toLowerCase().includes(searchProduct.toLowerCase())),
  );

  const formatIDR = (val: number | string | null | undefined) => {
    const num = Number(val || 0);
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0,
    }).format(num);
  };

  if (loading && !tier) {
    return (
      <div className="p-12 text-center text-slate-400">
        <RefreshCw className="w-8 h-8 animate-spin mx-auto mb-3 text-indigo-600" />
        Memuat rincian Tier...
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Top Breadcrumb & Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={onBack}
            className="p-2 bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition cursor-pointer text-slate-600 shadow-xs"
            title="Kembali ke Daftar Tier"
          >
            <ArrowLeft className="w-5 h-5" />
          </button>
          <div>
            <div className="flex items-center gap-2">
              <span
                className="w-3.5 h-3.5 rounded-full"
                style={{ backgroundColor: tier?.color || '#2563EB' }}
              ></span>
              <h1 className="text-xl font-bold text-slate-800">{tier?.name}</h1>
              <span className="font-mono text-xs px-2 py-0.5 bg-slate-100 text-slate-600 rounded-md font-bold">
                {tier?.code}
              </span>
              <span
                className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ${
                  tier?.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'
                }`}
              >
                {tier?.status === 'active' ? 'Aktif' : 'Nonaktif'}
              </span>
            </div>
            <p className="text-xs text-slate-500 mt-1">
              {tier?.description || 'Tidak ada deskripsi khusus untuk Tier ini.'}
            </p>
          </div>
        </div>

        <button
          type="button"
          onClick={loadTierDetail}
          disabled={loading}
          className="flex items-center gap-2 px-3 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl hover:bg-slate-50 transition text-xs font-semibold shadow-xs cursor-pointer self-start sm:self-auto"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
          Muat Ulang
        </button>
      </div>

      {/* Action Notification */}
      {actionMessage && (
        <div
          className={`p-4 rounded-xl flex items-center justify-between text-xs ${
            actionMessage.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
              : 'bg-rose-50 text-rose-800 border border-rose-200'
          }`}
        >
          <div className="flex items-center gap-2">
            {actionMessage.type === 'success' ? (
              <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
            ) : (
              <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
            )}
            <span>{actionMessage.text}</span>
          </div>
          <button
            type="button"
            onClick={() => setActionMessage(null)}
            className="text-slate-400 hover:text-slate-600 cursor-pointer"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* 4 Tabs Navigation */}
      <div className="flex border-b border-slate-200 gap-6 text-xs font-bold">
        <button
          type="button"
          onClick={() => setActiveTab('products')}
          className={`pb-3 flex items-center gap-2 border-b-2 transition cursor-pointer ${
            activeTab === 'products'
              ? 'border-indigo-600 text-indigo-600'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
        >
          <Package className="w-4 h-4" />
          Product & Pricing ({products.length})
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('general')}
          className={`pb-3 flex items-center gap-2 border-b-2 transition cursor-pointer ${
            activeTab === 'general'
              ? 'border-indigo-600 text-indigo-600'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
        >
          <Tag className="w-4 h-4" />
          General Information
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('customers')}
          className={`pb-3 flex items-center gap-2 border-b-2 transition cursor-pointer ${
            activeTab === 'customers'
              ? 'border-indigo-600 text-indigo-600'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
        >
          <Users className="w-4 h-4" />
          Assigned Customers ({customers.length})
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('history')}
          className={`pb-3 flex items-center gap-2 border-b-2 transition cursor-pointer ${
            activeTab === 'history'
              ? 'border-indigo-600 text-indigo-600'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
        >
          <History className="w-4 h-4" />
          Price History ({history.length})
        </button>
      </div>

      {/* TAB 1: PRODUCT & PRICING */}
      {activeTab === 'products' && (
        <div className="space-y-4">
          <div className="flex flex-col sm:flex-row items-center justify-between gap-3 bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
            <div className="relative flex-1 w-full sm:w-auto">
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                placeholder="Cari produk dalam Tier ini..."
                value={searchProduct}
                onChange={(e) => setSearchProduct(e.target.value)}
                className="w-full pl-9 pr-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>

            <button
              type="button"
              onClick={openAddModal}
              className="flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition text-xs font-bold shadow-xs cursor-pointer self-start sm:self-auto"
            >
              <Plus className="w-4 h-4" />
              Tambah Produk ke Tier
            </button>
          </div>

          <div className="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="bg-slate-50 text-slate-600 font-semibold uppercase tracking-wider border-b border-slate-200">
                    <th className="py-3 px-4">Nama Produk & SKU</th>
                    <th className="py-3 px-3">Satuan</th>
                    <th className="py-3 px-3 text-right">Modal HPP</th>
                    <th className="py-3 px-3 text-right">Harga Jual Tier</th>
                    <th className="py-3 px-3">Berlaku Mulai</th>
                    <th className="py-3 px-3">Status</th>
                    <th className="py-3 px-4 text-center">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {filteredProducts.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="py-10 text-center text-slate-400">
                        Belum ada produk yang ditetapkan pada Tier ini. Klik "Tambah Produk ke Tier" di atas.
                      </td>
                    </tr>
                  ) : (
                    filteredProducts.map((item) => (
                      <tr key={item.id} className="hover:bg-slate-50/70">
                        <td className="py-3 px-4">
                          <div className="font-bold text-slate-800">{item.product.name}</div>
                          <span className="font-mono text-[11px] text-slate-400">
                            {item.product.sku}
                          </span>
                        </td>
                        <td className="py-3 px-3 font-semibold text-slate-700">{item.product.unit}</td>
                        <td className="py-3 px-3 text-right text-slate-500">
                          {formatIDR(item.variant?.cost_price)}
                        </td>
                        <td className="py-3 px-3 text-right">
                          <span className="font-bold text-indigo-950 text-sm">
                            {formatIDR(item.current_price || item.variant?.price)}
                          </span>
                        </td>
                        <td className="py-3 px-3 text-slate-600">{item.effective_from || '-'}</td>
                        <td className="py-3 px-3">
                          <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">
                            Aktif
                          </span>
                        </td>
                        <td className="py-3 px-4 text-center">
                          <div className="flex items-center justify-center gap-1">
                            <button
                              type="button"
                              onClick={() => openEditPriceModal(item)}
                              className="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition cursor-pointer"
                              title="Ubah Harga Tier"
                            >
                              <Edit className="w-3.5 h-3.5" />
                            </button>
                            <button
                              type="button"
                              onClick={() => handleRemoveProduct(item)}
                              className="p-1.5 text-rose-500 hover:bg-rose-50 rounded-lg transition cursor-pointer"
                              title="Hapus Produk dari Tier"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* TAB 2: GENERAL INFORMATION */}
      {activeTab === 'general' && (
        <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-xs max-w-2xl">
          <form onSubmit={handleSaveGeneral} className="space-y-4">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Nama Tier</label>
              <input
                type="text"
                required
                value={generalForm.name}
                onChange={(e) => setGeneralForm({ ...generalForm, name: e.target.value })}
                className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">Kode Tier</label>
                <input
                  type="text"
                  required
                  value={generalForm.code}
                  onChange={(e) => setGeneralForm({ ...generalForm, code: e.target.value.toUpperCase() })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">Display Order</label>
                <input
                  type="number"
                  min="1"
                  value={generalForm.display_order}
                  onChange={(e) => setGeneralForm({ ...generalForm, display_order: Number(e.target.value) })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Warna Badge</label>
              <div className="flex items-center gap-3">
                <input
                  type="color"
                  value={generalForm.color}
                  onChange={(e) => setGeneralForm({ ...generalForm, color: e.target.value })}
                  className="w-8 h-8 rounded-lg cursor-pointer border-0 bg-transparent p-0"
                />
                <span className="text-xs font-mono text-slate-600">{generalForm.color}</span>
              </div>
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Deskripsi</label>
              <textarea
                rows={3}
                value={generalForm.description}
                onChange={(e) => setGeneralForm({ ...generalForm, description: e.target.value })}
                className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Status</label>
              <select
                value={generalForm.status}
                onChange={(e) => setGeneralForm({ ...generalForm, status: e.target.value as any })}
                className="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-indigo-500"
              >
                <option value="active">Aktif (Dapat digunakan kasir)</option>
                <option value="inactive">Nonaktif</option>
              </select>
            </div>

            <div className="pt-3 border-t border-slate-100 flex justify-end">
              <button
                type="submit"
                disabled={saving}
                className="px-5 py-2 bg-indigo-600 text-white rounded-xl text-xs font-bold hover:bg-indigo-700 transition flex items-center gap-2 cursor-pointer shadow-xs"
              >
                {saving && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                Simpan Perubahan
              </button>
            </div>
          </form>
        </div>
      )}

      {/* TAB 3: ASSIGNED CUSTOMERS */}
      {activeTab === 'customers' && (
        <div className="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
          <div className="p-4 border-b border-slate-100">
            <h3 className="font-bold text-slate-800 text-xs uppercase tracking-wider">
              Pelanggan yang Menggunakan Tier Ini
            </h3>
            <p className="text-xs text-slate-400 mt-0.5">
              Pelanggan-pelanggan ini secara otomatis mendapatkan harga Tier saat bertransaksi di Counter Sales.
            </p>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead className="bg-slate-50 text-slate-600 font-semibold uppercase tracking-wider border-b border-slate-200">
                <tr>
                  <th className="py-2.5 px-4">Kode & Nama Pelanggan</th>
                  <th className="py-2.5 px-3">Grup / Kategori</th>
                  <th className="py-2.5 px-3">Kontak / Telepon</th>
                  <th className="py-2.5 px-3">Kota</th>
                  <th className="py-2.5 px-3">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {customers.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="py-8 text-center text-slate-400">
                      Belum ada pelanggan yang dikaitkan ke Tier ini. Anda dapat mengaitkannya via modul Customer Management.
                    </td>
                  </tr>
                ) : (
                  customers.map((c) => (
                    <tr key={c.id} className="hover:bg-slate-50/70">
                      <td className="py-2.5 px-4">
                        <div className="font-bold text-slate-800">{c.name}</div>
                        <span className="font-mono text-[11px] text-slate-400">{c.code}</span>
                      </td>
                      <td className="py-2.5 px-3 capitalize text-slate-600">{c.customer_group || '-'}</td>
                      <td className="py-2.5 px-3 text-slate-600">{c.phone || c.email || '-'}</td>
                      <td className="py-2.5 px-3 text-slate-600">{c.city || '-'}</td>
                      <td className="py-2.5 px-3">
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">
                          Aktif
                        </span>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* TAB 4: PRICE HISTORY */}
      {activeTab === 'history' && (
        <div className="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
          <div className="p-4 border-b border-slate-100">
            <h3 className="font-bold text-slate-800 text-xs uppercase tracking-wider">
              Log Riwayat Perubahan Harga Tier
            </h3>
            <p className="text-xs text-slate-400 mt-0.5">
              Pencatatan riwayat audit saat harga dibuat atau diubah pada Tier ini.
            </p>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead className="bg-slate-50 text-slate-600 font-semibold uppercase tracking-wider border-b border-slate-200">
                <tr>
                  <th className="py-2.5 px-4">Tanggal Diubah</th>
                  <th className="py-2.5 px-4">Produk</th>
                  <th className="py-2.5 px-3 text-right">Harga Ditetapkan</th>
                  <th className="py-2.5 px-3">Mulai Berlaku</th>
                  <th className="py-2.5 px-3">Diubah Oleh</th>
                  <th className="py-2.5 px-4">Catatan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {history.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-8 text-center text-slate-400">
                      Belum ada catatan riwayat harga.
                    </td>
                  </tr>
                ) : (
                  history.map((h) => (
                    <tr key={h.id} className="hover:bg-slate-50/70">
                      <td className="py-2.5 px-4 text-slate-500 font-mono text-[11px]">
                        {new Date(h.created_at).toLocaleString('id-ID')}
                      </td>
                      <td className="py-2.5 px-4 font-bold text-slate-800">
                        {h.product?.name || `Produk #${h.product_id}`}
                      </td>
                      <td className="py-2.5 px-3 text-right font-bold text-indigo-950">
                        {formatIDR(h.price)}
                      </td>
                      <td className="py-2.5 px-3 text-slate-600">{h.effective_from}</td>
                      <td className="py-2.5 px-3 text-slate-600">{h.creator?.name || 'Sistem'}</td>
                      <td className="py-2.5 px-4 text-slate-500">{h.notes || '-'}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Modal: Tambah Produk ke Tier */}
      {showAddModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
          <div className="bg-white rounded-2xl w-full max-w-lg shadow-xl overflow-hidden p-6">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <h3 className="font-bold text-slate-800 text-sm flex items-center gap-2">
                <Plus className="w-4 h-4 text-indigo-600" />
                Tambah Produk ke Tier ({tier?.name})
              </h3>
              <button
                type="button"
                onClick={() => setShowAddModal(false)}
                className="text-slate-400 hover:text-slate-600 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleSaveAddProduct} className="space-y-4 pt-4">
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">Pilih Produk</label>
                <select
                  value={addForm.product_id}
                  onChange={(e) => {
                    const selId = Number(e.target.value);
                    const prod = allMasterProducts.find((p) => p.id === selId);
                    setAddForm({
                      ...addForm,
                      product_id: e.target.value,
                      price: String(prod?.variants?.[0]?.price || prod?.base_price || 0),
                    });
                  }}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  {allMasterProducts.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name} ({p.sku || p.variants?.[0]?.sku || p.slug})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  Harga Jual Khusus Tier Ini (Rp)
                </label>
                <input
                  type="number"
                  min="0"
                  step="any"
                  required
                  value={addForm.price}
                  onChange={(e) => setAddForm({ ...addForm, price: e.target.value })}
                  className="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-indigo-950 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Mulai Berlaku</label>
                  <input
                    type="date"
                    required
                    value={addForm.effective_from}
                    onChange={(e) => setAddForm({ ...addForm, effective_from: e.target.value })}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Berlaku Sampai (Opsional)
                  </label>
                  <input
                    type="date"
                    value={addForm.effective_until}
                    onChange={(e) => setAddForm({ ...addForm, effective_until: e.target.value })}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">Catatan</label>
                <input
                  type="text"
                  value={addForm.notes}
                  onChange={(e) => setAddForm({ ...addForm, notes: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowAddModal(false)}
                  className="px-4 py-2 bg-slate-100 text-slate-700 rounded-xl text-xs font-bold hover:bg-slate-200 transition cursor-pointer"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 bg-indigo-600 text-white rounded-xl text-xs font-bold hover:bg-indigo-700 transition flex items-center gap-1.5 cursor-pointer shadow-xs"
                >
                  {saving && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                  Simpan Produk
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal: Ubah Harga Tier */}
      {showEditPriceModal && editingItem && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
          <div className="bg-white rounded-2xl w-full max-w-md shadow-xl overflow-hidden p-6">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
              <h3 className="font-bold text-slate-800 text-sm flex items-center gap-2">
                <Coins className="w-4 h-4 text-amber-600" />
                Ubah Harga Tier ({editingItem.product.name})
              </h3>
              <button
                type="button"
                onClick={() => setShowEditPriceModal(false)}
                className="text-slate-400 hover:text-slate-600 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleSaveEditPrice} className="space-y-4 pt-4">
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">Harga Jual Baru (Rp)</label>
                <input
                  type="number"
                  min="0"
                  step="any"
                  required
                  value={editPriceForm.price}
                  onChange={(e) => setEditPriceForm({ ...editPriceForm, price: e.target.value })}
                  className="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-sm font-bold text-indigo-950 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">Mulai Berlaku</label>
                <input
                  type="date"
                  required
                  value={editPriceForm.effective_from}
                  onChange={(e) => setEditPriceForm({ ...editPriceForm, effective_from: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">Catatan Audit</label>
                <input
                  type="text"
                  value={editPriceForm.notes}
                  onChange={(e) => setEditPriceForm({ ...editPriceForm, notes: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div className="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowEditPriceModal(false)}
                  className="px-4 py-2 bg-slate-100 text-slate-700 rounded-xl text-xs font-bold hover:bg-slate-200 transition cursor-pointer"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 bg-amber-600 text-white rounded-xl text-xs font-bold hover:bg-amber-700 transition flex items-center gap-1.5 cursor-pointer shadow-xs"
                >
                  {saving && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                  Simpan Harga Baru
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};
