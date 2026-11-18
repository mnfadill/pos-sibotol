import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  Barcode,
  Clock,
  Edit3,
  Minus,
  PauseCircle,
  Percent,
  PlayCircle,
  Plus,
  Search,
  ShoppingBag,
  Sparkles,
  Trash2,
  UserCheck,
  UserPlus,
  Users,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime, formatIDR, formatQty } from '../../lib/format';
import type {
  CartItem,
  Category,
  DynamicTier,
  MasterDiscountRecord,
  OrderRecord,
  OutletInfo,
  PaymentLegInput,
  PricingRulesResponse,
  ProductCatalog,
  ProductModifier,
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

  const [categories, setCategories] = useState<Category[]>([]);
  const [products, setProducts] = useState<ProductCatalog[]>([]);
  const [heldOrders, setHeldOrders] = useState<OrderRecord[]>([]);
  const [masterDiscounts, setMasterDiscounts] = useState<MasterDiscountRecord[]>([]);
  const [selectedCategoryId, setSelectedCategoryId] = useState<number>(0);
  const [searchTerm, setSearchTerm] = useState<string>('');
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

  // Helper to accurately lookup tier price
  const resolveVariantPriceForTier = (
    variant: ProductVariantCatalog | undefined,
    targetTierCode: string,
    targetTierId?: number,
  ): number => {
    if (!variant) return 0;
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
      pricing_tier?: string;
      tier_id?: number | null;
      tier?: { id: number; code: string; name: string } | null;
    }>
  >([]);
  const [selectedCustomerId, setSelectedCustomerId] = useState<number | null>(null);

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
  const [chosenModifierIds, setChosenModifierIds] = useState<number[]>([]);
  const [chosenItemNotes, setChosenItemNotes] = useState<string>('');

  const [editingCartKey, setEditingCartKey] = useState<string | null>(null);
  const [overridePriceInput, setOverridePriceInput] = useState<string>('');
  const [overrideReasonInput, setOverrideReasonInput] = useState<string>('');
  const [itemNoteInput, setItemNoteInput] = useState<string>('');

  const [showHeldModal, setShowHeldModal] = useState<boolean>(false);
  const [showPaymentModal, setShowPaymentModal] = useState<boolean>(false);
  const [completedReceiptOrder, setCompletedReceiptOrder] = useState<OrderRecord | null>(null);

  useEffect(() => {
    loadCatalogAndHeld();
  }, [outlet.id]);

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
    // Instant dynamic price recalculation on existing cart items
    setCart((prevCart) =>
      prevCart.map((item) => {
        const prod = products.find((p) => p.id === item.product_id);
        const variant = prod?.variants.find((v) => v.id === item.product_variant_id);
        if (variant) {
          const newUnitPrice = resolveVariantPriceForTier(variant, newTierCode, newTierId);
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

  const loadCatalogAndHeld = async (tier: string = customerTier) => {
    setLoadingCatalog(true);
    try {
      const [catRes, prodRes, heldRes, rulesRes, tiersRes, custRes] = await Promise.all([
        api.get<{ categories: Category[] }>('/catalog/categories'),
        api.get<{ products: ProductCatalog[] }>('/catalog/products', {
          params: { customer_tier: tier },
        }),
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
              pricing_tier?: string;
              tier_id?: number | null;
              tier?: { id: number; code: string; name: string } | null;
            }>;
          }>('/customers')
          .catch(() => ({ data: { customers: [] } })),
      ]);
      setCategories(catRes.data.categories);
      setProducts(prodRes.data.products);
      setHeldOrders(heldRes.data.orders);
      setMasterDiscounts(
        (rulesRes.data.discounts ?? []).filter(
          (d) => d.is_active && (d.applies_to === 'both' || d.applies_to === 'pos'),
        ),
      );
      if (tiersRes.data?.tiers && tiersRes.data.tiers.length > 0) {
        setDynamicTiers(tiersRes.data.tiers);
      }
      if (custRes.data?.customers) {
        setRegisteredCustomers(custRes.data.customers);
      }
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat katalog',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoadingCatalog(false);
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
        p.variants.some(
          (v) =>
            v.sku.toLowerCase().includes(q) ||
            (v.barcode && v.barcode.toLowerCase().includes(q)),
        )
      );
    });
  }, [products, selectedCategoryId, searchTerm]);

  const addVariantToCart = (
    product: ProductCatalog,
    variant: ProductVariantCatalog,
    modifiers: ProductModifier[] = [],
    notes = '',
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
    const key = `${variant.id}:${modKey}:${notes.trim()}`;

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
          product_name: product.name,
          variant_name: variant.name,
          sku: variant.sku,
          quantity: 1,
          original_unit_price: resolveVariantPriceForTier(
            variant,
            customerTier,
            selectedTier?.id,
          ),
          unit_price_override: null,
          customer_tier: customerTier,
          modifiers,
          notes: notes.trim() || undefined,
        },
      ];
    });
  };

  const handleProductCardClick = (product: ProductCatalog) => {
    if (product.variants.length === 1 && product.modifiers.length === 0) {
      addVariantToCart(product, product.variants[0]);
      return;
    }
    const firstAvailable =
      product.variants.find((v) => v.is_available) ?? product.variants[0];
    setConfiguringProduct(product);
    setChosenVariantId(firstAvailable?.id ?? 0);
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
          name: string;
          sku: string;
          barcode: string | null;
          base_price: string;
          price_override: string | null;
          effective_price: string;
          is_available: boolean;
          stock_quantity: string;
        };
      }>(`/catalog/barcode/${encodeURIComponent(code)}`);

      const v = res.data.variant;
      const prod = products.find((p) => p.id === v.product_id);
      if (prod) {
        const variantObj = prod.variants.find((pv) => pv.id === v.id) ?? {
          ...v,
          is_default: true,
          reorder_level: '0',
        };
        addVariantToCart(prod, variantObj);
        setBarcodeInput('');
        toast({
          type: 'success',
          title: 'Barcode Terbaca',
          description: `${v.product_name} (${v.name}) ditambahkan ke keranjang.`,
        });
      }
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

  const handleSubmitCheckout = async (
    payments: PaymentLegInput[],
    idempotencyKey: string,
    supervisorPin?: string,
  ): Promise<OrderRecord> => {
    const payload = {
      ...buildOrderPayload(),
      supervisor_pin: supervisorPin || undefined,
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

  return (
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-4 p-4 h-[calc(100vh-61px)] overflow-hidden">
      {/* LEFT PANE: Moka Product Discovery & Barcode Bar (7/8 cols) */}
      <div className="lg:col-span-7 xl:col-span-8 flex flex-col gap-3 h-full overflow-hidden">
        {/* DYNAMIC MASTER CUSTOMER TIERS SELECTOR */}
        <div className="bg-white p-3 rounded-xl border border-slate-200 shadow-2xs shrink-0">
          <div className="flex items-center justify-between mb-2 px-0.5">
            <div className="flex items-center gap-2">
              <span className="w-2.5 h-2.5 rounded-full bg-blue-600 animate-pulse" />
              <span className="text-xs font-black uppercase tracking-wider text-slate-800">
                Pilih Tipe Customer & Master Harga
              </span>
            </div>
            <span className="text-[11px] font-semibold text-slate-500">
              🏷️ Tier Aktif:{' '}
              <strong className="text-slate-800">
                {selectedTier?.name || customerTier}
              </strong>
              {selectedTier?.description ? ` • ${selectedTier.description}` : ''}
            </span>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
            {activeTiers.map((tier) => {
              const isSelected =
                customerTier === tier.code ||
                String(tier.id) === customerTier ||
                (customerTier === 'A' && tier.code === 'TIER_1') ||
                (customerTier === 'B' && tier.code === 'TIER_2') ||
                (customerTier === 'C' && tier.code === 'TIER_3') ||
                (customerTier === 'D' && tier.code === 'TIER_4');
              const theme = getTierTheme(tier.color);
              return (
                <button
                  key={tier.id || tier.code}
                  type="button"
                  onClick={() => handleSelectCustomerTier(tier.code, tier.id)}
                  className={`p-2.5 rounded-xl text-left transition-all border flex flex-col gap-1 cursor-pointer relative overflow-hidden ${
                    isSelected
                      ? `${theme.activeClass} border-transparent ring-2`
                      : 'bg-slate-50 hover:bg-slate-100 border-slate-200 text-slate-700'
                  }`}
                >
                  <div className="flex items-center justify-between w-full">
                    <span className="text-xs font-black tracking-tight truncate">
                      {tier.name}
                    </span>
                    <span
                      className={`text-[9px] font-bold px-1.5 py-0.5 rounded-full uppercase shrink-0 ${
                        isSelected
                          ? 'bg-white/20 text-white'
                          : 'bg-white border border-slate-200 text-slate-600'
                      }`}
                    >
                      {tier.code}
                    </span>
                  </div>
                  <span
                    className={`text-[10px] truncate font-medium ${
                      isSelected ? 'text-white/90' : 'text-slate-600'
                    }`}
                  >
                    {tier.description || tier.name}
                  </span>
                </button>
              );
            })}
          </div>
        </div>

        {/* Search & Barcode Bar */}
        <div className="grid grid-cols-1 sm:grid-cols-12 gap-2.5 bg-white p-3 rounded-xl border border-slate-200 shadow-2xs shrink-0">
          <div className="sm:col-span-6 relative">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input
              ref={searchInputRef}
              type="text"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="Cari produk, SKU, atau tekan '/'..."
              className="w-full pl-9 pr-3 py-2 rounded-lg bg-slate-50 border border-slate-200 text-xs text-slate-900 focus:bg-white focus:outline-none focus:border-blue-600"
            />
          </div>

          <form onSubmit={handleBarcodeSubmit} className="sm:col-span-6 flex gap-2">
            <div className="relative flex-1">
              <Barcode className="w-4 h-4 text-blue-600 absolute left-3.5 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={barcodeInput}
                onChange={(e) => setBarcodeInput(e.target.value)}
                placeholder="Scan Barcode / SKU lalu Enter..."
                className="w-full pl-9 pr-3 py-2 rounded-lg bg-slate-50 border border-slate-200 text-xs text-slate-900 font-mono focus:bg-white focus:outline-none focus:border-blue-600"
              />
            </div>
            <Button type="submit" variant="outline" size="sm">
              Scan
            </Button>
          </form>
        </div>

        {/* Category Filter Tabs */}
        <div className="flex items-center gap-2 overflow-x-auto pb-1 shrink-0">
          <button
            type="button"
            onClick={() => setSelectedCategoryId(0)}
            className={`px-3.5 py-2 rounded-xl text-xs font-bold shrink-0 transition cursor-pointer ${
              selectedCategoryId === 0
                ? 'bg-blue-600 text-white shadow-xs'
                : 'bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50'
            }`}
          >
            Semua Produk ({products.length})
          </button>
          {categories.map((cat) => (
            <button
              type="button"
              key={cat.id}
              onClick={() => setSelectedCategoryId(cat.id)}
              className={`px-3.5 py-2 rounded-xl text-xs font-bold shrink-0 transition cursor-pointer flex items-center gap-2 ${
                selectedCategoryId === cat.id
                  ? 'bg-blue-600 text-white shadow-xs'
                  : 'bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50'
              }`}
            >
              {cat.color && (
                <span
                  className="w-2 h-2 rounded-full"
                  style={{ backgroundColor: selectedCategoryId === cat.id ? '#fff' : cat.color }}
                />
              )}
              <span>{cat.name}</span>
            </button>
          ))}
        </div>

        {/* Scrollable Product Grid */}
        <div className="flex-1 overflow-y-auto pr-1">
          {loadingCatalog ? (
            <div className="h-64 flex items-center justify-center text-sm text-slate-500">
              Memuat katalog produk outlet...
            </div>
          ) : (
            <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3 pb-4">
              {filteredProducts.map((product) => {
                const defaultVar =
                  product.variants.find((v) => v.is_default) ?? product.variants[0];
                const totalStock = product.variants.reduce(
                  (acc, v) => acc + parseFloat(v.stock_quantity),
                  0,
                );
                const anyAvailable = product.variants.some((v) => v.is_available);
                const hasOutletOverride = product.variants.some(
                  (v) => v.price_override !== null,
                );

                return (
                  <button
                    type="button"
                    key={product.id}
                    disabled={!anyAvailable}
                    onClick={() => handleProductCardClick(product)}
                    className={`group text-left bg-white rounded-xl border transition-all flex flex-col justify-between overflow-hidden cursor-pointer ${
                      anyAvailable
                        ? 'border-slate-200 hover:border-blue-500 hover:shadow-md active:scale-[0.99]'
                        : 'border-slate-200 opacity-50 cursor-not-allowed bg-slate-50'
                    }`}
                  >
                    <div>
                      <div
                        className="h-1.5 w-full"
                        style={{ backgroundColor: product.category?.color ?? '#2563eb' }}
                      />
                      <div className="p-3.5 pb-2">
                        <div className="flex items-center justify-between gap-1 mb-2">
                          <span className="text-[10px] font-mono font-semibold px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">
                            {product.sku}
                          </span>
                          <Badge
                            variant={
                              !anyAvailable
                                ? 'danger'
                                : totalStock <= 15
                                  ? 'warning'
                                  : 'success'
                            }
                          >
                            {!anyAvailable
                              ? 'Habis'
                              : `${formatQty(totalStock)} ${product.unit}`}
                          </Badge>
                        </div>

                        <div className="font-bold text-sm text-slate-900 group-hover:text-blue-600 transition line-clamp-2">
                          {product.name}
                        </div>
                        <div className="text-[11px] text-slate-500 mt-1 line-clamp-1">
                          {product.description}
                        </div>
                      </div>
                    </div>

                    <div className="px-3.5 py-2.5 bg-slate-50/70 border-t border-slate-100 flex items-end justify-between">
                      <div>
                        <div className="text-[10px] font-medium text-slate-500">
                          {product.variants.length > 1
                            ? `${product.variants.length} Pilihan Varian`
                            : defaultVar?.name}
                        </div>
                        {(() => {
                          const activeTierPrice = resolveVariantPriceForTier(
                            defaultVar,
                            customerTier,
                            selectedTier?.id,
                          );
                          const baseRetailPrice = defaultVar
                            ? parseFloat(defaultVar.base_price || defaultVar.effective_price)
                            : 0;
                          const hasDiff = activeTierPrice !== baseRetailPrice;
                          return (
                            <div className="flex items-baseline gap-1.5 mt-0.5">
                              <span className="text-sm font-extrabold text-blue-600 tabular-nums">
                                {formatIDR(activeTierPrice)}
                              </span>
                              {hasDiff && (
                                <span className="text-[10px] text-slate-400 line-through tabular-nums">
                                  {formatIDR(baseRetailPrice)}
                                </span>
                              )}
                            </div>
                          );
                        })()}
                      </div>

                      <div className="flex flex-col items-end gap-1">
                        {selectedTier && (
                          <span
                            className={`text-[9px] font-black uppercase px-1.5 py-0.5 rounded border ${
                              getTierTheme(selectedTier.color).badgeBg
                            }`}
                          >
                            {selectedTier.name}
                          </span>
                        )}
                        {hasOutletOverride && (
                          <Badge variant="info">Harga Outlet</Badge>
                        )}
                      </div>
                    </div>
                  </button>
                );
              })}
            </div>
          )}
        </div>
      </div>

      {/* RIGHT PANE: Moka High-Density Billing Cart (5/4 cols) */}
      <div className="lg:col-span-5 xl:col-span-4 bg-white border border-slate-200 rounded-xl shadow-xs flex flex-col h-full overflow-hidden">
        {/* Top Cart Bar: Customer Chip & Saved Bills */}
        <div className="p-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between gap-2 shrink-0">
          <div className="flex items-center gap-1.5 min-w-0">
            <button
              type="button"
              onClick={() => setShowCustomerDrawer((prev) => !prev)}
              className={`flex items-center gap-2 px-3 py-1.5 rounded-lg border text-xs font-bold transition cursor-pointer truncate ${
                customerName || tableLabel
                  ? 'bg-blue-50 border-blue-200 text-blue-700'
                  : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-100'
              }`}
            >
              <UserPlus className="w-3.5 h-3.5 text-blue-600 shrink-0" />
              <span className="truncate">
                {customerName || tableLabel
                  ? `${customerName || 'Tamu'}${tableLabel ? ` • Meja ${tableLabel}` : ''}`
                  : '+ Pelanggan / Meja'}
              </span>
            </button>

            <span
              className={`text-[10px] font-black uppercase px-2 py-1 rounded-md border shrink-0 ${
                getTierTheme(selectedTier?.color || 'blue').badgeBg
              }`}
            >
              {selectedTier?.name || `Tier ${customerTier}`}
            </span>
          </div>

          <div className="flex items-center gap-1.5 shrink-0">
            <button
              type="button"
              onClick={() => setShowHeldModal(true)}
              className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 border border-amber-200 text-amber-800 text-xs font-bold cursor-pointer"
            >
              <Clock className="w-3.5 h-3.5 text-amber-600" />
              <span>Bill ({heldOrders.length})</span>
            </button>

            {cart.length > 0 && (
              <button
                type="button"
                onClick={clearCart}
                title="Kosongkan Keranjang"
                className="p-1.5 rounded-lg bg-white hover:bg-rose-50 text-slate-400 hover:text-rose-600 border border-slate-200 cursor-pointer"
              >
                <Trash2 className="w-4 h-4" />
              </button>
            )}
          </div>
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

        {/* Collapsible Customer & Table Drawer */}
        {showCustomerDrawer && (
          <div className="p-3 bg-blue-50/70 border-b border-blue-100 space-y-2 shrink-0">
            {registeredCustomers.length > 0 && (
              <div className="flex items-center gap-2">
                <Users className="w-3.5 h-3.5 text-blue-600 shrink-0" />
                <select
                  value={selectedCustomerId ?? ''}
                  onChange={(e) => {
                    const id = e.target.value ? parseInt(e.target.value, 10) : null;
                    setSelectedCustomerId(id);
                    if (id) {
                      const c = registeredCustomers.find((rc) => rc.id === id);
                      if (c) {
                        setCustomerName(c.name);
                        setCustomerPhone(c.phone || '');
                        if (c.tier?.code) {
                          handleSelectCustomerTier(c.tier.code, c.tier.id);
                        } else if (c.pricing_tier) {
                          handleSelectCustomerTier(c.pricing_tier.toUpperCase());
                        }
                      }
                    }
                  }}
                  className="w-full px-2.5 py-1.5 rounded-lg bg-white border border-blue-200 text-xs font-semibold text-slate-800 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="">-- Pilih Pelanggan Terdaftar (Opsional) --</option>
                  {registeredCustomers.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} ({c.code}) {c.tier?.name ? `• ${c.tier.name}` : ''}
                    </option>
                  ))}
                </select>
              </div>
            )}
            <div className="grid grid-cols-3 gap-2">
              <input
                type="text"
                value={customerName}
                onChange={(e) => setCustomerName(e.target.value)}
                placeholder="Nama Pelanggan / Tamu"
                className="px-2.5 py-1.5 rounded-lg bg-white border border-slate-300 text-xs text-slate-900"
              />
              <input
                type="text"
                value={customerPhone}
                onChange={(e) => setCustomerPhone(e.target.value)}
                placeholder="No. HP"
                className="px-2.5 py-1.5 rounded-lg bg-white border border-slate-300 text-xs text-slate-900"
              />
              <input
                type="text"
                value={tableLabel}
                onChange={(e) => setTableLabel(e.target.value)}
                placeholder="No. Meja (T-01)"
                className="px-2.5 py-1.5 rounded-lg bg-white border border-slate-300 text-xs text-slate-900"
              />
            </div>
          </div>
        )}

        {/* Scrollable Cart Items List (Maximized Height) */}
        <div className="flex-1 p-3 space-y-2 overflow-y-auto">
          {cart.length === 0 ? (
            <div className="h-full flex flex-col items-center justify-center text-center text-slate-400 text-xs space-y-2 py-12">
              <div className="w-12 h-12 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400">
                <ShoppingBag className="w-6 h-6" />
              </div>
              <div className="font-bold text-slate-600">Keranjang Transaksi Kosong</div>
              <div className="text-[11px] max-w-[220px]">
                Pilih produk di sebelah kiri atau scan barcode untuk memulai pesanan.
              </div>
            </div>
          ) : (
            cart.map((item) => {
              const effectiveUnit = item.unit_price_override ?? item.original_unit_price;
              const modsUnit = item.modifiers.reduce((a, m) => a + parseFloat(m.price), 0);
              const lineTotal = Math.round((effectiveUnit + modsUnit) * item.quantity);

              return (
                <div
                  key={item.key}
                  className="p-3 rounded-xl bg-slate-50 hover:bg-slate-100/70 border border-slate-200/80 space-y-2 transition"
                >
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <div className="text-xs font-bold text-slate-900 truncate">
                        {item.product_name}
                      </div>
                      <div className="text-[11px] text-slate-500 font-medium">
                        {item.variant_name} &bull; {formatIDR(effectiveUnit)}
                      </div>
                    </div>
                    <div className="text-right shrink-0 tabular-nums">
                      <div className="text-xs font-extrabold text-slate-900">
                        {formatIDR(lineTotal)}
                      </div>
                      {item.unit_price_override !== null &&
                        item.unit_price_override !== undefined && (
                          <span className="text-[10px] text-amber-600 font-bold">
                            Override Harga
                          </span>
                        )}
                    </div>
                  </div>

                  {item.modifiers.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                      {item.modifiers.map((m) => (
                        <span
                          key={m.id}
                          className="text-[10px] px-2 py-0.5 rounded-md bg-white border border-slate-200 text-slate-600 font-medium"
                        >
                          + {m.name} ({formatIDR(m.price)})
                        </span>
                      ))}
                    </div>
                  )}

                  {item.notes && (
                    <div className="text-[11px] text-amber-700 bg-amber-50 px-2 py-0.5 rounded border border-amber-200">
                      Catatan: &ldquo;{item.notes}&rdquo;
                    </div>
                  )}

                  <div className="flex items-center justify-between pt-1">
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
                      className="text-[11px] font-semibold text-blue-600 hover:text-blue-800 flex items-center gap-1 cursor-pointer"
                    >
                      <Edit3 className="w-3 h-3" />
                      <span>Catatan / Ubah Harga</span>
                    </button>

                    <div className="flex items-center gap-1.5">
                      <button
                        type="button"
                        onClick={() => updateCartQty(item.key, -1)}
                        className="w-7 h-7 rounded-lg bg-white hover:bg-slate-100 border border-slate-300 flex items-center justify-center text-slate-700 cursor-pointer"
                      >
                        <Minus className="w-3.5 h-3.5" />
                      </button>
                      <span className="text-xs font-extrabold text-slate-900 w-6 text-center tabular-nums">
                        {item.quantity}
                      </span>
                      <button
                        type="button"
                        onClick={() => updateCartQty(item.key, 1)}
                        className="w-7 h-7 rounded-lg bg-blue-600 hover:bg-blue-700 text-white flex items-center justify-center cursor-pointer"
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

        {/* Sticky Cart Footer: Discount Chip, Tax Breakdown & Charge CTA */}
        <div className="p-3.5 bg-slate-50 border-t border-slate-200 space-y-2.5 shrink-0">
          {/* Collapsible Discount Drawer */}
          {showDiscountDrawer && (
            <div className="p-2.5 rounded-xl bg-white border border-slate-200 space-y-2">
              {masterDiscounts.length > 0 && (
                <div>
                  <div className="text-[10px] font-bold uppercase text-slate-400 mb-1">
                    Pilih Master Diskon Aktif:
                  </div>
                  <div className="flex flex-wrap gap-1.5">
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
                          className={`px-2 py-1 rounded-lg border text-[11px] font-bold transition cursor-pointer ${
                            discountReason.startsWith(md.code)
                              ? 'bg-blue-600 text-white border-blue-600'
                              : eligible
                                ? 'bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100'
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
                  className="col-span-4 px-2 py-1.5 rounded-lg bg-slate-50 border border-slate-300 text-xs font-semibold text-slate-800"
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
                  className="col-span-3 px-2 py-1.5 rounded-lg bg-slate-50 border border-slate-300 text-xs font-bold text-slate-900 disabled:opacity-40"
                />
                <input
                  type="text"
                  disabled={!discountType}
                  value={discountReason}
                  onChange={(e) => setDiscountReason(e.target.value)}
                  placeholder="Alasan / Kode Diskon"
                  className="col-span-5 px-2 py-1.5 rounded-lg bg-slate-50 border border-slate-300 text-xs text-slate-900 disabled:opacity-40"
                />
              </div>
            </div>
          )}

          {totals.requiresSupervisorPin && (
            <div className="px-3 py-1.5 rounded-lg bg-amber-50 border border-amber-200 text-[11px] font-semibold text-amber-800 flex items-center justify-between">
              <span>
                Diskon ({totals.effectiveDiscPercent.toFixed(1)}%) / Override Harga membutuhkan PIN Supervisor saat bayar
              </span>
            </div>
          )}

          {/* Financial Breakdown */}
          <div className="space-y-1 text-xs tabular-nums">
            <div className="flex justify-between text-slate-600">
              <span>
                Subtotal ({cart.reduce((a, b) => a + b.quantity, 0)} item)
              </span>
              <span className="font-semibold text-slate-900">
                {formatIDR(totals.subtotal)}
              </span>
            </div>

            <div className="flex justify-between items-center text-slate-600">
              <button
                type="button"
                onClick={() => setShowDiscountDrawer((prev) => !prev)}
                className="text-blue-600 hover:text-blue-800 font-semibold inline-flex items-center gap-1 cursor-pointer"
              >
                <Percent className="w-3 h-3" />
                <span>
                  {totals.discountTotal > 0 ? 'Ubah Diskon Order' : '+ Tambah Diskon'}
                </span>
              </button>
              <span className={totals.discountTotal > 0 ? 'font-bold text-rose-600' : ''}>
                -{formatIDR(totals.discountTotal)}
              </span>
            </div>

            {parseFloat(outlet.service_charge_rate) > 0 && (
              <div className="flex justify-between text-slate-500">
                <span>Service Charge ({parseFloat(outlet.service_charge_rate)}%)</span>
                <span>{formatIDR(totals.serviceChargeTotal)}</span>
              </div>
            )}

            <div className="flex justify-between text-slate-500">
              <span>PPN ({parseFloat(outlet.tax_rate)}%)</span>
              <span>{formatIDR(totals.taxTotal)}</span>
            </div>

            <div className="flex justify-between items-baseline pt-2 border-t border-slate-200">
              <span className="text-sm font-extrabold text-slate-900">Total Tagihan</span>
              <span className="text-lg font-black text-blue-600">
                {formatIDR(totals.grandTotal)}
              </span>
            </div>
          </div>

          {/* Primary Action Buttons */}
          <div className="grid grid-cols-12 gap-2 pt-1">
            <Button
              type="button"
              variant="outline"
              disabled={cart.length === 0}
              onClick={handleHoldOrder}
              className="col-span-4"
              icon={<PauseCircle className="w-4 h-4 text-amber-600" />}
            >
              Simpan Bill
            </Button>

            <Button
              type="button"
              variant="primary"
              size="lg"
              disabled={cart.length === 0}
              onClick={() => {
                if (!hasOpenShift) {
                  onRequireOpenShift();
                  return;
                }
                setShowPaymentModal(true);
              }}
              className="col-span-8"
              icon={<Sparkles className="w-4 h-4" />}
            >
              Bayar {formatIDR(totals.grandTotal)}
            </Button>
          </div>
        </div>
      </div>

      {/* Variant & Modifier Selection Modal */}
      <Modal
        isOpen={configuringProduct !== null}
        onClose={() => setConfiguringProduct(null)}
        title={configuringProduct?.name ?? 'Pilih Varian'}
        subtitle="Pilih ukuran varian, add-on modifier, dan catatan item"
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
                const mods = configuringProduct.modifiers.filter((m) =>
                  chosenModifierIds.includes(m.id),
                );
                if (v) {
                  addVariantToCart(configuringProduct, v, mods, chosenItemNotes);
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
                Pilih Varian Ukuran
              </label>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                {configuringProduct.variants.map((v) => (
                  <button
                    type="button"
                    key={v.id}
                    disabled={!v.is_available}
                    onClick={() => setChosenVariantId(v.id)}
                    className={`p-3 rounded-xl border text-left transition cursor-pointer ${
                      chosenVariantId === v.id
                        ? 'bg-blue-50 border-blue-600 text-slate-900 ring-2 ring-blue-500/15'
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
                          <span className="text-xs text-blue-600 font-extrabold tabular-nums">
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
                      Stok: {formatQty(v.stock_quantity)}
                    </div>
                  </button>
                ))}
              </div>
            </div>

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
                        className={`p-2.5 rounded-xl border text-left text-xs transition cursor-pointer ${
                          selected
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
                Catatan Item (Kitchen / Barista Notes)
              </label>
              <input
                type="text"
                value={chosenItemNotes}
                onChange={(e) => setChosenItemNotes(e.target.value)}
                placeholder="Contoh: Less ice, pisah es, extra panas..."
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
              Catatan Item (Notes)
            </label>
            <input
              type="text"
              value={itemNoteInput}
              onChange={(e) => setItemNoteInput(e.target.value)}
              placeholder="Catatan khusus barista / dapur"
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
        onClose={() => setShowPaymentModal(false)}
        onSubmitCheckout={handleSubmitCheckout}
        onOrderCompleted={(order) => setCompletedReceiptOrder(order)}
      />

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
