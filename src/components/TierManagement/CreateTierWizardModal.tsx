import React, { useEffect, useMemo, useState } from 'react';
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  Boxes,
  Check,
  CheckCircle2,
  DollarSign,
  HelpCircle,
  Info,
  Layers,
  Palette,
  Percent,
  RefreshCw,
  Search,
  ShieldCheck,
  Tag,
  X,
} from 'lucide-react';
import { api } from '../../lib/api';
import type { Category, MasterProductItem } from '../../types/pos';

interface CreateTierWizardModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
}

interface ProductPriceRow {
  product_id: number;
  product_variant_id?: number;
  name: string;
  sku: string;
  category_name?: string;
  unit: string;
  cost_price: number;
  default_price: number;
  selected: boolean;
  tier_price: number | '';
  effective_from: string;
  effective_until: string;
  status: 'active' | 'inactive';
  notes: string;
}

const PRESET_COLORS = [
  { name: 'Blue', hex: '#2563EB', bgClass: 'bg-blue-600' },
  { name: 'Emerald', hex: '#059669', bgClass: 'bg-emerald-600' },
  { name: 'Amber', hex: '#D97706', bgClass: 'bg-amber-600' },
  { name: 'Purple', hex: '#7C3AED', bgClass: 'bg-purple-600' },
  { name: 'Rose', hex: '#E11D48', bgClass: 'bg-rose-600' },
  { name: 'Indigo', hex: '#4F46E5', bgClass: 'bg-indigo-600' },
  { name: 'Cyan', hex: '#0891B2', bgClass: 'bg-cyan-600' },
  { name: 'Teal', hex: '#0D9488', bgClass: 'bg-teal-600' },
];

export const CreateTierWizardModal: React.FC<CreateTierWizardModalProps> = ({
  isOpen,
  onClose,
  onSuccess,
}) => {
  const [currentStep, setCurrentStep] = useState<1 | 2 | 3>(1);
  const [saving, setSaving] = useState<boolean>(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  // Step 1: Tier Info State
  const [tierInfo, setTierInfo] = useState({
    name: '',
    code: '',
    description: '',
    display_order: 1,
    color: '#2563EB',
    status: 'active' as 'active' | 'inactive',
  });

  // Step 2: Product Pricing Rows State
  const [loadingProducts, setLoadingProducts] = useState<boolean>(false);
  const [productRows, setProductRows] = useState<ProductPriceRow[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [selectedCatFilter, setSelectedCatFilter] = useState<string>('');
  const [bulkMarkupPercent, setBulkMarkupPercent] = useState<string>('');
  const [bulkDate, setBulkDate] = useState<string>(new Date().toISOString().slice(0, 10));

  // Load master products when modal opens
  useEffect(() => {
    if (!isOpen) return;

    const todayStr = new Date().toISOString().slice(0, 10);
    setBulkDate(todayStr);

    const loadMasterData = async () => {
      setLoadingProducts(true);
      setErrorMsg(null);
      try {
        const [prodRes, catRes] = await Promise.all([
          api.get<any>('/master-data/products?per_page=200'),
          api.get<{ categories: Category[] }>('/master-data/categories'),
        ]);

        const rawList: MasterProductItem[] = prodRes.data?.data || prodRes.data?.products || [];
        setCategories(Array.isArray(catRes.data?.categories) ? catRes.data.categories : []);

        const initialRows: ProductPriceRow[] = rawList.map((p) => {
          const v = p.variants?.[0];
          const cost = Number(v?.cost_price || 0);
          const defaultRetail = Number(v?.price || p.base_price || 0);

          return {
            product_id: p.id,
            product_variant_id: v?.id,
            name: p.name,
            sku: v?.sku || p.sku || p.slug,
            category_name: p.category?.name,
            unit: p.unit || 'PCS',
            cost_price: cost,
            default_price: defaultRetail,
            selected: true, // Default checked for convenience
            tier_price: defaultRetail, // Initial recommendation
            effective_from: todayStr,
            effective_until: '',
            status: 'active',
            notes: 'Harga awal pembentukan Tier',
          };
        });

        setProductRows(initialRows);
      } catch (err: any) {
        setErrorMsg('Gagal memuat katalog produk dari database.');
      } finally {
        setLoadingProducts(false);
      }
    };

    loadMasterData();
  }, [isOpen]);

  // Auto-generate code from name
  const handleNameChange = (val: string) => {
    const codeGen = val
      .toUpperCase()
      .replace(/[^A-Z0-9\s]/g, '')
      .trim()
      .replace(/\s+/g, '_');
    setTierInfo((prev) => ({
      ...prev,
      name: val,
      code: prev.code === '' || prev.code === prev.name.toUpperCase().replace(/\s+/g, '_') ? codeGen : prev.code,
    }));
  };

  // Toggle single product selection
  const handleToggleSelect = (pId: number) => {
    setProductRows((prev) =>
      prev.map((row) => (row.product_id === pId ? { ...row, selected: !row.selected } : row))
    );
  };

  // Select all or deselect all
  const handleSelectAll = (select: boolean) => {
    setProductRows((prev) => prev.map((row) => ({ ...row, selected: select })));
  };

  // Update tier price for a product
  const handlePriceChange = (pId: number, val: string) => {
    const num = val === '' ? '' : Math.max(0, parseFloat(val));
    setProductRows((prev) =>
      prev.map((row) => (row.product_id === pId ? { ...row, tier_price: num } : row))
    );
  };

  // Update effective date for a product
  const handleDateChange = (pId: number, dateVal: string) => {
    setProductRows((prev) =>
      prev.map((row) => (row.product_id === pId ? { ...row, effective_from: dateVal } : row))
    );
  };

  // Bulk set date
  const handleApplyBulkDate = () => {
    if (!bulkDate) return;
    setProductRows((prev) =>
      prev.map((row) => (row.selected ? { ...row, effective_from: bulkDate } : row))
    );
  };

  // Bulk set markup from cost price
  const handleApplyBulkMarkup = () => {
    const percent = parseFloat(bulkMarkupPercent);
    if (isNaN(percent) || percent < 0) {
      alert('Masukkan persentase markup yang valid (misal: 15 untuk 15%).');
      return;
    }
    setProductRows((prev) =>
      prev.map((row) => {
        if (!row.selected) return row;
        const newPrice = Math.round(row.cost_price * (1 + percent / 100));
        return { ...row, tier_price: newPrice };
      })
    );
  };

  // Filtered rows in Step 2
  const filteredRows = useMemo(() => {
    return productRows.filter((r) => {
      const matchSearch =
        searchTerm === '' ||
        r.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        r.sku.toLowerCase().includes(searchTerm.toLowerCase());
      const matchCat =
        selectedCatFilter === '' || r.category_name?.toLowerCase() === selectedCatFilter.toLowerCase();
      return matchSearch && matchCat;
    });
  }, [productRows, searchTerm, selectedCatFilter]);

  // Statistics for Step 3 Review
  const selectedRows = useMemo(() => productRows.filter((r) => r.selected), [productRows]);
  const validPricedRows = useMemo(
    () => selectedRows.filter((r) => typeof r.tier_price === 'number' && r.tier_price >= 0),
    [selectedRows]
  );
  const unpricedRows = useMemo(
    () => selectedRows.filter((r) => r.tier_price === '' || r.tier_price === null),
    [selectedRows]
  );

  // Validate Step 1
  const handleNextFromStep1 = () => {
    if (!tierInfo.name.trim()) {
      setErrorMsg('Nama Tier wajib diisi.');
      return;
    }
    if (!tierInfo.code.trim()) {
      setErrorMsg('Kode Tier wajib diisi.');
      return;
    }
    setErrorMsg(null);
    setCurrentStep(2);
  };

  // Validate Step 2
  const handleNextFromStep2 = () => {
    if (selectedRows.length === 0) {
      if (!confirm('Anda belum memilih satu pun produk untuk Tier ini. Lanjutkan tanpa produk?')) {
        return;
      }
    }
    if (unpricedRows.length > 0) {
      alert(
        `Terdapat ${unpricedRows.length} produk terpilih yang belum diisi harga jualnya. Mohon lengkapi harga jual sebelum lanjut.`
      );
      return;
    }
    setErrorMsg(null);
    setCurrentStep(3);
  };

  // Final Step 4: Atomic Save
  const handleFinalSave = async () => {
    setSaving(true);
    setErrorMsg(null);
    try {
      const payloadProducts = validPricedRows.map((r) => ({
        product_id: r.product_id,
        product_variant_id: r.product_variant_id,
        price: Number(r.tier_price),
        effective_from: r.effective_from,
        effective_until: r.effective_until || null,
        status: r.status,
        notes: r.notes || `Harga awal konfigurasi Tier ${tierInfo.name}`,
      }));

      const payload = {
        name: tierInfo.name.trim(),
        code: tierInfo.code.trim().toUpperCase(),
        description: tierInfo.description.trim() || null,
        display_order: Number(tierInfo.display_order) || 1,
        color: tierInfo.color,
        status: tierInfo.status,
        products: payloadProducts,
      };

      await api.post('/tiers', payload);
      onSuccess();
      onClose();
    } catch (err: any) {
      setErrorMsg(
        err?.response?.data?.message || 'Gagal menyimpan Tier. Pastikan kode Tier unik dan data valid.'
      );
    } finally {
      setSaving(false);
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

  const calcMargin = (sellPrice: number | string, costPrice: number | string) => {
    const s = Number(sellPrice || 0);
    const c = Number(costPrice || 0);
    if (s <= 0) return '0%';
    const margin = ((s - c) / s) * 100;
    return `${margin.toFixed(1)}%`;
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4 overflow-y-auto">
      <div className="bg-white rounded-3xl w-full max-w-5xl shadow-2xl overflow-hidden my-6 flex flex-col max-h-[92vh]">
        {/* Header with Stepper Progress */}
        <div className="px-6 py-4 border-b border-slate-100 bg-slate-50/70 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h2 className="text-lg font-bold text-slate-800 flex items-center gap-2">
              <Layers className="w-5 h-5 text-indigo-600" />
              Buat Tier Baru & Konfigurasi Harga Produk
            </h2>
            <p className="text-xs text-slate-500 mt-0.5">
              Definisikan identitas Tier, tetapkan harga jual per produk, dan tinjau sebelum disimpan.
            </p>
          </div>

          {/* Stepper Badges */}
          <div className="flex items-center gap-2">
            <div
              className={`flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition ${
                currentStep === 1
                  ? 'bg-indigo-600 text-white shadow-xs'
                  : currentStep > 1
                    ? 'bg-emerald-100 text-emerald-800'
                    : 'bg-slate-200 text-slate-500'
              }`}
            >
              <span>1</span>
              <span>Info Tier</span>
            </div>
            <span className="text-slate-300">→</span>
            <div
              className={`flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition ${
                currentStep === 2
                  ? 'bg-indigo-600 text-white shadow-xs'
                  : currentStep > 2
                    ? 'bg-emerald-100 text-emerald-800'
                    : 'bg-slate-200 text-slate-500'
              }`}
            >
              <span>2</span>
              <span>Atur Harga Produk</span>
            </div>
            <span className="text-slate-300">→</span>
            <div
              className={`flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition ${
                currentStep === 3
                  ? 'bg-indigo-600 text-white shadow-xs'
                  : 'bg-slate-200 text-slate-500'
              }`}
            >
              <span>3</span>
              <span>Tinjau & Simpan</span>
            </div>
          </div>
        </div>

        {/* Error Notification */}
        {errorMsg && (
          <div className="mx-6 mt-4 p-3.5 bg-rose-50 border border-rose-200 rounded-2xl flex items-center gap-3 text-xs text-rose-800">
            <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
            <span className="font-semibold">{errorMsg}</span>
          </div>
        )}

        {/* STEP CONTENT BODY */}
        <div className="p-6 overflow-y-auto flex-1">
          {/* STEP 1: TIER INFORMATION */}
          {currentStep === 1 && (
            <div className="space-y-6 max-w-2xl mx-auto py-2">
              <div className="bg-indigo-50/60 p-4 rounded-2xl border border-indigo-100 flex items-start gap-3">
                <Info className="w-5 h-5 text-indigo-600 shrink-0 mt-0.5" />
                <div className="text-xs text-indigo-950 space-y-1">
                  <p className="font-bold">Konsep Dynamic Tier SiBotol POS</p>
                  <p>
                    Setiap Tier memiliki daftar produk dan konfigurasi harga mandiri. Anda dapat membuat
                    nama Tier bebas (misal: <em>Distributor Premium</em>, <em>Mitra Prioritas</em>, dsb.) dan
                    langsung menentukan harga setiap produk di langkah berikutnya.
                  </p>
                </div>
              </div>

              <div className="space-y-4">
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Nama Tier <span className="text-rose-500">*</span>
                  </label>
                  <input
                    type="text"
                    required
                    placeholder="Contoh: Distributor Premium / Toko Kelontong Gold"
                    value={tierInfo.name}
                    onChange={(e) => handleNameChange(e.target.value)}
                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Kode Unik Tier <span className="text-rose-500">*</span>
                    </label>
                    <input
                      type="text"
                      required
                      placeholder="DIST_PREMIUM"
                      value={tierInfo.code}
                      onChange={(e) => setTierInfo({ ...tierInfo, code: e.target.value.toUpperCase() })}
                      className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-mono font-bold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    <span className="text-[11px] text-slate-400 mt-1 block">
                      Digunakan sebagai rujukan identitas sistem
                    </span>
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-700 mb-1">
                      Urutan Tampilan (Display Order)
                    </label>
                    <input
                      type="number"
                      min="1"
                      value={tierInfo.display_order}
                      onChange={(e) => setTierInfo({ ...tierInfo, display_order: Number(e.target.value) })}
                      className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Warna Penanda Tier (Label Badge)
                  </label>
                  <div className="flex items-center gap-3 pt-1">
                    {PRESET_COLORS.map((col) => (
                      <button
                        key={col.hex}
                        type="button"
                        onClick={() => setTierInfo({ ...tierInfo, color: col.hex })}
                        className={`w-7 h-7 rounded-full flex items-center justify-center transition cursor-pointer ${
                          col.bgClass
                        } ${tierInfo.color === col.hex ? 'ring-3 ring-offset-2 ring-slate-800 scale-110' : 'opacity-80 hover:opacity-100'}`}
                        title={col.name}
                      >
                        {tierInfo.color === col.hex && <Check className="w-4 h-4 text-white" />}
                      </button>
                    ))}
                    <input
                      type="color"
                      value={tierInfo.color}
                      onChange={(e) => setTierInfo({ ...tierInfo, color: e.target.value })}
                      className="w-8 h-8 rounded-lg cursor-pointer border-0 bg-transparent p-0"
                      title="Warna Khusus"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Deskripsi / Catatan Kebijakan Tier
                  </label>
                  <textarea
                    rows={3}
                    placeholder="Penjelasan kriteria customer atau batas minimum pemesanan untuk mendapatkan tier ini..."
                    value={tierInfo.description}
                    onChange={(e) => setTierInfo({ ...tierInfo, description: e.target.value })}
                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">Status Awal Tier</label>
                  <div className="flex items-center gap-4">
                    <label className="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-700">
                      <input
                        type="radio"
                        name="tier_status"
                        checked={tierInfo.status === 'active'}
                        onChange={() => setTierInfo({ ...tierInfo, status: 'active' })}
                        className="text-indigo-600 focus:ring-indigo-500"
                      />
                      <span>Aktif (Langsung dapat digunakan di POS Kasir)</span>
                    </label>
                    <label className="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-700">
                      <input
                        type="radio"
                        name="tier_status"
                        checked={tierInfo.status === 'inactive'}
                        onChange={() => setTierInfo({ ...tierInfo, status: 'inactive' })}
                        className="text-slate-600 focus:ring-slate-500"
                      />
                      <span>Nonaktif (Disimpan sebagai draf)</span>
                    </label>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* STEP 2: PRODUCT PRICING CONFIGURATION */}
          {currentStep === 2 && (
            <div className="space-y-4">
              {/* Context Banner */}
              <div className="flex flex-col md:flex-row md:items-center justify-between gap-3 bg-slate-50 p-3.5 rounded-2xl border border-slate-200">
                <div className="flex items-center gap-2.5">
                  <span
                    className="w-3.5 h-3.5 rounded-full"
                    style={{ backgroundColor: tierInfo.color }}
                  ></span>
                  <span className="font-bold text-slate-800 text-sm">{tierInfo.name}</span>
                  <span className="font-mono text-xs px-2 py-0.5 bg-white border border-slate-200 rounded-md text-slate-600">
                    {tierInfo.code}
                  </span>
                </div>

                <div className="flex items-center gap-2 text-xs">
                  <span className="text-slate-500">Produk Terpilih:</span>
                  <span className="font-bold px-2 py-0.5 bg-indigo-50 text-indigo-700 rounded-md">
                    {selectedRows.length} dari {productRows.length}
                  </span>
                </div>
              </div>

              {/* Filter & Bulk Price Update Bar */}
              <div className="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3 bg-white p-3.5 rounded-2xl border border-slate-200 shadow-xs">
                {/* Search & Category */}
                <div className="flex items-center gap-2.5 flex-1">
                  <div className="relative flex-1">
                    <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                      type="text"
                      placeholder="Cari nama produk atau SKU..."
                      value={searchTerm}
                      onChange={(e) => setSearchTerm(e.target.value)}
                      className="w-full pl-9 pr-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                  </div>

                  <select
                    value={selectedCatFilter}
                    onChange={(e) => setSelectedCatFilter(e.target.value)}
                    className="px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  >
                    <option value="">Semua Kategori</option>
                    {categories.map((c) => (
                      <option key={c.id} value={c.name}>
                        {c.name}
                      </option>
                    ))}
                  </select>
                </div>

                {/* Bulk Actions */}
                <div className="flex items-center gap-2 text-xs pt-2 lg:pt-0 border-t lg:border-t-0 border-slate-100">
                  {/* Select All Controls */}
                  <button
                    type="button"
                    onClick={() => handleSelectAll(true)}
                    className="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold transition cursor-pointer"
                  >
                    Pilih Semua
                  </button>
                  <button
                    type="button"
                    onClick={() => handleSelectAll(false)}
                    className="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-semibold transition cursor-pointer"
                  >
                    Kosongkan
                  </button>

                  <div className="h-4 w-px bg-slate-200 mx-1"></div>

                  {/* Bulk Markup Calculator */}
                  <div className="flex items-center gap-1.5">
                    <input
                      type="number"
                      placeholder="Markup %"
                      value={bulkMarkupPercent}
                      onChange={(e) => setBulkMarkupPercent(e.target.value)}
                      className="w-20 px-2 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-semibold focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    <button
                      type="button"
                      onClick={handleApplyBulkMarkup}
                      className="px-2.5 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg font-semibold transition cursor-pointer flex items-center gap-1"
                      title="Hitung harga jual = HPP Modal + Markup %"
                    >
                      <Percent className="w-3.5 h-3.5" />
                      Set
                    </button>
                  </div>
                </div>
              </div>

              {/* Product Pricing Table */}
              <div className="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-xs">
                <div className="max-h-[380px] overflow-y-auto">
                  <table className="w-full text-left border-collapse text-xs">
                    <thead className="sticky top-0 bg-slate-50 z-10 border-b border-slate-200 text-slate-600 font-semibold uppercase tracking-wider">
                      <tr>
                        <th className="py-2.5 px-3 w-10 text-center">
                          <input
                            type="checkbox"
                            checked={selectedRows.length === productRows.length && productRows.length > 0}
                            onChange={(e) => handleSelectAll(e.target.checked)}
                            className="rounded text-indigo-600 cursor-pointer"
                          />
                        </th>
                        <th className="py-2.5 px-3">Produk & SKU</th>
                        <th className="py-2.5 px-3">Satuan</th>
                        <th className="py-2.5 px-3 text-right">Modal (HPP)</th>
                        <th className="py-2.5 px-3 text-right">Retail Dasar</th>
                        <th className="py-2.5 px-3 w-44">
                          <span className="text-indigo-600 font-bold">Harga Jual Tier (Rp) *</span>
                        </th>
                        <th className="py-2.5 px-3 text-center">Margin</th>
                        <th className="py-2.5 px-3 w-36">Berlaku Mulai</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {loadingProducts ? (
                        <tr>
                          <td colSpan={8} className="py-12 text-center text-slate-400">
                            <RefreshCw className="w-6 h-6 animate-spin mx-auto mb-2 text-indigo-600" />
                            Memuat daftar produk...
                          </td>
                        </tr>
                      ) : filteredRows.length === 0 ? (
                        <tr>
                          <td colSpan={8} className="py-10 text-center text-slate-400">
                            Tidak ada produk ditemukan sesuai filter.
                          </td>
                        </tr>
                      ) : (
                        filteredRows.map((row) => (
                          <tr
                            key={row.product_id}
                            className={`transition ${
                              row.selected ? 'bg-white hover:bg-slate-50/70' : 'bg-slate-50/50 opacity-60'
                            }`}
                          >
                            <td className="py-2.5 px-3 text-center">
                              <input
                                type="checkbox"
                                checked={row.selected}
                                onChange={() => handleToggleSelect(row.product_id)}
                                className="rounded text-indigo-600 cursor-pointer"
                              />
                            </td>
                            <td className="py-2.5 px-3">
                              <div className="font-bold text-slate-800">{row.name}</div>
                              <div className="flex items-center gap-1.5 mt-0.5 text-slate-400 font-mono text-[11px]">
                                <span className="bg-slate-100 px-1 py-0.2 rounded text-slate-600">
                                  {row.sku}
                                </span>
                                {row.category_name && <span>• {row.category_name}</span>}
                              </div>
                            </td>
                            <td className="py-2.5 px-3 font-semibold text-slate-700">{row.unit}</td>
                            <td className="py-2.5 px-3 text-right text-slate-500">
                              {formatIDR(row.cost_price)}
                            </td>
                            <td className="py-2.5 px-3 text-right text-slate-400">
                              {formatIDR(row.default_price)}
                            </td>
                            <td className="py-2 px-3">
                              <input
                                type="number"
                                min="0"
                                step="any"
                                disabled={!row.selected}
                                placeholder="0"
                                value={row.tier_price}
                                onChange={(e) => handlePriceChange(row.product_id, e.target.value)}
                                className={`w-full px-2.5 py-1.5 rounded-lg text-xs font-bold focus:outline-none focus:ring-2 focus:ring-indigo-500 border ${
                                  !row.selected
                                    ? 'bg-slate-100 border-slate-200 text-slate-400 cursor-not-allowed'
                                    : row.tier_price === ''
                                      ? 'bg-rose-50 border-rose-300 text-rose-900'
                                      : 'bg-white border-indigo-200 text-indigo-950 shadow-xs'
                                }`}
                              />
                            </td>
                            <td className="py-2.5 px-3 text-center">
                              <span
                                className={`text-[11px] font-bold px-1.5 py-0.5 rounded ${
                                  row.tier_price && Number(row.tier_price) >= row.cost_price
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : 'bg-rose-50 text-rose-700'
                                }`}
                              >
                                {calcMargin(row.tier_price || 0, row.cost_price)}
                              </span>
                            </td>
                            <td className="py-2 px-3">
                              <input
                                type="date"
                                disabled={!row.selected}
                                value={row.effective_from}
                                onChange={(e) => handleDateChange(row.product_id, e.target.value)}
                                className="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-700 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                              />
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

          {/* STEP 3: REVIEW CONFIGURATION */}
          {currentStep === 3 && (
            <div className="space-y-6">
              {/* Summary Metrics Cards */}
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div className="bg-slate-50 p-4 rounded-2xl border border-slate-200">
                  <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                    Tier Identity
                  </span>
                  <div className="flex items-center gap-2 mt-2">
                    <span
                      className="w-4 h-4 rounded-full"
                      style={{ backgroundColor: tierInfo.color }}
                    ></span>
                    <span className="font-extrabold text-slate-800 text-sm">{tierInfo.name}</span>
                  </div>
                  <span className="text-xs font-mono text-slate-500 mt-1 block">
                    Kode: {tierInfo.code}
                  </span>
                </div>

                <div className="bg-indigo-50/60 p-4 rounded-2xl border border-indigo-200">
                  <span className="text-[11px] font-bold uppercase tracking-wider text-indigo-700">
                    Total Produk Ditetapkan
                  </span>
                  <p className="text-2xl font-black text-indigo-950 mt-1">{selectedRows.length}</p>
                  <span className="text-[11px] text-indigo-600">Produk aktif dalam tier</span>
                </div>

                <div className="bg-emerald-50/60 p-4 rounded-2xl border border-emerald-200">
                  <span className="text-[11px] font-bold uppercase tracking-wider text-emerald-700">
                    Harga Valid Terkonfigurasi
                  </span>
                  <p className="text-2xl font-black text-emerald-950 mt-1">{validPricedRows.length}</p>
                  <span className="text-[11px] text-emerald-600">Siap digunakan kasir</span>
                </div>

                <div
                  className={`p-4 rounded-2xl border ${
                    unpricedRows.length > 0
                      ? 'bg-rose-50 border-rose-200 text-rose-800'
                      : 'bg-slate-50 border-slate-200 text-slate-700'
                  }`}
                >
                  <span className="text-[11px] font-bold uppercase tracking-wider">
                    Produk Belum Berharga
                  </span>
                  <p className="text-2xl font-black mt-1">{unpricedRows.length}</p>
                  <span className="text-[11px]">
                    {unpricedRows.length === 0 ? 'Semua lengkap 100%' : 'Harus diisi sebelum simpan'}
                  </span>
                </div>
              </div>

              {/* Review Table */}
              <div className="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
                <div className="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                  <h3 className="text-xs font-bold uppercase tracking-wider text-slate-700">
                    Daftar Harga Produk yang Akan Disimpan ke Database
                  </h3>
                  <span className="text-[11px] text-slate-400">
                    Disimpan dalam 1 database transaction atomik
                  </span>
                </div>

                <div className="max-h-[300px] overflow-y-auto">
                  <table className="w-full text-left border-collapse text-xs">
                    <thead className="bg-slate-50 text-slate-600 font-semibold uppercase tracking-wider border-b border-slate-200">
                      <tr>
                        <th className="py-2.5 px-4">Nama Produk & SKU</th>
                        <th className="py-2.5 px-3">Satuan</th>
                        <th className="py-2.5 px-3 text-right">Modal (HPP)</th>
                        <th className="py-2.5 px-3 text-right">Harga Jual Tier</th>
                        <th className="py-2.5 px-3 text-center">Margin</th>
                        <th className="py-2.5 px-3">Tanggal Mulai Berlaku</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {validPricedRows.map((r) => (
                        <tr key={r.product_id} className="hover:bg-slate-50/70">
                          <td className="py-2.5 px-4">
                            <div className="font-bold text-slate-800">{r.name}</div>
                            <span className="font-mono text-[11px] text-slate-400">{r.sku}</span>
                          </td>
                          <td className="py-2.5 px-3 font-semibold text-slate-600">{r.unit}</td>
                          <td className="py-2.5 px-3 text-right text-slate-500">
                            {formatIDR(r.cost_price)}
                          </td>
                          <td className="py-2.5 px-3 text-right font-bold text-indigo-950">
                            {formatIDR(r.tier_price)}
                          </td>
                          <td className="py-2.5 px-3 text-center">
                            <span className="text-[11px] font-bold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded">
                              {calcMargin(r.tier_price || 0, r.cost_price)}
                            </span>
                          </td>
                          <td className="py-2.5 px-3 font-medium text-slate-600">{r.effective_from}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* Modal Footer Controls */}
        <div className="px-6 py-4 border-t border-slate-100 bg-slate-50/50 flex items-center justify-between">
          <div>
            {currentStep > 1 && (
              <button
                type="button"
                onClick={() => setCurrentStep((prev) => (prev - 1) as 1 | 2)}
                className="px-4 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl hover:bg-slate-100 text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-xs"
              >
                <ArrowLeft className="w-4 h-4" />
                Kembali & Perbaiki
              </button>
            )}
          </div>

          <div className="flex items-center gap-2.5">
            <button
              type="button"
              onClick={onClose}
              disabled={saving}
              className="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-200 text-xs font-bold transition cursor-pointer"
            >
              Batal
            </button>

            {currentStep === 1 && (
              <button
                type="button"
                onClick={handleNextFromStep1}
                className="px-5 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-sm"
              >
                Lanjut ke Atur Harga Produk
                <ArrowRight className="w-4 h-4" />
              </button>
            )}

            {currentStep === 2 && (
              <button
                type="button"
                onClick={handleNextFromStep2}
                className="px-5 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-sm"
              >
                Tinjau Konfigurasi (Review)
                <ArrowRight className="w-4 h-4" />
              </button>
            )}

            {currentStep === 3 && (
              <button
                type="button"
                disabled={saving || unpricedRows.length > 0}
                onClick={handleFinalSave}
                className="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-2 cursor-pointer shadow-sm disabled:opacity-50"
              >
                {saving ? (
                  <>
                    <RefreshCw className="w-4 h-4 animate-spin" />
                    Menyimpan Tier & Harga...
                  </>
                ) : (
                  <>
                    <CheckCircle2 className="w-4 h-4" />
                    Simpan Tier & Konfigurasi Harga
                  </>
                )}
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};
