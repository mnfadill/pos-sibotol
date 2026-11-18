import React, { useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  Barcode,
  Boxes,
  Check,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Coins,
  Copy,
  Download,
  Edit3,
  Layers,
  Package,
  Plus,
  RefreshCw,
  Search,
  Sliders,
  Sparkles,
  Tag,
  Trash2,
  Upload,
  X,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import type { Brand, Category, MasterProductItem, MasterUnit } from '../../types/pos';
import { Pagination } from '../ui/Pagination';

interface PackagingUnitDraft {
  id?: number;
  unit_code: string;
  unit_name: string;
  conversion_factor: string;
  barcode: string;
  is_purchase_unit: boolean;
  is_sales_unit: boolean;
  is_active: boolean;
  purchase_cost: string;
  tier_a_price: string;
  tier_b_price: string;
  tier_c_price: string;
  tier_d_price: string;
  showTierDetails?: boolean;
}

interface BulkEditRow {
  category_id: number;
  brand_id: number | null;
  cost_price: string;
  tier_a_price: string;
  tier_b_price: string;
  tier_c_price: string;
  tier_d_price: string;
  reorder_level: string;
  is_active: boolean;
}

type FormSectionId = 'info' | 'category' | 'packaging' | 'pricing' | 'barcode' | 'stock';

const PACKAGING_PRESETS: Array<{ code: string; name: string; factor: number }> = [
  { code: 'PACK', name: 'Pack (Isi 6)', factor: 6 },
  { code: 'DUS', name: 'Dus / Box (Isi 12)', factor: 12 },
  { code: 'KRT', name: 'Karton (Isi 24)', factor: 24 },
  { code: 'KRAT', name: 'Krat (Isi 24)', factor: 24 },
];

const BASE_UNIT_PRESETS: Array<{ code: string; name: string }> = [
  { code: 'BTL', name: 'Botol' },
  { code: 'KLG', name: 'Kaleng' },
  { code: 'PCS', name: 'PCS / Unit' },
];

export const MasterProductPage: React.FC = () => {
  const [products, setProducts] = useState<MasterProductItem[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [brands, setBrands] = useState<Brand[]>([]);
  const [units, setUnits] = useState<MasterUnit[]>([]);
  const [loading, setLoading] = useState<boolean>(true);

  // Filters & Pagination
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [selectedCategory, setSelectedCategory] = useState<string>('');
  const [selectedBrand, setSelectedBrand] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive' | 'low_stock'>('all');
  const [currentPage, setCurrentPage] = useState<number>(1);
  const perPage = 15;

  // Bulk Edit Mode (Shopify Admin style)
  const [bulkMode, setBulkMode] = useState<boolean>(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [bulkDrafts, setBulkDrafts] = useState<Record<number, BulkEditRow>>({});
  const [savingBulk, setSavingBulk] = useState<boolean>(false);

  // Import CSV Modal
  const [showImportModal, setShowImportModal] = useState<boolean>(false);
  const [csvText, setCsvText] = useState<string>('');
  const [importing, setImporting] = useState<boolean>(false);

  // Product Form Modal
  const [showFormModal, setShowFormModal] = useState<boolean>(false);
  const [activeSection, setActiveSection] = useState<FormSectionId>('info');
  const [editingProduct, setEditingProduct] = useState<MasterProductItem | null>(null);
  const [showDeleteModal, setShowDeleteModal] = useState<boolean>(false);
  const [productToDelete, setProductToDelete] = useState<MasterProductItem | null>(null);
  const [saving, setSaving] = useState<boolean>(false);
  const [actionMessage, setActionMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Inline creation states (Category, Brand, Base Unit)
  const [showInlineCategory, setShowInlineCategory] = useState<boolean>(false);
  const [newCategoryName, setNewCategoryName] = useState<string>('');
  const [creatingCategory, setCreatingCategory] = useState<boolean>(false);

  const [showInlineBrand, setShowInlineBrand] = useState<boolean>(false);
  const [newBrandName, setNewBrandName] = useState<string>('');
  const [creatingBrand, setCreatingBrand] = useState<boolean>(false);

  const [showInlineUnit, setShowInlineUnit] = useState<boolean>(false);
  const [newUnitCode, setNewUnitCode] = useState<string>('');
  const [newUnitName, setNewUnitName] = useState<string>('');

  // Main Product Form State
  const [formData, setFormData] = useState({
    name: '',
    sku: '',
    barcode: '',
    category_id: '',
    brand_id: '',
    unit: 'BTL',
    product_type: 'regular',
    short_description: '',
    net_content: '700',
    net_content_unit: 'ml',
    abv: '',
    packaging_type: 'Glass Bottle',
    image_url: '',
    is_active: true,
    cost_price: '0',
    tier_a_price: '0',
    tier_b_price: '0',
    tier_c_price: '0',
    tier_d_price: '0',
    reorder_level: '15',
    track_stock: true,
    track_batch: false,
    track_expiry: false,
  });

  // Multiple Selling & Purchase Packaging Units for this Product
  const [packagingUnits, setPackagingUnits] = useState<PackagingUnitDraft[]>([]);

  const loadData = async () => {
    setLoading(true);
    try {
      const [prodRes, catRes, brandRes, unitRes] = await Promise.all([
        api.get<any>('/master-data/products?per_page=250'),
        api.get<{ categories: Category[] }>('/master-data/categories'),
        api.get<{ brands: Brand[] }>('/master-data/brands'),
        api.get<{ units: MasterUnit[] }>('/master-data/units'),
      ]);

      const rawProds = prodRes.data?.data || prodRes.data?.products || [];
      setProducts(Array.isArray(rawProds) ? rawProds : []);
      setCategories(Array.isArray(catRes.data?.categories) ? catRes.data.categories : []);
      setBrands(Array.isArray(brandRes.data?.brands) ? brandRes.data.brands : []);
      setUnits(Array.isArray(unitRes.data?.units) ? unitRes.data.units : []);
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const baseUnitOptions = useMemo(() => {
    const map = new Map<string, { code: string; name: string }>();
    BASE_UNIT_PRESETS.forEach((u) => map.set(u.code, u));
    units.forEach((u) => map.set(u.code.toUpperCase(), { code: u.code.toUpperCase(), name: u.name }));
    return Array.from(map.values());
  }, [units]);

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

  const getProductPackagingUnits = (p: MasterProductItem) => {
    const raw = p.product_units || p.productUnits || p.units || [];
    return raw.filter((u) => !u.is_base && Number(u.conversion_factor) > 1);
  };

  const openCreateModal = () => {
    setEditingProduct(null);
    setActiveSection('info');
    setFormData({
      name: '',
      sku: '',
      barcode: '',
      category_id: categories.length > 0 ? String(categories[0].id) : '',
      brand_id: brands.length > 0 ? String(brands[0].id) : '',
      unit: 'BTL',
      product_type: 'regular',
      short_description: '',
      net_content: '700',
      net_content_unit: 'ml',
      abv: '',
      packaging_type: 'Glass Bottle',
      image_url: '',
      is_active: true,
      cost_price: '0',
      tier_a_price: '0',
      tier_b_price: '0',
      tier_c_price: '0',
      tier_d_price: '0',
      reorder_level: '15',
      track_stock: true,
      track_batch: false,
      track_expiry: false,
    });
    setPackagingUnits([]);
    setShowFormModal(true);
  };

  const openEditModal = (p: MasterProductItem, initialSec: FormSectionId = 'info') => {
    setEditingProduct(p);
    setActiveSection(initialSec);
    const variant = p.variants?.[0];
    const cost = Number(variant?.cost_price || 0);
    const tierA = Number(variant?.price || p.base_price || 0);
    const tierB = Number(variant?.member_price ?? Math.round(tierA * 0.95));
    const tierC = Number(variant?.reseller_price ?? Math.round(tierA * 0.9));
    const tierD = Number(variant?.wholesale_price ?? Math.round(tierA * 0.85));
    const reorder = Number((p as any).reorder_level || p.stock_summary?.reorder_level || 15);

    setFormData({
      name: p.name,
      sku: variant?.sku || p.sku || '',
      barcode: variant?.barcode || p.default_barcode || '',
      category_id: String(p.category_id || ''),
      brand_id: p.brand_id ? String(p.brand_id) : '',
      unit: (p.unit || 'BTL').toUpperCase(),
      product_type: p.product_type || 'regular',
      short_description: p.short_description || '',
      net_content: p.net_content ? String(parseFloat(String(p.net_content))) : '',
      net_content_unit: p.net_content_unit || 'ml',
      abv: p.abv ? String(parseFloat(String(p.abv))) : '',
      packaging_type: p.packaging_type || 'Glass Bottle',
      image_url: p.image_url || '',
      is_active: p.is_active !== false,
      cost_price: String(cost),
      tier_a_price: String(tierA),
      tier_b_price: String(tierB),
      tier_c_price: String(tierC),
      tier_d_price: String(tierD),
      reorder_level: String(reorder),
      track_stock: p.track_stock !== false,
      track_batch: Boolean(p.track_batch),
      track_expiry: Boolean(p.track_expiry),
    });

    const existingUnits = getProductPackagingUnits(p).map((u): PackagingUnitDraft => {
      const f = Number(u.conversion_factor || 1);
      const uSell = Number(u.selling_price ?? tierA * f);
      return {
        id: u.id,
        unit_code: u.unit_code.toUpperCase(),
        unit_name: u.unit_name || u.unit_code,
        conversion_factor: String(parseFloat(String(u.conversion_factor))),
        barcode: u.barcode || '',
        is_purchase_unit: u.is_purchase_unit !== false,
        is_sales_unit: u.is_sales_unit !== false,
        is_active: u.is_active !== false,
        purchase_cost: String(Number(u.purchase_cost ?? cost * f)),
        tier_a_price: String(uSell),
        tier_b_price: String(Number(u.member_price ?? Math.round(uSell * 0.95))),
        tier_c_price: String(Number(u.reseller_price ?? Math.round(uSell * 0.9))),
        tier_d_price: String(Number(u.wholesale_price ?? Math.round(uSell * 0.85))),
        showTierDetails: false,
      };
    });

    setPackagingUnits(existingUnits);
    setShowFormModal(true);
  };

  const addPackagingPreset = (preset?: { code: string; name: string; factor: number }) => {
    const code = preset?.code || 'DUS';
    const factor = preset?.factor || 12;
    const baseCost = Number(formData.cost_price) || 0;
    const baseTierA = Number(formData.tier_a_price) || 0;
    const baseTierB = Number(formData.tier_b_price) || Math.round(baseTierA * 0.95);
    const baseTierC = Number(formData.tier_c_price) || Math.round(baseTierA * 0.9);
    const baseTierD = Number(formData.tier_d_price) || Math.round(baseTierA * 0.85);

    setPackagingUnits((prev) => [
      ...prev,
      {
        unit_code: code,
        unit_name: preset?.name || `${code} (Isi ${factor})`,
        conversion_factor: String(factor),
        barcode: '',
        is_purchase_unit: true,
        is_sales_unit: true,
        is_active: true,
        purchase_cost: String(baseCost * factor),
        tier_a_price: String(baseTierA * factor),
        tier_b_price: String(baseTierB * factor),
        tier_c_price: String(baseTierC * factor),
        tier_d_price: String(baseTierD * factor),
        showTierDetails: false,
      },
    ]);
  };

  const updatePackagingRow = (idx: number, patch: Partial<PackagingUnitDraft>) => {
    setPackagingUnits((prev) => prev.map((row, i) => (i === idx ? { ...row, ...patch } : row)));
  };

  const recalculatePackagingPricesFromBase = (idx: number, factorOverride?: number) => {
    const row = packagingUnits[idx];
    if (!row) return;
    const factor = factorOverride ?? (Number(row.conversion_factor) || 1);
    const baseCost = Number(formData.cost_price) || 0;
    const baseTierA = Number(formData.tier_a_price) || 0;
    const baseTierB = Number(formData.tier_b_price) || Math.round(baseTierA * 0.95);
    const baseTierC = Number(formData.tier_c_price) || Math.round(baseTierA * 0.9);
    const baseTierD = Number(formData.tier_d_price) || Math.round(baseTierA * 0.85);

    updatePackagingRow(idx, {
      purchase_cost: String(Math.round(baseCost * factor)),
      tier_a_price: String(Math.round(baseTierA * factor)),
      tier_b_price: String(Math.round(baseTierB * factor)),
      tier_c_price: String(Math.round(baseTierC * factor)),
      tier_d_price: String(Math.round(baseTierD * factor)),
    });
  };

  const removePackagingRow = (idx: number) => {
    setPackagingUnits((prev) => prev.filter((_, i) => i !== idx));
  };

  const autoCalculateBaseTiers = () => {
    const retail = Number(formData.tier_a_price) || 0;
    setFormData((prev) => ({
      ...prev,
      tier_b_price: String(Math.round(retail * 0.95)),
      tier_c_price: String(Math.round(retail * 0.9)),
      tier_d_price: String(Math.round(retail * 0.85)),
    }));
  };

  // Inline creation handlers
  const handleCreateInlineCategory = async () => {
    if (!newCategoryName.trim()) return;
    setCreatingCategory(true);
    try {
      const code = newCategoryName
        .trim()
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, 8) || `CAT${Date.now().toString().slice(-3)}`;
      const res = await api.post<{ category: Category }>('/master-data/categories', {
        code,
        name: newCategoryName.trim(),
      });
      const created = res.data.category;
      setCategories((prev) => [...prev, created]);
      setFormData((prev) => ({ ...prev, category_id: String(created.id) }));
      setNewCategoryName('');
      setShowInlineCategory(false);
    } catch (err) {
      alert(extractErrorMessage(err));
    } finally {
      setCreatingCategory(false);
    }
  };

  const handleCreateInlineBrand = async () => {
    if (!newBrandName.trim()) return;
    setCreatingBrand(true);
    try {
      const code = newBrandName
        .trim()
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, 8) || `BRD${Date.now().toString().slice(-3)}`;
      const res = await api.post<{ brand: Brand }>('/master-data/brands', {
        code,
        name: newBrandName.trim(),
      });
      const created = res.data.brand;
      setBrands((prev) => [...prev, created]);
      setFormData((prev) => ({ ...prev, brand_id: String(created.id) }));
      setNewBrandName('');
      setShowInlineBrand(false);
    } catch (err) {
      alert(extractErrorMessage(err));
    } finally {
      setCreatingBrand(false);
    }
  };

  const handleApplyInlineUnit = () => {
    if (!newUnitCode.trim()) return;
    const cleanCode = newUnitCode.trim().toUpperCase();
    const cleanName = newUnitName.trim() || cleanCode;
    setUnits((prev) => [
      ...prev,
      {
        id: Date.now(),
        organization_id: 1,
        code: cleanCode,
        name: cleanName,
        unit_type: 'quantity',
        is_base: true,
        is_active: true,
      },
    ]);
    setFormData((prev) => ({ ...prev, unit: cleanCode }));
    setNewUnitCode('');
    setNewUnitName('');
    setShowInlineUnit(false);
  };

  const handleSaveProduct = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.name.trim()) {
      alert('Nama produk wajib diisi.');
      return;
    }

    setSaving(true);
    setActionMessage(null);
    try {
      const tierA = Number(formData.tier_a_price) || 0;
      const tierB = Number(formData.tier_b_price) || Math.round(tierA * 0.95);
      const tierC = Number(formData.tier_c_price) || Math.round(tierA * 0.9);
      const tierD = Number(formData.tier_d_price) || Math.round(tierA * 0.85);

      const payload = {
        name: formData.name.trim(),
        sku: formData.sku.trim() || undefined,
        barcode: formData.barcode.trim() || null,
        default_barcode: formData.barcode.trim() || null,
        category_id: Number(formData.category_id) || categories[0]?.id || 1,
        brand_id: formData.brand_id ? Number(formData.brand_id) : null,
        unit: formData.unit.toUpperCase(),
        product_type: formData.product_type,
        short_description: formData.short_description.trim() || null,
        net_content: formData.net_content !== '' ? Number(formData.net_content) : null,
        net_content_unit: formData.net_content_unit || 'ml',
        abv: formData.abv !== '' ? Number(formData.abv) : null,
        packaging_type: formData.packaging_type || null,
        image_url: formData.image_url.trim() || null,
        is_active: formData.is_active,
        status: formData.is_active ? 'active' : 'inactive',
        cost_price: Number(formData.cost_price) || 0,
        tier_a_price: tierA,
        tier_b_price: tierB,
        tier_c_price: tierC,
        tier_d_price: tierD,
        base_price: tierA,
        member_price: tierB,
        reseller_price: tierC,
        wholesale_price: tierD,
        reorder_level: Number(formData.reorder_level) || 0,
        track_stock: formData.track_stock,
        track_batch: formData.track_batch,
        track_expiry: formData.track_expiry,
        units: packagingUnits
          .filter((u) => u.unit_code.trim() !== '' && Number(u.conversion_factor) > 0)
          .map((u) => ({
            id: u.id,
            unit_code: u.unit_code.trim().toUpperCase(),
            unit_name: u.unit_name.trim() || u.unit_code.trim().toUpperCase(),
            conversion_factor: Number(u.conversion_factor),
            barcode: u.barcode.trim() || null,
            is_purchase_unit: u.is_purchase_unit,
            is_sales_unit: u.is_sales_unit,
            is_active: u.is_active,
            purchase_cost: Number(u.purchase_cost) || 0,
            selling_price: Number(u.tier_a_price) || 0,
            tier_a_price: Number(u.tier_a_price) || 0,
            member_price: Number(u.tier_b_price) || 0,
            tier_b_price: Number(u.tier_b_price) || 0,
            reseller_price: Number(u.tier_c_price) || 0,
            tier_c_price: Number(u.tier_c_price) || 0,
            wholesale_price: Number(u.tier_d_price) || 0,
            tier_d_price: Number(u.tier_d_price) || 0,
          })),
      };

      if (editingProduct) {
        await api.put(`/master-data/products/${editingProduct.id}`, payload);
        setActionMessage({
          type: 'success',
          text: `Produk "${formData.name}" beserta satuan kemasan & harga berhasil diperbarui.`,
        });
      } else {
        await api.post('/master-data/products', payload);
        setActionMessage({
          type: 'success',
          text: `Produk baru "${formData.name}" beserta satuan kemasan berhasil ditambahkan.`,
        });
      }

      setShowFormModal(false);
      await loadData();
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: extractErrorMessage(err),
      });
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteProduct = async () => {
    if (!productToDelete) return;
    setSaving(true);
    const target = productToDelete;
    try {
      const res = await api.delete<any>(`/master-data/products/${target.id}`);
      setProducts((prev) => prev.filter((p) => p.id !== target.id));
      setActionMessage({
        type: 'success',
        text: res.data?.message || `Produk "${target.name}" berhasil dihapus.`,
      });
      setShowDeleteModal(false);
      setProductToDelete(null);
      await loadData();
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: extractErrorMessage(err),
      });
    } finally {
      setSaving(false);
    }
  };

  // Filtering
  const filteredProducts = useMemo(() => {
    return products.filter((p) => {
      const v = p.variants?.[0];
      const pkgUnits = getProductPackagingUnits(p);
      const q = searchTerm.trim().toLowerCase();
      const matchSearch =
        q === '' ||
        p.name.toLowerCase().includes(q) ||
        p.sku?.toLowerCase().includes(q) ||
        v?.sku?.toLowerCase().includes(q) ||
        v?.barcode?.toLowerCase().includes(q) ||
        pkgUnits.some((u) => u.barcode?.toLowerCase().includes(q) || u.unit_code.toLowerCase().includes(q));
      const matchCat = selectedCategory === '' || String(p.category_id) === selectedCategory;
      const matchBrand = selectedBrand === '' || String(p.brand_id) === selectedBrand;

      const onHand = Number(p.stock_summary?.on_hand || 0);
      const reorder = Number((p as any).reorder_level || p.stock_summary?.reorder_level || 15);
      const matchStatus =
        statusFilter === 'all' ||
        (statusFilter === 'active' && p.is_active !== false) ||
        (statusFilter === 'inactive' && p.is_active === false) ||
        (statusFilter === 'low_stock' && onHand <= reorder);

      return matchSearch && matchCat && matchBrand && matchStatus;
    });
  }, [products, searchTerm, selectedCategory, selectedBrand, statusFilter]);

  const totalPages = Math.max(1, Math.ceil(filteredProducts.length / perPage));
  const paginatedProducts = useMemo(() => {
    const start = (currentPage - 1) * perPage;
    return filteredProducts.slice(start, start + perPage);
  }, [filteredProducts, currentPage]);

  // Bulk Edit Helpers
  const startBulkMode = () => {
    const drafts: Record<number, BulkEditRow> = {};
    const targets = selectedIds.length > 0
      ? filteredProducts.filter((p) => selectedIds.includes(p.id))
      : paginatedProducts;

    targets.forEach((p) => {
      const v = p.variants?.[0];
      const tierA = Number(v?.price || p.base_price || 0);
      drafts[p.id] = {
        category_id: p.category_id,
        brand_id: p.brand_id ?? null,
        cost_price: String(Number(v?.cost_price || 0)),
        tier_a_price: String(tierA),
        tier_b_price: String(Number(v?.member_price ?? Math.round(tierA * 0.95))),
        tier_c_price: String(Number(v?.reseller_price ?? Math.round(tierA * 0.9))),
        tier_d_price: String(Number(v?.wholesale_price ?? Math.round(tierA * 0.85))),
        reorder_level: String(Number((p as any).reorder_level || p.stock_summary?.reorder_level || 15)),
        is_active: p.is_active !== false,
      };
    });
    setBulkDrafts(drafts);
    setBulkMode(true);
  };

  const handleSaveBulkEdit = async () => {
    const items = Object.entries(bulkDrafts).map(([idStr, d]) => ({
      id: Number(idStr),
      category_id: d.category_id,
      brand_id: d.brand_id,
      cost_price: Number(d.cost_price) || 0,
      tier_a_price: Number(d.tier_a_price) || 0,
      tier_b_price: Number(d.tier_b_price) || 0,
      tier_c_price: Number(d.tier_c_price) || 0,
      tier_d_price: Number(d.tier_d_price) || 0,
      reorder_level: Number(d.reorder_level) || 0,
      is_active: d.is_active,
    }));

    if (items.length === 0) return;
    setSavingBulk(true);
    try {
      const res = await api.post('/master-data/products/bulk-update', { items });
      setActionMessage({
        type: 'success',
        text: res.data?.message || `${items.length} produk berhasil diperbarui.`,
      });
      setBulkMode(false);
      setSelectedIds([]);
      await loadData();
    } catch (err) {
      setActionMessage({
        type: 'error',
        text: extractErrorMessage(err),
      });
    } finally {
      setSavingBulk(false);
    }
  };

  // Export CSV
  const handleExportCsv = () => {
    const headers = [
      'SKU',
      'Nama Produk',
      'Kategori',
      'Brand',
      'Ukuran',
      'Stock UOM',
      'Kemasan Jual',
      'Stok (Base UOM)',
      'Min Stok',
      'HPP (Modal)',
      'Harga Retail (Tier A)',
      'Harga Member (Tier B)',
      'Harga Reseller (Tier C)',
      'Harga Grosir (Tier D)',
      'Status',
    ];
    const rows = filteredProducts.map((p) => {
      const v = p.variants?.[0];
      const pkg = getProductPackagingUnits(p)
        .map((u) => `1 ${u.unit_code}=${parseFloat(String(u.conversion_factor))} ${(p.unit || 'BTL').toUpperCase()} (${Number(u.selling_price || 0)})`)
        .join(' | ');
      return [
        v?.sku || p.sku || '',
        `"${(p.name || '').replace(/"/g, '""')}"`,
        `"${p.category?.name || ''}"`,
        `"${p.brand?.name || ''}"`,
        p.net_content ? `${parseFloat(String(p.net_content))}${p.net_content_unit || 'ml'}` : '',
        (p.unit || 'BTL').toUpperCase(),
        `"${pkg}"`,
        parseFloat(String(p.stock_summary?.on_hand || 0)),
        parseFloat(String((p as any).reorder_level || p.stock_summary?.reorder_level || 15)),
        Number(v?.cost_price || 0),
        Number(v?.price || p.base_price || 0),
        Number(v?.member_price || 0),
        Number(v?.reseller_price || 0),
        Number(v?.wholesale_price || 0),
        p.is_active !== false ? 'ACTIVE' : 'INACTIVE',
      ].join(',');
    });

    const csv = [headers.join(','), ...rows].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `katalog-produk-sibotol-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };

  // Import CSV
  const handleImportCsv = async (e: React.FormEvent) => {
    e.preventDefault();
    const lines = csvText
      .split(/\r?\n/)
      .map((l) => l.trim())
      .filter(Boolean);
    if (lines.length === 0) return;

    const defaultCatId = categories[0]?.id || 1;
    const parsedProducts = lines
      .filter((line) => !line.toLowerCase().startsWith('sku,'))
      .map((line, idx) => {
        const parts = line.split(',').map((s) => s.trim().replace(/^"|"$/g, ''));
        return {
          sku: parts[0] || `SBT-IMP-${Date.now().toString().slice(-4)}-${idx + 1}`,
          name: parts[1] || `Produk Import #${idx + 1}`,
          category_id: defaultCatId,
          unit: (parts[2] || 'BTL').toUpperCase(),
          cost_price: Number(parts[3]) || 0,
          base_price: Number(parts[4]) || 0,
          default_barcode: parts[5] || undefined,
        };
      });

    if (parsedProducts.length === 0) return;
    setImporting(true);
    try {
      const res = await api.post('/master-data/products/import', { products: parsedProducts });
      setActionMessage({
        type: 'success',
        text: `${res.data?.imported_count || parsedProducts.length} produk berhasil diimport.`,
      });
      setShowImportModal(false);
      setCsvText('');
      await loadData();
    } catch (err) {
      setActionMessage({
        type: 'error',
        text: extractErrorMessage(err),
      });
    } finally {
      setImporting(false);
    }
  };

  const lowStockProductsCount = useMemo(() => {
    return products.filter((p) => {
      const onHand = Number(p.stock_summary?.on_hand || 0);
      const reorder = Number((p as any).reorder_level || p.stock_summary?.reorder_level || 15);
      return onHand <= reorder;
    }).length;
  }, [products]);

  const multiUnitProductsCount = useMemo(() => {
    return products.filter((p) => getProductPackagingUnits(p).length > 0).length;
  }, [products]);

  return (
    <div className="p-5 space-y-5 max-w-[1600px] mx-auto">
      {/* Top Header */}
      <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2.5">
            <div className="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center shadow-xs">
              <Package className="w-5 h-5" />
            </div>
            <div>
              <h1 className="text-lg font-extrabold text-slate-900">
                Master Produk &amp; Multi-Satuan Kemasan
              </h1>
              <p className="text-xs text-slate-500">
                Satu produk mendukung banyak satuan penjualan &amp; pembelian (Botol, Pack, Dus, Karton) dengan konversi stok dan harga per kemasan otomatis.
              </p>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={loadData}
            disabled={loading}
            className="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl hover:bg-slate-50 transition text-xs font-semibold cursor-pointer"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            Segarkan
          </button>

          <button
            type="button"
            onClick={handleExportCsv}
            className="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl hover:bg-slate-50 transition text-xs font-semibold cursor-pointer"
          >
            <Download className="w-3.5 h-3.5 text-slate-500" />
            Export Excel/CSV
          </button>

          <button
            type="button"
            onClick={() => setShowImportModal(true)}
            className="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl hover:bg-slate-50 transition text-xs font-semibold cursor-pointer"
          >
            <Upload className="w-3.5 h-3.5 text-slate-500" />
            Import CSV
          </button>

          {!bulkMode ? (
            <button
              type="button"
              onClick={startBulkMode}
              className="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-900 text-white rounded-xl hover:bg-slate-800 transition text-xs font-semibold cursor-pointer"
            >
              <Sliders className="w-3.5 h-3.5" />
              Bulk Edit {selectedIds.length > 0 ? `(${selectedIds.length})` : ''}
            </button>
          ) : (
            <div className="inline-flex items-center gap-1.5">
              <button
                type="button"
                onClick={() => setBulkMode(false)}
                className="px-3 py-2 bg-white border border-slate-300 text-slate-700 rounded-xl text-xs font-semibold cursor-pointer"
              >
                Batal Bulk Edit
              </button>
              <button
                type="button"
                disabled={savingBulk}
                onClick={handleSaveBulkEdit}
                className="inline-flex items-center gap-1.5 px-3.5 py-2 bg-blue-600 text-white rounded-xl hover:bg-blue-700 text-xs font-bold cursor-pointer"
              >
                <Check className="w-3.5 h-3.5" />
                {savingBulk ? 'Menyimpan...' : 'Simpan Bulk Edit'}
              </button>
            </div>
          )}

          <button
            type="button"
            onClick={openCreateModal}
            className="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 transition text-xs font-bold shadow-xs cursor-pointer"
          >
            <Plus className="w-4 h-4" />
            Tambah Produk
          </button>
        </div>
      </div>

      {/* Notifications */}
      {actionMessage && (
        <div
          className={`p-3.5 rounded-xl flex items-center justify-between text-xs font-semibold ${
            actionMessage.type === 'success'
              ? 'bg-emerald-50 text-emerald-900 border border-emerald-200'
              : 'bg-rose-50 text-rose-900 border border-rose-200'
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

      {/* KPI Summary Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase">Total Produk</div>
            <div className="text-xl font-black text-slate-900 mt-0.5">{products.length}</div>
            <div className="text-[11px] text-slate-400">SKU terdaftar di katalog</div>
          </div>
          <div className="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
            <Package className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase">Produk Multi-Kemasan</div>
            <div className="text-xl font-black text-blue-700 mt-0.5">{multiUnitProductsCount}</div>
            <div className="text-[11px] text-slate-400">Memiliki satuan Pack / Dus / Krat</div>
          </div>
          <div className="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
            <Layers className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase">Kategori &amp; Brand</div>
            <div className="text-xl font-black text-slate-900 mt-0.5">
              {categories.length} <span className="text-xs font-semibold text-slate-400">Kat</span> /{' '}
              {brands.length} <span className="text-xs font-semibold text-slate-400">Brand</span>
            </div>
            <div className="text-[11px] text-slate-400">Klasifikasi katalog minuman</div>
          </div>
          <div className="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
            <Tag className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs flex items-center justify-between">
          <div>
            <div className="text-[11px] font-bold text-slate-500 uppercase">Stok Menipis</div>
            <div className="text-xl font-black text-rose-600 mt-0.5">{lowStockProductsCount}</div>
            <div className="text-[11px] text-slate-400">&le; Batas minimum stok</div>
          </div>
          <div className="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
            <AlertTriangle className="w-5 h-5" />
          </div>
        </div>
      </div>

      {/* Filter & Search Bar */}
      <div className="bg-white p-4 rounded-xl border border-slate-200 shadow-2xs flex flex-wrap items-center justify-between gap-3">
        <div className="relative flex-1 min-w-[240px]">
          <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            placeholder="Cari nama produk, SKU, atau scan barcode satuan/kemasan..."
            value={searchTerm}
            onChange={(e) => {
              setSearchTerm(e.target.value);
              setCurrentPage(1);
            }}
            className="w-full h-9 pl-9 pr-3 bg-slate-50 border border-slate-200 rounded-lg text-xs focus:outline-none focus:bg-white focus:border-emerald-600"
          />
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <select
            value={selectedCategory}
            onChange={(e) => {
              setSelectedCategory(e.target.value);
              setCurrentPage(1);
            }}
            className="h-9 px-3 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-700 cursor-pointer"
          >
            <option value="">Semua Kategori</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>

          <select
            value={selectedBrand}
            onChange={(e) => {
              setSelectedBrand(e.target.value);
              setCurrentPage(1);
            }}
            className="h-9 px-3 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-700 cursor-pointer"
          >
            <option value="">Semua Brand</option>
            {brands.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>

          <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs">
            {(
              [
                { id: 'all', label: 'Semua' },
                { id: 'active', label: 'Aktif' },
                { id: 'inactive', label: 'Nonaktif' },
                { id: 'low_stock', label: 'Stok Menipis' },
              ] as const
            ).map((tab) => (
              <button
                key={tab.id}
                type="button"
                onClick={() => {
                  setStatusFilter(tab.id);
                  setCurrentPage(1);
                }}
                className={`px-2.5 py-1 rounded-md font-semibold transition cursor-pointer ${
                  statusFilter === tab.id
                    ? 'bg-white text-slate-900 shadow-2xs'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Products Data Table */}
      <div className="bg-white rounded-xl border border-slate-200 shadow-2xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="bg-slate-50 text-slate-500 font-bold border-b border-slate-200 uppercase tracking-wider text-[11px]">
                <th className="py-3 px-3 w-9">
                  <input
                    type="checkbox"
                    checked={
                      paginatedProducts.length > 0 &&
                      paginatedProducts.every((p) => selectedIds.includes(p.id))
                    }
                    onChange={(e) => {
                      if (e.target.checked) {
                        setSelectedIds((prev) =>
                          Array.from(new Set([...prev, ...paginatedProducts.map((p) => p.id)])),
                        );
                      } else {
                        const pageIds = paginatedProducts.map((p) => p.id);
                        setSelectedIds((prev) => prev.filter((id) => !pageIds.includes(id)));
                      }
                    }}
                    className="rounded text-emerald-600"
                  />
                </th>
                <th className="py-3 px-3">Produk &amp; Spesifikasi</th>
                <th className="py-3 px-3">Satuan &amp; Konversi Kemasan</th>
                <th className="py-3 px-3">Stok (Stock UOM)</th>
                <th className="py-3 px-3 text-right">Harga Beli (HPP)</th>
                <th className="py-3 px-3">Harga Jual (Satuan &amp; Kemasan)</th>
                <th className="py-3 px-3 text-center">Status</th>
                <th className="py-3 px-3 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    <RefreshCw className="w-6 h-6 animate-spin mx-auto mb-2 text-emerald-600" />
                    Memuat katalog produk...
                  </td>
                </tr>
              ) : paginatedProducts.length === 0 ? (
                <tr>
                  <td colSpan={8} className="py-12 text-center text-slate-400">
                    Tidak ada produk yang cocok dengan filter pencarian.
                  </td>
                </tr>
              ) : (
                paginatedProducts.map((p) => {
                  const v = p.variants?.[0];
                  const baseUnitCode = (p.unit || 'BTL').toUpperCase();
                  const pkgUnits = getProductPackagingUnits(p);
                  const cost = Number(v?.cost_price || 0);
                  const priceA = Number(v?.price || p.base_price || 0);
                  const priceB = Number(v?.member_price ?? Math.round(priceA * 0.95));
                  const priceC = Number(v?.reseller_price ?? Math.round(priceA * 0.9));
                  const priceD = Number(v?.wholesale_price ?? Math.round(priceA * 0.85));

                  const onHand = Number(p.stock_summary?.on_hand || 0);
                  const reorder = Number(
                    (p as any).reorder_level || p.stock_summary?.reorder_level || 15,
                  );
                  const isLowStock = onHand <= reorder;
                  const bulkRow = bulkDrafts[p.id];

                  return (
                    <tr key={p.id} className="hover:bg-slate-50/80 transition align-top">
                      <td className="py-3.5 px-3">
                        <input
                          type="checkbox"
                          checked={selectedIds.includes(p.id)}
                          onChange={(e) => {
                            if (e.target.checked) {
                              setSelectedIds((prev) => [...prev, p.id]);
                            } else {
                              setSelectedIds((prev) => prev.filter((id) => id !== p.id));
                            }
                          }}
                          className="rounded text-emerald-600"
                        />
                      </td>

                      {/* Column 1: Produk & Spesifikasi */}
                      <td className="py-3.5 px-3">
                        <div className="font-bold text-slate-900 text-xs flex items-center gap-1.5">
                          <span>{p.name}</span>
                          {p.net_content && Number(p.net_content) > 0 && (
                            <span className="px-1.5 py-0.5 rounded bg-sky-50 text-sky-700 border border-sky-200 text-[10px] font-bold">
                              {parseFloat(String(p.net_content))} {p.net_content_unit || 'ml'}
                            </span>
                          )}
                          {p.abv && Number(p.abv) > 0 && (
                            <span className="px-1.5 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 text-[10px] font-bold">
                              ABV {parseFloat(String(p.abv))}%
                            </span>
                          )}
                        </div>

                        <div className="flex flex-wrap items-center gap-1.5 mt-1 text-[11px] text-slate-500">
                          <span className="font-mono font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-0.2 rounded">
                            {v?.sku || p.sku}
                          </span>
                          {(v?.barcode || p.default_barcode) && (
                            <span className="inline-flex items-center gap-0.5 font-mono text-slate-500">
                              <Barcode className="w-3 h-3" />
                              {v?.barcode || p.default_barcode}
                            </span>
                          )}
                        </div>

                        {bulkMode && bulkRow ? (
                          <div className="mt-2 flex items-center gap-1.5">
                            <select
                              value={bulkRow.category_id}
                              onChange={(e) =>
                                setBulkDrafts((prev) => ({
                                  ...prev,
                                  [p.id]: { ...bulkRow, category_id: Number(e.target.value) },
                                }))
                              }
                              className="h-7 px-2 border border-blue-300 rounded text-[11px] bg-white"
                            >
                              {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                  {c.name}
                                </option>
                              ))}
                            </select>
                          </div>
                        ) : (
                          <div className="flex flex-wrap items-center gap-1 mt-1.5">
                            {p.category && (
                              <span className="bg-slate-100 text-slate-700 px-2 py-0.5 rounded text-[10px] font-semibold">
                                {p.category.name}
                              </span>
                            )}
                            {p.brand && (
                              <span className="bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded text-[10px] font-semibold">
                                {p.brand.name}
                              </span>
                            )}
                          </div>
                        )}
                      </td>

                      {/* Column 2: Satuan & Konversi Kemasan */}
                      <td className="py-3.5 px-3">
                        <div className="space-y-1">
                          <div className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-slate-900 text-white text-[11px] font-bold">
                            <span>Stock UOM: {baseUnitCode}</span>
                          </div>
                          {pkgUnits.length === 0 ? (
                            <div className="text-[11px] text-slate-400">
                              Hanya satuan dasar ({baseUnitCode})
                            </div>
                          ) : (
                            <div className="flex flex-col gap-1 pt-0.5">
                              {pkgUnits.map((u) => (
                                <div
                                  key={u.id ?? u.unit_code}
                                  className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-blue-50 border border-blue-200 text-blue-900 text-[11px] font-semibold w-fit"
                                >
                                  <span>
                                    1 {u.unit_code} = {parseFloat(String(u.conversion_factor))}{' '}
                                    {baseUnitCode}
                                  </span>
                                </div>
                              ))}
                            </div>
                          )}
                          <button
                            type="button"
                            onClick={() => openEditModal(p, 'packaging')}
                            className="text-[11px] font-semibold text-emerald-700 hover:underline flex items-center gap-0.5 cursor-pointer pt-0.5"
                          >
                            <Plus className="w-3 h-3" /> Atur Kemasan
                          </button>
                        </div>
                      </td>

                      {/* Column 3: Stok (Stock UOM) */}
                      <td className="py-3.5 px-3">
                        <div
                          className={`font-extrabold text-xs ${
                            isLowStock ? 'text-rose-600' : 'text-slate-900'
                          }`}
                        >
                          {onHand.toLocaleString('id-ID')} {baseUnitCode}
                        </div>
                        {p.stock_summary?.display && (
                          <div className="text-[11px] text-slate-500 mt-0.5">
                            {p.stock_summary.display}
                          </div>
                        )}
                        <div className="text-[10px] text-slate-400 mt-0.5">
                          Min. Stok: {reorder} {baseUnitCode}
                          {isLowStock && (
                            <span className="ml-1 text-rose-600 font-bold">(Menipis)</span>
                          )}
                        </div>
                      </td>

                      {/* Column 4: Harga Beli (HPP) */}
                      <td className="py-3.5 px-3 text-right">
                        {bulkMode && bulkRow ? (
                          <input
                            type="number"
                            value={bulkRow.cost_price}
                            onChange={(e) =>
                              setBulkDrafts((prev) => ({
                                ...prev,
                                [p.id]: { ...bulkRow, cost_price: e.target.value },
                              }))
                            }
                            className="w-24 h-7 px-2 text-right border border-blue-300 rounded text-xs font-bold"
                          />
                        ) : (
                          <div>
                            <div className="font-bold text-slate-800">{formatIDR(cost)}</div>
                            <div className="text-[10px] text-slate-400">per {baseUnitCode}</div>
                          </div>
                        )}
                      </td>

                      {/* Column 5: Harga Jual (Satuan & Kemasan + Tier) */}
                      <td className="py-3.5 px-3">
                        {bulkMode && bulkRow ? (
                          <div className="grid grid-cols-2 gap-1.5 max-w-xs">
                            <div>
                              <span className="text-[10px] text-slate-500">Tier A (Retail)</span>
                              <input
                                type="number"
                                value={bulkRow.tier_a_price}
                                onChange={(e) =>
                                  setBulkDrafts((prev) => ({
                                    ...prev,
                                    [p.id]: { ...bulkRow, tier_a_price: e.target.value },
                                  }))
                                }
                                className="w-full h-7 px-2 border border-blue-300 rounded text-xs font-bold"
                              />
                            </div>
                            <div>
                              <span className="text-[10px] text-slate-500">Tier D (Grosir)</span>
                              <input
                                type="number"
                                value={bulkRow.tier_d_price}
                                onChange={(e) =>
                                  setBulkDrafts((prev) => ({
                                    ...prev,
                                    [p.id]: { ...bulkRow, tier_d_price: e.target.value },
                                  }))
                                }
                                className="w-full h-7 px-2 border border-blue-300 rounded text-xs font-bold"
                              />
                            </div>
                          </div>
                        ) : (
                          <div className="space-y-1.5">
                            {/* Base unit selling price */}
                            <div className="flex items-center justify-between gap-3">
                              <span className="text-[11px] font-semibold text-slate-600">
                                1 {baseUnitCode}:
                              </span>
                              <span className="font-extrabold text-slate-900">
                                {formatIDR(priceA)}
                              </span>
                            </div>

                            {/* Packaging unit selling prices */}
                            {pkgUnits.map((u) => (
                              <div
                                key={u.id ?? u.unit_code}
                                className="flex items-center justify-between gap-3 text-[11px] bg-emerald-50/70 border border-emerald-200/80 px-2 py-0.5 rounded"
                              >
                                <span className="font-semibold text-emerald-900">
                                  1 {u.unit_code} ({parseFloat(String(u.conversion_factor))}{' '}
                                  {baseUnitCode}):
                                </span>
                                <span className="font-extrabold text-emerald-800">
                                  {formatIDR(u.selling_price)}
                                </span>
                              </div>
                            ))}

                            {/* Tier summary pills */}
                            <div className="flex flex-wrap gap-1 pt-0.5 text-[10px]">
                              <span className="px-1.5 py-0.2 rounded bg-blue-50 text-blue-700 font-semibold">
                                Member: {formatIDR(priceB)}
                              </span>
                              <span className="px-1.5 py-0.2 rounded bg-purple-50 text-purple-700 font-semibold">
                                Reseller: {formatIDR(priceC)}
                              </span>
                              <span className="px-1.5 py-0.2 rounded bg-amber-50 text-amber-800 font-semibold">
                                Grosir: {formatIDR(priceD)}
                              </span>
                            </div>
                          </div>
                        )}
                      </td>

                      {/* Column 6: Status */}
                      <td className="py-3.5 px-3 text-center">
                        {p.is_active !== false ? (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-600" />
                            Aktif
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-600 border border-slate-200">
                            Nonaktif
                          </span>
                        )}
                      </td>

                      {/* Column 7: Aksi */}
                      <td className="py-3.5 px-3 text-right">
                        <div className="inline-flex items-center justify-end gap-1">
                          <button
                            type="button"
                            title="Edit Produk, Kemasan & Harga"
                            onClick={() => openEditModal(p, 'info')}
                            className="inline-flex items-center gap-1 px-2.5 py-1.5 text-blue-700 bg-blue-50 hover:bg-blue-100 rounded-lg transition font-semibold cursor-pointer"
                          >
                            <Edit3 className="w-3.5 h-3.5" />
                            Edit
                          </button>
                          <button
                            type="button"
                            title="Hapus Produk"
                            onClick={() => {
                              setProductToDelete(p);
                              setShowDeleteModal(true);
                            }}
                            className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition cursor-pointer"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        <Pagination
          currentPage={currentPage}
          lastPage={totalPages}
          total={filteredProducts.length}
          onPageChange={setCurrentPage}
        />
      </div>

      {/* =====================================================================
          MODAL TAMBAH / EDIT PRODUK (6 SECTION TERSTRUKTUR)
         ===================================================================== */}
      {showFormModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-[2px] p-4">
          <div className="bg-white rounded-2xl w-full max-w-4xl shadow-2xl border border-slate-200 overflow-hidden max-h-[92vh] flex flex-col">
            {/* Modal Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-white shrink-0">
              <div>
                <h2 className="text-base font-extrabold text-slate-900 flex items-center gap-2">
                  <Package className="w-5 h-5 text-emerald-600" />
                  {editingProduct ? `Edit Produk: ${editingProduct.name}` : 'Tambah Produk Baru'}
                </h2>
                <p className="text-xs text-slate-500 mt-0.5">
                  Konfigurasi informasi produk, multi-satuan kemasan, harga per satuan/tier, barcode, dan stok dalam satu layar.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setShowFormModal(false)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* 6-Section Step Navigation Bar */}
            <div className="px-6 py-2.5 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center gap-1.5 shrink-0">
              {(
                [
                  { id: 'info', num: '1', label: 'Informasi Produk' },
                  { id: 'category', num: '2', label: 'Kategori & Brand' },
                  {
                    id: 'packaging',
                    num: '3',
                    label: `Satuan & Kemasan (${packagingUnits.length + 1})`,
                  },
                  { id: 'pricing', num: '4', label: 'Harga & Tier' },
                  { id: 'barcode', num: '5', label: 'Barcode' },
                  { id: 'stock', num: '6', label: 'Pengaturan Stok' },
                ] as const
              ).map((sec) => {
                const active = activeSection === sec.id;
                return (
                  <button
                    key={sec.id}
                    type="button"
                    onClick={() => setActiveSection(sec.id)}
                    className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition cursor-pointer ${
                      active
                        ? 'bg-emerald-600 text-white shadow-xs'
                        : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-100'
                    }`}
                  >
                    <span
                      className={`w-4 h-4 rounded-full text-[10px] flex items-center justify-center ${
                        active ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-700'
                      }`}
                    >
                      {sec.num}
                    </span>
                    <span>{sec.label}</span>
                  </button>
                );
              })}
            </div>

            {/* Form Body */}
            <form onSubmit={handleSaveProduct} className="p-6 overflow-y-auto flex-1 space-y-6 text-xs">
              {/* SECTION 1: INFORMASI PRODUK */}
              {activeSection === 'info' && (
                <div className="space-y-4">
                  <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                    <h3 className="text-sm font-extrabold text-slate-900">
                      1. Informasi Dasar Produk
                    </h3>
                    <span className="text-[11px] text-slate-500">
                      Identitas utama dan spesifikasi fisik minuman
                    </span>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div className="sm:col-span-2">
                      <label className="block font-bold text-slate-700 mb-1">
                        Nama Produk <span className="text-rose-500">*</span>
                      </label>
                      <input
                        type="text"
                        required
                        placeholder="Contoh: Chivas Regal 12 YO 700ml / Bintang Pilsener 330ml"
                        value={formData.name}
                        onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                        className="w-full h-10 px-3 bg-white border border-slate-300 rounded-xl text-xs font-semibold focus:outline-none focus:border-emerald-600"
                      />
                    </div>

                    <div>
                      <label className="block font-bold text-slate-700 mb-1">
                        SKU / Kode Produk
                      </label>
                      <div className="flex items-center gap-2">
                        <input
                          type="text"
                          placeholder="Kosongkan untuk auto-generate"
                          value={formData.sku}
                          onChange={(e) =>
                            setFormData({ ...formData, sku: e.target.value.toUpperCase() })
                          }
                          className="flex-1 h-10 px-3 bg-white border border-slate-300 rounded-xl font-mono"
                        />
                        <button
                          type="button"
                          onClick={() => {
                            const prefix =
                              formData.name
                                .replace(/[^A-Za-z0-9]/g, '')
                                .toUpperCase()
                                .slice(0, 5) || 'PRD';
                            const rand = Math.floor(100 + Math.random() * 900);
                            setFormData({ ...formData, sku: `SBT-${prefix}-${rand}` });
                          }}
                          className="h-10 px-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-semibold cursor-pointer"
                        >
                          Auto SKU
                        </button>
                      </div>
                    </div>

                    <div>
                      <label className="block font-bold text-slate-700 mb-1">Status Produk</label>
                      <select
                        value={formData.is_active ? 'active' : 'inactive'}
                        onChange={(e) =>
                          setFormData({ ...formData, is_active: e.target.value === 'active' })
                        }
                        className="w-full h-10 px-3 bg-white border border-slate-300 rounded-xl font-semibold"
                      >
                        <option value="active">Aktif (Tampil di POS &amp; Sales Order)</option>
                        <option value="inactive">Nonaktif (Disembunyikan sementara)</option>
                      </select>
                    </div>

                    <div>
                      <label className="block font-bold text-slate-700 mb-1">
                        Ukuran / Volume Isi Bersih
                      </label>
                      <div className="flex items-center gap-2">
                        <input
                          type="number"
                          min="0"
                          step="any"
                          placeholder="Contoh: 700"
                          value={formData.net_content}
                          onChange={(e) =>
                            setFormData({ ...formData, net_content: e.target.value })
                          }
                          className="flex-1 h-10 px-3 bg-white border border-slate-300 rounded-xl"
                        />
                        <select
                          value={formData.net_content_unit}
                          onChange={(e) =>
                            setFormData({ ...formData, net_content_unit: e.target.value })
                          }
                          className="h-10 px-3 bg-slate-50 border border-slate-300 rounded-xl font-bold"
                        >
                          <option value="ml">ml</option>
                          <option value="L">Liter (L)</option>
                          <option value="gr">gram</option>
                        </select>
                      </div>
                      <span className="text-[11px] text-slate-400">
                        Disimpan sebagai atribut spesifikasi produk, bukan satuan stok.
                      </span>
                    </div>

                    <div>
                      <label className="block font-bold text-slate-700 mb-1">
                        Kadar Alkohol / ABV (%) &amp; Kemasan Fisik
                      </label>
                      <div className="grid grid-cols-2 gap-2">
                        <input
                          type="number"
                          min="0"
                          max="100"
                          step="0.1"
                          placeholder="ABV % (opsional)"
                          value={formData.abv}
                          onChange={(e) => setFormData({ ...formData, abv: e.target.value })}
                          className="h-10 px-3 bg-white border border-slate-300 rounded-xl"
                        />
                        <select
                          value={formData.packaging_type}
                          onChange={(e) =>
                            setFormData({ ...formData, packaging_type: e.target.value })
                          }
                          className="h-10 px-3 bg-white border border-slate-300 rounded-xl"
                        >
                          <option value="Glass Bottle">Botol Kaca</option>
                          <option value="Can">Kaleng (Can)</option>
                          <option value="Plastic Bottle">Botol PET</option>
                          <option value="Keg">Keg / Barrel</option>
                        </select>
                      </div>
                    </div>

                    <div className="sm:col-span-2">
                      <label className="block font-bold text-slate-700 mb-1">
                        Catatan / Deskripsi Singkat (Opsional)
                      </label>
                      <input
                        type="text"
                        placeholder="Deskripsi singkat produk..."
                        value={formData.short_description}
                        onChange={(e) =>
                          setFormData({ ...formData, short_description: e.target.value })
                        }
                        className="w-full h-10 px-3 bg-white border border-slate-300 rounded-xl"
                      />
                    </div>
                  </div>
                </div>
              )}

              {/* SECTION 2: KATEGORI & BRAND (WITH INLINE CREATION) */}
              {activeSection === 'category' && (
                <div className="space-y-5">
                  <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                    <h3 className="text-sm font-extrabold text-slate-900">
                      2. Kategori &amp; Brand Produk
                    </h3>
                    <span className="text-[11px] text-slate-500">
                      Anda dapat membuat kategori atau brand baru langsung di sini
                    </span>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    {/* Kategori */}
                    <div className="p-4 rounded-xl border border-slate-200 bg-slate-50/60 space-y-3">
                      <div className="flex items-center justify-between">
                        <label className="font-bold text-slate-800">
                          Kategori Produk <span className="text-rose-500">*</span>
                        </label>
                        <button
                          type="button"
                          onClick={() => setShowInlineCategory((v) => !v)}
                          className="text-[11px] font-bold text-emerald-700 hover:underline flex items-center gap-1 cursor-pointer"
                        >
                          <Plus className="w-3.5 h-3.5" />
                          Buat Kategori Baru
                        </button>
                      </div>

                      <select
                        value={formData.category_id}
                        onChange={(e) => setFormData({ ...formData, category_id: e.target.value })}
                        className="w-full h-10 px-3 bg-white border border-slate-300 rounded-xl font-semibold"
                      >
                        {categories.map((c) => (
                          <option key={c.id} value={c.id}>
                            {c.name}
                          </option>
                        ))}
                      </select>

                      {showInlineCategory && (
                        <div className="p-3 bg-white rounded-xl border border-emerald-300 space-y-2">
                          <div className="font-bold text-emerald-900 text-[11px]">
                            Tambah Kategori Baru (Inline)
                          </div>
                          <div className="flex items-center gap-2">
                            <input
                              type="text"
                              placeholder="Contoh: Whisky / Soju / Beer"
                              value={newCategoryName}
                              onChange={(e) => setNewCategoryName(e.target.value)}
                              className="flex-1 h-9 px-3 border border-slate-300 rounded-lg"
                            />
                            <button
                              type="button"
                              disabled={creatingCategory}
                              onClick={handleCreateInlineCategory}
                              className="h-9 px-3 bg-emerald-600 text-white rounded-lg font-bold cursor-pointer"
                            >
                              Simpan
                            </button>
                          </div>
                        </div>
                      )}
                    </div>

                    {/* Brand */}
                    <div className="p-4 rounded-xl border border-slate-200 bg-slate-50/60 space-y-3">
                      <div className="flex items-center justify-between">
                        <label className="font-bold text-slate-800">Brand / Merk</label>
                        <button
                          type="button"
                          onClick={() => setShowInlineBrand((v) => !v)}
                          className="text-[11px] font-bold text-blue-700 hover:underline flex items-center gap-1 cursor-pointer"
                        >
                          <Plus className="w-3.5 h-3.5" />
                          Buat Brand Baru
                        </button>
                      </div>

                      <select
                        value={formData.brand_id}
                        onChange={(e) => setFormData({ ...formData, brand_id: e.target.value })}
                        className="w-full h-10 px-3 bg-white border border-slate-300 rounded-xl font-semibold"
                      >
                        <option value="">-- Tanpa Brand Khusus --</option>
                        {brands.map((b) => (
                          <option key={b.id} value={b.id}>
                            {b.name}
                          </option>
                        ))}
                      </select>

                      {showInlineBrand && (
                        <div className="p-3 bg-white rounded-xl border border-blue-300 space-y-2">
                          <div className="font-bold text-blue-900 text-[11px]">
                            Tambah Brand Baru (Inline)
                          </div>
                          <div className="flex items-center gap-2">
                            <input
                              type="text"
                              placeholder="Contoh: Chivas / Heineken / Jinro"
                              value={newBrandName}
                              onChange={(e) => setNewBrandName(e.target.value)}
                              className="flex-1 h-9 px-3 border border-slate-300 rounded-lg"
                            />
                            <button
                              type="button"
                              disabled={creatingBrand}
                              onClick={handleCreateInlineBrand}
                              className="h-9 px-3 bg-blue-600 text-white rounded-lg font-bold cursor-pointer"
                            >
                              Simpan
                            </button>
                          </div>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              )}

              {/* SECTION 3: SATUAN & KEMASAN (ONE PRODUCT, MULTIPLE SELLING UNITS) */}
              {activeSection === 'packaging' && (
                <div className="space-y-5">
                  <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2">
                    <div>
                      <h3 className="text-sm font-extrabold text-slate-900">
                        3. Konfigurasi Satuan Dasar &amp; Multi-Kemasan
                      </h3>
                      <p className="text-[11px] text-slate-500">
                        Seluruh stok disimpan dalam Satuan Dasar (Stock UOM). Pembelian dan penjualan menggunakan kemasan akan dikonversi otomatis.
                      </p>
                    </div>
                  </div>

                  {/* Base Stock UOM Selector */}
                  <div className="p-4 rounded-xl bg-slate-900 text-white flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                      <div className="text-xs font-extrabold uppercase tracking-wider text-emerald-400">
                        Stock UOM (Satuan Dasar Stok) *
                      </div>
                      <p className="text-[11px] text-slate-300 mt-0.5">
                        Satuan terkecil untuk perhitungan stok gudang dan outlet (misal: Botol, Kaleng, atau PCS).
                      </p>
                    </div>

                    <div className="flex items-center gap-2">
                      <select
                        value={formData.unit}
                        onChange={(e) => setFormData({ ...formData, unit: e.target.value })}
                        className="h-10 px-3 bg-slate-800 border border-slate-700 text-white rounded-xl font-bold"
                      >
                        {baseUnitOptions.map((u) => (
                          <option key={u.code} value={u.code}>
                            {u.code} &mdash; {u.name}
                          </option>
                        ))}
                      </select>
                      <button
                        type="button"
                        onClick={() => setShowInlineUnit((v) => !v)}
                        className="h-10 px-3 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl font-bold cursor-pointer"
                      >
                        + Satuan Baru
                      </button>
                    </div>
                  </div>

                  {showInlineUnit && (
                    <div className="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 flex flex-wrap items-center gap-2">
                      <input
                        type="text"
                        placeholder="Kode (cth: BTL)"
                        value={newUnitCode}
                        onChange={(e) => setNewUnitCode(e.target.value.toUpperCase())}
                        className="h-9 px-3 bg-white border border-slate-300 rounded-lg w-32 font-mono font-bold"
                      />
                      <input
                        type="text"
                        placeholder="Nama Satuan (cth: Botol Kaca)"
                        value={newUnitName}
                        onChange={(e) => setNewUnitName(e.target.value)}
                        className="h-9 px-3 bg-white border border-slate-300 rounded-lg flex-1"
                      />
                      <button
                        type="button"
                        onClick={handleApplyInlineUnit}
                        className="h-9 px-3.5 bg-emerald-600 text-white rounded-lg font-bold cursor-pointer"
                      >
                        Gunakan Satuan
                      </button>
                    </div>
                  )}

                  {/* Quick Add Packaging Buttons */}
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="font-bold text-slate-800">
                      Daftar Kemasan Penjualan &amp; Pembelian Alternatif
                    </div>
                    <div className="flex flex-wrap items-center gap-1.5">
                      {PACKAGING_PRESETS.map((preset) => (
                        <button
                          key={preset.code}
                          type="button"
                          onClick={() => addPackagingPreset(preset)}
                          className="px-2.5 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-800 border border-blue-200 font-bold text-[11px] cursor-pointer"
                        >
                          + {preset.code} (Isi {preset.factor} {formData.unit})
                        </button>
                      ))}
                      <button
                        type="button"
                        onClick={() =>
                          addPackagingPreset({ code: 'KEMASAN', name: 'Kemasan Baru', factor: 12 })
                        }
                        className="px-3 py-1.5 rounded-lg bg-slate-900 text-white font-bold text-[11px] cursor-pointer"
                      >
                        + Kemasan Custom
                      </button>
                    </div>
                  </div>

                  {/* Packaging List */}
                  {packagingUnits.length === 0 ? (
                    <div className="p-6 rounded-xl border-2 border-dashed border-slate-200 text-center space-y-2">
                      <Layers className="w-7 h-7 text-slate-400 mx-auto" />
                      <div className="font-bold text-slate-700">
                        Belum ada kemasan bertingkat (Pack / Dus / Karton)
                      </div>
                      <p className="text-[11px] text-slate-500 max-w-md mx-auto">
                        Klik salah satu tombol preset di atas (misal <b>+ PACK (Isi 6)</b> atau{' '}
                        <b>+ DUS (Isi 12)</b>) agar produk ini dapat dibeli atau dijual dalam satuan
                        kemasan tanpa membuat produk duplikat.
                      </p>
                    </div>
                  ) : (
                    <div className="space-y-3">
                      {packagingUnits.map((u, idx) => {
                        const factor = Number(u.conversion_factor) || 1;
                        return (
                          <div
                            key={idx}
                            className="p-4 rounded-xl border border-slate-200 bg-slate-50/70 space-y-3"
                          >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                              {/* Live Conversion Preview Badge */}
                              <div className="inline-flex items-center gap-2 px-3 py-1 rounded-lg bg-emerald-600 text-white font-extrabold text-xs shadow-2xs">
                                <Sparkles className="w-3.5 h-3.5" />
                                <span>
                                  Preview Konversi: 1 {u.unit_code || 'KEMASAN'} = {factor}{' '}
                                  {formData.unit}
                                </span>
                              </div>

                              <div className="flex items-center gap-3">
                                <label className="inline-flex items-center gap-1.5 font-semibold text-slate-700 cursor-pointer">
                                  <input
                                    type="checkbox"
                                    checked={u.is_purchase_unit}
                                    onChange={(e) =>
                                      updatePackagingRow(idx, {
                                        is_purchase_unit: e.target.checked,
                                      })
                                    }
                                    className="rounded text-blue-600"
                                  />
                                  Dapat Dibeli (PO)
                                </label>
                                <label className="inline-flex items-center gap-1.5 font-semibold text-slate-700 cursor-pointer">
                                  <input
                                    type="checkbox"
                                    checked={u.is_sales_unit}
                                    onChange={(e) =>
                                      updatePackagingRow(idx, {
                                        is_sales_unit: e.target.checked,
                                      })
                                    }
                                    className="rounded text-emerald-600"
                                  />
                                  Dapat Dijual (POS &amp; SO)
                                </label>
                                <button
                                  type="button"
                                  onClick={() => removePackagingRow(idx)}
                                  className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg cursor-pointer"
                                  title="Hapus Kemasan"
                                >
                                  <Trash2 className="w-4 h-4" />
                                </button>
                              </div>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
                              <div>
                                <label className="block font-bold text-slate-700 mb-1">
                                  Kode Kemasan *
                                </label>
                                <input
                                  type="text"
                                  required
                                  placeholder="PACK / DUS / KRT"
                                  value={u.unit_code}
                                  onChange={(e) =>
                                    updatePackagingRow(idx, {
                                      unit_code: e.target.value.toUpperCase(),
                                    })
                                  }
                                  className="w-full h-9 px-3 bg-white border border-slate-300 rounded-lg font-mono font-bold uppercase"
                                />
                              </div>

                              <div>
                                <label className="block font-bold text-slate-700 mb-1">
                                  Nama Kemasan *
                                </label>
                                <input
                                  type="text"
                                  required
                                  placeholder="Contoh: Dus Isi 12 Botol"
                                  value={u.unit_name}
                                  onChange={(e) =>
                                    updatePackagingRow(idx, { unit_name: e.target.value })
                                  }
                                  className="w-full h-9 px-3 bg-white border border-slate-300 rounded-lg"
                                />
                              </div>

                              <div>
                                <label className="block font-bold text-slate-700 mb-1">
                                  Isi per Kemasan ({formData.unit}) *
                                </label>
                                <input
                                  type="number"
                                  min="1"
                                  step="any"
                                  required
                                  value={u.conversion_factor}
                                  onChange={(e) => {
                                    const val = e.target.value;
                                    updatePackagingRow(idx, { conversion_factor: val });
                                  }}
                                  className="w-full h-9 px-3 bg-white border border-emerald-400 rounded-lg font-extrabold text-emerald-900"
                                />
                              </div>

                              <div>
                                <label className="block font-bold text-slate-700 mb-1">
                                  Harga Jual 1 {u.unit_code} (Rp)
                                </label>
                                <input
                                  type="number"
                                  min="0"
                                  step="any"
                                  value={u.tier_a_price}
                                  onChange={(e) =>
                                    updatePackagingRow(idx, { tier_a_price: e.target.value })
                                  }
                                  className="w-full h-9 px-3 bg-white border border-slate-300 rounded-lg font-bold"
                                />
                              </div>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}

              {/* SECTION 4: HARGA (PER SATUAN, KEMASAN & TIER PELANGGAN) */}
              {activeSection === 'pricing' && (
                <div className="space-y-5">
                  <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2">
                    <div>
                      <h3 className="text-sm font-extrabold text-slate-900">
                        4. Konfigurasi Harga per Satuan &amp; Tier Pelanggan
                      </h3>
                      <p className="text-[11px] text-slate-500">
                        Harga kemasan (misal Dus) dapat diatur lebih murah dari hasil perkalian harga botol satuan.
                      </p>
                    </div>
                    <button
                      type="button"
                      onClick={autoCalculateBaseTiers}
                      className="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 border border-blue-200 font-bold text-[11px] cursor-pointer"
                    >
                      Hitung Otomatis Tier B/C/D dari Retail
                    </button>
                  </div>

                  {/* Base Unit Pricing */}
                  <div className="p-4 rounded-xl border border-slate-200 bg-slate-50/70 space-y-4">
                    <div className="flex items-center justify-between">
                      <div className="font-extrabold text-slate-900 text-xs">
                        A. Harga Satuan Dasar: 1 {formData.unit}
                      </div>
                      <span className="px-2 py-0.5 rounded bg-slate-900 text-white text-[10px] font-bold">
                        Base Stock UOM
                      </span>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-5 gap-3">
                      <div>
                        <label className="block font-bold text-slate-700 mb-1">
                          Harga Beli / HPP (Rp)
                        </label>
                        <input
                          type="number"
                          min="0"
                          step="any"
                          value={formData.cost_price}
                          onChange={(e) =>
                            setFormData({ ...formData, cost_price: e.target.value })
                          }
                          className="w-full h-9 px-3 bg-white border border-slate-300 rounded-lg font-bold"
                        />
                      </div>

                      <div>
                        <label className="block font-bold text-emerald-800 mb-1">
                          Tier A: Retail / Umum *
                        </label>
                        <input
                          type="number"
                          min="0"
                          step="any"
                          required
                          value={formData.tier_a_price}
                          onChange={(e) =>
                            setFormData({ ...formData, tier_a_price: e.target.value })
                          }
                          className="w-full h-9 px-3 bg-white border border-emerald-400 rounded-lg font-extrabold text-emerald-950"
                        />
                        <div className="text-[10px] text-emerald-700 mt-0.5">
                          Margin: {calcMargin(formData.tier_a_price, formData.cost_price)}
                        </div>
                      </div>

                      <div>
                        <label className="block font-bold text-blue-800 mb-1">
                          Tier B: Member
                        </label>
                        <input
                          type="number"
                          min="0"
                          step="any"
                          value={formData.tier_b_price}
                          onChange={(e) =>
                            setFormData({ ...formData, tier_b_price: e.target.value })
                          }
                          className="w-full h-9 px-3 bg-white border border-blue-300 rounded-lg font-bold text-blue-950"
                        />
                        <div className="text-[10px] text-blue-700 mt-0.5">
                          Margin: {calcMargin(formData.tier_b_price, formData.cost_price)}
                        </div>
                      </div>

                      <div>
                        <label className="block font-bold text-purple-800 mb-1">
                          Tier C: Reseller
                        </label>
                        <input
                          type="number"
                          min="0"
                          step="any"
                          value={formData.tier_c_price}
                          onChange={(e) =>
                            setFormData({ ...formData, tier_c_price: e.target.value })
                          }
                          className="w-full h-9 px-3 bg-white border border-purple-300 rounded-lg font-bold text-purple-950"
                        />
                        <div className="text-[10px] text-purple-700 mt-0.5">
                          Margin: {calcMargin(formData.tier_c_price, formData.cost_price)}
                        </div>
                      </div>

                      <div>
                        <label className="block font-bold text-amber-800 mb-1">
                          Tier D: Grosir VIP
                        </label>
                        <input
                          type="number"
                          min="0"
                          step="any"
                          value={formData.tier_d_price}
                          onChange={(e) =>
                            setFormData({ ...formData, tier_d_price: e.target.value })
                          }
                          className="w-full h-9 px-3 bg-white border border-amber-300 rounded-lg font-bold text-amber-950"
                        />
                        <div className="text-[10px] text-amber-700 mt-0.5">
                          Margin: {calcMargin(formData.tier_d_price, formData.cost_price)}
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Packaging Units Pricing */}
                  {packagingUnits.length > 0 && (
                    <div className="space-y-3">
                      <div className="font-extrabold text-slate-900 text-xs">
                        B. Harga Khusus per Satuan Kemasan (Tidak Wajib Hasil Kali Harga {formData.unit})
                      </div>

                      {packagingUnits.map((u, idx) => {
                        const factor = Number(u.conversion_factor) || 1;
                        const linearRetail = (Number(formData.tier_a_price) || 0) * factor;
                        const currentPkgPrice = Number(u.tier_a_price) || 0;
                        const diff = linearRetail - currentPkgPrice;

                        return (
                          <div
                            key={idx}
                            className="p-4 rounded-xl border border-blue-200 bg-blue-50/40 space-y-3"
                          >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                              <div className="flex items-center gap-2">
                                <span className="px-2.5 py-1 rounded-lg bg-blue-600 text-white font-extrabold">
                                  1 {u.unit_code} = {factor} {formData.unit}
                                </span>
                                {linearRetail > 0 && diff > 0 && (
                                  <span className="text-[11px] font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded">
                                    Hemat {formatIDR(diff)} dibanding {factor} &times;{' '}
                                    {formatIDR(formData.tier_a_price)}
                                  </span>
                                )}
                              </div>

                              <button
                                type="button"
                                onClick={() => recalculatePackagingPricesFromBase(idx)}
                                className="text-[11px] font-bold text-blue-700 hover:underline cursor-pointer"
                              >
                                Isi dari Harga {formData.unit} &times; {factor}
                              </button>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-5 gap-3">
                              <div>
                                <label className="block font-bold text-slate-700 mb-1">
                                  Harga Beli 1 {u.unit_code}
                                </label>
                                <input
                                  type="number"
                                  min="0"
                                  step="any"
                                  value={u.purchase_cost}
                                  onChange={(e) =>
                                    updatePackagingRow(idx, { purchase_cost: e.target.value })
                                  }
                                  className="w-full h-9 px-3 bg-white border border-slate-300 rounded-lg font-bold"
                                />
                              </div>

                              <div>
                                <label className="block font-bold text-emerald-800 mb-1">
                                  Harga Retail 1 {u.unit_code}
                                </label>
                                <input
                                  type="number"
                                  min="0"
                                  step="any"
                                  value={u.tier_a_price}
                                  onChange={(e) =>
                                    updatePackagingRow(idx, { tier_a_price: e.target.value })
                                  }
                                  className="w-full h-9 px-3 bg-white border border-emerald-400 rounded-lg font-extrabold"
                                />
                              </div>

                              <div>
                                <label className="block font-bold text-blue-800 mb-1">
                                  Tier B (Member)
                                </label>
                                <input
                                  type="number"
                                  min="0"
                                  step="any"
                                  value={u.tier_b_price}
                                  onChange={(e) =>
                                    updatePackagingRow(idx, { tier_b_price: e.target.value })
                                  }
                                  className="w-full h-9 px-3 bg-white border border-slate-300 rounded-lg font-bold"
                                />
                              </div>

                              <div>
                                <label className="block font-bold text-purple-800 mb-1">
                                  Tier C (Reseller)
                                </label>
                                <input
                                  type="number"
                                  min="0"
                                  step="any"
                                  value={u.tier_c_price}
                                  onChange={(e) =>
                                    updatePackagingRow(idx, { tier_c_price: e.target.value })
                                  }
                                  className="w-full h-9 px-3 bg-white border border-slate-300 rounded-lg font-bold"
                                />
                              </div>

                              <div>
                                <label className="block font-bold text-amber-800 mb-1">
                                  Tier D (Grosir VIP)
                                </label>
                                <input
                                  type="number"
                                  min="0"
                                  step="any"
                                  value={u.tier_d_price}
                                  onChange={(e) =>
                                    updatePackagingRow(idx, { tier_d_price: e.target.value })
                                  }
                                  className="w-full h-9 px-3 bg-white border border-slate-300 rounded-lg font-bold"
                                />
                              </div>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}

              {/* SECTION 5: BARCODE PER SATUAN & KEMASAN */}
              {activeSection === 'barcode' && (
                <div className="space-y-4">
                  <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                    <div>
                      <h3 className="text-sm font-extrabold text-slate-900">
                        5. Barcode per Satuan &amp; Kemasan
                      </h3>
                      <p className="text-[11px] text-slate-500">
                        Saat kasir men-scan barcode kemasan (misal barcode Dus), POS otomatis memilih satuan Dus beserta harga Dus.
                      </p>
                    </div>
                  </div>

                  <div className="space-y-3">
                    {/* Base Unit Barcode */}
                    <div className="p-4 rounded-xl border border-slate-200 bg-slate-50 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                      <div>
                        <div className="font-bold text-slate-900">
                          Barcode Satuan Dasar (1 {formData.unit})
                        </div>
                        <div className="text-[11px] text-slate-500">
                          Barcode utama pada botol / kaleng / unit terkecil
                        </div>
                      </div>
                      <div className="flex items-center gap-2 min-w-[280px]">
                        <input
                          type="text"
                          placeholder="Scan atau ketik barcode..."
                          value={formData.barcode}
                          onChange={(e) =>
                            setFormData({ ...formData, barcode: e.target.value })
                          }
                          className="flex-1 h-9 px-3 bg-white border border-slate-300 rounded-lg font-mono font-bold"
                        />
                        <button
                          type="button"
                          onClick={() =>
                            setFormData({
                              ...formData,
                              barcode: `899${Math.floor(1000000000 + Math.random() * 8999999999)}`,
                            })
                          }
                          className="h-9 px-2.5 bg-white border border-slate-300 rounded-lg text-[11px] font-semibold cursor-pointer"
                        >
                          Generate
                        </button>
                      </div>
                    </div>

                    {/* Packaging Unit Barcodes */}
                    {packagingUnits.map((u, idx) => (
                      <div
                        key={idx}
                        className="p-4 rounded-xl border border-blue-200 bg-blue-50/40 flex flex-col sm:flex-row sm:items-center justify-between gap-3"
                      >
                        <div>
                          <div className="font-bold text-blue-950">
                            Barcode Kemasan {u.unit_code} (1 {u.unit_code} ={' '}
                            {u.conversion_factor} {formData.unit})
                          </div>
                          <div className="text-[11px] text-slate-500">{u.unit_name}</div>
                        </div>
                        <div className="flex items-center gap-2 min-w-[280px]">
                          <input
                            type="text"
                            placeholder={`Barcode khusus ${u.unit_code}...`}
                            value={u.barcode}
                            onChange={(e) =>
                              updatePackagingRow(idx, { barcode: e.target.value })
                            }
                            className="flex-1 h-9 px-3 bg-white border border-slate-300 rounded-lg font-mono font-bold"
                          />
                          <button
                            type="button"
                            onClick={() =>
                              updatePackagingRow(idx, {
                                barcode: `899${Math.floor(1000000000 + Math.random() * 8999999999)}`,
                              })
                            }
                            className="h-9 px-2.5 bg-white border border-slate-300 rounded-lg text-[11px] font-semibold cursor-pointer"
                          >
                            Generate
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* SECTION 6: PENGATURAN STOK */}
              {activeSection === 'stock' && (
                <div className="space-y-4">
                  <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                    <div>
                      <h3 className="text-sm font-extrabold text-slate-900">
                        6. Pengaturan Inventori &amp; Minimum Stok
                      </h3>
                      <p className="text-[11px] text-slate-500">
                        Pengaturan batas peringatan stok menipis (Reorder Level) dan pelacakan inventori.
                      </p>
                    </div>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div className="p-4 rounded-xl border border-slate-200 bg-slate-50 space-y-2">
                      <label className="block font-bold text-slate-800">
                        Batas Minimum Stok ( dalam {formData.unit} )
                      </label>
                      <input
                        type="number"
                        min="0"
                        step="any"
                        value={formData.reorder_level}
                        onChange={(e) =>
                          setFormData({ ...formData, reorder_level: e.target.value })
                        }
                        className="w-full h-10 px-3 bg-white border border-slate-300 rounded-xl font-extrabold"
                      />
                      <p className="text-[11px] text-slate-500">
                        Sistem akan menampilkan peringatan <b>Stok Menipis</b> ketika saldo stok
                        gudang/outlet &le; {formData.reorder_level || 0} {formData.unit}.
                      </p>
                    </div>

                    <div className="p-4 rounded-xl border border-slate-200 bg-slate-50 space-y-3">
                      <div className="font-bold text-slate-800">Kontrol Pelacakan Persediaan</div>

                      <label className="flex items-center gap-2.5 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={formData.track_stock}
                          onChange={(e) =>
                            setFormData({ ...formData, track_stock: e.target.checked })
                          }
                          className="rounded text-emerald-600"
                        />
                        <span>Lacak saldo stok &amp; mutasi persediaan</span>
                      </label>

                      <label className="flex items-center gap-2.5 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={formData.track_batch}
                          onChange={(e) =>
                            setFormData({ ...formData, track_batch: e.target.checked })
                          }
                          className="rounded text-emerald-600"
                        />
                        <span>Lacak Nomor Batch / Lot Kedatangan</span>
                      </label>

                      <label className="flex items-center gap-2.5 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={formData.track_expiry}
                          onChange={(e) =>
                            setFormData({ ...formData, track_expiry: e.target.checked })
                          }
                          className="rounded text-emerald-600"
                        />
                        <span>Lacak Tanggal Kedaluwarsa (FEFO)</span>
                      </label>
                    </div>
                  </div>
                </div>
              )}

              {/* Modal Footer */}
              <div className="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-slate-200">
                <div className="text-[11px] text-slate-500">
                  Stock UOM: <b className="text-slate-800">{formData.unit}</b> &bull; Kemasan
                  Tambahan: <b className="text-slate-800">{packagingUnits.length} satuan</b> &bull;
                  Harga Retail:{' '}
                  <b className="text-emerald-700">{formatIDR(formData.tier_a_price)}</b>
                </div>

                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setShowFormModal(false)}
                    className="px-4 py-2 bg-slate-100 text-slate-700 rounded-xl hover:bg-slate-200 text-xs font-bold transition cursor-pointer"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    disabled={saving}
                    className="px-5 py-2 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 text-xs font-bold shadow-xs transition flex items-center gap-2 cursor-pointer"
                  >
                    {saving && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                    {editingProduct ? 'Simpan Perubahan Produk' : 'Simpan Produk Baru'}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL IMPORT CSV */}
      {showImportModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-[2px] p-4">
          <div className="bg-white rounded-2xl w-full max-w-lg shadow-xl p-6 space-y-4 text-xs">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-extrabold text-slate-900">
                Import Produk dari Excel / CSV
              </h3>
              <button
                type="button"
                onClick={() => setShowImportModal(false)}
                className="text-slate-400 hover:text-slate-700 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
            <p className="text-slate-500">
              Format baris CSV: <code>SKU, Nama Produk, Satuan Dasar, HPP, Harga Retail, Barcode</code>
            </p>
            <form onSubmit={handleImportCsv} className="space-y-3">
              <textarea
                rows={6}
                required
                placeholder={'SBT-WHK-01, Whisky 700ml, BTL, 350000, 450000, 8991002003001\nSBT-BER-02, Beer Lager 330ml, KLG, 18000, 25000, 8991002003002'}
                value={csvText}
                onChange={(e) => setCsvText(e.target.value)}
                className="w-full p-3 border border-slate-300 rounded-xl font-mono text-[11px]"
              />
              <div className="flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setShowImportModal(false)}
                  className="px-3.5 py-2 bg-slate-100 text-slate-700 rounded-xl font-bold cursor-pointer"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={importing}
                  className="px-4 py-2 bg-emerald-600 text-white rounded-xl font-bold cursor-pointer"
                >
                  {importing ? 'Mengimport...' : 'Jalankan Import'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Konfirmasi Hapus */}
      {showDeleteModal && productToDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-[2px] p-4">
          <div className="bg-white rounded-2xl w-full max-w-md shadow-xl overflow-hidden p-6 text-center">
            <div className="w-12 h-12 bg-rose-50 text-rose-600 rounded-full flex items-center justify-center mx-auto mb-4">
              <Trash2 className="w-6 h-6" />
            </div>
            <h3 className="text-base font-extrabold text-slate-900 mb-2">Hapus Produk Ini?</h3>
            <p className="text-xs text-slate-500 mb-6">
              Anda yakin ingin menghapus{' '}
              <span className="font-bold text-slate-800">{productToDelete.name}</span>? Produk akan
              dinonaktifkan secara aman tanpa merusak riwayat transaksi historis.
            </p>
            <div className="flex items-center justify-center gap-3">
              <button
                type="button"
                onClick={() => {
                  setShowDeleteModal(false);
                  setProductToDelete(null);
                }}
                className="px-4 py-2 bg-slate-100 text-slate-700 rounded-xl hover:bg-slate-200 text-xs font-bold transition cursor-pointer"
              >
                Batal
              </button>
              <button
                type="button"
                disabled={saving}
                onClick={handleDeleteProduct}
                className="px-5 py-2 bg-rose-600 text-white rounded-xl hover:bg-rose-700 text-xs font-bold shadow-xs transition flex items-center gap-2 cursor-pointer"
              >
                {saving && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                Ya, Hapus Produk
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
