import React, { useEffect, useMemo, useState } from 'react';
import {
  CheckCircle2,
  RefreshCw,
  Search,
  Tags,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatIDR, formatQty } from '../../lib/format';
import type { Category, OutletInfo, ProductCatalog } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { useToast } from '../ui/ToastProvider';

interface CatalogPricingPageProps {
  outlet: OutletInfo;
}

export const CatalogPricingPage: React.FC<CatalogPricingPageProps> = ({ outlet }) => {
  const { toast } = useToast();
  const [categories, setCategories] = useState<Category[]>([]);
  const [products, setProducts] = useState<ProductCatalog[]>([]);
  const [selectedCategoryId, setSelectedCategoryId] = useState<number>(0);
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(true);
  const [savingVariantId, setSavingVariantId] = useState<number | null>(null);

  const [overrideInputs, setOverrideInputs] = useState<
    Record<number, { is_available: boolean; price_override: string }>
  >({});

  useEffect(() => {
    loadCatalog();
  }, [outlet.id]);

  const loadCatalog = async () => {
    setLoading(true);
    try {
      const [catRes, prodRes] = await Promise.all([
        api.get<{ categories: Category[] }>('/catalog/categories'),
        api.get<{ products: ProductCatalog[] }>('/catalog/products?include_unavailable=1'),
      ]);
      setCategories(catRes.data.categories);
      setProducts(prodRes.data.products);

      const map: Record<number, { is_available: boolean; price_override: string }> = {};
      prodRes.data.products.forEach((p) => {
        p.variants.forEach((v) => {
          map[v.id] = {
            is_available: v.is_available,
            price_override: v.price_override ? String(parseFloat(v.price_override)) : '',
          };
        });
      });
      setOverrideInputs(map);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat katalog produk',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  const filteredProducts = useMemo(() => {
    return products.filter((p) => {
      if (selectedCategoryId > 0 && p.category_id !== selectedCategoryId) {
        return false;
      }
      if (!searchTerm.trim()) return true;
      const q = searchTerm.toLowerCase();
      return (
        p.name.toLowerCase().includes(q) ||
        p.sku.toLowerCase().includes(q) ||
        p.variants.some((v) => v.sku.toLowerCase().includes(q))
      );
    });
  }, [products, selectedCategoryId, searchTerm]);

  const handleSaveOutletSetting = async (
    variantId: number,
    productName: string,
    variantName: string,
  ) => {
    const setting = overrideInputs[variantId];
    if (!setting) return;
    setSavingVariantId(variantId);
    try {
      await api.patch(`/catalog/variants/${variantId}/outlet-setting`, {
        is_available: setting.is_available,
        price_override:
          setting.price_override.trim() !== '' ? parseFloat(setting.price_override) : null,
      });
      toast({
        type: 'success',
        title: 'Pengaturan Harga & Ketersediaan Disimpan',
        description: `${productName} (${variantName}) berhasil diperbarui untuk outlet ${outlet.code}.`,
      });
      await loadCatalog();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menyimpan pengaturan outlet',
        description: extractErrorMessage(err),
      });
    } finally {
      setSavingVariantId(null);
    }
  };

  return (
    <div className="p-5 space-y-4 max-w-[1600px] mx-auto">
      {/* Header & Filter Bar */}
      <Card padding="sm" className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div className="flex items-center gap-2">
              <h2 className="text-base font-extrabold text-slate-900">
                Katalog Produk &amp; Override Harga Spesifik Outlet
              </h2>
              <Badge variant="primary">{outlet.code}</Badge>
            </div>
            <p className="text-xs text-slate-500 mt-0.5">
              Atur status ketersediaan (In-Stock / Out-of-Stock) dan kustomisasi harga jual khusus untuk outlet {outlet.name}.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <div className="relative">
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                placeholder="Cari produk atau SKU..."
                className="pl-9 pr-3 py-2 rounded-lg bg-slate-50 border border-slate-200 text-xs text-slate-900 w-60 focus:bg-white focus:outline-none focus:border-blue-600"
              />
            </div>

            <Button
              variant="outline"
              size="sm"
              onClick={loadCatalog}
              loading={loading}
              icon={<RefreshCw className="w-3.5 h-3.5" />}
            >
              Segarkan
            </Button>
          </div>
        </div>

        {/* Category Filter Tabs */}
        <div className="flex items-center gap-1.5 overflow-x-auto pt-1">
          <button
            type="button"
            onClick={() => setSelectedCategoryId(0)}
            className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer shrink-0 ${
              selectedCategoryId === 0
                ? 'bg-blue-600 text-white shadow-2xs'
                : 'bg-slate-100 text-slate-600 hover:text-slate-900'
            }`}
          >
            Semua Kategori ({products.length})
          </button>
          {categories.map((cat) => (
            <button
              key={cat.id}
              type="button"
              onClick={() => setSelectedCategoryId(cat.id)}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer shrink-0 ${
                selectedCategoryId === cat.id
                  ? 'bg-blue-600 text-white shadow-2xs'
                  : 'bg-slate-100 text-slate-600 hover:text-slate-900'
              }`}
            >
              {cat.name}
            </button>
          ))}
        </div>
      </Card>

      {/* Catalog Variants Table */}
      <Card padding="none" className="overflow-hidden">
        {filteredProducts.length === 0 && !loading ? (
          <EmptyState
            icon={<Tags className="w-6 h-6" />}
            title="Produk Tidak Ditemukan"
            description="Coba kata kunci atau kategori lain."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                  <th className="py-3 px-4">Produk &amp; Varian</th>
                  <th className="py-3 px-4">SKU / Barcode</th>
                  <th className="py-3 px-4 text-right">Stok Saat Ini</th>
                  <th className="py-3 px-4 text-right">Harga Dasar Pusat</th>
                  <th className="py-3 px-4">Status di Outlet Ini</th>
                  <th className="py-3 px-4">Harga Khusus Outlet (IDR)</th>
                  <th className="py-3 px-4 text-right">Harga Efektif Kasir</th>
                  <th className="py-3 px-4 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredProducts.flatMap((p) =>
                  p.variants.map((v) => {
                    const current = overrideInputs[v.id] ?? {
                      is_available: v.is_available,
                      price_override: v.price_override ? String(parseFloat(v.price_override)) : '',
                    };
                    const previewEffective =
                      current.price_override.trim() !== ''
                        ? parseFloat(current.price_override)
                        : parseFloat(v.base_price);

                    return (
                      <tr key={v.id} className="hover:bg-slate-50/80 transition">
                        <td className="py-3 px-4">
                          <div className="font-bold text-slate-900">{p.name}</div>
                          <div className="text-[11px] text-blue-600 font-semibold">
                            {v.name} &bull; {p.category?.name ?? 'Umum'}
                          </div>
                        </td>

                        <td className="py-3 px-4 font-mono text-[11px] text-slate-600">
                          <div className="font-bold text-slate-800">{v.sku}</div>
                          <div>{v.barcode ?? '-'}</div>
                        </td>

                        <td className="py-3 px-4 text-right font-bold tabular-nums text-slate-700">
                          {formatQty(v.stock_quantity)} {p.unit}
                        </td>

                        <td className="py-3 px-4 text-right font-semibold text-slate-500 tabular-nums">
                          {formatIDR(v.base_price)}
                        </td>

                        <td className="py-3 px-4">
                          <label className="inline-flex items-center gap-2 cursor-pointer select-none">
                            <input
                              type="checkbox"
                              checked={current.is_available}
                              onChange={(e) =>
                                setOverrideInputs((prev) => ({
                                  ...prev,
                                  [v.id]: { ...current, is_available: e.target.checked },
                                }))
                              }
                              className="w-4 h-4 rounded border-slate-300 text-blue-600"
                            />
                            <Badge variant={current.is_available ? 'success' : 'danger'}>
                              {current.is_available ? 'In Stock (Aktif)' : 'Out of Stock'}
                            </Badge>
                          </label>
                        </td>

                        <td className="py-3 px-4">
                          <input
                            type="number"
                            min={0}
                            step="500"
                            value={current.price_override}
                            onChange={(e) =>
                              setOverrideInputs((prev) => ({
                                ...prev,
                                [v.id]: { ...current, price_override: e.target.value },
                              }))
                            }
                            placeholder="Ikuti harga pusat"
                            className="w-40 px-3 py-1.5 rounded-lg bg-white border border-slate-300 text-xs font-bold text-slate-900 tabular-nums focus:outline-none focus:border-blue-600"
                          />
                        </td>

                        <td className="py-3 px-4 text-right font-extrabold text-blue-600 tabular-nums">
                          {formatIDR(previewEffective)}
                        </td>

                        <td className="py-3 px-4 text-right">
                          <Button
                            variant="primary"
                            size="xs"
                            loading={savingVariantId === v.id}
                            icon={<CheckCircle2 className="w-3.5 h-3.5" />}
                            onClick={() => handleSaveOutletSetting(v.id, p.name, v.name)}
                          >
                            Simpan
                          </Button>
                        </td>
                      </tr>
                    );
                  }),
                )}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
};

