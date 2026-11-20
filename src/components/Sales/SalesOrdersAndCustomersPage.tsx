import React, { useEffect, useState } from 'react';
import {
  CheckCircle2,
  Eye,
  EyeOff,
  FileText,
  Plus,
  RefreshCw,
  ShoppingBag,
  Truck,
  UserPlus,
  Users,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime, formatIDR, formatQty } from '../../lib/format';
import type { DynamicTier, OrderRecord, ProductCatalog } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { Modal } from '../ui/Modal';
import { SearchableSelect } from '../ui/SearchableSelect';
import { useToast } from '../ui/ToastProvider';

interface CustomerRecord {
  id: number;
  outlet_id?: number | null;
  code: string;
  name: string;
  company_name?: string | null;
  masked_nik?: string | null;
  has_nik?: boolean;
  can_reveal_nik?: boolean;
  raw_nik?: string | null;
  customer_group: string;
  pricing_tier: string;
  tier_id?: number | null;
  tier?: { id: number; code: string; name: string; color: string } | null;
  phone?: string | null;
  city?: string | null;
  address?: string | null;
  credit_limit: string;
  payment_term_days: number;
  outstanding_balance: string;
}

interface SalesOrderRecord {
  id: number;
  so_number: string;
  status: string;
  payment_type: string;
  payment_term_days: number;
  due_date?: string | null;
  grand_total: string;
  created_at: string;
  customer?: CustomerRecord;
  warehouse?: { code: string; name: string };
  invoice?: { id: number; invoice_number: string; status: string; balance_due: string } | null;
  items: Array<{
    id: number;
    sku: string;
    product_name: string;
    variant_name: string;
    ordered_qty: string;
    fulfilled_qty: string;
    unit_price: string;
    line_total: string;
  }>;
}

interface Props {
  activeOutletId?: number | null;
  activeOutletName?: string;
  initialTab?: 'sales_orders' | 'customers';
}

export const SalesOrdersAndCustomersPage: React.FC<Props> = ({
  activeOutletId,
  activeOutletName,
  initialTab = 'sales_orders',
}) => {
  const { toast } = useToast();
  const [activeTab, setActiveTab] = useState<'sales_orders' | 'customers'>(initialTab);
  const [customers, setCustomers] = useState<CustomerRecord[]>([]);
  const [salesOrders, setSalesOrders] = useState<SalesOrderRecord[]>([]);
  const [products, setProducts] = useState<ProductCatalog[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [fulfillingSoId, setFulfillingSoId] = useState<number | null>(null);

  // Create B2B Sales Order Modal
  const [showSoModal, setShowSoModal] = useState<boolean>(false);
  const [selectedCustomerId, setSelectedCustomerId] = useState<number>(0);
  const [paymentType, setPaymentType] = useState<'tempo' | 'transfer' | 'cash' | 'qris'>('tempo');
  const [selectedVariantId, setSelectedVariantId] = useState<number>(0);
  const [selectedSoUomCode, setSelectedSoUomCode] = useState<string>('BTL');
  const [soQty, setSoQty] = useState<string>('10');
  const [soNotes, setSoNotes] = useState<string>('Order grosir B2B');
  const [approveCreditOverride, setApproveCreditOverride] = useState<boolean>(false);
  const [submittingSo, setSubmittingSo] = useState<boolean>(false);

  useEffect(() => {
    setActiveTab(initialTab);
  }, [initialTab]);

  // Create Customer Modal
  const [showCustModal, setShowCustModal] = useState<boolean>(false);
  const [custCode, setCustCode] = useState<string>('');
  const [custName, setCustName] = useState<string>('');
  const [custGroup, setCustGroup] = useState<string>('wholesaler');
  const [custTier, setCustTier] = useState<string>('wholesale');
  const [selectedTierId, setSelectedTierId] = useState<number | null>(null);
  const [tiers, setTiers] = useState<DynamicTier[]>([]);
  const [custPhone, setCustPhone] = useState<string>('');
  const [custCompany, setCustCompany] = useState<string>('');
  const [custNik, setCustNik] = useState<string>('');
  const [custCity, setCustCity] = useState<string>('');
  const [custAddress, setCustAddress] = useState<string>('');
  const [custCreditLimit, setCustCreditLimit] = useState<string>('25000000');
  const [custTermDays, setCustTermDays] = useState<string>('30');
  const [submittingCust, setSubmittingCust] = useState<boolean>(false);

  // Customer Detail & History Modal
  const [showDetailModal, setShowDetailModal] = useState<boolean>(false);
  const [loadingDetail, setLoadingDetail] = useState<boolean>(false);
  const [revealedNik, setRevealedNik] = useState<string | null>(null);
  const [detailData, setDetailData] = useState<{
    customer: CustomerRecord;
    invoices: Array<{
      id: number;
      invoice_number: string;
      status: string;
      issue_date: string;
      due_date: string;
      grand_total: string;
      paid_total: string;
      balance_due: string;
      allocations?: Array<{
        id: number;
        amount: string;
        customer_payment?: {
          id: number;
          payment_number: string;
          method: string;
          amount: string;
          payment_date: string;
          notes?: string | null;
        } | null;
      }>;
    }>;
    orders: OrderRecord[];
  } | null>(null);

  const openCustomerDetail = async (customerId: number, reveal = false) => {
    setShowDetailModal(true);
    setLoadingDetail(true);
    if (!reveal) setRevealedNik(null);
    try {
      const res = await api.get<NonNullable<typeof detailData>>(`/customers/${customerId}`, {
        params: { reveal_nik: reveal ? 1 : 0 },
      });
      setDetailData(res.data);
      if (reveal && res.data.customer.raw_nik) {
        setRevealedNik(res.data.customer.raw_nik);
      }
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat detail pelanggan',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoadingDetail(false);
    }
  };

  const fetchData = async () => {
    setLoading(true);
    try {
      const [custRes, soRes, prodRes, tiersRes] = await Promise.all([
        api.get<{ customers: CustomerRecord[] }>('/customers'),
        api.get<{ sales_orders: SalesOrderRecord[] }>('/sales-orders'),
        api.get<{ products: ProductCatalog[] }>('/catalog/products'),
        api.get<{ tiers: DynamicTier[] }>('/tiers').catch(() => ({ data: { tiers: [] } })),
      ]);
      setCustomers(custRes.data.customers);
      setSalesOrders(soRes.data.sales_orders);
      setProducts(prodRes.data.products);
      if (tiersRes.data?.tiers) {
        setTiers(tiersRes.data.tiers.filter((t) => t.status === 'active'));
      }

      if (custRes.data.customers[0]) {
        setSelectedCustomerId(custRes.data.customers[0].id);
      }
      const firstVar = prodRes.data.products[0]?.variants?.[0]?.id;
      if (firstVar && !selectedVariantId) {
        setSelectedVariantId(firstVar);
      }
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat Sales Order & Pelanggan',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, [activeOutletId]);

  const selectedVariantEntry = (() => {
    for (const p of products) {
      for (const v of p.variants) {
        if (v.id === selectedVariantId) {
          const baseCode = (p.unit || 'BTL').toUpperCase();
          const rawUnits = (v.units || p.product_units || []) as Array<{
            id?: number;
            unit_code: string;
            unit_name: string;
            conversion_factor: string | number;
            is_base?: boolean;
            is_sales_unit?: boolean;
            selling_price?: string | number | null;
          }>;
          const unitOptions = [
            {
              unitId: undefined as number | undefined,
              code: baseCode,
              label: `${baseCode} (Satuan Dasar)`,
              factor: 1,
            },
            ...rawUnits
              .filter(
                (u) =>
                  !u.is_base &&
                  u.is_sales_unit !== false &&
                  u.unit_code.toUpperCase() !== baseCode,
              )
              .map((u) => ({
                unitId: u.id,
                code: u.unit_code.toUpperCase(),
                label: `${u.unit_code.toUpperCase()} — ${u.unit_name} (1 ${u.unit_code.toUpperCase()} = ${parseFloat(String(u.conversion_factor))} ${baseCode})`,
                factor: Number(u.conversion_factor || 1),
              })),
          ];
          return { product: p, variant: v, baseCode, unitOptions };
        }
      }
    }
    return null;
  })();

  const activeSoUnit =
    selectedVariantEntry?.unitOptions.find((u) => u.code === selectedSoUomCode) ??
    selectedVariantEntry?.unitOptions[0] ?? {
      unitId: undefined,
      code: 'BTL',
      label: 'BTL',
      factor: 1,
    };

  const handleCreateSalesOrder = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmittingSo(true);
    try {
      await api.post('/sales-orders', {
        customer_id: selectedCustomerId,
        payment_type: paymentType,
        approve_credit_override: approveCreditOverride,
        notes: soNotes,
        items: [
          {
            product_variant_id: selectedVariantId,
            product_unit_id: activeSoUnit.unitId,
            uom_code: activeSoUnit.code,
            quantity: Number(soQty),
          },
        ],
      });
      toast({
        type: 'success',
        title: 'B2B Sales Order Berhasil Dibuat',
        description: `Sales Order tercatat pada ${activeOutletName} dan stok gudang telah di-reserve.`,
      });
      setShowSoModal(false);
      fetchData();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal membuat Sales Order',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmittingSo(false);
    }
  };

  const handleFulfillAndInvoice = async (soId: number) => {
    setFulfillingSoId(soId);
    try {
      await api.post(`/sales-orders/${soId}/fulfill`);
      toast({
        type: 'success',
        title: 'Sales Order Dikirim & Invoice Diterbitkan',
        description:
          'Surat jalan dibuat, stok gudang cabang dikurangi, dan tagihan masuk ke Accounts Receivable.',
      });
      fetchData();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memproses pengiriman & invoice',
        description: extractErrorMessage(err),
      });
    } finally {
      setFulfillingSoId(null);
    }
  };

  const handleCancelSalesOrder = async (soId: number) => {
    if (!window.confirm('Apakah Anda yakin ingin membatalkan Sales Order ini? Seluruh alokasi reservasi stok gudang akan segera dilepas kembali.')) {
      return;
    }
    try {
      await api.post(`/sales-orders/${soId}/cancel`, {
        reason: 'Dibatalkan oleh operasional outlet / pelanggan',
      });
      toast({
        type: 'success',
        title: 'Sales Order Dibatalkan',
        description: 'Status SO menjadi dibatalkan dan alokasi stok reservasi telah dilepas.',
      });
      fetchData();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal membatalkan Sales Order',
        description: extractErrorMessage(err),
      });
    }
  };

  const handleCreateCustomer = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmittingCust(true);
    try {
      await api.post('/customers', {
        code: custCode.trim().toUpperCase() || undefined,
        name: custName.trim(),
        company_name: custCompany.trim() || custName.trim(),
        nik: custNik.trim() || undefined,
        customer_group: custGroup,
        pricing_tier: custTier,
        tier_id: selectedTierId ?? undefined,
        phone: custPhone,
        city: custCity || undefined,
        address: custAddress || undefined,
        credit_limit: Number(custCreditLimit),
        payment_term_days: Number(custTermDays),
      });
      toast({
        type: 'success',
        title: 'Pelanggan Bisnis Ditambahkan',
        description: `${custName} berhasil terdaftar.`,
      });
      setShowCustModal(false);
      setCustCode('');
      setCustName('');
      setCustCompany('');
      setCustNik('');
      setCustPhone('');
      fetchData();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menyimpan pelanggan',
        description: extractErrorMessage(err),
      });
    } finally {
      setSubmittingCust(false);
    }
  };

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
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-extrabold text-slate-900">
            B2B Sales Order, Distribusi & Manajemen Pelanggan Grosir
          </h1>
          <p className="text-xs text-slate-500">
            Outlet Pemenuhan Aktif: <b>{activeOutletName}</b> • Mendukung harga tier grosir, kontrol
            batas kredit (Credit Limit), & tempo.
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
            variant="outline"
            size="sm"
            leftIcon={<UserPlus className="w-4 h-4" />}
            onClick={() => setShowCustModal(true)}
          >
            Tambah Pelanggan B2B
          </Button>
          <Button
            variant="primary"
            size="sm"
            leftIcon={<Plus className="w-4 h-4" />}
            onClick={() => setShowSoModal(true)}
          >
            Buat B2B Sales Order
          </Button>
        </div>
      </div>

      {/* Tabs */}
      <div className="inline-flex rounded-xl bg-slate-200/70 p-1">
        <button
          type="button"
          onClick={() => setActiveTab('sales_orders')}
          className={`px-4 py-2 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 ${
            activeTab === 'sales_orders'
              ? 'bg-white text-blue-600 shadow-xs'
              : 'text-slate-600 hover:text-slate-900'
          }`}
        >
          <ShoppingBag className="w-3.5 h-3.5" />
          B2B Sales Orders & Pengiriman ({salesOrders.length})
        </button>
        <button
          type="button"
          onClick={() => setActiveTab('customers')}
          className={`px-4 py-2 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 ${
            activeTab === 'customers'
              ? 'bg-white text-blue-600 shadow-xs'
              : 'text-slate-600 hover:text-slate-900'
          }`}
        >
          <Users className="w-3.5 h-3.5" />
          Pelanggan Grosir & Credit Limit ({customers.length})
        </button>
      </div>

      {activeTab === 'sales_orders' && (
        <Card
          title={`Daftar B2B Sales Order — ${activeOutletName}`}
          subtitle="Alur: Buat SO (Cek Stok & Credit Limit) → Picking/Delivery & Terbitkan Invoice (Masuk ke Piutang/AR)"
        >
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase text-slate-500">
                  <th className="py-3 px-4">No. SO & Tanggal</th>
                  <th className="py-3 px-4">Pelanggan Bisnis</th>
                  <th className="py-3 px-4">Gudang Pemenuhan</th>
                  <th className="py-3 px-4">Rincian Item (Harga Tier)</th>
                  <th className="py-3 px-4">Tipe Bayar</th>
                  <th className="py-3 px-4 text-right">Grand Total</th>
                  <th className="py-3 px-4">Status / Invoice</th>
                  <th className="py-3 px-4 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {salesOrders.map((so) => (
                  <tr key={so.id} className="hover:bg-slate-50/70">
                    <td className="py-3.5 px-4">
                      <div className="font-mono font-bold text-slate-900">{so.so_number}</div>
                      <div className="text-xs text-slate-400">
                        {formatDateTime(so.created_at)}
                      </div>
                    </td>
                    <td className="py-3.5 px-4">
                      <div className="font-bold text-slate-900">{so.customer?.name}</div>
                      <div className="text-xs text-slate-500 uppercase">
                        {so.customer?.customer_group} • Tier: {so.customer?.pricing_tier}
                      </div>
                    </td>
                    <td className="py-3.5 px-4">
                      <Badge variant="info">{so.warehouse?.code}</Badge>
                    </td>
                    <td className="py-3.5 px-4">
                      {so.items.map((item) => (
                        <div key={item.id} className="text-xs text-slate-700">
                          <b>{item.product_name}</b> ({item.variant_name}) ×{' '}
                          <b>{formatQty(item.ordered_qty)}</b> @ {formatIDR(item.unit_price)}
                        </div>
                      ))}
                    </td>
                    <td className="py-3.5 px-4">
                      <Badge variant={so.payment_type === 'tempo' ? 'warning' : 'success'}>
                        {so.payment_type === 'tempo'
                          ? `TEMPO ${so.payment_term_days} HARI`
                          : so.payment_type.toUpperCase()}
                      </Badge>
                    </td>
                    <td className="py-3.5 px-4 text-right font-extrabold text-slate-900 tabular-nums">
                      {formatIDR(so.grand_total)}
                    </td>
                    <td className="py-3.5 px-4">
                      <Badge variant={so.status === 'invoiced' ? 'success' : 'info'} dot>
                        {so.status.toUpperCase()}
                      </Badge>
                      {so.invoice && (
                        <div className="text-[11px] font-mono text-slate-500 mt-1">
                          {so.invoice.invoice_number}
                        </div>
                      )}
                    </td>
                    <td className="py-3.5 px-4 text-right">
                      {so.status !== 'invoiced' && so.status !== 'cancelled' && (
                        <div className="flex items-center justify-end gap-1.5">
                          <Button
                            variant="primary"
                            size="xs"
                            loading={fulfillingSoId === so.id}
                            leftIcon={<Truck className="w-3.5 h-3.5" />}
                            onClick={() => handleFulfillAndInvoice(so.id)}
                          >
                            Kirim & Terbitkan Invoice
                          </Button>
                          <Button
                            variant="outline"
                            size="xs"
                            onClick={() => handleCancelSalesOrder(so.id)}
                          >
                            Batalkan
                          </Button>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {activeTab === 'customers' && (
        <Card
          title="Master Pelanggan Bisnis, Tier Harga & Kontrol Kredit"
          subtitle="Mengelola Retailer, Wholesaler, Reseller, dan Business Customer beserta Credit Limit & Saldo Piutang."
        >
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase text-slate-500">
                  <th className="py-3 px-4">Kode & Nama Pelanggan</th>
                  <th className="py-3 px-4">Segmen & Tier Harga</th>
                  <th className="py-3 px-4">Kontak & NIK (Masked)</th>
                  <th className="py-3 px-4 text-right">Termin (TOP)</th>
                  <th className="py-3 px-4 text-right">Credit Limit</th>
                  <th className="py-3 px-4 text-right">Piutang Berjalan (AR)</th>
                  <th className="py-3 px-4 text-right">Sisa Plafon Kredit</th>
                  <th className="py-3 px-4 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {customers.map((c) => {
                  const limit = parseFloat(c.credit_limit);
                  const used = parseFloat(c.outstanding_balance);
                  const remaining = Math.max(0, limit - used);
                  return (
                    <tr key={c.id} className="hover:bg-slate-50/70">
                      <td className="py-3.5 px-4">
                        <div className="font-mono text-xs font-bold text-blue-600">{c.code}</div>
                        <div className="font-bold text-slate-900">{c.name}</div>
                        {c.company_name && c.company_name !== c.name && (
                          <div className="text-xs text-slate-500">{c.company_name}</div>
                        )}
                      </td>
                      <td className="py-3.5 px-4">
                        <Badge variant="info">{c.customer_group.toUpperCase()}</Badge>
                        <div className="text-xs text-slate-500 mt-1">
                          Pricing Tier: <b>{c.tier?.name || c.pricing_tier.toUpperCase()}</b>
                        </div>
                      </td>
                      <td className="py-3.5 px-4 text-xs text-slate-600">
                        <div className="font-semibold text-slate-800">{c.phone || '-'}</div>
                        <div>{c.city || c.address || '-'}</div>
                        {c.masked_nik && (
                          <div className="font-mono text-[11px] text-slate-500">
                            NIK: {c.masked_nik}
                          </div>
                        )}
                      </td>
                      <td className="py-3.5 px-4 text-right font-semibold text-slate-700">
                        {c.payment_term_days} Hari
                      </td>
                      <td className="py-3.5 px-4 text-right font-bold text-slate-900 tabular-nums">
                        {formatIDR(c.credit_limit)}
                      </td>
                      <td className="py-3.5 px-4 text-right font-bold text-amber-700 tabular-nums">
                        {formatIDR(c.outstanding_balance)}
                      </td>
                      <td className="py-3.5 px-4 text-right font-extrabold text-emerald-700 tabular-nums">
                        {formatIDR(remaining)}
                      </td>
                      <td className="py-3.5 px-4 text-right">
                        <Button
                          variant="outline"
                          size="xs"
                          leftIcon={<FileText className="w-3.5 h-3.5" />}
                          onClick={() => openCustomerDetail(c.id)}
                        >
                          Detail & Riwayat
                        </Button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* Modal Buat B2B Sales Order */}
      <Modal
        open={showSoModal}
        onClose={() => setShowSoModal(false)}
        title={`Buat B2B Sales Order — ${activeOutletName}`}
        description="Harga produk otomatis menyesuaikan Pricing Tier pelanggan (Wholesale / Reseller / Retail)."
      >
        <form onSubmit={handleCreateSalesOrder} className="space-y-4">
          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">
              Pilih Pelanggan Bisnis
            </label>
            <select
              value={selectedCustomerId}
              onChange={(e) => setSelectedCustomerId(Number(e.target.value))}
              className="w-full px-3 py-2.5 rounded-xl border border-slate-300 text-sm bg-white"
            >
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} ({c.customer_group.toUpperCase()} • Tier: {c.pricing_tier.toUpperCase()}{' '}
                  • Limit: {formatIDR(c.credit_limit)})
                </option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Metode Pembayaran
              </label>
              <select
                value={paymentType}
                onChange={(e) =>
                  setPaymentType(e.target.value as 'tempo' | 'transfer' | 'cash' | 'qris')
                }
                className="w-full px-3 py-2.5 rounded-xl border border-slate-300 text-sm bg-white"
              >
                <option value="tempo">Pembayaran Tempo (Credit / AR)</option>
                <option value="transfer">Transfer Bank (Lunas)</option>
                <option value="qris">QRIS (Lunas)</option>
                <option value="cash">Tunai / Cash (Lunas)</option>
              </select>
            </div>
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Kuantitas Pesanan
              </label>
              <input
                type="number"
                min="1"
                required
                value={soQty}
                onChange={(e) => setSoQty(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">
              Produk / Varian (Ketik Nama / SKU atau Klik Daftar)
            </label>
            <SearchableSelect
              value={selectedVariantId}
              onChange={(nextId) => {
                setSelectedVariantId(nextId);
                const foundProd = products.find((p) => p.variants.some((v) => v.id === nextId));
                if (foundProd) {
                  setSelectedSoUomCode((foundProd.unit || 'BTL').toUpperCase());
                }
              }}
              placeholder="Ketik nama barang / SKU atau pilih dari daftar..."
              options={allVariants.map((v) => ({
                value: v.id,
                label: `${v.productName} (${v.variantName})`,
                sublabel: `SKU: ${v.sku}`,
                badge: `Stok: ${v.stock}`,
                searchKeywords: `${v.productName} ${v.variantName} ${v.sku} ${v.barcode}`,
              }))}
            />
          </div>

          {selectedVariantEntry && (
            <div className="p-3.5 rounded-xl bg-blue-50/60 border border-blue-200 space-y-2">
              <label className="block text-xs font-bold text-blue-950">
                Satuan / Kemasan Penjualan (Selling UOM)
              </label>
              <select
                value={activeSoUnit.code}
                onChange={(e) => setSelectedSoUomCode(e.target.value)}
                className="w-full px-3 py-2 rounded-lg border border-blue-300 text-xs font-bold bg-white text-blue-950"
              >
                {selectedVariantEntry.unitOptions.map((u) => (
                  <option key={u.code} value={u.code}>
                    {u.label}
                  </option>
                ))}
              </select>
              <div className="text-[11px] font-semibold text-blue-800">
                Konversi Stok Otomatis: <b>{Number(soQty) || 0} {activeSoUnit.code}</b> ={' '}
                <b>
                  {(Number(soQty) || 0) * activeSoUnit.factor} {selectedVariantEntry.baseCode}
                </b>{' '}
                (dikurangi dari stok dasar gudang)
              </div>
            </div>
          )}

          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">Catatan Order</label>
            <input
              type="text"
              value={soNotes}
              onChange={(e) => setSoNotes(e.target.value)}
              className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
            />
          </div>

          <label className="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
            <input
              type="checkbox"
              checked={approveCreditOverride}
              onChange={(e) => setApproveCreditOverride(e.target.checked)}
              className="rounded border-slate-300"
            />
            Setujui Credit Limit Override (jika order melebihi sisa plafon kredit pelanggan)
          </label>

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" type="button" onClick={() => setShowSoModal(false)}>
              Batal
            </Button>
            <Button
              variant="primary"
              type="submit"
              loading={submittingSo}
              leftIcon={<CheckCircle2 className="w-4 h-4" />}
            >
              Simpan Sales Order & Reserve Stok
            </Button>
          </div>
        </form>
      </Modal>

      {/* Modal Tambah Pelanggan B2B */}
      <Modal
        open={showCustModal}
        onClose={() => setShowCustModal(false)}
        title="Tambah Pelanggan Bisnis (Retailer / Wholesaler / Reseller)"
        description="Tentukan grup pelanggan, tier harga grosir, serta plafon kredit (Credit Limit)."
      >
        <form onSubmit={handleCreateCustomer} className="space-y-4">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Kode Pelanggan</label>
              <input
                type="text"
                required
                value={custCode}
                onChange={(e) => setCustCode(e.target.value.toUpperCase())}
                placeholder="CUST-TNG-002"
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Nama Toko / Perusahaan
              </label>
              <input
                type="text"
                required
                value={custName}
                onChange={(e) => setCustName(e.target.value)}
                placeholder="Toko Agen Sejahtera"
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Grup Pelanggan</label>
              <select
                value={custGroup}
                onChange={(e) => setCustGroup(e.target.value)}
                className="w-full px-3 py-2.5 rounded-xl border border-slate-300 text-sm bg-white"
              >
                <option value="wholesaler">Wholesaler (Grosir Besar)</option>
                <option value="reseller">Reseller / Agen</option>
                <option value="retailer">Retailer (Toko Ritel)</option>
                <option value="business">Business / Horeka</option>
              </select>
            </div>
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Dynamic Pricing Tier
              </label>
              <select
                value={selectedTierId ?? ''}
                onChange={(e) => {
                  const val = e.target.value ? parseInt(e.target.value, 10) : null;
                  setSelectedTierId(val);
                  if (val) {
                    const match = tiers.find((t) => t.id === val);
                    if (match) {
                      const tierCode = match.code.toLowerCase();
                      if (['retail', 'reseller', 'wholesale', 'vip'].includes(tierCode)) {
                        setCustTier(tierCode);
                      } else if (tierCode === 'tier_1') {
                        setCustTier('retail');
                      } else if (tierCode === 'tier_2') {
                        setCustTier('reseller');
                      } else if (tierCode === 'tier_3') {
                        setCustTier('reseller');
                      } else if (tierCode === 'tier_4') {
                        setCustTier('wholesale');
                      }
                    }
                  }
                }}
                className="w-full px-3 py-2.5 rounded-xl border border-slate-300 text-sm bg-white"
              >
                <option value="">-- Pilih Tier Harga (Opsional) --</option>
                {tiers.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name} ({t.code})
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Credit Limit (Rp)
              </label>
              <input
                type="number"
                min="0"
                required
                value={custCreditLimit}
                onChange={(e) => setCustCreditLimit(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Termin Tempo (Hari)
              </label>
              <input
                type="number"
                min="0"
                required
                value={custTermDays}
                onChange={(e) => setCustTermDays(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                No. HP / Telepon <span className="text-rose-600">*</span>
              </label>
              <input
                type="text"
                required
                value={custPhone}
                onChange={(e) => setCustPhone(e.target.value)}
                placeholder="0812-xxxx-xxxx"
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Nama Usaha / Toko
              </label>
              <input
                type="text"
                value={custCompany}
                onChange={(e) => setCustCompany(e.target.value)}
                placeholder="Toko Agen Sejahtera"
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Kota</label>
              <input
                type="text"
                value={custCity}
                onChange={(e) => setCustCity(e.target.value)}
                placeholder="Tangerang"
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                NIK / KTP (Opsional — Terlindungi)
              </label>
              <input
                type="text"
                maxLength={20}
                value={custNik}
                onChange={(e) => setCustNik(e.target.value.replace(/\D/g, ''))}
                placeholder="16 digit NIK KTP"
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm font-mono"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">Alamat Pengiriman</label>
            <input
              type="text"
              value={custAddress}
              onChange={(e) => setCustAddress(e.target.value)}
              className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
            />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" type="button" onClick={() => setShowCustModal(false)}>
              Batal
            </Button>
            <Button variant="primary" type="submit" loading={submittingCust}>
              Simpan Pelanggan
            </Button>
          </div>
        </form>
      </Modal>

      {/* Modal Detail Pelanggan, Histori Transaksi & Pembayaran */}
      <Modal
        isOpen={showDetailModal}
        onClose={() => setShowDetailModal(false)}
        title={detailData ? `Detail Pelanggan • ${detailData.customer.name}` : 'Detail Pelanggan'}
        subtitle="Identitas pelanggan, proteksi NIK/KTP, riwayat transaksi POS & histori cicilan invoice"
        maxWidth="3xl"
      >
        {loadingDetail || !detailData ? (
          <div className="py-10 text-center text-xs text-slate-500">
            Memuat riwayat transaksi dan pembayaran pelanggan...
          </div>
        ) : (
          <div className="space-y-4 text-xs">
            <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <div className="text-[10px] font-bold uppercase text-slate-400">Pelanggan</div>
                <div className="text-sm font-extrabold text-slate-900">
                  {detailData.customer.name}
                </div>
                <div className="font-mono text-slate-600">{detailData.customer.code}</div>
                {detailData.customer.company_name && (
                  <div className="text-slate-600">{detailData.customer.company_name}</div>
                )}
              </div>
              <div>
                <div className="text-[10px] font-bold uppercase text-slate-400">
                  Kontak & NIK/KTP
                </div>
                <div className="font-semibold text-slate-800">
                  HP: {detailData.customer.phone || '-'}
                </div>
                <div className="text-slate-600">Alamat: {detailData.customer.address || '-'}</div>
                <div className="flex items-center gap-1.5 mt-1">
                  <span>NIK:</span>
                  <span className="font-mono font-bold">
                    {revealedNik || detailData.customer.masked_nik || 'Tidak diisi'}
                  </span>
                  {detailData.customer.has_nik && detailData.customer.can_reveal_nik && (
                    <button
                      type="button"
                      onClick={() => {
                        if (revealedNik) {
                          setRevealedNik(null);
                        } else {
                          openCustomerDetail(detailData.customer.id, true);
                        }
                      }}
                      className="text-blue-600 hover:underline inline-flex items-center gap-0.5 font-semibold cursor-pointer"
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
                  Saldo Piutang (AR)
                </div>
                <div className="text-base font-black text-amber-700 tabular-nums">
                  {formatIDR(detailData.customer.outstanding_balance)}
                </div>
                <div className="text-slate-500">
                  Credit Limit: {formatIDR(detailData.customer.credit_limit)}
                </div>
              </div>
            </div>

            <div className="space-y-2">
              <div className="font-bold text-slate-800 uppercase">
                Riwayat Invoice & Cicilan ({detailData.invoices.length})
              </div>
              {detailData.invoices.length === 0 ? (
                <div className="p-4 text-center text-slate-400 bg-slate-50 rounded-xl border border-slate-200">
                  Belum ada invoice untuk pelanggan ini.
                </div>
              ) : (
                <div className="max-h-56 overflow-y-auto space-y-2">
                  {detailData.invoices.map((inv) => (
                    <div
                      key={inv.id}
                      className="p-3 rounded-xl bg-white border border-slate-200 space-y-1.5"
                    >
                      <div className="flex items-center justify-between">
                        <div>
                          <span className="font-mono font-bold text-slate-900">
                            {inv.invoice_number}
                          </span>{' '}
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
                          <span className="ml-2 text-slate-500">
                            Jatuh Tempo: {inv.due_date}
                          </span>
                        </div>
                        <div className="text-right tabular-nums">
                          <span className="text-slate-500">
                            Total: {formatIDR(inv.grand_total)} • Dibayar:{' '}
                            {formatIDR(inv.paid_total)} •{' '}
                          </span>
                          <strong className="text-rose-600">
                            Sisa: {formatIDR(inv.balance_due)}
                          </strong>
                        </div>
                      </div>
                      {inv.allocations && inv.allocations.length > 0 && (
                        <div className="pl-2 border-l-2 border-emerald-300 space-y-1">
                          {inv.allocations.map((a) => (
                            <div
                              key={a.id}
                              className="flex justify-between text-[11px] text-slate-600"
                            >
                              <span>
                                {a.customer_payment?.payment_number} •{' '}
                                {a.customer_payment?.payment_date} ({a.customer_payment?.method})
                              </span>
                              <span className="font-bold text-emerald-700">
                                +{formatIDR(a.amount)}
                              </span>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
};

