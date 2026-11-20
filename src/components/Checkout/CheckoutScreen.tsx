import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  Barcode,
  Building2,
  CheckCircle2,
  Clock,
  CreditCard,
  Edit3,
  Eye,
  EyeOff,
  FileText,
  Minus,
  Package,
  PauseCircle,
  Percent,
  Phone,
  PlayCircle,
  Plus,
  Search,
  ShoppingBag,
  Sparkles,
  Tag,
  Trash2,
  UserCheck,
  UserPlus,
  Users,
  X,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime, formatIDR, formatQty } from '../../lib/format';
import { posCache } from '../../lib/posCache';
import type {
  CartItem,
  Category,
  DynamicTier,
  MasterDiscountRecord,
  OrderRecord,
  OutletInfo,
  PaymentLegInput,
  PaymentSchemeType,
  PricingRulesResponse,
  ProductCatalog,
  ProductModifier,
  ProductUnit,
  ProductVariantCatalog,
} from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import { ReceiptModal } from '../ui/ReceiptModal';
import { useToast } from '../ui/ToastProvider';
import { SplitPaymentModal } from './SplitPaymentModal';

interface Props {
  outlet: OutletInfo;
  hasOpenShift: boolean;
  onRequireOpenShift: () => void;
  onOrderCompletedOrHeld: () => void;
}

export const CheckoutScreen: React.FC<Props> = ({
  outlet,
  hasOpenShift,
  onRequireOpenShift,
  onOrderCompletedOrHeld,
}) => {
  const { toast } = useToast();
  const searchInputRef = useRef<HTMLInputElement | null>(null);
  const catalogAbortRef = useRef<AbortController | null>(null);
  const inFlightCatalogKeyRef = useRef<string | null>(null);
  const loadMoreSentinelRef = useRef<HTMLDivElement | null>(null);
  // Preserves loaded product metadata for items already in cart across category/search changes
  const productCacheRef = useRef<Map<number, ProductCatalog>>(new Map());

  const [categories, setCategories] = useState<Category[]>([]);
  const [totalCatalogProducts, setTotalCatalogProducts] = useState<number>(0);
  const [products, setProducts] = useState<ProductCatalog[]>([]);
  const [catalogPage, setCatalogPage] = useState<number>(1);
  const [catalogHasMore, setCatalogHasMore] = useState<boolean>(false);
  const [catalogTotalMatches, setCatalogTotalMatches] = useState<number>(0);
  const [loadingMore, setLoadingMore] = useState<boolean>(false);
  const [isShowingCachedData, setIsShowingCachedData] = useState<boolean>(false);
  const [isRevalidatingCatalog, setIsRevalidatingCatalog] = useState<boolean>(false);
  const [heldOrders, setHeldOrders] = useState<OrderRecord[]>([]);
  const [masterDiscounts, setMasterDiscounts] = useState<MasterDiscountRecord[]>([]);
  const [selectedCategoryId, setSelectedCategoryId] = useState<number>(0);
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [debouncedSearch, setDebouncedSearch] = useState<string>('');
  const [barcodeInput, setBarcodeInput] = useState<string>('');
  const [loadingCatalog, setLoadingCatalog] = useState<boolean>(true);

  // Dynamic Tier Theme helper
  const getTierTheme = (color?: string | null) => {
    switch (color?.toLowerCase()) {
      case 'emerald':
      case 'green':
        return {
          activeClass: 'bg-emerald-600 text-white shadow-sm ring-2 ring-emerald-500',
          badgeBg: 'bg-emerald-100 text-emerald-800 border-emerald-200',
          badgeColor: 'emerald',
        };
      case 'amber':
      case 'yellow':
      case 'orange':
        return {
          activeClass: 'bg-amber-600 text-white shadow-sm ring-2 ring-amber-500',
          badgeBg: 'bg-amber-100 text-amber-800 border-amber-200',
          badgeColor: 'amber',
        };
      case 'purple':
      case 'violet':
        return {
          activeClass: 'bg-purple-600 text-white shadow-sm ring-2 ring-purple-500',
          badgeBg: 'bg-purple-100 text-purple-800 border-purple-200',
          badgeColor: 'purple',
        };
      case 'rose':
      case 'red':
        return {
          activeClass: 'bg-rose-600 text-white shadow-sm ring-2 ring-rose-500',
          badgeBg: 'bg-rose-100 text-rose-800 border-rose-200',
          badgeColor: 'rose',
        };
      case 'indigo':
        return {
          activeClass: 'bg-indigo-600 text-white shadow-sm ring-2 ring-indigo-500',
          badgeBg: 'bg-indigo-100 text-indigo-800 border-indigo-200',
          badgeColor: 'indigo',
        };
      case 'teal':
      case 'cyan':
        return {
          activeClass: 'bg-teal-600 text-white shadow-sm ring-2 ring-teal-500',
          badgeBg: 'bg-teal-100 text-teal-800 border-teal-200',
          badgeColor: 'teal',
        };
      case 'blue':
      default:
        return {
          activeClass: 'bg-blue-600 text-white shadow-sm ring-2 ring-blue-500',
          badgeBg: 'bg-blue-100 text-blue-800 border-blue-200',
          badgeColor: 'blue',
        };
    }
  };

  const DEFAULT_TIERS: Array<{
    id: number;
    code: string;
    name: string;
    description: string;
    color: string;
  }> = [
      {
        id: 1,
        code: 'TIER_1',
        name: 'Tier 1: Retail',
        description: 'Harga eceran reguler (Walk-in / Umum)',
        color: 'blue',
      },
      {
        id: 2,
        code: 'TIER_2',
        name: 'Tier 2: Member',
        description: 'Harga khusus member / langganan',
        color: 'emerald',
      },
      {
        id: 3,
        code: 'TIER_3',
        name: 'Tier 3: Reseller',
        description: 'Harga reseller / warung mitra',
        color: 'amber',
      },
      {
        id: 4,
        code: 'TIER_4',
        name: 'Tier 4: Wholesale',
        description: 'Harga partai besar & grosir VIP',
        color: 'purple',
      },
    ];

  // Helper to accurately lookup tier price (supports both base variant and packaging units)
  const resolveVariantPriceForTier = (
    variant: ProductVariantCatalog | undefined,
    targetTierCode: string,
    targetTierId?: number,
    productUnit?: ProductUnit | null,
  ): number => {
    if (!variant) return 0;

    if (productUnit) {
      const code = targetTierCode.toUpperCase();
      if ((code === 'TIER_1' || code === 'A') && productUnit.tier_a_price && parseFloat(String(productUnit.tier_a_price)) > 0) {
        return parseFloat(String(productUnit.tier_a_price));
      }
      if ((code === 'TIER_2' || code === 'B') && (productUnit.tier_b_price || productUnit.member_price)) {
        const val = parseFloat(String(productUnit.tier_b_price || productUnit.member_price || '0'));
        if (val > 0) return val;
      }
      if ((code === 'TIER_3' || code === 'C') && (productUnit.tier_c_price || productUnit.reseller_price)) {
        const val = parseFloat(String(productUnit.tier_c_price || productUnit.reseller_price || '0'));
        if (val > 0) return val;
      }
      if ((code === 'TIER_4' || code === 'D') && (productUnit.tier_d_price || productUnit.wholesale_price)) {
        const val = parseFloat(String(productUnit.tier_d_price || productUnit.wholesale_price || '0'));
        if (val > 0) return val;
      }
      const unitSelling = parseFloat(productUnit.selling_price || '0');
      if (unitSelling > 0) return unitSelling;
      const factor = Math.max(1, parseFloat(productUnit.conversion_factor || '1'));
      return resolveVariantPriceForTier(variant, targetTierCode, targetTierId, null) * factor;
    }

    if (!variant.tier_prices) {
      return parseFloat(variant.effective_price || variant.base_price || '0');
    }

    if (variant.tier_prices[targetTierCode] !== undefined) {
      return parseFloat(variant.tier_prices[targetTierCode]);
    }
    if (targetTierId && variant.tier_prices[String(targetTierId)] !== undefined) {
      return parseFloat(variant.tier_prices[String(targetTierId)]);
    }

    const aliasMap: Record<string, string> = {
      TIER_1: 'A',
      TIER_2: 'B',
      TIER_3: 'C',
      TIER_4: 'D',
      A: 'TIER_1',
      B: 'TIER_2',
      C: 'TIER_3',
      D: 'TIER_4',
    };
    const mapped = aliasMap[targetTierCode.toUpperCase()];
    if (mapped && variant.tier_prices[mapped] !== undefined) {
      return parseFloat(variant.tier_prices[mapped]);
    }

    return parseFloat(variant.effective_price || variant.base_price || '0');
  };

  // Dynamic Tiers State
  const [dynamicTiers, setDynamicTiers] = useState<DynamicTier[]>([]);
  const [customerTier, setCustomerTier] = useState<string>('TIER_1');
  const [registeredCustomers, setRegisteredCustomers] = useState<
    Array<{
      id: number;
      name: string;
      code: string;
      phone?: string | null;
      company_name?: string | null;
      address?: string | null;
      masked_nik?: string | null;
      has_nik?: boolean;
      pricing_tier?: string;
      tier_id?: number | null;
      payment_term_days?: number;
      outstanding_balance?: string;
      credit_limit?: string;
      tier?: { id: number; code: string; name: string } | null;
    }>
  >([]);
  const [selectedCustomerId, setSelectedCustomerId] = useState<number | null>(null);
  const [customerSearchTerm, setCustomerSearchTerm] = useState<string>('');
  const [showCustomerSelectorMenu, setShowCustomerSelectorMenu] = useState<boolean>(false);

  // Quick Create Customer Modal State (Nama wajib, No HP wajib, Nama Usaha, Alamat, NIK/KTP opsional)
  const [showQuickCreateCustomerModal, setShowQuickCreateCustomerModal] = useState<boolean>(false);
  const [newCustName, setNewCustName] = useState<string>('');
  const [newCustPhone, setNewCustPhone] = useState<string>('');
  const [newCustCompany, setNewCustCompany] = useState<string>('');
  const [newCustAddress, setNewCustAddress] = useState<string>('');
  const [newCustNik, setNewCustNik] = useState<string>('');
  const [submittingNewCustomer, setSubmittingNewCustomer] = useState<boolean>(false);

  // Customer Detail, Transaction & Payment History + AR Settlement Modal State
  const [showCustomerDetailModal, setShowCustomerDetailModal] = useState<boolean>(false);
  const [loadingCustomerDetail, setLoadingCustomerDetail] = useState<boolean>(false);
  const [revealedNik, setRevealedNik] = useState<string | null>(null);
  const [customerDetailData, setCustomerDetailData] = useState<{
    customer: {
      id: number;
      code: string;
      name: string;
      phone?: string | null;
      company_name?: string | null;
      address?: string | null;
      masked_nik?: string | null;
      has_nik?: boolean;
      can_reveal_nik?: boolean;
      raw_nik?: string | null;
      credit_limit: string;
      outstanding_balance: string;
      payment_term_days: number;
      pricing_tier?: string;
      tier?: { id: number; code: string; name: string } | null;
    };
    invoices: Array<{
      id: number;
      invoice_number: string;
      status: string;
      issue_date: string;
      due_date: string;
      grand_total: string;
      paid_total: string;
      balance_due: string;
      outlet?: { id: number; code: string; name: string } | null;
      order?: { id: number; order_number: string } | null;
      sales_order?: { id: number; so_number: string } | null;
      allocations?: Array<{
        id: number;
        amount: string;
        customer_payment?: {
          id: number;
          payment_number: string;
          method: string;
          amount: string;
          payment_date: string;
          reference_number?: string | null;
          notes?: string | null;
        } | null;
      }>;
    }>;
    orders: OrderRecord[];
    payments: Array<{
      id: number;
      payment_number: string;
      method: string;
      amount: string;
      payment_date: string;
      reference_number?: string | null;
      notes?: string | null;
      outlet?: { id: number; code: string; name: string } | null;
    }>;
  } | null>(null);

  // Settling AR Invoice from Customer Detail Modal
  const [settlingInvoiceId, setSettlingInvoiceId] = useState<number | null>(null);
  const [settleAmountInput, setSettleAmountInput] = useState<string>('');
  const [settleMethodInput, setSettleMethodInput] = useState<'cash' | 'bank_transfer' | 'qris'>('cash');
  const [settleRefInput, setSettleRefInput] = useState<string>('');
  const [submittingSettle, setSubmittingSettle] = useState<boolean>(false);

  const activeTiers = dynamicTiers.length > 0
    ? dynamicTiers.filter((t) => t.status === 'active')
    : DEFAULT_TIERS;

  const selectedTier =
    activeTiers.find((t) => t.code === customerTier || String(t.id) === customerTier) ||
    activeTiers.find(
      (t) =>
        (customerTier === 'A' && t.code === 'TIER_1') ||
        (customerTier === 'B' && t.code === 'TIER_2') ||
        (customerTier === 'C' && t.code === 'TIER_3') ||
        (customerTier === 'D' && t.code === 'TIER_4'),
    ) ||
    activeTiers[0];

  // Cart & Order Metadata State
  const [activeOrderId, setActiveOrderId] = useState<number | null>(null);
  const [activeOrderNumber, setActiveOrderNumber] = useState<string | null>(null);
  const [customerName, setCustomerName] = useState<string>('');
  const [customerPhone, setCustomerPhone] = useState<string>('');
  const [tableLabel, setTableLabel] = useState<string>('');
  const [orderNotes, setOrderNotes] = useState<string>('');
  const [discountType, setDiscountType] = useState<'percent' | 'fixed' | ''>('');
  const [discountValue, setDiscountValue] = useState<string>('');
  const [discountReason, setDiscountReason] = useState<string>('');
  const [cart, setCart] = useState<CartItem[]>([]);

  // Compact Moka-style Drawers / Modals
  const [showCustomerDrawer, setShowCustomerDrawer] = useState<boolean>(false);
  const [showDiscountDrawer, setShowDiscountDrawer] = useState<boolean>(false);

  const [configuringProduct, setConfiguringProduct] = useState<ProductCatalog | null>(null);
  const [chosenVariantId, setChosenVariantId] = useState<number>(0);
  const [chosenUnitId, setChosenUnitId] = useState<number | null>(null);
  const [chosenModifierIds, setChosenModifierIds] = useState<number[]>([]);
  const [chosenItemNotes, setChosenItemNotes] = useState<string>('');

  const [editingCartKey, setEditingCartKey] = useState<string | null>(null);
  const [overridePriceInput, setOverridePriceInput] = useState<string>('');
  const [overrideReasonInput, setOverrideReasonInput] = useState<string>('');
  const [itemNoteInput, setItemNoteInput] = useState<string>('');

  const [showHeldModal, setShowHeldModal] = useState<boolean>(false);
  const [showPaymentModal, setShowPaymentModal] = useState<boolean>(false);
  const [completedReceiptOrder, setCompletedReceiptOrder] = useState<OrderRecord | null>(null);

  // Debounce product search (250ms) to avoid spamming server-side search requests
  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedSearch(searchTerm.trim());
    }, 250);
    return () => window.clearTimeout(timer);
  }, [searchTerm]);

  // Initial static / metadata bootstrap on outlet switch (categories, held bills, discounts, tiers, customers)
  useEffect(() => {
    productCacheRef.current.clear();
    loadStaticBootstrap();
  }, [outlet.id]);

  // Fetch paginated products whenever outlet, category, or debounced search changes (with request cancellation)
  useEffect(() => {
    fetchProductsPage(1, false);
    return () => {
      catalogAbortRef.current?.abort();
    };
  }, [outlet.id, selectedCategoryId, debouncedSearch]);

  // Infinite scroll observer on bottom sentinel
  useEffect(() => {
    const el = loadMoreSentinelRef.current;
    if (!el || !catalogHasMore || loadingCatalog || loadingMore) return;

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting && catalogHasMore && !loadingMore && !loadingCatalog) {
          fetchProductsPage(catalogPage + 1, true);
        }
      },
      { rootMargin: '200px' },
    );

    observer.observe(el);
    return () => observer.disconnect();
  }, [catalogHasMore, loadingCatalog, loadingMore, catalogPage, selectedCategoryId, debouncedSearch, outlet.id]);

  // Keyboard shortcut '/' to focus product search
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (
        e.key === '/' &&
        document.activeElement?.tagName !== 'INPUT' &&
        document.activeElement?.tagName !== 'TEXTAREA'
      ) {
        e.preventDefault();
        searchInputRef.current?.focus();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, []);

  const handleSelectCustomerTier = (newTierCode: string, newTierId?: number) => {
    setCustomerTier(newTierCode);
    // Instant dynamic price recalculation on existing cart items (reads from products OR productCacheRef so cart never loses tier pricing when filtered!)
    setCart((prevCart) =>
      prevCart.map((item) => {
        const prod =
          products.find((p) => p.id === item.product_id) ??
          productCacheRef.current.get(item.product_id);
        const variant = prod?.variants.find((v) => v.id === item.product_variant_id);
        const unit =
          item.product_unit_id && prod?.product_units
            ? prod.product_units.find((u) => u.id === item.product_unit_id) ?? null
            : null;
        if (variant) {
          const newUnitPrice = resolveVariantPriceForTier(variant, newTierCode, newTierId, unit);
          return {
            ...item,
            original_unit_price: newUnitPrice,
            customer_tier: newTierCode,
          };
        }
        return {
          ...item,
          customer_tier: newTierCode,
        };
      }),
    );
  };

  const loadStaticBootstrap = async () => {
    const whId = outlet.warehouse?.id ?? null;

    // 1. Stale-While-Revalidate: hydrate categories, tiers, and discounts instantly from browser cache
    const cachedBoot = posCache.getBootstrap(outlet.id, whId);
    if (cachedBoot) {
      setCategories(cachedBoot.data.categories);
      setTotalCatalogProducts(cachedBoot.data.totalProducts);
      if (cachedBoot.data.tiers.length > 0) {
        setDynamicTiers(cachedBoot.data.tiers);
      }
      setMasterDiscounts(cachedBoot.data.discounts);
    }

    // 2. Background revalidation from live backend
    try {
      const [catRes, heldRes, rulesRes, tiersRes, custRes] = await Promise.all([
        api.get<{ categories: Category[]; total_products?: number }>('/catalog/categories'),
        api.get<{ orders: OrderRecord[] }>('/orders/held'),
        api.get<PricingRulesResponse>('/pricing-rules'),
        api.get<{ tiers: DynamicTier[] }>('/tiers').catch(() => ({ data: { tiers: [] } })),
        api
          .get<{
            customers: Array<{
              id: number;
              name: string;
              code: string;
              phone?: string | null;
              company_name?: string | null;
              address?: string | null;
              masked_nik?: string | null;
              has_nik?: boolean;
              pricing_tier?: string;
              tier_id?: number | null;
              payment_term_days?: number;
              outstanding_balance?: string;
              credit_limit?: string;
              tier?: { id: number; code: string; name: string } | null;
            }>;
          }>('/customers')
          .catch(() => ({ data: { customers: [] } })),
      ]);

      const nextCats = catRes.data.categories ?? [];
      const nextTotal = typeof catRes.data.total_products === 'number' ? catRes.data.total_products : 0;
      const nextDiscounts = (rulesRes.data.discounts ?? []).filter(
        (d) => d.is_active && (d.applies_to === 'both' || d.applies_to === 'pos'),
      );
      const nextTiers = tiersRes.data?.tiers ?? [];

      setCategories(nextCats);
      if (nextTotal > 0) {
        setTotalCatalogProducts(nextTotal);
      }
      setHeldOrders(heldRes.data.orders);
      setMasterDiscounts(nextDiscounts);
      if (nextTiers.length > 0) {
        setDynamicTiers(nextTiers);
      }
      if (custRes.data?.customers) {
        setRegisteredCustomers(custRes.data.customers);
      }

      posCache.setBootstrap(outlet.id, whId, {
        categories: nextCats,
        totalProducts: nextTotal,
        tiers: nextTiers,
        discounts: nextDiscounts,
      });
    } catch (err) {
      if (!cachedBoot) {
        toast({
          type: 'error',
          title: 'Gagal memuat konfigurasi kasir',
          description: extractErrorMessage(err),
        });
      }
    }
  };

  const fetchProductsPage = async (page: number, append: boolean, skipCache = false) => {
    const whId = outlet.warehouse?.id ?? null;
    const reqKey = `o${outlet.id}:w${whId ?? 0}:c${selectedCategoryId}:t${customerTier}:s${debouncedSearch}:p${page}`;

    // Deduplicate identical in-flight requests
    if (inFlightCatalogKeyRef.current === reqKey) {
      return;
    }

    const canUseBrowserCache = !append && !skipCache && debouncedSearch === '';
    let hydratedFromCache = false;

    if (canUseBrowserCache) {
      const cachedPage = posCache.getCatalogPage({
        outletId: outlet.id,
        warehouseId: whId,
        categoryId: selectedCategoryId,
        tier: customerTier,
        page,
      });
      if (cachedPage && cachedPage.data.products.length > 0) {
        hydratedFromCache = true;
        for (const p of cachedPage.data.products) {
          productCacheRef.current.set(p.id, p);
        }
        setProducts(cachedPage.data.products);
        setCatalogPage(cachedPage.data.pagination?.current_page ?? page);
        setCatalogHasMore(Boolean(cachedPage.data.pagination?.has_more));
        setCatalogTotalMatches(cachedPage.data.pagination?.total ?? cachedPage.data.products.length);
        setLoadingCatalog(false);
        setIsShowingCachedData(true);
        setIsRevalidatingCatalog(true);
      }
    }

    if (!append) {
      catalogAbortRef.current?.abort();
      const controller = new AbortController();
      catalogAbortRef.current = controller;
      if (!hydratedFromCache) {
        setLoadingCatalog(true);
        setIsShowingCachedData(false);
      }
    } else {
      setLoadingMore(true);
    }

    inFlightCatalogKeyRef.current = reqKey;
    const activeSignal = catalogAbortRef.current?.signal;

    try {
      const res = await api.get<{
        products: ProductCatalog[];
        synced_at?: string;
        pagination?: {
          current_page: number;
          per_page: number;
          total: number;
          last_page: number;
          has_more: boolean;
        };
      }>('/catalog/products', {
        signal: activeSignal,
        params: {
          page,
          per_page: 30,
          compact: 1,
          customer_tier: customerTier,
          category_id: selectedCategoryId > 0 ? selectedCategoryId : undefined,
          search: debouncedSearch || undefined,
        },
      });

      const incoming = res.data.products ?? [];
      for (const p of incoming) {
        productCacheRef.current.set(p.id, p);
      }

      setProducts((prev) => {
        if (!append) return incoming;
        const existingIds = new Set(prev.map((x) => x.id));
        const merged = [...prev];
        for (const p of incoming) {
          if (!existingIds.has(p.id)) {
            merged.push(p);
          }
        }
        return merged;
      });

      const meta = res.data.pagination;
      setCatalogPage(meta?.current_page ?? page);
      setCatalogHasMore(Boolean(meta?.has_more));
      setCatalogTotalMatches(meta?.total ?? incoming.length);
      if (selectedCategoryId === 0 && !debouncedSearch && meta?.total !== undefined) {
        setTotalCatalogProducts(meta.total);
      }

      setIsShowingCachedData(false);
      setIsRevalidatingCatalog(false);

      // Persist fresh page 1 into browser cache (isolated by outlet + warehouse + category + tier)
      if (!append && debouncedSearch === '' && page === 1) {
        posCache.setCatalogPage(
          {
            outletId: outlet.id,
            warehouseId: whId,
            categoryId: selectedCategoryId,
            tier: customerTier,
            page: 1,
          },
          {
            products: incoming,
            pagination: meta,
            syncedAt: res.data.synced_at,
          },
        );
      }
    } catch (err: unknown) {
      const isCanceled =
        (err as { code?: string; name?: string })?.code === 'ERR_CANCELED' ||
        (err as { name?: string })?.name === 'CanceledError' ||
        (err as { name?: string })?.name === 'AbortError';
      if (!isCanceled && !hydratedFromCache) {
        toast({
          type: 'error',
          title: 'Gagal memuat katalog produk',
          description: extractErrorMessage(err),
        });
      }
    } finally {
      if (inFlightCatalogKeyRef.current === reqKey) {
        inFlightCatalogKeyRef.current = null;
      }
      if (!append) {
        setLoadingCatalog(false);
        setIsRevalidatingCatalog(false);
      } else {
        setLoadingMore(false);
      }
    }
  };

  // Non-blocking background refresh after checkout/hold: immediately invalidates browser cache for this outlet
  const loadCatalogAndHeld = () => {
    posCache.invalidateOutletCatalog(outlet.id);
    // Fire-and-forget background refresh so kasir can immediately start the next transaction without waiting
    void Promise.all([
      api
        .get<{ orders: OrderRecord[] }>('/orders/held')
        .then((res) => setHeldOrders(res.data.orders))
        .catch(() => {}),
      fetchProductsPage(1, false, true),
    ]);
  };

  // Server-side search & category filter already applied; products is ready to render directly
  const filteredProducts = products;

  const addVariantToCart = (
    product: ProductCatalog,
    variant: ProductVariantCatalog,
    modifiers: ProductModifier[] = [],
    notes = '',
    productUnit?: ProductUnit | null,
  ) => {
    if (!variant.is_available) {
      toast({
        type: 'warning',
        title: 'Produk sedang Out of Stock',
        description: `${product.name} (${variant.name}) tidak tersedia di outlet ini.`,
      });
      return;
    }

    const modKey = modifiers
      .map((m) => m.id)
      .sort((a, b) => a - b)
      .join(',');
    const unitKey = productUnit?.id ? `u${productUnit.id}` : 'base';
    const key = `${variant.id}:${unitKey}:${modKey}:${notes.trim()}`;
    const uomCode = productUnit?.unit_code || product.unit || 'PCS';
    const unitName = productUnit?.unit_name || product.unit || 'PCS';
    const conversionFactor = productUnit?.conversion_factor || '1';

    setCart((prev) => {
      const existing = prev.find((item) => item.key === key);
      if (existing) {
        return prev.map((item) =>
          item.key === key ? { ...item, quantity: item.quantity + 1 } : item,
        );
      }
      return [
        ...prev,
        {
          key,
          product_id: product.id,
          product_variant_id: variant.id,
          product_unit_id: productUnit?.id ?? null,
          uom_code: uomCode,
          unit_name: unitName,
          conversion_factor: conversionFactor,
          product_name: product.name,
          variant_name: variant.name,
          sku: variant.sku,
          quantity: 1,
          original_unit_price: resolveVariantPriceForTier(
            variant,
            customerTier,
            selectedTier?.id,
            productUnit,
          ),
          unit_price_override: null,
          customer_tier: customerTier,
          modifiers,
          notes: notes.trim() || undefined,
        },
      ];
    });
  };

  const handleProductCardClick = (product: ProductCatalog, quickUnit?: ProductUnit | null) => {
    const firstAvailable =
      product.variants.find((v) => v.is_available) ?? product.variants[0];
    if (
      product.variants.length === 1 &&
      product.modifiers.length === 0 &&
      (quickUnit !== undefined || !product.product_units || product.product_units.length <= 1)
    ) {
      addVariantToCart(product, product.variants[0], [], '', quickUnit ?? null);
      return;
    }
    setConfiguringProduct(product);
    setChosenVariantId(firstAvailable?.id ?? 0);
    setChosenUnitId(quickUnit?.id ?? null);
    setChosenModifierIds([]);
    setChosenItemNotes('');
  };

  const handleBarcodeSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const code = barcodeInput.trim();
    if (!code) return;

    try {
      const res = await api.get<{
        variant: {
          id: number;
          product_id: number;
          product_name: string;
          product_unit_base?: string;
          name: string;
          sku: string;
          barcode: string | null;
          base_price: string;
          price_override: string | null;
          effective_price: string;
          tier_prices?: Record<string, string>;
          is_available: boolean;
          stock_quantity: string;
          product_unit_id?: number | null;
          uom_code?: string;
          conversion_factor?: string;
          matched_unit?: ProductUnit | null;
        };
      }>(`/catalog/barcode/${encodeURIComponent(code)}`);

      const v = res.data.variant;
      // Support scanned products even if they are on page 2+ and not yet loaded in `products`
      let prod =
        products.find((p) => p.id === v.product_id) ??
        productCacheRef.current.get(v.product_id);

      const variantObj: ProductVariantCatalog = prod?.variants.find((pv) => pv.id === v.id) ?? {
        ...v,
        is_default: true,
        reorder_level: '0',
      };

      if (!prod) {
        prod = {
          id: v.product_id,
          category_id: null,
          name: v.product_name,
          sku: v.sku,
          description: null,
          base_price: v.base_price,
          unit: v.product_unit_base || v.uom_code || 'PCS',
          track_stock: true,
          image_url: null,
          variants: [variantObj],
          product_units: v.matched_unit ? [v.matched_unit] : [],
          modifiers: [],
        };
        productCacheRef.current.set(prod.id, prod);
      }

      const matchedUnit =
        v.matched_unit ??
        (v.product_unit_id
          ? prod.product_units?.find((pu) => pu.id === v.product_unit_id) ?? null
          : null);
      addVariantToCart(prod, variantObj, [], '', matchedUnit);
      setBarcodeInput('');
      toast({
        type: 'success',
        title: 'Barcode Terbaca',
        description: `${v.product_name} (${matchedUnit ? matchedUnit.unit_name : v.name}) ditambahkan ke keranjang.`,
      });
    } catch (err) {
      toast({
        type: 'error',
        title: 'Barcode / SKU Tidak Ditemukan',
        description: extractErrorMessage(err),
      });
    }
  };

  const updateCartQty = (key: string, delta: number) => {
    setCart((prev) =>
      prev
        .map((item) =>
          item.key === key ? { ...item, quantity: item.quantity + delta } : item,
        )
        .filter((item) => item.quantity > 0),
    );
  };

  // Totals Calculation
  const totals = useMemo(() => {
    const subtotal = cart.reduce((acc, item) => {
      const unit = item.unit_price_override ?? item.original_unit_price;
      const mods = item.modifiers.reduce((mAcc, m) => mAcc + parseFloat(m.price), 0);
      return acc + Math.round((unit + mods) * item.quantity);
    }, 0);

    let discountTotal = 0;
    const rawDiscVal = parseFloat(discountValue || '0');
    if (discountType === 'percent' && rawDiscVal > 0) {
      discountTotal = Math.round((subtotal * Math.min(100, rawDiscVal)) / 100);
    } else if (discountType === 'fixed' && rawDiscVal > 0) {
      discountTotal = Math.min(subtotal, Math.round(rawDiscVal));
    }

    const netAfterDiscount = Math.max(0, subtotal - discountTotal);
    const scRate = parseFloat(outlet.service_charge_rate || '0');
    const serviceChargeTotal = Math.round((netAfterDiscount * scRate) / 100);

    const taxableBase = netAfterDiscount + serviceChargeTotal;
    const taxRate = parseFloat(outlet.tax_rate || '0');
    const taxTotal = Math.round((taxableBase * taxRate) / 100);

    const grandTotal = taxableBase + taxTotal;

    const maxCashierDiscPercent = parseFloat(outlet.max_cashier_discount_percent || '0');
    const effectiveDiscPercent = subtotal > 0 ? (discountTotal / subtotal) * 100 : 0;
    const hasPriceOverride = cart.some(
      (i) =>
        i.unit_price_override !== null &&
        i.unit_price_override !== undefined &&
        i.unit_price_override !== i.original_unit_price,
    );
    const requiresSupervisorPin =
      hasPriceOverride || effectiveDiscPercent > maxCashierDiscPercent + 0.001;

    return {
      subtotal,
      discountTotal,
      serviceChargeTotal,
      taxTotal,
      grandTotal,
      requiresSupervisorPin,
      effectiveDiscPercent,
    };
  }, [cart, discountType, discountValue, outlet]);

  const clearCart = () => {
    setActiveOrderId(null);
    setActiveOrderNumber(null);
    setCustomerName('');
    setCustomerPhone('');
    setTableLabel('');
    setOrderNotes('');
    setDiscountType('');
    setDiscountValue('');
    setDiscountReason('');
    setShowCustomerDrawer(false);
    setShowDiscountDrawer(false);
    setCart([]);
    setCustomerTier(activeTiers[0]?.code || 'TIER_1');
    setSelectedCustomerId(null);
  };

  const buildOrderPayload = () => ({
    order_id: activeOrderId ?? undefined,
    customer_id: selectedCustomerId ?? undefined,
    customer_tier: customerTier,
    customer_name: customerName || `Customer ${selectedTier?.name || customerTier}`,
    customer_phone: customerPhone || null,
    table_label: tableLabel || null,
    notes: orderNotes || null,
    discount_type: discountType || null,
    discount_value: discountType ? parseFloat(discountValue || '0') : 0,
    discount_reason: discountReason || null,
    items: cart.map((item) => ({
      product_variant_id: item.product_variant_id,
      product_unit_id: item.product_unit_id ?? null,
      uom_code: item.uom_code || null,
      quantity: item.quantity,
      unit_price_override:
        item.unit_price_override !== null && item.unit_price_override !== undefined
          ? item.unit_price_override
          : null,
      price_override_reason: item.price_override_reason || null,
      modifier_ids: item.modifiers.map((m) => m.id),
      notes: item.notes || null,
    })),
  });

  const handleHoldOrder = async () => {
    if (!hasOpenShift) {
      onRequireOpenShift();
      return;
    }
    if (cart.length === 0) return;

    try {
      const res = await api.post<{ order: OrderRecord }>('/orders/hold', buildOrderPayload());
      toast({
        type: 'success',
        title: `Bill Di-Hold (${res.data.order.order_number})`,
        description: 'Pesanan disimpan dan dapat dilanjutkan dari terminal mana pun di outlet ini.',
      });
      clearCart();
      await loadCatalogAndHeld();
      onOrderCompletedOrHeld();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menyimpan Hold Order',
        description: extractErrorMessage(err),
      });
    }
  };

  const handleResumeHeldOrder = async (order: OrderRecord) => {
    if (!hasOpenShift) {
      onRequireOpenShift();
      return;
    }
    try {
      const res = await api.post<{ order: OrderRecord }>(`/orders/${order.id}/resume`);
      const resumed = res.data.order;
      setActiveOrderId(resumed.id);
      setActiveOrderNumber(resumed.order_number);
      setCustomerName(resumed.customer_name ?? '');
      setCustomerPhone(resumed.customer_phone ?? '');
      setTableLabel(resumed.table_label ?? '');
      setOrderNotes(resumed.notes ?? '');
      setDiscountType(resumed.discount_type ?? '');
      setDiscountValue(
        parseFloat(resumed.discount_value) > 0 ? String(parseFloat(resumed.discount_value)) : '',
      );
      setDiscountReason(resumed.discount_reason ?? '');

      if (resumed.crm_customer_ref && /tier_([a-z0-9_]+)/i.test(resumed.crm_customer_ref)) {
        const matched = resumed.crm_customer_ref.match(/tier_([a-z0-9_]+)/i);
        if (matched) {
          setCustomerTier(matched[1].toUpperCase());
        }
      }

      setCart(
        resumed.items.map((oi) => {
          const origPrice = parseFloat(oi.original_unit_price);
          const chargedPrice = parseFloat(oi.unit_price);
          return {
            key: `resumed-${oi.id}`,
            product_id: oi.product_id,
            product_variant_id: oi.product_variant_id,
            product_name: oi.product_name,
            variant_name: oi.variant_name,
            sku: oi.sku,
            quantity: parseFloat(oi.quantity),
            original_unit_price: origPrice,
            unit_price_override: origPrice !== chargedPrice ? chargedPrice : null,
            price_override_reason: oi.price_override_reason ?? undefined,
            modifiers: (oi.modifiers ?? []).map((m) => ({
              id: m.id,
              product_id: oi.product_id,
              group_name: m.group_name,
              name: m.name,
              price: m.price,
              is_required: false,
              is_multiple: true,
            })),
            notes: oi.notes ?? undefined,
          };
        }),
      );
      setShowHeldModal(false);
      toast({
        type: 'info',
        title: `Melanjutkan Bill ${resumed.order_number}`,
        description: 'Item pesanan telah dimuat ke keranjang kasir.',
      });
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat Hold Order',
        description: extractErrorMessage(err),
      });
    }
  };

  const selectedCustomerObj = useMemo(
    () =>
      selectedCustomerId
        ? registeredCustomers.find((c) => c.id === selectedCustomerId) ?? null
        : null,
    [selectedCustomerId, registeredCustomers],
  );

  const filteredRegisteredCustomers = useMemo(() => {
    const q = customerSearchTerm.trim().toLowerCase();
    if (!q) return registeredCustomers;
    return registeredCustomers.filter(
      (c) =>
        c.name.toLowerCase().includes(q) ||
        c.code.toLowerCase().includes(q) ||
        (c.phone && c.phone.toLowerCase().includes(q)) ||
        (c.company_name && c.company_name.toLowerCase().includes(q)),
    );
  }, [registeredCustomers, customerSearchTerm]);

  const handleSelectRegisteredCustomer = (c: (typeof registeredCustomers)[number] | null) => {
    if (!c) {
      setSelectedCustomerId(null);
      setCustomerName('');
      setCustomerPhone('');
      setShowCustomerSelectorMenu(false);
      return;
    }
    setSelectedCustomerId(c.id);
    setCustomerName(c.name);
    setCustomerPhone(c.phone || '');
    if (c.tier?.code) {
      handleSelectCustomerTier(c.tier.code, c.tier.id);
    } else if (c.pricing_tier) {
      handleSelectCustomerTier(c.pricing_tier.toUpperCase());
    }
    setShowCustomerSelectorMenu(false);
    setCustomerSearchTerm('');
  };

  const handleQuickCreateCustomer = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newCustName.trim() || !newCustPhone.trim()) {
      toast({
        type: 'warning',
        title: 'Data Pelanggan Belum Lengkap',
        description: 'Nama Pelanggan dan Nomor HP wajib diisi.',
      });
      return;
    }
    setSubmittingNewCustomer(true);
    try {
      const res = await api.post<{ customer: (typeof registeredCustomers)[number] }>('/customers', {
        name: newCustName.trim(),
        phone: newCustPhone.trim(),
        company_name: newCustCompany.trim() || undefined,
        address: newCustAddress.trim() || undefined,
        nik: newCustNik.trim() || undefined,
        customer_group: 'retailer',
        pricing_tier: 'retail',
        tier_id: selectedTier?.id,
      });
      const created = res.data.customer;
      setRegisteredCustomers((prev) => [created, ...prev]);
      handleSelectRegisteredCustomer(created);
      setShowQuickCreateCustomerModal(false);
      setNewCustName('');
      setNewCustPhone('');
      setNewCustCompany('');
      setNewCustAddress('');
      setNewCustNik('');
      toast({
        type: 'success',
        title: 'Pelanggan Baru Terdaftar',
        description: `${created.name} (${created.code}) otomatis dipilih untuk transaksi ini.`,
      });
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal Membuat Pelanggan Baru',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmittingNewCustomer(false);
    }
  };

  const openCustomerDetailModal = async (custId: number, reveal = false) => {
    setShowCustomerDetailModal(true);
    setLoadingCustomerDetail(true);
    if (!reveal) {
      setRevealedNik(null);
      setSettlingInvoiceId(null);
    }
    try {
      const res = await api.get<NonNullable<typeof customerDetailData>>(`/customers/${custId}`, {
        params: { reveal_nik: reveal ? 1 : 0 },
      });
      setCustomerDetailData(res.data);
      if (reveal && res.data.customer.raw_nik) {
        setRevealedNik(res.data.customer.raw_nik);
      }
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal Memuat Detail Pelanggan',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoadingCustomerDetail(false);
    }
  };

  const handleSettleCustomerInvoice = async (e: React.FormEvent, invoiceId: number) => {
    e.preventDefault();
    if (!customerDetailData) return;
    const payAmt = parseFloat(settleAmountInput || '0');
    if (payAmt <= 0) return;

    setSubmittingSettle(true);
    try {
      await api.post('/receivables/payments', {
        invoice_id: invoiceId,
        amount: payAmt,
        method: settleMethodInput,
        reference_number: settleRefInput || undefined,
        notes: 'Pelunasan / cicilan piutang via POS Kasir',
        idempotency_key: `pos-ar-${invoiceId}-${Date.now()}`,
      });
      toast({
        type: 'success',
        title: 'Pembayaran Piutang Berhasil Dicatat',
        description: `Pembayaran sebesar ${formatIDR(payAmt)} telah dialokasikan ke invoice.`,
      });
      setSettlingInvoiceId(null);
      setSettleAmountInput('');
      setSettleRefInput('');
      await openCustomerDetailModal(customerDetailData.customer.id, Boolean(revealedNik));
      await loadCatalogAndHeld();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal Mencatat Pelunasan Piutang',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmittingSettle(false);
    }
  };

  const handleSubmitCheckout = async (
    payments: PaymentLegInput[],
    idempotencyKey: string,
    supervisorPin?: string,
    paymentScheme?: PaymentSchemeType,
    dueDate?: string,
  ): Promise<OrderRecord> => {
    const payload = {
      ...buildOrderPayload(),
      supervisor_pin: supervisorPin || undefined,
      payment_scheme: paymentScheme || undefined,
      due_date: dueDate || undefined,
      payments: payments.map((p) => ({
        method: p.method,
        amount: p.amount,
        tendered_amount: p.method === 'cash' ? p.tendered_amount : p.amount,
        provider: p.provider || null,
        reference_number: p.reference_number || null,
        card_last4: p.card_last4 || null,
        qris_payload: p.qris_payload || null,
      })),
    };

    const res = await api.post<{ order: OrderRecord }>('/orders/checkout', payload, {
      headers: {
        'X-Idempotency-Key': idempotencyKey,
      },
    });

    clearCart();
    await loadCatalogAndHeld();
    onOrderCompletedOrHeld();
    return res.data.order;
  };

  const editingItem = cart.find((c) => c.key === editingCartKey) ?? null;

  const categoryCounts = useMemo(() => {
    const counts: Record<number, number> = {};
    for (const p of products) {
      if (p.category_id) {
        counts[p.category_id] = (counts[p.category_id] || 0) + 1;
      }
    }
    return counts;
  }, [products]);

  const totalCartItems = useMemo(
    () => cart.reduce((acc, item) => acc + item.quantity, 0),
    [cart],
  );

  return (
    <div className="bg-[#F8FAFC] h-[calc(100vh-53px)] p-3.5 overflow-hidden">
      <div className="grid grid-cols-1 lg:grid-cols-[65fr_35fr] gap-3.5 h-full overflow-hidden">
        {/* LEFT COLUMN (65%): PRODUCT CATALOG, SEARCH/BARCODE & CATEGORY NAVIGATION */}
        <div className="flex flex-col gap-3 h-full overflow-hidden min-w-0">
          {/* 1. Unified Search & Barcode Scanner Bar (Square / Shopify POS Style) */}
          <div className="bg-white p-2.5 rounded-xl border border-slate-200 shadow-2xs flex flex-col sm:flex-row items-stretch sm:items-center gap-2 shrink-0">
            {/* Instant Product Search */}
            <div className="relative flex-1 min-w-0">
              <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
              <input
                ref={searchInputRef}
                type="text"
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                placeholder="Cari nama produk, SKU, atau merk..."
                className="w-full pl-9 pr-14 py-2 rounded-lg bg-[#F8FAFC] border border-slate-200 text-xs font-medium text-slate-900 placeholder:text-slate-400 focus:bg-white focus:outline-none focus:border-[#2563EB] focus:ring-2 focus:ring-[#2563EB]/15 transition"
              />
              {searchTerm ? (
                <button
                  type="button"
                  onClick={() => setSearchTerm('')}
                  title="Bersihkan pencarian"
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 rounded-md text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 cursor-pointer"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              ) : (
                <kbd className="hidden sm:inline-flex items-center px-1.5 py-0.5 text-[10px] font-mono font-semibold text-slate-400 bg-white border border-slate-200 rounded absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none">
                  /
                </kbd>
              )}
            </div>

            {/* Dedicated Barcode / Packaging Scanner Input */}
            <form
              onSubmit={handleBarcodeSubmit}
              className="flex items-center gap-1.5 sm:w-[44%] shrink-0"
            >
              <div className="relative flex-1 min-w-0">
                <Barcode className="w-4 h-4 text-[#2563EB] absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                <input
                  type="text"
                  value={barcodeInput}
                  onChange={(e) => setBarcodeInput(e.target.value)}
                  placeholder="Scan barcode botol / dus / SKU..."
                  className="w-full pl-9 pr-3 py-2 rounded-lg bg-[#F8FAFC] border border-slate-200 text-xs font-mono text-slate-900 placeholder:font-sans placeholder:text-slate-400 focus:bg-white focus:outline-none focus:border-[#2563EB] focus:ring-2 focus:ring-[#2563EB]/15 transition"
                />
              </div>
              <button
                type="submit"
                className="px-3 py-2 rounded-lg bg-[#2563EB] hover:bg-blue-700 text-white text-xs font-bold transition shrink-0 cursor-pointer"
              >
                Scan
              </button>
            </form>
          </div>

          {/* 2. Clean Category Navigation Bar + Subtle SWR Sync Status */}
          <div className="flex items-center justify-between gap-2 shrink-0">
            <div className="flex items-center gap-1.5 overflow-x-auto pb-0.5 no-scrollbar flex-1 min-w-0">
              <button
                type="button"
                onClick={() => setSelectedCategoryId(0)}
                className={`px-3 py-1.5 rounded-lg text-xs font-bold shrink-0 transition flex items-center gap-1.5 cursor-pointer ${selectedCategoryId === 0
                  ? 'bg-[#2563EB] text-white shadow-2xs'
                  : 'bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:border-slate-300'
                  }`}
              >
                <span>Semua</span>
                <span
                  className={`text-[10px] px-1.5 py-0.2 rounded-full font-extrabold tabular-nums ${selectedCategoryId === 0
                    ? 'bg-white/20 text-white'
                    : 'bg-slate-100 text-slate-600'
                    }`}
                >
                  {totalCatalogProducts || products.length}
                </span>
              </button>

              {categories.map((cat) => {
                const isSelected = selectedCategoryId === cat.id;
                const count =
                  typeof cat.products_count === 'number'
                    ? cat.products_count
                    : (categoryCounts[cat.id] ?? 0);
                return (
                  <button
                    type="button"
                    key={cat.id}
                    onClick={() => setSelectedCategoryId(cat.id)}
                    className={`px-3 py-1.5 rounded-lg text-xs font-bold shrink-0 transition flex items-center gap-1.5 cursor-pointer ${isSelected
                      ? 'bg-[#2563EB] text-white shadow-2xs'
                      : 'bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:border-slate-300'
                      }`}
                  >
                    {cat.color && (
                      <span
                        className="w-2 h-2 rounded-full shrink-0"
                        style={{
                          backgroundColor: isSelected ? '#ffffff' : cat.color,
                        }}
                      />
                    )}
                    <span>{cat.name}</span>
                    <span
                      className={`text-[10px] px-1.5 py-0.2 rounded-full font-bold tabular-nums ${isSelected
                        ? 'bg-white/20 text-white'
                        : 'bg-slate-100 text-slate-500'
                        }`}
                    >
                      {count}
                    </span>
                  </button>
                );
              })}
            </div>

            {(isShowingCachedData || isRevalidatingCatalog) && (
              <span
                title="Menampilkan cache lokal sementara sambil menyinkronkan stok & harga terbaru dari server"
                className="hidden md:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-50 border border-amber-200 text-[10px] font-bold text-amber-800 shrink-0"
              >
                <span className="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse" />
                <span>Menyinkronkan stok &amp; harga...</span>
              </span>
            )}
          </div>

          {/* 3. High-Readability Product Grid (Optimized for 1366x768 & Up) */}
          <div className="flex-1 overflow-y-auto pr-1">
            {loadingCatalog ? (
              <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2.5 pb-4">
                {Array.from({ length: 8 }).map((_, i) => (
                  <div
                    key={i}
                    className="h-36 rounded-xl bg-white border border-slate-200 p-3.5 animate-pulse flex flex-col justify-between"
                  >
                    <div className="space-y-2">
                      <div className="flex justify-between">
                        <div className="h-4 w-16 bg-slate-100 rounded" />
                        <div className="h-4 w-14 bg-slate-100 rounded" />
                      </div>
                      <div className="h-4 w-3/4 bg-slate-100 rounded" />
                      <div className="h-3 w-1/2 bg-slate-100 rounded" />
                    </div>
                    <div className="h-6 w-24 bg-slate-100 rounded" />
                  </div>
                ))}
              </div>
            ) : filteredProducts.length === 0 ? (
              <div className="h-64 bg-white rounded-xl border border-slate-200 flex flex-col items-center justify-center text-center p-6">
                <Package className="w-8 h-8 text-slate-300 mb-2" />
                <div className="text-sm font-bold text-slate-700">
                  Produk Tidak Ditemukan
                </div>
                <div className="text-xs text-slate-500 mt-0.5">
                  Coba kata kunci lain atau ganti filter kategori di atas.
                </div>
                {(searchTerm || selectedCategoryId !== 0) && (
                  <button
                    type="button"
                    onClick={() => {
                      setSearchTerm('');
                      setSelectedCategoryId(0);
                    }}
                    className="mt-3 px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-xs font-bold text-slate-700 cursor-pointer"
                  >
                    Reset Filter
                  </button>
                )}
              </div>
            ) : (
              <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2.5 pb-4">
                {filteredProducts.map((product) => {
                  const defaultVar =
                    product.variants.find((v) => v.is_default) ?? product.variants[0];
                  const totalStock = product.variants.reduce(
                    (acc, v) => acc + parseFloat(v.stock_quantity),
                    0,
                  );
                  const anyAvailable = product.variants.some((v) => v.is_available);
                  const salesUnits = (product.product_units ?? []).filter(
                    (u) => u.is_active && u.is_sales_unit,
                  );
                  const activeTierPrice = resolveVariantPriceForTier(
                    defaultVar,
                    customerTier,
                    selectedTier?.id,
                  );
                  const baseRetailPrice = defaultVar
                    ? parseFloat(defaultVar.base_price || defaultVar.effective_price)
                    : 0;
                  const hasTierDiscount =
                    activeTierPrice > 0 &&
                    baseRetailPrice > 0 &&
                    activeTierPrice < baseRetailPrice;

                  const qtyInCart = cart
                    .filter((c) => c.product_id === product.id)
                    .reduce((sum, c) => sum + c.quantity, 0);

                  return (
                    <div
                      key={product.id}
                      onClick={() => {
                        if (anyAvailable) handleProductCardClick(product);
                      }}
                      role="button"
                      tabIndex={anyAvailable ? 0 : -1}
                      onKeyDown={(e) => {
                        if (anyAvailable && (e.key === 'Enter' || e.key === ' ')) {
                          e.preventDefault();
                          handleProductCardClick(product);
                        }
                      }}
                      className={`group relative text-left bg-white rounded-xl border transition-all flex flex-col justify-between overflow-hidden select-none ${anyAvailable
                        ? 'border-slate-200 hover:border-[#2563EB] hover:shadow-md cursor-pointer active:scale-[0.99]'
                        : 'border-slate-200 opacity-55 cursor-not-allowed bg-slate-50'
                        } ${qtyInCart > 0 ? 'ring-2 ring-[#2563EB]' : ''}`}
                      style={{ contentVisibility: 'auto', containIntrinsicSize: '180px 148px' }}
                    >
                      {/* Top Content: SKU, Stock Badge, Product Name, Unit & Brand */}
                      <div className="p-3 pb-2 space-y-1.5">
                        <div className="flex items-center justify-between gap-1.5">
                          <span className="text-[10px] font-mono font-bold px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 truncate">
                            {defaultVar?.sku || product.sku}
                          </span>

                          <span
                            className={`text-[10px] font-extrabold px-2 py-0.5 rounded-full shrink-0 tabular-nums ${!anyAvailable || totalStock <= 0
                              ? 'bg-rose-100 text-rose-700'
                              : totalStock <= 15
                                ? 'bg-amber-100 text-amber-800'
                                : 'bg-emerald-50 text-[#059669]'
                              }`}
                          >
                            {!anyAvailable
                              ? 'Habis'
                              : `${formatQty(totalStock)} ${product.unit}`}
                          </span>
                        </div>

                        <div className="font-bold text-xs sm:text-[13px] text-slate-900 group-hover:text-[#2563EB] leading-snug line-clamp-2 min-h-[2.25rem]">
                          {product.name}
                        </div>

                        {/* Metadata Pill Row: Brand / Size / Packaging Units */}
                        <div className="flex flex-wrap items-center gap-1 pt-0.5">
                          {product.brand?.name && (
                            <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 truncate max-w-[100px]">
                              {product.brand.name}
                            </span>
                          )}
                          <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-blue-50 text-[#2563EB]">
                            {product.variants.length > 1
                              ? `${product.variants.length} Varian`
                              : defaultVar?.name || product.unit}
                          </span>
                          {salesUnits.length > 1 && (
                            <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-emerald-50 text-[#059669]">
                              {salesUnits.length} Satuan
                            </span>
                          )}
                        </div>
                      </div>

                      {/* Quick Multi-Unit Packaging Pills (1-Click Botol / Pack / Dus / Karton) */}
                      {anyAvailable &&
                        product.variants.length === 1 &&
                        product.modifiers.length === 0 &&
                        salesUnits.length > 1 && (
                          <div
                            className="px-3 pb-2 flex flex-wrap gap-1"
                            onClick={(e) => e.stopPropagation()}
                          >
                            {salesUnits.slice(0, 3).map((u) => (
                              <button
                                key={u.id}
                                type="button"
                                onClick={() => handleProductCardClick(product, u)}
                                title={`Tambah 1 ${u.unit_name} (${parseFloat(u.conversion_factor)} ${product.unit})`}
                                className="px-2 py-0.5 rounded-md bg-slate-100 hover:bg-[#2563EB] hover:text-white text-slate-700 text-[10px] font-bold border border-slate-200/80 transition cursor-pointer"
                              >
                                +{u.unit_code}
                              </button>
                            ))}
                          </div>
                        )}

                      {/* Bottom Price Bar */}
                      <div className="px-3 py-2 bg-[#F8FAFC] border-t border-slate-100 flex items-center justify-between gap-1">
                        <div className="min-w-0">
                          <div className="flex items-baseline gap-1.5">
                            <span className="text-sm font-extrabold text-[#2563EB] tabular-nums">
                              {formatIDR(activeTierPrice)}
                            </span>
                            <span className="text-[10px] font-semibold text-slate-400">
                              /{product.unit}
                            </span>
                          </div>
                          {hasTierDiscount && (
                            <div className="text-[10px] text-slate-400 line-through tabular-nums leading-none">
                              {formatIDR(baseRetailPrice)}
                            </div>
                          )}
                        </div>

                        {qtyInCart > 0 ? (
                          <span className="w-6 h-6 rounded-full bg-[#2563EB] text-white text-[11px] font-extrabold flex items-center justify-center tabular-nums shadow-2xs shrink-0">
                            {qtyInCart}
                          </span>
                        ) : (
                          <span className="w-6 h-6 rounded-lg bg-white border border-slate-200 group-hover:bg-[#2563EB] group-hover:border-[#2563EB] group-hover:text-white text-slate-500 flex items-center justify-center transition shrink-0">
                            <Plus className="w-3.5 h-3.5" />
                          </span>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}

            {/* Infinite Scroll Sentinel & Load-More Footer */}
            {!loadingCatalog && filteredProducts.length > 0 && (
              <div ref={loadMoreSentinelRef} className="py-3 flex flex-col items-center justify-center gap-2">
                {loadingMore ? (
                  <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2.5 w-full">
                    {Array.from({ length: 4 }).map((_, i) => (
                      <div
                        key={`more-skel-${i}`}
                        className="h-32 rounded-xl bg-white border border-slate-200 p-3 animate-pulse flex flex-col justify-between"
                      >
                        <div className="space-y-2">
                          <div className="h-3.5 w-16 bg-slate-100 rounded" />
                          <div className="h-4 w-3/4 bg-slate-100 rounded" />
                        </div>
                        <div className="h-5 w-20 bg-slate-100 rounded" />
                      </div>
                    ))}
                  </div>
                ) : catalogHasMore ? (
                  <button
                    type="button"
                    onClick={() => fetchProductsPage(catalogPage + 1, true)}
                    className="px-4 py-1.5 rounded-lg bg-white hover:bg-slate-50 border border-slate-200 text-xs font-bold text-[#2563EB] shadow-2xs cursor-pointer"
                  >
                    Muat Lebih Banyak ({filteredProducts.length} / {catalogTotalMatches} produk)
                  </button>
                ) : (
                  <span className="text-[11px] font-medium text-slate-400">
                    Menampilkan seluruh {filteredProducts.length} produk
                    {selectedCategoryId > 0 || debouncedSearch ? ' yang cocok' : ''}
                  </span>
                )}
              </div>
            )}
          </div>
        </div>

        {/* RIGHT COLUMN (35%): CUSTOMER & TIER SELECTOR, COMPACT CART & LARGE CHARGE CTA */}
        <div className="bg-white border border-slate-200 rounded-xl shadow-xs flex flex-col h-full overflow-hidden min-w-0">
          {/* 1. Searchable Customer Identity Selector & Pricing Tier Header */}
          <div className="p-3 bg-white border-b border-slate-200 space-y-2 shrink-0">
            <div className="flex items-center justify-between gap-1.5">
              {/* Searchable Customer Selector Trigger */}
              <div className="relative flex-1 min-w-0">
                <button
                  type="button"
                  onClick={() => setShowCustomerSelectorMenu((prev) => !prev)}
                  className={`w-full px-2.5 py-1.5 rounded-lg border text-left flex items-center justify-between gap-2 transition cursor-pointer ${
                    selectedCustomerObj
                      ? 'bg-blue-50/80 border-blue-300 text-slate-900'
                      : 'bg-[#F8FAFC] hover:bg-slate-100 border-slate-200 text-slate-700'
                  }`}
                >
                  <div className="flex items-center gap-2 min-w-0">
                    <Users className="w-3.5 h-3.5 text-[#2563EB] shrink-0" />
                    <div className="min-w-0">
                      <div className="text-xs font-bold truncate">
                        {selectedCustomerObj
                          ? selectedCustomerObj.name
                          : customerName
                            ? `Tamu: ${customerName}`
                            : 'Pilih / Cari Pelanggan (Nama / No. HP)'}
                      </div>
                      {selectedCustomerObj && (
                        <div className="text-[10px] text-slate-500 truncate">
                          {selectedCustomerObj.code}
                          {selectedCustomerObj.phone ? ` • ${selectedCustomerObj.phone}` : ''}
                          {selectedCustomerObj.company_name &&
                          selectedCustomerObj.company_name !== selectedCustomerObj.name
                            ? ` • ${selectedCustomerObj.company_name}`
                            : ''}
                        </div>
                      )}
                    </div>
                  </div>

                  {selectedCustomerObj ? (
                    <span
                      onClick={(e) => {
                        e.stopPropagation();
                        handleSelectRegisteredCustomer(null);
                      }}
                      title="Lepas pelanggan"
                      className="p-0.5 rounded hover:bg-blue-100 text-slate-400 hover:text-rose-600"
                    >
                      <X className="w-3.5 h-3.5" />
                    </span>
                  ) : (
                    <Search className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                  )}
                </button>

                {/* Dropdown Search & Customer List */}
                {showCustomerSelectorMenu && (
                  <>
                    <div
                      className="fixed inset-0 z-20"
                      onClick={() => setShowCustomerSelectorMenu(false)}
                    />
                    <div className="absolute left-0 right-0 mt-1.5 bg-white border border-slate-200 rounded-xl shadow-xl z-30 overflow-hidden">
                      <div className="p-2 border-b border-slate-100 bg-slate-50 flex items-center gap-1.5">
                        <div className="relative flex-1">
                          <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
                          <input
                            type="text"
                            autoFocus
                            value={customerSearchTerm}
                            onChange={(e) => setCustomerSearchTerm(e.target.value)}
                            placeholder="Cari nama, nomor HP, atau nama usaha..."
                            className="w-full pl-8 pr-2.5 py-1.5 rounded-lg bg-white border border-slate-200 text-xs text-slate-900 focus:outline-none focus:border-[#2563EB]"
                          />
                        </div>
                        <button
                          type="button"
                          onClick={() => {
                            setShowCustomerSelectorMenu(false);
                            setNewCustName(customerSearchTerm);
                            setShowQuickCreateCustomerModal(true);
                          }}
                          className="px-2.5 py-1.5 rounded-lg bg-[#2563EB] hover:bg-blue-700 text-white text-[11px] font-bold shrink-0 flex items-center gap-1 cursor-pointer"
                        >
                          <UserPlus className="w-3 h-3" />
                          <span>+ Baru</span>
                        </button>
                      </div>

                      <div className="max-h-60 overflow-y-auto divide-y divide-slate-100 text-xs">
                        <button
                          type="button"
                          onClick={() => handleSelectRegisteredCustomer(null)}
                          className="w-full px-3 py-2 text-left hover:bg-slate-50 text-slate-600 font-semibold flex items-center justify-between cursor-pointer"
                        >
                          <span>Pelanggan Umum (Walk-in Tanpa Akun)</span>
                          {!selectedCustomerId && (
                            <CheckCircle2 className="w-3.5 h-3.5 text-[#2563EB]" />
                          )}
                        </button>

                        {filteredRegisteredCustomers.length === 0 ? (
                          <div className="p-4 text-center space-y-2">
                            <div className="text-slate-400 text-xs">
                              Pelanggan &ldquo;{customerSearchTerm}&rdquo; belum terdaftar.
                            </div>
                            <button
                              type="button"
                              onClick={() => {
                                setShowCustomerSelectorMenu(false);
                                setNewCustName(customerSearchTerm);
                                setShowQuickCreateCustomerModal(true);
                              }}
                              className="px-3 py-1.5 rounded-lg bg-blue-50 text-[#2563EB] font-bold text-xs hover:bg-blue-100 inline-flex items-center gap-1.5 cursor-pointer"
                            >
                              <UserPlus className="w-3.5 h-3.5" />
                              <span>Buat Pelanggan Baru</span>
                            </button>
                          </div>
                        ) : (
                          filteredRegisteredCustomers.map((c) => {
                            const isSelected = c.id === selectedCustomerId;
                            const arBalance = parseFloat(c.outstanding_balance || '0');
                            return (
                              <button
                                key={c.id}
                                type="button"
                                onClick={() => handleSelectRegisteredCustomer(c)}
                                className={`w-full px-3 py-2 text-left hover:bg-blue-50/50 flex items-center justify-between gap-2 cursor-pointer ${
                                  isSelected ? 'bg-blue-50/80' : ''
                                }`}
                              >
                                <div className="min-w-0">
                                  <div className="font-bold text-slate-900 truncate flex items-center gap-1.5">
                                    <span>{c.name}</span>
                                    <span className="text-[10px] font-mono px-1.5 py-0.2 rounded bg-slate-100 text-slate-600">
                                      {c.code}
                                    </span>
                                  </div>
                                  <div className="text-[11px] text-slate-500 truncate flex items-center gap-2">
                                    {c.phone && <span>📞 {c.phone}</span>}
                                    {c.company_name && c.company_name !== c.name && (
                                      <span>🏢 {c.company_name}</span>
                                    )}
                                  </div>
                                </div>
                                <div className="text-right shrink-0">
                                  {c.tier?.name && (
                                    <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-blue-50 text-[#2563EB] block">
                                      {c.tier.name}
                                    </span>
                                  )}
                                  {arBalance > 0 && (
                                    <span className="text-[10px] font-bold text-amber-700 block mt-0.5">
                                      Piutang: {formatIDR(arBalance)}
                                    </span>
                                  )}
                                </div>
                              </button>
                            );
                          })
                        )}
                      </div>
                    </div>
                  </>
                )}
              </div>

              {/* Quick Create New Customer Button */}
              <button
                type="button"
                onClick={() => setShowQuickCreateCustomerModal(true)}
                title="Tambah Pelanggan Baru"
                className="px-2.5 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 border border-blue-200 text-[#2563EB] text-xs font-bold transition shrink-0 flex items-center gap-1 cursor-pointer"
              >
                <UserPlus className="w-3.5 h-3.5" />
                <span className="hidden xl:inline">Baru</span>
              </button>

              {/* View Customer Detail, History & AR Settlement Button */}
              {selectedCustomerObj && (
                <button
                  type="button"
                  onClick={() => openCustomerDetailModal(selectedCustomerObj.id)}
                  title="Detail Pelanggan, Riwayat Transaksi & Pelunasan Piutang"
                  className="px-2.5 py-1.5 rounded-lg bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-[#059669] text-xs font-bold transition shrink-0 flex items-center gap-1 cursor-pointer"
                >
                  <FileText className="w-3.5 h-3.5" />
                  <span className="hidden xl:inline">Riwayat & Piutang</span>
                </button>
              )}

              {/* Saved / Held Bills Counter Button */}
              <button
                type="button"
                onClick={() => setShowHeldModal(true)}
                title="Daftar Bill Di-Hold"
                className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 border border-amber-200 text-amber-800 text-xs font-bold shrink-0 cursor-pointer"
              >
                <Clock className="w-3.5 h-3.5 text-amber-600" />
                <span className="tabular-nums">{heldOrders.length}</span>
              </button>

              {/* Clear Cart Button */}
              {cart.length > 0 && (
                <button
                  type="button"
                  onClick={clearCart}
                  title="Kosongkan Keranjang"
                  className="p-1.5 rounded-lg bg-white hover:bg-rose-50 text-slate-400 hover:text-rose-600 border border-slate-200 shrink-0 cursor-pointer"
                >
                  <Trash2 className="w-3.5 h-3.5" />
                </button>
              )}
            </div>

            {/* Compact Tier Price Selector Bar (Auto-applies prices to catalog & cart) */}
            <div className="flex items-center gap-1 bg-[#F8FAFC] p-1 rounded-lg border border-slate-200/80">
              <div className="flex items-center gap-1 px-2 text-[10px] font-extrabold uppercase tracking-wider text-slate-400 shrink-0">
                <Tag className="w-3 h-3 text-[#2563EB]" />
                <span>Tier:</span>
              </div>
              <div className="grid grid-cols-4 gap-1 flex-1">
                {activeTiers.map((tier) => {
                  const isSelected =
                    customerTier === tier.code ||
                    String(tier.id) === customerTier ||
                    (customerTier === 'A' && tier.code === 'TIER_1') ||
                    (customerTier === 'B' && tier.code === 'TIER_2') ||
                    (customerTier === 'C' && tier.code === 'TIER_3') ||
                    (customerTier === 'D' && tier.code === 'TIER_4');
                  const shortLabel = tier.name.replace(/^Tier\s*\d+\s*:\s*/i, '');
                  return (
                    <button
                      key={tier.id || tier.code}
                      type="button"
                      onClick={() => handleSelectCustomerTier(tier.code, tier.id)}
                      title={`${tier.name}${tier.description ? ` — ${tier.description}` : ''}`}
                      className={`py-1 px-2 rounded-md text-[11px] font-bold truncate transition cursor-pointer ${isSelected
                        ? 'bg-[#2563EB] text-white shadow-2xs'
                        : 'text-slate-600 hover:text-slate-900 hover:bg-white'
                        }`}
                    >
                      {shortLabel}
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Optional Manual Walk-in Name / Phone / Table Drawer */}
            {showCustomerDrawer && (
              <div className="grid grid-cols-3 gap-1.5 pt-1">
                <input
                  type="text"
                  value={customerName}
                  onChange={(e) => setCustomerName(e.target.value)}
                  placeholder="Nama Pelanggan"
                  className="px-2.5 py-1.5 rounded-lg bg-[#F8FAFC] border border-slate-200 text-xs text-slate-900 focus:bg-white focus:outline-none focus:border-[#2563EB]"
                />
                <input
                  type="text"
                  value={customerPhone}
                  onChange={(e) => setCustomerPhone(e.target.value)}
                  placeholder="No. HP / WA"
                  className="px-2.5 py-1.5 rounded-lg bg-[#F8FAFC] border border-slate-200 text-xs text-slate-900 focus:bg-white focus:outline-none focus:border-[#2563EB]"
                />
                <input
                  type="text"
                  value={tableLabel}
                  onChange={(e) => setTableLabel(e.target.value)}
                  placeholder="Meja / Ref"
                  className="px-2.5 py-1.5 rounded-lg bg-[#F8FAFC] border border-slate-200 text-xs text-slate-900 focus:bg-white focus:outline-none focus:border-[#2563EB]"
                />
              </div>
            )}
          </div>

          {/* Active Resumed Draft Banner */}
          {activeOrderNumber && (
            <div className="px-3 py-1.5 bg-amber-50 border-b border-amber-200 flex items-center justify-between text-[11px] font-semibold text-amber-800 shrink-0">
              <span>Melanjutkan Draft Bill: {activeOrderNumber}</span>
              <button
                type="button"
                onClick={clearCart}
                className="underline hover:text-amber-950 cursor-pointer"
              >
                Batal
              </button>
            </div>
          )}

          {/* 2. Compact Cart Items List with Easy Quantity Controls */}
          <div className="flex-1 overflow-y-auto divide-y divide-slate-100">
            {cart.length === 0 ? (
              <div className="h-full flex flex-col items-center justify-center text-center text-slate-400 text-xs space-y-2 p-6">
                <div className="w-11 h-11 rounded-2xl bg-[#F8FAFC] border border-slate-200/80 flex items-center justify-center text-slate-400">
                  <ShoppingBag className="w-5 h-5" />
                </div>
                <div className="font-bold text-slate-600">Keranjang Masih Kosong</div>
                <div className="text-[11px] text-slate-400 max-w-[210px]">
                  Klik produk di katalog kiri atau scan barcode untuk menambahkan item.
                </div>
              </div>
            ) : (
              cart.map((item) => {
                const effectiveUnit = item.unit_price_override ?? item.original_unit_price;
                const modsUnit = item.modifiers.reduce((a, m) => a + parseFloat(m.price), 0);
                const lineTotal = Math.round((effectiveUnit + modsUnit) * item.quantity);
                const hasConversion =
                  item.conversion_factor && parseFloat(item.conversion_factor) > 1;

                return (
                  <div
                    key={item.key}
                    className="px-3 py-2.5 hover:bg-[#F8FAFC] transition space-y-1.5"
                  >
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0 flex-1">
                        <div className="text-xs font-bold text-slate-900 truncate">
                          {item.product_name}
                        </div>
                        <div className="flex flex-wrap items-center gap-1 text-[11px] text-slate-500">
                          <span>{item.variant_name}</span>
                          {item.uom_code && (
                            <span className="px-1.5 py-0.2 rounded bg-blue-50 text-[#2563EB] text-[10px] font-bold">
                              {item.uom_code}
                              {hasConversion ? ` (x${parseFloat(item.conversion_factor!)})` : ''}
                            </span>
                          )}
                          <span>&bull;</span>
                          <span className="tabular-nums font-semibold text-slate-700">
                            {formatIDR(effectiveUnit)}
                          </span>
                          {item.unit_price_override !== null &&
                            item.unit_price_override !== undefined && (
                              <span className="text-[10px] text-amber-600 font-bold">
                                (Override)
                              </span>
                            )}
                        </div>
                      </div>

                      {/* Line Total */}
                      <div className="text-right shrink-0 tabular-nums">
                        <div className="text-xs font-extrabold text-slate-900">
                          {formatIDR(lineTotal)}
                        </div>
                      </div>
                    </div>

                    {item.modifiers.length > 0 && (
                      <div className="flex flex-wrap gap-1">
                        {item.modifiers.map((m) => (
                          <span
                            key={m.id}
                            className="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 font-medium"
                          >
                            + {m.name} ({formatIDR(m.price)})
                          </span>
                        ))}
                      </div>
                    )}

                    {item.notes && (
                      <div className="text-[10px] text-amber-700 bg-amber-50 px-2 py-0.5 rounded border border-amber-200/80">
                        Catatan: &ldquo;{item.notes}&rdquo;
                      </div>
                    )}

                    {/* Compact Action & Stepper Row */}
                    <div className="flex items-center justify-between pt-0.5">
                      <button
                        type="button"
                        onClick={() => {
                          setEditingCartKey(item.key);
                          setOverridePriceInput(
                            String(item.unit_price_override ?? item.original_unit_price),
                          );
                          setOverrideReasonInput(item.price_override_reason ?? '');
                          setItemNoteInput(item.notes ?? '');
                        }}
                        className="text-[11px] font-semibold text-slate-500 hover:text-[#2563EB] inline-flex items-center gap-1 cursor-pointer"
                      >
                        <Edit3 className="w-3 h-3" />
                        <span>Harga / Catatan</span>
                      </button>

                      <div className="flex items-center gap-1">
                        <button
                          type="button"
                          onClick={() => updateCartQty(item.key, -1)}
                          aria-label="Kurangi jumlah"
                          className="w-7 h-7 rounded-lg bg-white hover:bg-slate-100 border border-slate-300 flex items-center justify-center text-slate-700 transition cursor-pointer"
                        >
                          <Minus className="w-3.5 h-3.5" />
                        </button>
                        <input
                          type="text"
                          inputMode="numeric"
                          aria-label="Kuantitas item"
                          value={item.quantity}
                          onChange={(e) => {
                            const raw = e.target.value.replace(/\D/g, '');
                            const val = raw ? parseInt(raw, 10) : 0;
                            if (val > 0) {
                              setCart((prev) =>
                                prev.map((c) =>
                                  c.key === item.key ? { ...c, quantity: val } : c,
                                ),
                              );
                            }
                          }}
                          className="w-9 h-7 text-center text-xs font-extrabold text-slate-900 bg-[#F8FAFC] border border-slate-200 rounded-md tabular-nums focus:outline-none focus:border-[#2563EB]"
                        />
                        <button
                          type="button"
                          onClick={() => updateCartQty(item.key, 1)}
                          aria-label="Tambah jumlah"
                          className="w-7 h-7 rounded-lg bg-[#2563EB] hover:bg-blue-700 text-white flex items-center justify-center transition cursor-pointer"
                        >
                          <Plus className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </div>
                  </div>
                );
              })
            )}
          </div>

          {/* 3. Clear Totals Summary & Prominent Payment CTA Footer */}
          <div className="p-3 bg-[#F8FAFC] border-t border-slate-200 space-y-2.5 shrink-0">
            {/* Collapsible Discount Drawer */}
            {showDiscountDrawer && (
              <div className="p-2.5 rounded-xl bg-white border border-slate-200 space-y-2">
                {masterDiscounts.length > 0 && (
                  <div>
                    <div className="text-[10px] font-bold uppercase text-slate-400 mb-1">
                      Voucher / Promo Diskon:
                    </div>
                    <div className="flex flex-wrap gap-1">
                      {masterDiscounts.map((md) => {
                        const minPurch = parseFloat(md.min_purchase || '0');
                        const eligible = totals.subtotal >= minPurch;
                        return (
                          <button
                            key={md.id}
                            type="button"
                            onClick={() => {
                              if (!eligible) {
                                toast({
                                  type: 'warning',
                                  title: 'Belum Memenuhi Minimal Belanja',
                                  description: `Voucher ${md.code} membutuhkan minimal belanja ${formatIDR(minPurch)}.`,
                                });
                                return;
                              }
                              if (
                                md.type === 'percent' &&
                                md.max_discount &&
                                parseFloat(md.max_discount) > 0
                              ) {
                                const rawDisc = Math.round(
                                  (totals.subtotal * parseFloat(md.value)) / 100,
                                );
                                const maxCap = Math.round(parseFloat(md.max_discount));
                                if (rawDisc > maxCap) {
                                  setDiscountType('fixed');
                                  setDiscountValue(String(maxCap));
                                  setDiscountReason(`${md.code} - ${md.name} (Plafon Maks)`);
                                  return;
                                }
                              }
                              setDiscountType(md.type);
                              setDiscountValue(String(parseFloat(md.value)));
                              setDiscountReason(`${md.code} - ${md.name}`);
                            }}
                            className={`px-2 py-1 rounded-md border text-[11px] font-bold transition cursor-pointer ${discountReason.startsWith(md.code)
                              ? 'bg-[#2563EB] text-white border-[#2563EB]'
                              : eligible
                                ? 'bg-blue-50 text-[#2563EB] border-blue-200 hover:bg-blue-100'
                                : 'bg-slate-100 text-slate-400 border-slate-200'
                              }`}
                          >
                            {md.code} (
                            {md.type === 'percent'
                              ? `${parseFloat(md.value)}%`
                              : formatIDR(md.value)}
                            )
                          </button>
                        );
                      })}
                    </div>
                  </div>
                )}

                <div className="grid grid-cols-12 gap-1.5 items-center">
                  <select
                    value={discountType}
                    onChange={(e) =>
                      setDiscountType(e.target.value as 'percent' | 'fixed' | '')
                    }
                    className="col-span-4 px-2 py-1.5 rounded-lg bg-[#F8FAFC] border border-slate-300 text-xs font-semibold text-slate-800"
                  >
                    <option value="">Tanpa Diskon</option>
                    <option value="percent">Diskon %</option>
                    <option value="fixed">Diskon Rp</option>
                  </select>
                  <input
                    type="number"
                    min={0}
                    disabled={!discountType}
                    value={discountValue}
                    onChange={(e) => setDiscountValue(e.target.value)}
                    placeholder={discountType === 'percent' ? '%' : 'Rp'}
                    className="col-span-3 px-2 py-1.5 rounded-lg bg-[#F8FAFC] border border-slate-300 text-xs font-bold text-slate-900 disabled:opacity-40"
                  />
                  <input
                    type="text"
                    disabled={!discountType}
                    value={discountReason}
                    onChange={(e) => setDiscountReason(e.target.value)}
                    placeholder="Keterangan diskon"
                    className="col-span-5 px-2 py-1.5 rounded-lg bg-[#F8FAFC] border border-slate-300 text-xs text-slate-900 disabled:opacity-40"
                  />
                </div>
              </div>
            )}

            {totals.requiresSupervisorPin && (
              <div className="px-2.5 py-1.5 rounded-lg bg-amber-50 border border-amber-200 text-[11px] font-semibold text-amber-800">
                Otorisasi PIN Supervisor diperlukan saat bayar (Diskon/Override Harga)
              </div>
            )}

            {/* Clear Subtotal, Discount & Grand Total Breakdown */}
            <div className="space-y-1 text-xs tabular-nums">
              <div className="flex justify-between text-slate-600">
                <span>Subtotal ({totalCartItems} item)</span>
                <span className="font-semibold text-slate-900">
                  {formatIDR(totals.subtotal)}
                </span>
              </div>

              <div className="flex justify-between items-center text-slate-600">
                <button
                  type="button"
                  onClick={() => setShowDiscountDrawer((prev) => !prev)}
                  className="text-[#2563EB] hover:text-blue-800 font-semibold inline-flex items-center gap-1 cursor-pointer"
                >
                  <Percent className="w-3 h-3" />
                  <span>
                    {totals.discountTotal > 0 ? 'Ubah Diskon' : 'Tambah Diskon'}
                  </span>
                </button>
                <span
                  className={
                    totals.discountTotal > 0 ? 'font-bold text-rose-600' : 'text-slate-400'
                  }
                >
                  -{formatIDR(totals.discountTotal)}
                </span>
              </div>

              {parseFloat(outlet.service_charge_rate) > 0 && (
                <div className="flex justify-between text-slate-500">
                  <span>Service ({parseFloat(outlet.service_charge_rate)}%)</span>
                  <span>{formatIDR(totals.serviceChargeTotal)}</span>
                </div>
              )}

              {parseFloat(outlet.tax_rate) > 0 && (
                <div className="flex justify-between text-slate-500">
                  <span>PPN ({parseFloat(outlet.tax_rate)}%)</span>
                  <span>{formatIDR(totals.taxTotal)}</span>
                </div>
              )}

              <div className="flex justify-between items-baseline pt-2 border-t border-slate-200">
                <span className="text-xs font-extrabold uppercase tracking-wider text-slate-700">
                  Grand Total
                </span>
                <span className="text-xl font-black text-slate-900">
                  {formatIDR(totals.grandTotal)}
                </span>
              </div>
            </div>

            {/* Hold Bill & Prominent Payment CTA Buttons */}
            <div className="grid grid-cols-12 gap-2 pt-0.5">
              <button
                type="button"
                disabled={cart.length === 0}
                onClick={handleHoldOrder}
                className="col-span-4 py-3 px-3 rounded-xl bg-white hover:bg-slate-100 disabled:opacity-45 disabled:cursor-not-allowed border border-slate-300 text-xs font-bold text-slate-700 flex items-center justify-center gap-1.5 transition cursor-pointer"
              >
                <PauseCircle className="w-4 h-4 text-amber-600 shrink-0" />
                <span>Hold Bill</span>
              </button>

              <button
                type="button"
                disabled={cart.length === 0}
                onClick={() => {
                  if (!hasOpenShift) {
                    onRequireOpenShift();
                    return;
                  }
                  setShowPaymentModal(true);
                }}
                className="col-span-8 py-3 px-4 rounded-xl bg-[#059669] hover:bg-emerald-700 disabled:opacity-45 disabled:cursor-not-allowed text-white font-extrabold text-sm shadow-sm flex items-center justify-between transition cursor-pointer"
              >
                <span className="inline-flex items-center gap-1.5">
                  <Sparkles className="w-4 h-4" />
                  <span>Bayar Sekarang</span>
                </span>
                <span className="tabular-nums text-base font-black">
                  {formatIDR(totals.grandTotal)}
                </span>
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* Variant, Packaging Unit & Modifier Selection Modal */}
      <Modal
        isOpen={configuringProduct !== null}
        onClose={() => setConfiguringProduct(null)}
        title={configuringProduct?.name ?? 'Pilih Varian & Satuan'}
        subtitle="Pilih varian, satuan kemasan (Botol / Pack / Dus), atau catatan pesanan"
        maxWidth="lg"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setConfiguringProduct(null)}>
              Batal
            </Button>
            <Button
              variant="primary"
              onClick={() => {
                if (!configuringProduct) return;
                const v = configuringProduct.variants.find((pv) => pv.id === chosenVariantId);
                const u =
                  chosenUnitId && configuringProduct.product_units
                    ? configuringProduct.product_units.find((pu) => pu.id === chosenUnitId) ?? null
                    : null;
                const mods = configuringProduct.modifiers.filter((m) =>
                  chosenModifierIds.includes(m.id),
                );
                if (v) {
                  addVariantToCart(configuringProduct, v, mods, chosenItemNotes, u);
                }
                setConfiguringProduct(null);
              }}
            >
              Tambahkan ke Keranjang
            </Button>
          </div>
        }
      >
        {configuringProduct && (
          <div className="space-y-4">
            <div>
              <label className="block text-xs font-bold uppercase text-slate-500 mb-2">
                Pilih Varian
              </label>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                {configuringProduct.variants.map((v) => (
                  <button
                    type="button"
                    key={v.id}
                    disabled={!v.is_available}
                    onClick={() => setChosenVariantId(v.id)}
                    className={`p-3 rounded-xl border text-left transition cursor-pointer ${chosenVariantId === v.id
                      ? 'bg-blue-50 border-[#2563EB] text-slate-900 ring-2 ring-[#2563EB]/15'
                      : 'bg-white border-slate-200 text-slate-700 hover:border-slate-300'
                      } ${!v.is_available ? 'opacity-40 cursor-not-allowed' : ''}`}
                  >
                    <div className="text-xs font-bold">{v.name}</div>
                    {(() => {
                      const vTierPrice = resolveVariantPriceForTier(
                        v,
                        customerTier,
                        selectedTier?.id,
                      );
                      const vBasePrice = parseFloat(v.base_price || v.effective_price);
                      const hasDiff = vTierPrice !== vBasePrice;
                      return (
                        <div className="flex items-baseline gap-1.5 mt-0.5">
                          <span className="text-xs text-[#2563EB] font-extrabold tabular-nums">
                            {formatIDR(vTierPrice)}
                          </span>
                          {hasDiff && (
                            <span className="text-[10px] text-slate-400 line-through tabular-nums">
                              {formatIDR(vBasePrice)}
                            </span>
                          )}
                        </div>
                      );
                    })()}
                    <div className="text-[10px] text-slate-500 mt-0.5">
                      Stok: {formatQty(v.stock_quantity)} {configuringProduct.unit}
                    </div>
                  </button>
                ))}
              </div>
            </div>

            {configuringProduct.product_units &&
              configuringProduct.product_units.filter((u) => u.is_active && u.is_sales_unit)
                .length > 0 && (
                <div>
                  <label className="block text-xs font-bold uppercase text-slate-500 mb-2">
                    Pilih Satuan Penjualan / Kemasan
                  </label>
                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    {configuringProduct.product_units
                      .filter((u) => u.is_active && u.is_sales_unit)
                      .map((u) => {
                        const activeVar =
                          configuringProduct.variants.find((pv) => pv.id === chosenVariantId) ??
                          configuringProduct.variants[0];
                        const unitPrice = resolveVariantPriceForTier(
                          activeVar,
                          customerTier,
                          selectedTier?.id,
                          u,
                        );
                        const isSelected =
                          chosenUnitId === u.id || (chosenUnitId === null && u.is_base);
                        return (
                          <button
                            key={u.id}
                            type="button"
                            onClick={() => setChosenUnitId(u.id)}
                            className={`p-2.5 rounded-xl border text-left transition cursor-pointer ${isSelected
                              ? 'bg-blue-50 border-[#2563EB] text-slate-900 ring-2 ring-[#2563EB]/15'
                              : 'bg-white border-slate-200 text-slate-700 hover:border-slate-300'
                              }`}
                          >
                            <div className="flex items-center justify-between">
                              <span className="text-xs font-bold">{u.unit_name}</span>
                              <span className="text-[10px] font-mono font-bold px-1.5 py-0.2 rounded bg-slate-100 text-slate-600">
                                {u.unit_code}
                              </span>
                            </div>
                            <div className="text-xs font-extrabold text-[#2563EB] tabular-nums mt-1">
                              {formatIDR(unitPrice)}
                            </div>
                            <div className="text-[10px] text-slate-500">
                              Isi: {parseFloat(u.conversion_factor)} {configuringProduct.unit}
                            </div>
                          </button>
                        );
                      })}
                  </div>
                </div>
              )}

            {configuringProduct.modifiers.length > 0 && (
              <div>
                <label className="block text-xs font-bold uppercase text-slate-500 mb-2">
                  Add-On / Modifiers (Opsional)
                </label>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  {configuringProduct.modifiers.map((m) => {
                    const selected = chosenModifierIds.includes(m.id);
                    return (
                      <button
                        type="button"
                        key={m.id}
                        onClick={() =>
                          setChosenModifierIds((prev) =>
                            selected ? prev.filter((id) => id !== m.id) : [...prev, m.id],
                          )
                        }
                        className={`p-2.5 rounded-xl border text-left text-xs transition cursor-pointer ${selected
                          ? 'bg-blue-50 border-blue-600 text-slate-900'
                          : 'bg-white border-slate-200 text-slate-700'
                          }`}
                      >
                        <div className="font-bold">{m.name}</div>
                        <div className="text-[11px] text-blue-600 font-semibold">
                          +{formatIDR(m.price)}
                        </div>
                      </button>
                    );
                  })}
                </div>
              </div>
            )}

            <div>
              <label className="block text-xs font-bold uppercase text-slate-500 mb-1">
                Catatan Item
              </label>
              <input
                type="text"
                value={chosenItemNotes}
                onChange={(e) => setChosenItemNotes(e.target.value)}
                placeholder="Tambahkan catatan item..."
                className="w-full px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-xs text-slate-900"
              />
            </div>
          </div>
        )}
      </Modal>

      {/* Edit Cart Item (Notes & Price Override) Modal */}
      <Modal
        isOpen={editingItem !== null}
        onClose={() => setEditingCartKey(null)}
        title={
          editingItem
            ? `Ubah Item • ${editingItem.product_name} (${editingItem.variant_name})`
            : 'Ubah Item'
        }
        subtitle={
          editingItem
            ? `Harga Standar Outlet: ${formatIDR(editingItem.original_unit_price)}`
            : undefined
        }
        maxWidth="md"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setEditingCartKey(null)}>
              Batal
            </Button>
            <Button
              variant="primary"
              onClick={() => {
                if (!editingItem) return;
                const newPrice = parseFloat(overridePriceInput || '0');
                setCart((prev) =>
                  prev.map((c) =>
                    c.key === editingItem.key
                      ? {
                        ...c,
                        unit_price_override:
                          newPrice !== c.original_unit_price ? newPrice : null,
                        price_override_reason: overrideReasonInput || undefined,
                        notes: itemNoteInput || undefined,
                      }
                      : c,
                  ),
                );
                setEditingCartKey(null);
              }}
            >
              Simpan Perubahan
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1">
              Harga Satuan Baru (Price Override — IDR)
            </label>
            <input
              type="number"
              min={0}
              value={overridePriceInput}
              onChange={(e) => setOverridePriceInput(e.target.value)}
              className="w-full px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-sm font-bold text-slate-900"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1">
              Alasan Override Harga
            </label>
            <input
              type="text"
              value={overrideReasonInput}
              onChange={(e) => setOverrideReasonInput(e.target.value)}
              placeholder="Contoh: Promo member / penyesuaian khusus"
              className="w-full px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-xs text-slate-900"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1">
              Catatan Item
            </label>
            <input
              type="text"
              value={itemNoteInput}
              onChange={(e) => setItemNoteInput(e.target.value)}
              placeholder="Tambahkan catatan item..."
              className="w-full px-3.5 py-2 rounded-lg bg-white border border-slate-300 text-xs text-slate-900"
            />
          </div>
        </div>
      </Modal>

      {/* Held Orders (Saved Bills across Terminals) Modal */}
      <Modal
        isOpen={showHeldModal}
        onClose={() => setShowHeldModal(false)}
        title={`Daftar Bill Di-Hold (Outlet ${outlet.code})`}
        subtitle="Bill yang disimpan dari terminal mana pun di outlet ini dapat dilanjutkan (Resume)"
        maxWidth="2xl"
      >
        <div className="space-y-3">
          {heldOrders.length === 0 ? (
            <div className="text-center py-8 text-xs text-slate-500">
              Tidak ada bill yang sedang di-hold pada outlet ini.
            </div>
          ) : (
            heldOrders.map((h) => (
              <div
                key={h.id}
                className="p-4 rounded-xl bg-slate-50 border border-slate-200 flex flex-wrap items-center justify-between gap-4"
              >
                <div className="space-y-1">
                  <div className="flex items-center gap-2">
                    <span className="font-mono text-xs font-bold text-blue-600">
                      {h.order_number}
                    </span>
                    {h.table_label && (
                      <Badge variant="warning">Meja: {h.table_label}</Badge>
                    )}
                    <Badge variant="neutral">
                      Terminal: {h.terminal?.code ?? `#${h.pos_terminal_id}`}
                    </Badge>
                  </div>
                  <div className="text-xs text-slate-900 font-bold">
                    Pelanggan: {h.customer_name || 'Walk-in Guest'} &bull;{' '}
                    <span className="text-blue-600">{formatIDR(h.grand_total)}</span>
                  </div>
                  <div className="text-[11px] text-slate-500">
                    {h.items.map((i) => `${parseFloat(i.quantity)}x ${i.product_name}`).join(', ')}
                  </div>
                  <div className="text-[10px] text-slate-400">
                    Disimpan pada {formatDateTime(h.held_at)}
                  </div>
                </div>

                <Button
                  variant="primary"
                  size="sm"
                  icon={<PlayCircle className="w-4 h-4" />}
                  onClick={() => handleResumeHeldOrder(h)}
                >
                  Lanjutkan Bill
                </Button>
              </div>
            ))
          )}
        </div>
      </Modal>

      {/* Moka Charge & Split Payment Modal */}
      <SplitPaymentModal
        isOpen={showPaymentModal}
        grandTotal={totals.grandTotal}
        requiresSupervisorPin={totals.requiresSupervisorPin}
        selectedCustomer={selectedCustomerObj}
        onRequestSelectCustomer={() => setShowCustomerSelectorMenu(true)}
        onClose={() => setShowPaymentModal(false)}
        onSubmitCheckout={handleSubmitCheckout}
        onOrderCompleted={(order) => setCompletedReceiptOrder(order)}
      />

      {/* Quick Create Customer Modal (Nama wajib, No HP wajib, Nama Usaha, Alamat, NIK/KTP opsional) */}
      <Modal
        isOpen={showQuickCreateCustomerModal}
        onClose={() => setShowQuickCreateCustomerModal(false)}
        title="Tambah Pelanggan Baru (POS)"
        subtitle="Pelanggan otomatis terhubung ke outlet aktif dan dipilih untuk transaksi saat ini"
        maxWidth="md"
      >
        <form onSubmit={handleQuickCreateCustomer} className="space-y-3.5">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Nama Pelanggan <span className="text-rose-600">*</span>
              </label>
              <input
                type="text"
                required
                value={newCustName}
                onChange={(e) => setNewCustName(e.target.value)}
                placeholder="Contoh: Budi Santoso"
                className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs text-slate-900 focus:outline-none focus:border-[#2563EB]"
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Nomor HP / WhatsApp <span className="text-rose-600">*</span>
              </label>
              <input
                type="text"
                required
                value={newCustPhone}
                onChange={(e) => setNewCustPhone(e.target.value)}
                placeholder="Contoh: 081234567890"
                className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs text-slate-900 focus:outline-none focus:border-[#2563EB]"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Nama Usaha / Toko (Opsional)
              </label>
              <input
                type="text"
                value={newCustCompany}
                onChange={(e) => setNewCustCompany(e.target.value)}
                placeholder="Contoh: Toko Berkah Minuman"
                className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs text-slate-900 focus:outline-none focus:border-[#2563EB]"
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                NIK / KTP (Opsional — Terlindungi)
              </label>
              <input
                type="text"
                maxLength={20}
                value={newCustNik}
                onChange={(e) => setNewCustNik(e.target.value.replace(/\D/g, ''))}
                placeholder="16 digit NIK KTP (disamarkan otomatis)"
                className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs font-mono text-slate-900 focus:outline-none focus:border-[#2563EB]"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">
              Alamat Lengkap (Opsional)
            </label>
            <input
              type="text"
              value={newCustAddress}
              onChange={(e) => setNewCustAddress(e.target.value)}
              placeholder="Alamat toko / pengiriman pelanggan..."
              className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs text-slate-900 focus:outline-none focus:border-[#2563EB]"
            />
          </div>

          <div className="flex justify-end gap-2 pt-2 border-t border-slate-200">
            <Button
              type="button"
              variant="outline"
              onClick={() => setShowQuickCreateCustomerModal(false)}
            >
              Batal
            </Button>
            <Button type="submit" variant="primary" loading={submittingNewCustomer}>
              Simpan & Pilih Pelanggan
            </Button>
          </div>
        </form>
      </Modal>

      {/* Customer Detail, Transaction History, Installments & AR Settlement Modal */}
      <Modal
        isOpen={showCustomerDetailModal}
        onClose={() => setShowCustomerDetailModal(false)}
        title={
          customerDetailData
            ? `Profil Pelanggan & Piutang • ${customerDetailData.customer.name}`
            : 'Memuat Detail Pelanggan...'
        }
        subtitle="Identitas pelanggan, riwayat invoice DP/Tempo, histori cicilan terpisah & pelunasan piutang"
        maxWidth="3xl"
      >
        {loadingCustomerDetail || !customerDetailData ? (
          <div className="py-12 text-center text-xs text-slate-500">
            Memuat data transaksi dan riwayat pembayaran pelanggan...
          </div>
        ) : (
          <div className="space-y-5 text-xs">
            {/* Customer Identity & NIK Protection Card */}
            <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <div className="text-[10px] font-bold uppercase text-slate-400">
                  Identitas & Usaha
                </div>
                <div className="text-sm font-extrabold text-slate-900 mt-0.5">
                  {customerDetailData.customer.name}
                </div>
                <div className="text-slate-600 font-mono text-[11px]">
                  ID: {customerDetailData.customer.code}
                </div>
                {customerDetailData.customer.company_name && (
                  <div className="text-slate-600 flex items-center gap-1 mt-0.5">
                    <Building2 className="w-3 h-3 text-slate-400" />
                    <span>{customerDetailData.customer.company_name}</span>
                  </div>
                )}
              </div>

              <div>
                <div className="text-[10px] font-bold uppercase text-slate-400">
                  Kontak & NIK/KTP (Masked)
                </div>
                <div className="font-semibold text-slate-800 flex items-center gap-1 mt-0.5">
                  <Phone className="w-3 h-3 text-[#2563EB]" />
                  <span>{customerDetailData.customer.phone || '-'}</span>
                </div>
                <div className="text-slate-600 mt-0.5 truncate">
                  Alamat: {customerDetailData.customer.address || '-'}
                </div>
                <div className="flex items-center gap-1.5 mt-1">
                  <span className="text-slate-500">NIK/KTP:</span>
                  <span className="font-mono font-bold text-slate-800">
                    {revealedNik || customerDetailData.customer.masked_nik || 'Tidak diisi'}
                  </span>
                  {customerDetailData.customer.has_nik &&
                    customerDetailData.customer.can_reveal_nik && (
                      <button
                        type="button"
                        onClick={() => {
                          if (revealedNik) {
                            setRevealedNik(null);
                          } else {
                            openCustomerDetailModal(customerDetailData.customer.id, true);
                          }
                        }}
                        className="text-[#2563EB] hover:underline inline-flex items-center gap-0.5 font-semibold cursor-pointer"
                      >
                        {revealedNik ? (
                          <>
                            <EyeOff className="w-3 h-3" />
                            <span>Sembunyikan</span>
                          </>
                        ) : (
                          <>
                            <Eye className="w-3 h-3" />
                            <span>Lihat</span>
                          </>
                        )}
                      </button>
                    )}
                </div>
              </div>

              <div className="sm:text-right">
                <div className="text-[10px] font-bold uppercase text-slate-400">
                  Total Saldo Piutang Aktif
                </div>
                <div className="text-lg font-black text-amber-700 tabular-nums mt-0.5">
                  {formatIDR(customerDetailData.customer.outstanding_balance)}
                </div>
                <div className="text-[11px] text-slate-500">
                  Plafon Kredit: {formatIDR(customerDetailData.customer.credit_limit)} • Termin:{' '}
                  {customerDetailData.customer.payment_term_days} Hari
                </div>
              </div>
            </div>

            {/* Invoices & Receivable Settlement Section */}
            <div className="space-y-2">
              <div className="font-extrabold text-slate-800 uppercase tracking-wider text-[11px]">
                Daftar Invoice & Pelunasan Piutang ({customerDetailData.invoices.length})
              </div>
              {customerDetailData.invoices.length === 0 ? (
                <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center text-slate-400">
                  Belum ada invoice untuk pelanggan ini.
                </div>
              ) : (
                <div className="space-y-2 max-h-64 overflow-y-auto pr-1">
                  {customerDetailData.invoices.map((inv) => {
                    const balanceDue = parseFloat(inv.balance_due || '0');
                    const isSettling = settlingInvoiceId === inv.id;
                    return (
                      <div
                        key={inv.id}
                        className="p-3 rounded-xl bg-white border border-slate-200 space-y-2"
                      >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <div>
                            <div className="flex items-center gap-2">
                              <span className="font-mono font-extrabold text-slate-900">
                                {inv.invoice_number}
                              </span>
                              <Badge
                                variant={
                                  inv.status === 'paid'
                                    ? 'success'
                                    : inv.status === 'partial'
                                      ? 'info'
                                      : 'warning'
                                }
                              >
                                {inv.status.toUpperCase()}
                              </Badge>
                              {inv.outlet && (
                                <span className="text-[10px] px-1.5 py-0.2 rounded bg-slate-100 text-slate-600">
                                  {inv.outlet.name}
                                </span>
                              )}
                            </div>
                            <div className="text-[11px] text-slate-500 mt-0.5">
                              Terbit: {inv.issue_date} • Jatuh Tempo:{' '}
                              <strong className="text-slate-800">{inv.due_date}</strong>
                            </div>
                          </div>

                          <div className="flex items-center gap-4">
                            <div className="text-right tabular-nums">
                              <div className="text-[10px] text-slate-400">
                                Tagihan: {formatIDR(inv.grand_total)} • Dibayar:{' '}
                                <span className="text-[#059669] font-bold">
                                  {formatIDR(inv.paid_total)}
                                </span>
                              </div>
                              <div className="text-xs font-extrabold text-rose-600">
                                Sisa Piutang: {formatIDR(inv.balance_due)}
                              </div>
                            </div>

                            {balanceDue > 0 && inv.status !== 'paid' && (
                              <Button
                                type="button"
                                variant={isSettling ? 'outline' : 'primary'}
                                size="xs"
                                onClick={() => {
                                  if (isSettling) {
                                    setSettlingInvoiceId(null);
                                  } else {
                                    setSettlingInvoiceId(inv.id);
                                    setSettleAmountInput(String(Math.round(balanceDue)));
                                    setSettleRefInput(`PAY-${inv.invoice_number}`);
                                  }
                                }}
                              >
                                {isSettling ? 'Tutup' : 'Bayar / Cicil'}
                              </Button>
                            )}
                          </div>
                        </div>

                        {/* Separate Payment Allocations History per Invoice */}
                        {inv.allocations && inv.allocations.length > 0 && (
                          <div className="pt-1.5 border-t border-slate-100 space-y-1">
                            <div className="text-[10px] font-bold text-slate-400 uppercase">
                              Histori Pembayaran / Cicilan Invoice Ini:
                            </div>
                            {inv.allocations.map((alloc) => (
                              <div
                                key={alloc.id}
                                className="flex items-center justify-between text-[11px] bg-slate-50 px-2.5 py-1 rounded"
                              >
                                <span>
                                  <strong className="font-mono">
                                    {alloc.customer_payment?.payment_number}
                                  </strong>{' '}
                                  • {alloc.customer_payment?.payment_date} •{' '}
                                  <span className="uppercase font-semibold">
                                    {alloc.customer_payment?.method}
                                  </span>
                                  {alloc.customer_payment?.notes
                                    ? ` (${alloc.customer_payment.notes})`
                                    : ''}
                                </span>
                                <span className="font-bold text-[#059669] tabular-nums">
                                  +{formatIDR(alloc.amount)}
                                </span>
                              </div>
                            ))}
                          </div>
                        )}

                        {/* Inline AR Settlement Form */}
                        {isSettling && (
                          <form
                            onSubmit={(e) => handleSettleCustomerInvoice(e, inv.id)}
                            className="p-3 rounded-lg bg-blue-50/70 border border-blue-200 grid grid-cols-1 sm:grid-cols-12 gap-2 items-end"
                          >
                            <div className="sm:col-span-4">
                              <label className="block text-[10px] font-bold text-slate-700 mb-1">
                                Nominal Bayar (Maks {formatIDR(balanceDue)})
                              </label>
                              <input
                                type="number"
                                min={1}
                                max={balanceDue}
                                step="any"
                                required
                                value={settleAmountInput}
                                onChange={(e) => setSettleAmountInput(e.target.value)}
                                className="w-full px-2.5 py-1.5 rounded bg-white border border-blue-300 text-xs font-bold text-slate-900"
                              />
                            </div>
                            <div className="sm:col-span-3">
                              <label className="block text-[10px] font-bold text-slate-700 mb-1">
                                Metode
                              </label>
                              <select
                                value={settleMethodInput}
                                onChange={(e) =>
                                  setSettleMethodInput(
                                    e.target.value as 'cash' | 'bank_transfer' | 'qris',
                                  )
                                }
                                className="w-full px-2.5 py-1.5 rounded bg-white border border-blue-300 text-xs font-semibold text-slate-900"
                              >
                                <option value="cash">Tunai (Cash)</option>
                                <option value="bank_transfer">Transfer Bank</option>
                                <option value="qris">QRIS</option>
                              </select>
                            </div>
                            <div className="sm:col-span-3">
                              <label className="block text-[10px] font-bold text-slate-700 mb-1">
                                No. Referensi
                              </label>
                              <input
                                type="text"
                                value={settleRefInput}
                                onChange={(e) => setSettleRefInput(e.target.value)}
                                placeholder="Ref / Bukti"
                                className="w-full px-2.5 py-1.5 rounded bg-white border border-blue-300 text-xs text-slate-900"
                              />
                            </div>
                            <div className="sm:col-span-2">
                              <Button
                                type="submit"
                                variant="primary"
                                size="sm"
                                loading={submittingSettle}
                                className="w-full"
                              >
                                Simpan
                              </Button>
                            </div>
                          </form>
                        )}
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            {/* POS Transactions History Section */}
            <div className="space-y-2">
              <div className="font-extrabold text-slate-800 uppercase tracking-wider text-[11px]">
                Riwayat Transaksi POS Pelanggan ({customerDetailData.orders.length})
              </div>
              {customerDetailData.orders.length === 0 ? (
                <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 text-center text-slate-400">
                  Belum ada riwayat transaksi POS.
                </div>
              ) : (
                <div className="max-h-48 overflow-y-auto divide-y divide-slate-100 border border-slate-200 rounded-xl bg-white">
                  {customerDetailData.orders.map((ord) => (
                    <div
                      key={ord.id}
                      className="px-3 py-2 flex items-center justify-between gap-2"
                    >
                      <div>
                        <div className="font-mono font-bold text-slate-900">
                          {ord.order_number}
                          {ord.invoice && (
                            <span className="ml-2 text-[10px] font-mono text-[#2563EB]">
                              ({ord.invoice.invoice_number} • {ord.invoice.status.toUpperCase()})
                            </span>
                          )}
                        </div>
                        <div className="text-[10px] text-slate-500">
                          {formatDateTime(ord.completed_at || ord.created_at)} •{' '}
                          {ord.items.map((i) => `${parseFloat(i.quantity)}x ${i.product_name}`).join(', ')}
                        </div>
                      </div>
                      <div className="text-right tabular-nums shrink-0">
                        <div className="font-extrabold text-slate-900">
                          {formatIDR(ord.grand_total)}
                        </div>
                        <div className="text-[10px] text-slate-500">
                          Dibayar: {formatIDR(ord.paid_total)}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}
      </Modal>

      {/* Post-Checkout Thermal Receipt Modal */}
      <ReceiptModal
        isOpen={completedReceiptOrder !== null}
        order={completedReceiptOrder}
        outlet={outlet}
        isPostCheckout
        onClose={() => setCompletedReceiptOrder(null)}
      />
    </div>
  );
};
