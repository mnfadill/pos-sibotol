import React, { useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  ArrowUpDown,
  Building2,
  CheckCircle2,
  Coins,
  Edit,
  Info,
  RefreshCw,
  Search,
  Tag,
  UserCheck,
  Users,
  X,
  Zap,
} from 'lucide-react';
import { api } from '../../lib/api';
import type { Category, MasterProductItem } from '../../types/pos';

export const MasterPricingPage: React.FC = () => {
  const [products, setProducts] = useState<MasterProductItem[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [selectedCategory, setSelectedCategory] = useState<string>('');

  // Edit Modal State
  const [showEditModal, setShowEditModal] = useState<boolean>(false);
  const [editingItem, setEditingItem] = useState<{
    product: MasterProductItem;
    variantId: number;
    name: string;
    sku: string;
    unit: string;
  } | null>(null);

  const [priceForm, setPriceForm] = useState({
    cost_price: 0,
    tier_a_price: 0,
    tier_b_price: 0,
    tier_c_price: 0,
    tier_d_price: 0,
    notes: '',
  });

  const [saving, setSaving] = useState<boolean>(false);
  const [actionMessage, setActionMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const loadData = async () => {
    setLoading(true);
    try {
      const [prodRes, catRes] = await Promise.all([
        api.get<any>('/master-data/products?per_page=150'),
        api.get<{ categories: Category[] }>('/master-data/categories'),
      ]);

      const rawProds = prodRes.data?.data || prodRes.data?.products || [];
      setProducts(Array.isArray(rawProds) ? rawProds : []);
      setCategories(Array.isArray(catRes.data?.categories) ? catRes.data.categories : []);
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal memuat master harga produk.',
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const openPriceModal = (p: MasterProductItem) => {
    const v = p.variants?.[0];
    if (!v) return;

    const cost = Number(v.cost_price || 0);
    const tierA = Number(v.price || 0);
    const tierB = Number(v.member_price ?? (tierA * 0.95));
    const tierC = Number(v.reseller_price ?? (tierA * 0.9));
    const tierD = Number(v.wholesale_price ?? (tierA * 0.85));

    setEditingItem({
      product: p,
      variantId: v.id,
      name: p.name,
      sku: v.sku,
      unit: p.unit || 'PCS',
    });

    setPriceForm({
      cost_price: cost,
      tier_a_price: tierA,
      tier_b_price: tierB,
      tier_c_price: tierC,
      tier_d_price: tierD,
      notes: 'Penetapan harga nominal 4 identitas',
    });

    setShowEditModal(true);
  };

  const handleSavePrices = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingItem) return;

    setSaving(true);
    setActionMessage(null);
    try {
      await api.put('/master-data/pricing/update-tier-prices', {
        product_id: editingItem.product.id,
        variant_id: editingItem.variantId,
        cost_price: Number(priceForm.cost_price) || 0,
        tier_a_price: Number(priceForm.tier_a_price) || 0,
        tier_b_price: Number(priceForm.tier_b_price) || 0,
        tier_c_price: Number(priceForm.tier_c_price) || 0,
        tier_d_price: Number(priceForm.tier_d_price) || 0,
        notes: priceForm.notes || undefined,
      });

      setActionMessage({
        type: 'success',
        text: `Harga 4 Tier untuk "${editingItem.name}" berhasil disinkronkan ke seluruh sistem POS!`,
      });
      setShowEditModal(false);
      await loadData();
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal menyimpan perubahan harga.',
      });
    } finally {
      setSaving(false);
    }
  };

  const filteredProducts = useMemo(() => {
    return products.filter((p) => {
      const v = p.variants?.[0];
      const matchSearch =
        searchTerm === '' ||
        p.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        v?.sku?.toLowerCase().includes(searchTerm.toLowerCase());
      const matchCat = selectedCategory === '' || String(p.category_id) === selectedCategory;
      return matchSearch && matchCat;
    });
  }, [products, searchTerm, selectedCategory]);

  const formatIDR = (val: number | string | null | undefined) => {
    const num = Number(val || 0);
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0,
    }).format(num);
  };

  const calcMargin = (sellPrice: number | string, costPrice: number | string) => {
    const s = Number(sellPrice || 0);
    const c = Number(costPrice || 0);
    if (s <= 0) return '0%';
    const margin = ((s - c) / s) * 100;
    return `${margin.toFixed(1)}%`;
  };

  return (
    <div className="p-6 space-y-6">
      {/* Top Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800 flex items-center gap-2">
            <Coins className="w-7 h-7 text-amber-600" />
            Master Harga 4 Identitas (Tier A, B, C, D)
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Pengaturan nominal harga jual langsung (bukan persentase diskon) berdasarkan identitas pelanggan di Counter Sales POS.
          </p>
        </div>
        <button
          type="button"
          onClick={loadData}
          disabled={loading}
          className="flex items-center gap-2 px-3 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl hover:bg-slate-50 transition text-sm font-semibold shadow-xs cursor-pointer self-start md:self-auto"
        >
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Muat Ulang
        </button>
      </div>

      {/* Notifications */}
      {actionMessage && (
        <div
          className={`p-4 rounded-xl flex items-center justify-between text-sm ${
            actionMessage.type === 'success'
              ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
              : 'bg-rose-50 text-rose-800 border border-rose-200'
          }`}
        >
          <div className="flex items-center gap-2">
            {actionMessage.type === 'success' ? (
              <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
            ) : (
              <AlertTriangle className="w-5 h-5 text-rose-600 shrink-0" />
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

      {/* 4 Identities Overview Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Tier A */}
        <div className="bg-emerald-50/60 p-4 rounded-2xl border border-emerald-200 shadow-xs relative overflow-hidden">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-emerald-800 uppercase tracking-wider">
              Identitas A: Umum (Retail)
            </span>
            <Users className="w-5 h-5 text-emerald-600" />
          </div>
          <p className="text-xs text-emerald-950 mt-1 font-medium">Customer Walk-in / Biasa</p>
          <p className="text-[11px] text-emerald-700 mt-2">
            Harga standar ritel counter sales. Dipilih otomatis saat kasir memilih tipe customer Umum.
          </p>
        </div>

        {/* Tier B */}
        <div className="bg-blue-50/60 p-4 rounded-2xl border border-blue-200 shadow-xs relative overflow-hidden">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-blue-800 uppercase tracking-wider">
              Identitas B: Member (Langganan)
            </span>
            <UserCheck className="w-5 h-5 text-blue-600" />
          </div>
          <p className="text-xs text-blue-950 mt-1 font-medium">Pelanggan Terdaftar / Silver</p>
          <p className="text-[11px] text-blue-700 mt-2">
            Harga spesial member outlet. Kasir cukup memilih Member dan tarif otomatis terisi.
          </p>
        </div>

        {/* Tier C */}
        <div className="bg-purple-50/60 p-4 rounded-2xl border border-purple-200 shadow-xs relative overflow-hidden">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-purple-800 uppercase tracking-wider">
              Identitas C: Reseller (Mitra)
            </span>
            <Tag className="w-5 h-5 text-purple-600" />
          </div>
          <p className="text-xs text-purple-950 mt-1 font-medium">Warung Mitra / Gold Customer</p>
          <p className="text-[11px] text-purple-700 mt-2">
            Tarif reseller mitra warung & toko kelontong sekitar outlet dengan margin grosir mini.
          </p>
        </div>

        {/* Tier D */}
        <div className="bg-amber-50/60 p-4 rounded-2xl border border-amber-200 shadow-xs relative overflow-hidden">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold text-amber-800 uppercase tracking-wider">
              Identitas D: Grosir VIP (Wholesale)
            </span>
            <Building2 className="w-5 h-5 text-amber-600" />
          </div>
          <p className="text-xs text-amber-950 mt-1 font-medium">B2B Partai / Platinum</p>
          <p className="text-[11px] text-amber-700 mt-2">
            Harga terendah untuk pembelian partai besar B2B sub-distributor / agen utama.
          </p>
        </div>
      </div>

      {/* Filter and Search Bar */}
      <div className="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col md:flex-row items-center gap-4">
        <div className="relative flex-1 w-full">
          <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            placeholder="Cari produk atau SKU..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
          />
        </div>

        <select
          value={selectedCategory}
          onChange={(e) => setSelectedCategory(e.target.value)}
          className="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-amber-500 cursor-pointer w-full md:w-auto"
        >
          <option value="">Semua Kategori</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
      </div>

      {/* Matrix Table */}
      <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200 uppercase tracking-wider">
                <th className="py-3 px-4">Nama Produk & SKU</th>
                <th className="py-3 px-3">Satuan</th>
                <th className="py-3 px-3 text-right">HPP (Modal)</th>
                <th className="py-3 px-3 text-center bg-emerald-50/50">Tier A (Retail)</th>
                <th className="py-3 px-3 text-center bg-blue-50/50">Tier B (Member)</th>
                <th className="py-3 px-3 text-center bg-purple-50/50">Tier C (Reseller)</th>
                <th className="py-3 px-3 text-center bg-amber-50/50">Tier D (Grosir VIP)</th>
                <th className="py-3 px-4 text-center">Aksi Cepat</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    <RefreshCw className="w-6 h-6 animate-spin mx-auto mb-2 text-amber-600" />
                    Memuat matriks harga...
                  </td>
                </tr>
              ) : filteredProducts.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    Tidak ada produk ditemukan.
                  </td>
                </tr>
              ) : (
                filteredProducts.map((p) => {
                  const v = p.variants?.[0];
                  const cost = Number(v?.cost_price || 0);
                  const priceA = Number(v?.price || 0);
                  const priceB = Number(v?.member_price ?? (priceA * 0.95));
                  const priceC = Number(v?.reseller_price ?? (priceA * 0.9));
                  const priceD = Number(v?.wholesale_price ?? (priceA * 0.85));

                  return (
                    <tr key={p.id} className="hover:bg-slate-50/70 transition">
                      <td className="py-3 px-4">
                        <div className="font-semibold text-slate-800 text-sm">{p.name}</div>
                        <div className="flex items-center gap-2 mt-0.5 text-slate-400">
                          <span className="font-mono text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded text-[11px]">
                            {v?.sku || 'SKU-NONE'}
                          </span>
                          {p.category && (
                            <span className="bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded text-[11px]">
                              {p.category.name}
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="py-3 px-3">
                        <span className="font-bold text-slate-700 bg-slate-100 px-2 py-1 rounded">
                          {p.unit || 'PCS'}
                        </span>
                      </td>
                      <td className="py-3 px-3 text-right font-semibold text-slate-600">
                        {formatIDR(cost)}
                      </td>
                      <td className="py-3 px-3 text-center bg-emerald-50/20">
                        <div className="font-bold text-emerald-950">{formatIDR(priceA)}</div>
                        <span className="text-[10px] font-medium text-emerald-700 bg-emerald-100/70 px-1.5 py-0.5 rounded">
                          {calcMargin(priceA, cost)}
                        </span>
                      </td>
                      <td className="py-3 px-3 text-center bg-blue-50/20">
                        <div className="font-bold text-blue-950">{formatIDR(priceB)}</div>
                        <span className="text-[10px] font-medium text-blue-700 bg-blue-100/70 px-1.5 py-0.5 rounded">
                          {calcMargin(priceB, cost)}
                        </span>
                      </td>
                      <td className="py-3 px-3 text-center bg-purple-50/20">
                        <div className="font-bold text-purple-950">{formatIDR(priceC)}</div>
                        <span className="text-[10px] font-medium text-purple-700 bg-purple-100/70 px-1.5 py-0.5 rounded">
                          {calcMargin(priceC, cost)}
                        </span>
                      </td>
                      <td className="py-3 px-3 text-center bg-amber-50/20">
                        <div className="font-bold text-amber-950">{formatIDR(priceD)}</div>
                        <span className="text-[10px] font-medium text-amber-700 bg-amber-100/70 px-1.5 py-0.5 rounded">
                          {calcMargin(priceD, cost)}
                        </span>
                      </td>
                      <td className="py-3 px-4 text-center">
                        <button
                          type="button"
                          onClick={() => openPriceModal(p)}
                          className="px-3 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-800 rounded-lg text-xs font-semibold transition flex items-center gap-1.5 mx-auto cursor-pointer"
                        >
                          <Edit className="w-3.5 h-3.5" />
                          Ubah Harga 4 Tier
                        </button>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Modal Ubah Harga 4 Tier */}
      {showEditModal && editingItem && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4 overflow-y-auto">
          <div className="bg-white rounded-2xl w-full max-w-2xl shadow-xl overflow-hidden my-8">
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-amber-50/40">
              <div className="flex items-center gap-2">
                <Coins className="w-5 h-5 text-amber-600" />
                <h2 className="text-lg font-bold text-slate-800">
                  Ubah Master Harga 4 Tier ({editingItem.name})
                </h2>
              </div>
              <button
                type="button"
                onClick={() => setShowEditModal(false)}
                className="text-slate-400 hover:text-slate-600 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSavePrices} className="p-6 space-y-5">
              <div className="bg-slate-50 p-3 rounded-xl border border-slate-200 flex items-center justify-between text-xs">
                <div>
                  <span className="text-slate-500">SKU:</span>{' '}
                  <span className="font-mono font-bold text-slate-800">{editingItem.sku}</span>
                </div>
                <div>
                  <span className="text-slate-500">Satuan Dasar:</span>{' '}
                  <span className="font-bold text-slate-800">{editingItem.unit}</span>
                </div>
              </div>

              {/* HPP Modal */}
              <div className="bg-slate-50 p-4 rounded-xl border border-slate-200">
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  HPP (Harga Pokok Pembelian / Modal Produk) (Rp)
                </label>
                <input
                  type="number"
                  min="0"
                  step="any"
                  required
                  value={priceForm.cost_price}
                  onChange={(e) => setPriceForm({ ...priceForm, cost_price: Number(e.target.value) })}
                  className="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-sm font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500"
                />
              </div>

              {/* 4 Identity Inputs */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {/* Tier A: Retail */}
                <div className="bg-emerald-50/50 p-3.5 rounded-xl border border-emerald-200">
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-xs font-bold text-emerald-800">Tier A: Retail / Umum</span>
                    <span className="text-[10px] font-bold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded">
                      Margin: {calcMargin(priceForm.tier_a_price, priceForm.cost_price)}
                    </span>
                  </div>
                  <input
                    type="number"
                    min="0"
                    step="any"
                    required
                    value={priceForm.tier_a_price}
                    onChange={(e) => setPriceForm({ ...priceForm, tier_a_price: Number(e.target.value) })}
                    className="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                  />
                </div>

                {/* Tier B: Member */}
                <div className="bg-blue-50/50 p-3.5 rounded-xl border border-blue-200">
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-xs font-bold text-blue-800">Tier B: Member / Langganan</span>
                    <span className="text-[10px] font-bold text-blue-700 bg-blue-100 px-2 py-0.5 rounded">
                      Margin: {calcMargin(priceForm.tier_b_price, priceForm.cost_price)}
                    </span>
                  </div>
                  <input
                    type="number"
                    min="0"
                    step="any"
                    required
                    value={priceForm.tier_b_price}
                    onChange={(e) => setPriceForm({ ...priceForm, tier_b_price: Number(e.target.value) })}
                    className="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm font-bold text-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>

                {/* Tier C: Reseller */}
                <div className="bg-purple-50/50 p-3.5 rounded-xl border border-purple-200">
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-xs font-bold text-purple-800">Tier C: Reseller / Mitra Warung</span>
                    <span className="text-[10px] font-bold text-purple-700 bg-purple-100 px-2 py-0.5 rounded">
                      Margin: {calcMargin(priceForm.tier_c_price, priceForm.cost_price)}
                    </span>
                  </div>
                  <input
                    type="number"
                    min="0"
                    step="any"
                    required
                    value={priceForm.tier_c_price}
                    onChange={(e) => setPriceForm({ ...priceForm, tier_c_price: Number(e.target.value) })}
                    className="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm font-bold text-purple-900 focus:outline-none focus:ring-2 focus:ring-purple-500"
                  />
                </div>

                {/* Tier D: Wholesale */}
                <div className="bg-amber-50/50 p-3.5 rounded-xl border border-amber-200">
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-xs font-bold text-amber-800">Tier D: Grosir VIP (Wholesale)</span>
                    <span className="text-[10px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded">
                      Margin: {calcMargin(priceForm.tier_d_price, priceForm.cost_price)}
                    </span>
                  </div>
                  <input
                    type="number"
                    min="0"
                    step="any"
                    required
                    value={priceForm.tier_d_price}
                    onChange={(e) => setPriceForm({ ...priceForm, tier_d_price: Number(e.target.value) })}
                    className="w-full px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm font-bold text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-500"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Catatan Riwayat Perubahan (Audit Trail)
                </label>
                <input
                  type="text"
                  placeholder="Contoh: Penyesuaian harga promo supplier / fluktuasi bahan baku"
                  value={priceForm.notes}
                  onChange={(e) => setPriceForm({ ...priceForm, notes: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-amber-500"
                />
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowEditModal(false)}
                  className="px-4 py-2 bg-slate-100 text-slate-700 rounded-xl hover:bg-slate-200 text-sm font-semibold transition cursor-pointer"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 bg-amber-600 text-white rounded-xl hover:bg-amber-700 text-sm font-semibold shadow-sm transition flex items-center gap-2 cursor-pointer"
                >
                  {saving && <RefreshCw className="w-4 h-4 animate-spin" />}
                  Simpan Perubahan Harga
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

