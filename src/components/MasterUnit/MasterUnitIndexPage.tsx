import React, { useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  Boxes,
  CheckCircle2,
  Layers,
  Package,
  Plus,
  RefreshCw,
  Search,
  Tag,
  Trash2,
  X,
} from 'lucide-react';
import { api } from '../../lib/api';
import type { MasterProductItem, MasterUnit, ProductUnit } from '../../types/pos';

export const MasterUnitIndexPage: React.FC = () => {
  const [activeTab, setActiveTab] = useState<'base_units' | 'product_packaging'>('product_packaging');
  const [baseUnits, setBaseUnits] = useState<MasterUnit[]>([]);
  const [products, setProducts] = useState<MasterProductItem[]>([]);
  const [selectedProductId, setSelectedProductId] = useState<number | null>(null);
  const [selectedProductUnits, setSelectedProductUnits] = useState<ProductUnit[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [loadingUnits, setLoadingUnits] = useState<boolean>(false);
  const [actionMessage, setActionMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Search product
  const [productSearch, setProductSearch] = useState<string>('');

  // Modal State: Tambah Base Unit
  const [showBaseUnitModal, setShowBaseUnitModal] = useState<boolean>(false);
  const [baseUnitForm, setBaseUnitForm] = useState({
    code: '',
    name: '',
    unit_type: 'unit',
    description: '',
  });

  // Modal State: Tambah Packaging Index per Product
  const [showPackagingModal, setShowPackagingModal] = useState<boolean>(false);
  const [packagingForm, setPackagingForm] = useState({
    unit_code: '',
    unit_name: '',
    conversion_factor: 12,
    barcode: '',
    purchase_cost: 0,
    tier_a_price: 0,
    tier_b_price: 0,
    tier_c_price: 0,
    tier_d_price: 0,
  });

  const [saving, setSaving] = useState<boolean>(false);

  const loadInitialData = async () => {
    setLoading(true);
    try {
      const [uRes, pRes] = await Promise.all([
        api.get<{ units: MasterUnit[] }>('/master-data/units'),
        api.get<any>('/master-data/products?per_page=150'),
      ]);

      const unitsData = Array.isArray(uRes.data?.units) ? uRes.data.units : [];
      setBaseUnits(unitsData);

      const prodsData = pRes.data?.data || pRes.data?.products || [];
      const prodsList: MasterProductItem[] = Array.isArray(prodsData) ? prodsData : [];
      setProducts(prodsList);

      if (prodsList.length > 0 && selectedProductId === null) {
        setSelectedProductId(prodsList[0].id);
        loadProductUnits(prodsList[0].id);
      }
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal memuat master satuan.',
      });
    } finally {
      setLoading(false);
    }
  };

  const loadProductUnits = async (productId: number) => {
    setLoadingUnits(true);
    try {
      const res = await api.get<{ product_units: ProductUnit[] }>(
        `/master-data/products/${productId}/units`
      );
      setSelectedProductUnits(Array.isArray(res.data?.product_units) ? res.data.product_units : []);
    } catch {
      setSelectedProductUnits([]);
    } finally {
      setLoadingUnits(false);
    }
  };

  useEffect(() => {
    loadInitialData();
  }, []);

  const handleSelectProduct = (pId: number) => {
    setSelectedProductId(pId);
    loadProductUnits(pId);
  };

  const selectedProduct = useMemo(() => {
    return products.find((p) => p.id === selectedProductId) || null;
  }, [products, selectedProductId]);

  // Handle Save Base Unit
  const handleSaveBaseUnit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!baseUnitForm.code || !baseUnitForm.name) return;

    setSaving(true);
    setActionMessage(null);
    try {
      await api.post('/master-data/units', baseUnitForm);
      setActionMessage({
        type: 'success',
        text: `Satuan "${baseUnitForm.name} (${baseUnitForm.code})" berhasil ditambahkan.`,
      });
      setShowBaseUnitModal(false);
      setBaseUnitForm({ code: '', name: '', unit_type: 'unit', description: '' });
      await loadInitialData();
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal menyimpan satuan master.',
      });
    } finally {
      setSaving(false);
    }
  };

  // Handle Delete Base Unit
  const handleDeleteBaseUnit = async (unitId: number, code: string) => {
    if (!confirm(`Hapus satuan master "${code}"?`)) return;
    try {
      await api.delete(`/master-data/units/${unitId}`);
      setActionMessage({ type: 'success', text: `Satuan master "${code}" berhasil dihapus.` });
      await loadInitialData();
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal menghapus satuan master.',
      });
    }
  };

  // Open Packaging Modal
  const openPackagingModal = () => {
    if (!selectedProduct) return;
    const v = selectedProduct.variants?.[0];
    const cost = Number(v?.cost_price || 0);
    const pA = Number(v?.price || 0);
    const defaultFactor = 24;

    setPackagingForm({
      unit_code: 'DUS',
      unit_name: 'DUS / Karton',
      conversion_factor: defaultFactor,
      barcode: '',
      purchase_cost: cost * defaultFactor,
      tier_a_price: pA * defaultFactor,
      tier_b_price: Number(v?.member_price ?? pA * 0.95) * defaultFactor,
      tier_c_price: Number(v?.reseller_price ?? pA * 0.9) * defaultFactor,
      tier_d_price: Number(v?.wholesale_price ?? pA * 0.85) * defaultFactor,
    });
    setShowPackagingModal(true);
  };

  // Handle Save Packaging Unit
  const handleSavePackagingUnit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedProductId || !packagingForm.unit_code) return;

    setSaving(true);
    setActionMessage(null);
    try {
      await api.post(`/master-data/products/${selectedProductId}/units`, {
        unit_code: packagingForm.unit_code.toUpperCase(),
        unit_name: packagingForm.unit_name,
        conversion_factor: Number(packagingForm.conversion_factor),
        barcode: packagingForm.barcode || undefined,
        purchase_cost: Number(packagingForm.purchase_cost) || 0,
        selling_price: Number(packagingForm.tier_a_price) || 0,
        tier_a_price: Number(packagingForm.tier_a_price) || 0,
        member_price: Number(packagingForm.tier_b_price) || 0,
        tier_b_price: Number(packagingForm.tier_b_price) || 0,
        reseller_price: Number(packagingForm.tier_c_price) || 0,
        tier_c_price: Number(packagingForm.tier_c_price) || 0,
        wholesale_price: Number(packagingForm.tier_d_price) || 0,
        tier_d_price: Number(packagingForm.tier_d_price) || 0,
        is_base_unit: false,
        is_default_pos_unit: false,
        is_default_purchase_unit: false,
        is_default_sales_order_unit: true,
      });

      setActionMessage({
        type: 'success',
        text: `Index kemasan "${packagingForm.unit_code}" (x${packagingForm.conversion_factor}) berhasil ditambahkan ke produk!`,
      });
      setShowPackagingModal(false);
      await loadProductUnits(selectedProductId);
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal menyimpan index kemasan.',
      });
    } finally {
      setSaving(false);
    }
  };

  // Handle Delete Packaging Unit
  const handleDeletePackagingUnit = async (unitId: number, unitCode: string) => {
    if (!selectedProductId) return;
    if (!confirm(`Hapus kemasan "${unitCode}" dari produk ini?`)) return;

    try {
      await api.delete(`/master-data/products/${selectedProductId}/units/${unitId}`);
      setActionMessage({
        type: 'success',
        text: `Index kemasan "${unitCode}" berhasil dihapus.`,
      });
      await loadProductUnits(selectedProductId);
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal menghapus index kemasan.',
      });
    }
  };

  const formatIDR = (val: number | string | null | undefined) => {
    const num = Number(val || 0);
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0,
    }).format(num);
  };

  const filteredProductOptions = useMemo(() => {
    if (!productSearch) return products;
    return products.filter((p) =>
      p.name.toLowerCase().includes(productSearch.toLowerCase()) ||
      p.variants?.[0]?.sku?.toLowerCase().includes(productSearch.toLowerCase())
    );
  }, [products, productSearch]);

  return (
    <div className="p-6 space-y-6">
      {/* Top Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800 flex items-center gap-2">
            <Layers className="w-7 h-7 text-indigo-600" />
            Master Satuan & Index Kemasan Bertingkat
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Pengaturan satuan dasar umum (UOM) dan pembuatan index kemasan bertingkat (PCS, PACK, DUS) per produk beserta nominal harga 4 tier.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={loadInitialData}
            disabled={loading}
            className="flex items-center gap-2 px-3 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl hover:bg-slate-50 transition text-sm font-semibold shadow-xs cursor-pointer"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
            Muat Ulang
          </button>
        </div>
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

      {/* Tab Switcher */}
      <div className="flex border-b border-slate-200 gap-4">
        <button
          type="button"
          onClick={() => setActiveTab('product_packaging')}
          className={`pb-3 px-1 text-sm font-bold flex items-center gap-2 border-b-2 transition cursor-pointer ${
            activeTab === 'product_packaging'
              ? 'border-indigo-600 text-indigo-600'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
        >
          <Boxes className="w-4 h-4" />
          Index Kemasan Per Produk (Multi-UOM)
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('base_units')}
          className={`pb-3 px-1 text-sm font-bold flex items-center gap-2 border-b-2 transition cursor-pointer ${
            activeTab === 'base_units'
              ? 'border-indigo-600 text-indigo-600'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
        >
          <Tag className="w-4 h-4" />
          Master Satuan Dasar (Base UOM)
        </button>
      </div>

      {/* TAB 1: INDEX KEMASAN PER PRODUK */}
      {activeTab === 'product_packaging' && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Kolom Kiri: Pemilihan Produk */}
          <div className="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs space-y-4">
            <h3 className="font-bold text-slate-800 text-sm flex items-center gap-2">
              <Package className="w-4 h-4 text-indigo-600" />
              Pilih Produk
            </h3>

            <div className="relative">
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                placeholder="Cari nama atau SKU..."
                value={productSearch}
                onChange={(e) => setProductSearch(e.target.value)}
                className="w-full pl-9 pr-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>

            <div className="space-y-1.5 max-h-[500px] overflow-y-auto pr-1">
              {filteredProductOptions.map((p) => {
                const isSelected = p.id === selectedProductId;
                return (
                  <button
                    key={p.id}
                    type="button"
                    onClick={() => handleSelectProduct(p.id)}
                    className={`w-full text-left p-3 rounded-xl transition cursor-pointer flex items-center justify-between border ${
                      isSelected
                        ? 'bg-indigo-50 border-indigo-200 text-indigo-950 font-semibold'
                        : 'bg-white hover:bg-slate-50 border-slate-100 text-slate-700'
                    }`}
                  >
                    <div>
                      <div className="text-xs">{p.name}</div>
                      <div className="text-[11px] text-slate-400 mt-0.5 font-mono">
                        {p.variants?.[0]?.sku || 'SKU'} • Base: {p.unit || 'PCS'}
                      </div>
                    </div>
                    {isSelected && (
                      <span className="w-2 h-2 rounded-full bg-indigo-600"></span>
                    )}
                  </button>
                );
              })}
            </div>
          </div>

          {/* Kolom Kanan: Rincian Satuan & Index Kemasan */}
          <div className="lg:col-span-2 space-y-4">
            {selectedProduct ? (
              <>
                {/* Product Header Card */}
                <div className="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
                  <div>
                    <span className="text-[11px] font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded uppercase tracking-wider">
                      Spesifikasi Produk Terpilih
                    </span>
                    <h2 className="text-xl font-bold text-slate-800 mt-1">{selectedProduct.name}</h2>
                    <div className="flex items-center gap-3 mt-1 text-xs text-slate-500">
                      <span>SKU: <strong className="font-mono text-slate-700">{selectedProduct.variants?.[0]?.sku}</strong></span>
                      <span>•</span>
                      <span>Satuan Dasar: <strong className="text-indigo-700">{selectedProduct.unit || 'PCS'}</strong></span>
                      <span>•</span>
                      <span>HPP Dasar: <strong>{formatIDR(selectedProduct.variants?.[0]?.cost_price)}</strong></span>
                    </div>
                  </div>

                  <button
                    type="button"
                    onClick={openPackagingModal}
                    className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition text-xs font-semibold shadow-sm cursor-pointer self-start md:self-auto"
                  >
                    <Plus className="w-4 h-4" />
                    Tambah Index Kemasan (DUS/PACK)
                  </button>
                </div>

                {/* Packaging Hierarchy Table */}
                <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
                  <div className="p-4 border-b border-slate-100 flex items-center justify-between">
                    <h3 className="font-bold text-slate-800 text-xs uppercase tracking-wider">
                      Hierarki Satuan & Harga Kemasan Bertingkat
                    </h3>
                    <span className="text-[11px] text-slate-400">
                      Otomatis konversi ke stok fisik (Base Unit) saat checkout & SO
                    </span>
                  </div>

                  <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse text-xs">
                      <thead>
                        <tr className="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200 uppercase tracking-wider">
                          <th className="py-2.5 px-3">Kode Kemasan</th>
                          <th className="py-2.5 px-3">Index Konversi</th>
                          <th className="py-2.5 px-3">Barcode</th>
                          <th className="py-2.5 px-3 text-right">Modal</th>
                          <th className="py-2.5 px-3 text-center">Tier A (Retail)</th>
                          <th className="py-2.5 px-3 text-center">Tier B (Member)</th>
                          <th className="py-2.5 px-3 text-center">Tier C (Reseller)</th>
                          <th className="py-2.5 px-3 text-center">Tier D (Grosir)</th>
                          <th className="py-2.5 px-3 text-center">Aksi</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {/* Row 1: Base Unit */}
                        <tr className="bg-slate-50/50">
                          <td className="py-2.5 px-3 font-bold text-slate-800">
                            {selectedProduct.unit || 'PCS'}
                            <span className="ml-1.5 text-[10px] font-semibold text-emerald-700 bg-emerald-100 px-1.5 py-0.5 rounded">
                              Satuan Dasar
                            </span>
                          </td>
                          <td className="py-2.5 px-3 font-semibold text-slate-600">
                            1.000 (Base)
                          </td>
                          <td className="py-2.5 px-3 font-mono text-slate-500">
                            {selectedProduct.variants?.[0]?.barcode || selectedProduct.default_barcode || '-'}
                          </td>
                          <td className="py-2.5 px-3 text-right text-slate-600">
                            {formatIDR(selectedProduct.variants?.[0]?.cost_price)}
                          </td>
                          <td className="py-2.5 px-3 text-center font-bold text-emerald-950">
                            {formatIDR(selectedProduct.variants?.[0]?.price)}
                          </td>
                          <td className="py-2.5 px-3 text-center font-bold text-blue-950">
                            {formatIDR(selectedProduct.variants?.[0]?.member_price ?? Number(selectedProduct.variants?.[0]?.price || 0) * 0.95)}
                          </td>
                          <td className="py-2.5 px-3 text-center font-bold text-purple-950">
                            {formatIDR(selectedProduct.variants?.[0]?.reseller_price ?? Number(selectedProduct.variants?.[0]?.price || 0) * 0.9)}
                          </td>
                          <td className="py-2.5 px-3 text-center font-bold text-amber-950">
                            {formatIDR(selectedProduct.variants?.[0]?.wholesale_price ?? Number(selectedProduct.variants?.[0]?.price || 0) * 0.85)}
                          </td>
                          <td className="py-2.5 px-3 text-center text-slate-400 text-[11px] italic">
                            Default
                          </td>
                        </tr>

                        {/* Packaging Units */}
                        {loadingUnits ? (
                          <tr>
                            <td colSpan={9} className="py-8 text-center text-slate-400">
                              <RefreshCw className="w-5 h-5 animate-spin mx-auto mb-1 text-indigo-600" />
                              Memuat kemasan bertingkat...
                            </td>
                          </tr>
                        ) : selectedProductUnits.length === 0 ? (
                          <tr>
                            <td colSpan={9} className="py-6 text-center text-slate-400 text-xs">
                              Belum ada index kemasan khusus (DUS/PACK) untuk produk ini. Klik "Tambah Index Kemasan" di atas untuk menambahkan.
                            </td>
                          </tr>
                        ) : (
                          selectedProductUnits
                            .filter((u) => Number(u.conversion_factor) !== 1 || u.unit_code !== selectedProduct.unit)
                            .map((u) => (
                              <tr key={u.id} className="hover:bg-slate-50/70">
                                <td className="py-2.5 px-3">
                                  <div className="font-bold text-indigo-950">{u.unit_code}</div>
                                  <div className="text-[11px] text-slate-400">{u.unit_name}</div>
                                </td>
                                <td className="py-2.5 px-3">
                                  <span className="font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded">
                                    1 {u.unit_code} = {Number(u.conversion_factor)} {selectedProduct.unit}
                                  </span>
                                </td>
                                <td className="py-2.5 px-3 font-mono text-slate-500">
                                  {u.barcode || '-'}
                                </td>
                                <td className="py-2.5 px-3 text-right text-slate-600">
                                  {formatIDR(u.purchase_cost)}
                                </td>
                                <td className="py-2.5 px-3 text-center font-bold text-emerald-950">
                                  {formatIDR(u.selling_price)}
                                </td>
                                <td className="py-2.5 px-3 text-center font-bold text-blue-950">
                                  {formatIDR(u.member_price ?? Number(u.selling_price || 0) * 0.95)}
                                </td>
                                <td className="py-2.5 px-3 text-center font-bold text-purple-950">
                                  {formatIDR(u.reseller_price ?? Number(u.selling_price || 0) * 0.9)}
                                </td>
                                <td className="py-2.5 px-3 text-center font-bold text-amber-950">
                                  {formatIDR(u.wholesale_price ?? Number(u.selling_price || 0) * 0.85)}
                                </td>
                                <td className="py-2.5 px-3 text-center">
                                  <button
                                    type="button"
                                    onClick={() => handleDeletePackagingUnit(u.id, u.unit_code)}
                                    className="p-1 text-rose-500 hover:bg-rose-50 rounded transition cursor-pointer"
                                    title="Hapus Kemasan"
                                  >
                                    <Trash2 className="w-4 h-4" />
                                  </button>
                                </td>
                              </tr>
                            ))
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              </>
            ) : (
              <div className="bg-white p-12 text-center text-slate-400 rounded-2xl border border-slate-200">
                Pilih salah satu produk di sebelah kiri untuk mengatur index kemasan.
              </div>
            )}
          </div>
        </div>
      )}

      {/* TAB 2: MASTER SATUAN DASAR (BASE UOM) */}
      {activeTab === 'base_units' && (
        <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
          <div className="p-4 border-b border-slate-100 flex items-center justify-between">
            <div>
              <h3 className="font-bold text-slate-800 text-sm">Daftar Satuan Master Sistem</h3>
              <p className="text-xs text-slate-400 mt-0.5">
                Kamus satuan dasar yang dapat digunakan saat mendaftarkan produk baru atau konversi kemasan.
              </p>
            </div>
            <button
              type="button"
              onClick={() => setShowBaseUnitModal(true)}
              className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition text-xs font-semibold shadow-sm cursor-pointer"
            >
              <Plus className="w-4 h-4" />
              Tambah Satuan Baru
            </button>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200 uppercase tracking-wider">
                  <th className="py-3 px-4">Kode Satuan</th>
                  <th className="py-3 px-4">Nama Lengkap</th>
                  <th className="py-3 px-4">Tipe Satuan</th>
                  <th className="py-3 px-4">Keterangan</th>
                  <th className="py-3 px-4 text-center">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {baseUnits.map((u) => (
                  <tr key={u.id} className="hover:bg-slate-50/70 transition">
                    <td className="py-3 px-4">
                      <span className="font-bold text-indigo-950 font-mono bg-indigo-50 px-2 py-0.5 rounded">
                        {u.code}
                      </span>
                    </td>
                    <td className="py-3 px-4 font-semibold text-slate-800">{u.name}</td>
                    <td className="py-3 px-4 capitalize text-slate-600">{u.unit_type}</td>
                    <td className="py-3 px-4 text-slate-500">{u.description || '-'}</td>
                    <td className="py-3 px-4 text-center">
                      <button
                        type="button"
                        onClick={() => handleDeleteBaseUnit(u.id, u.code)}
                        className="p-1.5 text-rose-500 hover:bg-rose-50 rounded-lg transition cursor-pointer"
                        title="Hapus Satuan"
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Modal Tambah Satuan Dasar */}
      {showBaseUnitModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4">
          <div className="bg-white rounded-2xl w-full max-w-md shadow-xl overflow-hidden p-6">
            <div className="flex items-center justify-between pb-4 border-b border-slate-100">
              <h2 className="text-base font-bold text-slate-800 flex items-center gap-2">
                <Tag className="w-5 h-5 text-indigo-600" />
                Tambah Satuan Master Baru
              </h2>
              <button
                type="button"
                onClick={() => setShowBaseUnitModal(false)}
                className="text-slate-400 hover:text-slate-600 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSaveBaseUnit} className="space-y-4 pt-4">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Kode Satuan <span className="text-rose-500">*</span>
                </label>
                <input
                  type="text"
                  required
                  placeholder="Contoh: DUS, PACK, BAL, LTR"
                  value={baseUnitForm.code}
                  onChange={(e) => setBaseUnitForm({ ...baseUnitForm, code: e.target.value.toUpperCase() })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Nama Satuan <span className="text-rose-500">*</span>
                </label>
                <input
                  type="text"
                  required
                  placeholder="Contoh: Dus Karton / Pack Isi 12"
                  value={baseUnitForm.name}
                  onChange={(e) => setBaseUnitForm({ ...baseUnitForm, name: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Tipe Satuan
                </label>
                <select
                  value={baseUnitForm.unit_type}
                  onChange={(e) => setBaseUnitForm({ ...baseUnitForm, unit_type: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  <option value="unit">Unit / Kemasan Satuan</option>
                  <option value="weight">Berat (Kg, Gram)</option>
                  <option value="volume">Volume (Liter, ML)</option>
                  <option value="length">Panjang (Meter, CM)</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Deskripsi / Keterangan
                </label>
                <input
                  type="text"
                  placeholder="Opsional"
                  value={baseUnitForm.description}
                  onChange={(e) => setBaseUnitForm({ ...baseUnitForm, description: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowBaseUnitModal(false)}
                  className="px-4 py-2 bg-slate-100 text-slate-700 rounded-xl hover:bg-slate-200 text-xs font-semibold transition cursor-pointer"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 text-xs font-semibold shadow-sm transition flex items-center gap-2 cursor-pointer"
                >
                  {saving && <RefreshCw className="w-4 h-4 animate-spin" />}
                  Simpan Satuan
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Tambah Index Kemasan per Produk */}
      {showPackagingModal && selectedProduct && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4 overflow-y-auto">
          <div className="bg-white rounded-2xl w-full max-w-xl shadow-xl overflow-hidden my-8">
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-indigo-50/40">
              <h2 className="text-base font-bold text-slate-800 flex items-center gap-2">
                <Boxes className="w-5 h-5 text-indigo-600" />
                Tambah Kemasan ({selectedProduct.name})
              </h2>
              <button
                type="button"
                onClick={() => setShowPackagingModal(false)}
                className="text-slate-400 hover:text-slate-600 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleSavePackagingUnit} className="p-6 space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Kode Kemasan <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    placeholder="DUS / PACK / BAL"
                    value={packagingForm.unit_code}
                    onChange={(e) => setPackagingForm({ ...packagingForm, unit_code: e.target.value.toUpperCase() })}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Nama Kemasan <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    placeholder="Contoh: Dus Karton 24 Botol"
                    value={packagingForm.unit_name}
                    onChange={(e) => setPackagingForm({ ...packagingForm, unit_name: e.target.value })}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Faktor Konversi (Jumlah {selectedProduct.unit}) <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="number"
                    min="1"
                    required
                    value={packagingForm.conversion_factor}
                    onChange={(e) => setPackagingForm({ ...packagingForm, conversion_factor: Number(e.target.value) })}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                  <span className="text-[10px] text-slate-400">1 {packagingForm.unit_code || 'Kemasan'} = {packagingForm.conversion_factor} {selectedProduct.unit}</span>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">
                    Barcode Kemasan (Opsional)
                  </label>
                  <input
                    type="text"
                    placeholder="Scan barcode dus"
                    value={packagingForm.barcode}
                    onChange={(e) => setPackagingForm({ ...packagingForm, barcode: e.target.value })}
                    className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              </div>

              {/* Pricing Section */}
              <div className="pt-3 border-t border-slate-100">
                <h4 className="text-xs font-bold text-slate-700 mb-2">Penetapan Harga Kemasan (4 Tier Nominal)</h4>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-[11px] font-semibold text-slate-600 mb-0.5">
                      Modal / HPP Kemasan (Rp)
                    </label>
                    <input
                      type="number"
                      min="0"
                      value={packagingForm.purchase_cost}
                      onChange={(e) => setPackagingForm({ ...packagingForm, purchase_cost: Number(e.target.value) })}
                      className="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-semibold"
                    />
                  </div>

                  <div>
                    <label className="block text-[11px] font-semibold text-emerald-800 mb-0.5">
                      Tier A: Retail / Umum (Rp)
                    </label>
                    <input
                      type="number"
                      min="0"
                      value={packagingForm.tier_a_price}
                      onChange={(e) => setPackagingForm({ ...packagingForm, tier_a_price: Number(e.target.value) })}
                      className="w-full px-2.5 py-1.5 bg-emerald-50/50 border border-emerald-200 rounded-lg text-xs font-bold text-emerald-900"
                    />
                  </div>

                  <div>
                    <label className="block text-[11px] font-semibold text-blue-800 mb-0.5">
                      Tier B: Member (Rp)
                    </label>
                    <input
                      type="number"
                      min="0"
                      value={packagingForm.tier_b_price}
                      onChange={(e) => setPackagingForm({ ...packagingForm, tier_b_price: Number(e.target.value) })}
                      className="w-full px-2.5 py-1.5 bg-blue-50/50 border border-blue-200 rounded-lg text-xs font-bold text-blue-900"
                    />
                  </div>

                  <div>
                    <label className="block text-[11px] font-semibold text-purple-800 mb-0.5">
                      Tier C: Reseller (Rp)
                    </label>
                    <input
                      type="number"
                      min="0"
                      value={packagingForm.tier_c_price}
                      onChange={(e) => setPackagingForm({ ...packagingForm, tier_c_price: Number(e.target.value) })}
                      className="w-full px-2.5 py-1.5 bg-purple-50/50 border border-purple-200 rounded-lg text-xs font-bold text-purple-900"
                    />
                  </div>

                  <div className="col-span-2">
                    <label className="block text-[11px] font-semibold text-amber-800 mb-0.5">
                      Tier D: Grosir VIP (Rp)
                    </label>
                    <input
                      type="number"
                      min="0"
                      value={packagingForm.tier_d_price}
                      onChange={(e) => setPackagingForm({ ...packagingForm, tier_d_price: Number(e.target.value) })}
                      className="w-full px-2.5 py-1.5 bg-amber-50/50 border border-amber-200 rounded-lg text-xs font-bold text-amber-900"
                    />
                  </div>
                </div>
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowPackagingModal(false)}
                  className="px-4 py-2 bg-slate-100 text-slate-700 rounded-xl hover:bg-slate-200 text-xs font-semibold transition cursor-pointer"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={saving}
                  className="px-5 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 text-xs font-semibold shadow-sm transition flex items-center gap-2 cursor-pointer"
                >
                  {saving && <RefreshCw className="w-4 h-4 animate-spin" />}
                  Simpan Kemasan
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

