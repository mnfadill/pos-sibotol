import React, { useEffect, useMemo, useState } from 'react';
import {
  Calculator,
  CheckCircle2,
  Edit3,
  Globe,
  Percent,
  Plus,
  RefreshCw,
  ShieldCheck,
  Sparkles,
  Store,
  Trash2,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatIDR } from '../../lib/format';
import type {
  MasterDiscountRecord,
  OutletInfo,
  OutletTaxConfig,
  PricingRulesResponse,
} from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card, StatCard } from '../ui/Card';
import { Modal } from '../ui/Modal';
import { useToast } from '../ui/ToastProvider';

interface Props {
  outlet: OutletInfo;
  onConfigUpdated?: () => void;
}

export const TaxAndDiscountMasterPage: React.FC<Props> = ({
  outlet,
  onConfigUpdated,
}) => {
  const { toast } = useToast();
  const [loading, setLoading] = useState<boolean>(true);
  const [outletsConfig, setOutletsConfig] = useState<OutletTaxConfig[]>([]);
  const [discounts, setDiscounts] = useState<MasterDiscountRecord[]>([]);

  // Tax & Service Charge Form State
  const [targetOutletId, setTargetOutletId] = useState<number>(outlet.id);
  const [applyToAllOutlets, setApplyToAllOutlets] = useState<boolean>(false);
  const [taxRateInput, setTaxRateInput] = useState<string>(
    String(parseFloat(outlet.tax_rate || '11')),
  );
  const [serviceChargeInput, setServiceChargeInput] = useState<string>(
    String(parseFloat(outlet.service_charge_rate || '0')),
  );
  const [maxCashierDiscInput, setMaxCashierDiscInput] = useState<string>(
    String(parseFloat(outlet.max_cashier_discount_percent || '10')),
  );
  const [savingTax, setSavingTax] = useState<boolean>(false);

  // Master Discount Modal State
  const [showDiscountModal, setShowDiscountModal] = useState<boolean>(false);
  const [editingDiscount, setEditingDiscount] = useState<MasterDiscountRecord | null>(null);
  const [discCode, setDiscCode] = useState<string>('');
  const [discName, setDiscName] = useState<string>('');
  const [discDescription, setDiscDescription] = useState<string>('');
  const [discType, setDiscType] = useState<'percent' | 'fixed'>('percent');
  const [discValue, setDiscValue] = useState<string>('10');
  const [discMinPurchase, setDiscMinPurchase] = useState<string>('0');
  const [discMaxDiscount, setDiscMaxDiscount] = useState<string>('');
  const [discAppliesTo, setDiscAppliesTo] = useState<'both' | 'pos' | 'b2b'>('both');
  const [discScopeOutletId, setDiscScopeOutletId] = useState<string>('global');
  const [discIsActive, setDiscIsActive] = useState<boolean>(true);
  const [savingDiscount, setSavingDiscount] = useState<boolean>(false);

  const fetchPricingRules = async (keepTargetOutletId?: number) => {
    setLoading(true);
    try {
      const res = await api.get<PricingRulesResponse>('/pricing-rules');
      setOutletsConfig(res.data.outlets_config);
      setDiscounts(res.data.discounts);

      const desiredId = keepTargetOutletId ?? outlet.id;
      const currentOutletCfg =
        res.data.outlets_config.find((o) => o.id === desiredId) ??
        res.data.outlets_config.find((o) => o.id === outlet.id) ??
        res.data.active_outlet_config;

      if (currentOutletCfg) {
        setTargetOutletId(currentOutletCfg.id);
        setTaxRateInput(String(parseFloat(currentOutletCfg.tax_rate)));
        setServiceChargeInput(String(parseFloat(currentOutletCfg.service_charge_rate)));
        setMaxCashierDiscInput(
          String(parseFloat(currentOutletCfg.max_cashier_discount_percent)),
        );
      }
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat Master Diskon & PPN',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchPricingRules();
  }, [outlet.id]);

  const handleSelectTargetOutlet = (selectedId: number) => {
    setTargetOutletId(selectedId);
    const found = outletsConfig.find((o) => o.id === selectedId);
    if (found) {
      setTaxRateInput(String(parseFloat(found.tax_rate)));
      setServiceChargeInput(String(parseFloat(found.service_charge_rate)));
      setMaxCashierDiscInput(String(parseFloat(found.max_cashier_discount_percent)));
    }
  };

  const selectedOutletConfig = useMemo(
    () => outletsConfig.find((o) => o.id === targetOutletId) ?? outlet,
    [outletsConfig, targetOutletId, outlet],
  );

  // Live Simulator Calculation (Sample Rp 100.000)
  const simulation = useMemo(() => {
    const sampleSubtotal = 100000;
    const scRate = Math.max(0, parseFloat(serviceChargeInput || '0'));
    const taxRate = Math.max(0, parseFloat(taxRateInput || '0'));

    const scAmount = Math.round((sampleSubtotal * scRate) / 100);
    const taxableBase = sampleSubtotal + scAmount;
    const taxAmount = Math.round((taxableBase * taxRate) / 100);
    const grandTotal = taxableBase + taxAmount;

    return {
      sampleSubtotal,
      scRate,
      scAmount,
      taxRate,
      taxAmount,
      grandTotal,
    };
  }, [taxRateInput, serviceChargeInput]);

  const handleSaveTaxConfig = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingTax(true);
    try {
      await api.put('/pricing-rules/outlet-tax', {
        outlet_id: targetOutletId,
        apply_to_all_outlets: applyToAllOutlets,
        tax_rate: parseFloat(taxRateInput || '0'),
        service_charge_rate: parseFloat(serviceChargeInput || '0'),
        max_cashier_discount_percent: parseFloat(maxCashierDiscInput || '0'),
      });
      toast({
        type: 'success',
        title: 'Konfigurasi PPN & Service Charge Disimpan',
        description: applyToAllOutlets
          ? 'Tarif PPN, Service Charge, dan batas diskon kasir berhasil diterapkan ke seluruh outlet.'
          : 'Tarif PPN, Service Charge, dan batas diskon outlet berhasil diperbarui.',
      });
      await fetchPricingRules(targetOutletId);
      onConfigUpdated?.();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menyimpan konfigurasi PPN & Service Charge',
        description: extractErrorMessage(err),
      });
    } finally {
      setSavingTax(false);
    }
  };

  const openCreateDiscountModal = () => {
    setEditingDiscount(null);
    setDiscCode('');
    setDiscName('');
    setDiscDescription('');
    setDiscType('percent');
    setDiscValue('10');
    setDiscMinPurchase('50000');
    setDiscMaxDiscount('');
    setDiscAppliesTo('both');
    setDiscScopeOutletId('global');
    setDiscIsActive(true);
    setShowDiscountModal(true);
  };

  const openEditDiscountModal = (disc: MasterDiscountRecord) => {
    setEditingDiscount(disc);
    setDiscCode(disc.code);
    setDiscName(disc.name);
    setDiscDescription(disc.description ?? '');
    setDiscType(disc.type);
    setDiscValue(String(parseFloat(disc.value)));
    setDiscMinPurchase(String(parseFloat(disc.min_purchase || '0')));
    setDiscMaxDiscount(disc.max_discount ? String(parseFloat(disc.max_discount)) : '');
    setDiscAppliesTo(disc.applies_to);
    setDiscScopeOutletId(disc.outlet_id ? String(disc.outlet_id) : 'global');
    setDiscIsActive(disc.is_active);
    setShowDiscountModal(true);
  };

  const handleSaveDiscount = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingDiscount(true);
    try {
      const payload = {
        code: discCode.trim().toUpperCase(),
        name: discName.trim(),
        description: discDescription.trim() || null,
        type: discType,
        value: parseFloat(discValue || '0'),
        min_purchase: parseFloat(discMinPurchase || '0'),
        max_discount:
          discType === 'percent' && discMaxDiscount.trim() !== ''
            ? parseFloat(discMaxDiscount)
            : null,
        applies_to: discAppliesTo,
        outlet_id: discScopeOutletId === 'global' ? null : Number(discScopeOutletId),
        is_active: discIsActive,
      };

      if (editingDiscount) {
        await api.put(`/pricing-rules/discounts/${editingDiscount.id}`, payload);
        toast({
          type: 'success',
          title: 'Master Diskon Diperbarui',
          description: `Program diskon ${payload.code} berhasil disimpan.`,
        });
      } else {
        await api.post('/pricing-rules/discounts', payload);
        toast({
          type: 'success',
          title: 'Master Diskon Ditambahkan',
          description: `Program diskon ${payload.code} siap digunakan di kasir & B2B.`,
        });
      }

      setShowDiscountModal(false);
      await fetchPricingRules();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menyimpan Master Diskon',
        description: extractErrorMessage(err),
      });
    } finally {
      setSavingDiscount(false);
    }
  };

  const handleToggleDiscountActive = async (disc: MasterDiscountRecord) => {
    try {
      await api.put(`/pricing-rules/discounts/${disc.id}`, {
        is_active: !disc.is_active,
      });
      toast({
        type: 'info',
        title: disc.is_active ? 'Diskon Dinonaktifkan' : 'Diskon Diaktifkan',
        description: `${disc.code} (${disc.name}) sekarang ${!disc.is_active ? 'Aktif' : 'Nonaktif'}.`,
      });
      await fetchPricingRules();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal mengubah status diskon',
        description: extractErrorMessage(err),
      });
    }
  };

  const handleDeleteDiscount = async (disc: MasterDiscountRecord) => {
    try {
      await api.delete(`/pricing-rules/discounts/${disc.id}`);
      toast({
        type: 'success',
        title: 'Master Diskon Dihapus',
        description: `Kode diskon ${disc.code} telah dihapus.`,
      });
      await fetchPricingRules();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menghapus diskon',
        description: extractErrorMessage(err),
      });
    }
  };

  return (
    <div className="p-5 space-y-5 max-w-[1600px] mx-auto">
      {/* Top Header */}
      <div className="flex flex-wrap items-center justify-between gap-3 bg-white p-4 rounded-xl border border-slate-200 shadow-2xs">
        <div>
          <div className="flex items-center gap-2">
            <h2 className="text-base font-extrabold text-slate-900">
              Master Diskon, PPN (Pajak) &amp; Service Charge
            </h2>
            <Badge variant="primary">{outlet.code}</Badge>
          </div>
          <p className="text-xs text-slate-500 mt-0.5">
            Atur persentase PPN, Service Charge, batas otorisasi diskon kasir, serta daftar voucher/program diskon untuk Counter POS &amp; B2B Sales Order.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            loading={loading}
            icon={<RefreshCw className="w-3.5 h-3.5" />}
            onClick={() => fetchPricingRules(targetOutletId)}
          >
            Segarkan
          </Button>
          <Button
            variant="primary"
            size="sm"
            icon={<Plus className="w-4 h-4" />}
            onClick={openCreateDiscountModal}
          >
            Buat Master Diskon Baru
          </Button>
        </div>
      </div>

      {/* 3 Summary StatCards for Selected Outlet */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <StatCard
          label={`Tarif PPN Aktif (${selectedOutletConfig.name})`}
          value={`${parseFloat(selectedOutletConfig.tax_rate)}%`}
          subtext="Otomatis dihitung pada struk POS & Invoice B2B"
          accent="blue"
          icon={<Percent className="w-5 h-5" />}
        />
        <StatCard
          label={`Service Charge (${selectedOutletConfig.name})`}
          value={`${parseFloat(selectedOutletConfig.service_charge_rate)}%`}
          subtext="Biaya layanan tambahan sebelum pajak PPN"
          accent="emerald"
          icon={<Sparkles className="w-5 h-5" />}
        />
        <StatCard
          label="Batas Diskon Kasir (Tanpa PIN)"
          value={`Maks. ${parseFloat(selectedOutletConfig.max_cashier_discount_percent)}%`}
          subtext="Di atas batas ini wajib otorisasi PIN Supervisor"
          accent="amber"
          icon={<ShieldCheck className="w-5 h-5" />}
        />
      </div>

      {/* Section 1: Konfigurasi PPN, Service Charge & Simulasi Perhitungan */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        {/* Form Pengaturan PPN & Service Charge (7 cols) */}
        <Card
          className="lg:col-span-7"
          title="Pengaturan PPN (Pajak), Service Charge & Otorisasi Diskon"
          subtitle="Konfigurasi dapat disimpan untuk satu outlet spesifik atau diterapkan serentak ke seluruh outlet."
        >
          <form onSubmit={handleSaveTaxConfig} className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-slate-50 p-3.5 rounded-xl border border-slate-200">
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  Pilih Outlet yang Diatur
                </label>
                <select
                  value={targetOutletId}
                  disabled={applyToAllOutlets}
                  onChange={(e) => handleSelectTargetOutlet(Number(e.target.value))}
                  className="w-full px-3 py-2 rounded-lg bg-white border border-slate-300 text-xs font-semibold text-slate-900 disabled:opacity-50"
                >
                  {outletsConfig.map((o) => (
                    <option key={o.id} value={o.id}>
                      {o.name} ({o.code}) — PPN {parseFloat(o.tax_rate)}%
                    </option>
                  ))}
                </select>
              </div>

              <div className="flex items-end pb-1">
                <label className="inline-flex items-center gap-2.5 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={applyToAllOutlets}
                    onChange={(e) => setApplyToAllOutlets(e.target.checked)}
                    className="w-4 h-4 rounded border-slate-300 text-blue-600"
                  />
                  <div>
                    <div className="text-xs font-bold text-slate-800 flex items-center gap-1">
                      <Globe className="w-3.5 h-3.5 text-blue-600" />
                      Terapkan ke Seluruh Outlet ({outletsConfig.length} Cabang)
                    </div>
                    <div className="text-[11px] text-slate-500">
                      Menyamakan tarif PPN &amp; Service Charge di semua cabang
                    </div>
                  </div>
                </label>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              {/* PPN Input */}
              <div className="p-3.5 rounded-xl border border-slate-200 bg-white space-y-2">
                <label className="block text-xs font-bold text-slate-800">
                  Tarif PPN / Pajak (%)
                </label>
                <div className="relative">
                  <input
                    type="number"
                    step="0.01"
                    min={0}
                    max={100}
                    required
                    value={taxRateInput}
                    onChange={(e) => setTaxRateInput(e.target.value)}
                    className="w-full px-3 py-2 pr-8 rounded-lg border border-slate-300 text-sm font-extrabold text-blue-600 focus:outline-none focus:border-blue-600"
                  />
                  <span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">
                    %
                  </span>
                </div>
                <div className="flex flex-wrap gap-1 pt-1">
                  {[
                    { label: '0% (Bebas PPN)', val: '0' },
                    { label: '11% (Standar)', val: '11' },
                    { label: '12% (PPN Baru)', val: '12' },
                  ].map((preset) => (
                    <button
                      key={preset.val}
                      type="button"
                      onClick={() => setTaxRateInput(preset.val)}
                      className={`px-2 py-0.5 rounded text-[10px] font-semibold border cursor-pointer ${
                        taxRateInput === preset.val
                          ? 'bg-blue-600 text-white border-blue-600'
                          : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100'
                      }`}
                    >
                      {preset.label}
                    </button>
                  ))}
                </div>
              </div>

              {/* Service Charge Input */}
              <div className="p-3.5 rounded-xl border border-slate-200 bg-white space-y-2">
                <label className="block text-xs font-bold text-slate-800">
                  Service Charge (%)
                </label>
                <div className="relative">
                  <input
                    type="number"
                    step="0.01"
                    min={0}
                    max={100}
                    required
                    value={serviceChargeInput}
                    onChange={(e) => setServiceChargeInput(e.target.value)}
                    className="w-full px-3 py-2 pr-8 rounded-lg border border-slate-300 text-sm font-extrabold text-emerald-600 focus:outline-none focus:border-blue-600"
                  />
                  <span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">
                    %
                  </span>
                </div>
                <div className="flex flex-wrap gap-1 pt-1">
                  {[
                    { label: '0% (Tanpa SC)', val: '0' },
                    { label: '5% (Layanan)', val: '5' },
                    { label: '10% (Resto)', val: '10' },
                  ].map((preset) => (
                    <button
                      key={preset.val}
                      type="button"
                      onClick={() => setServiceChargeInput(preset.val)}
                      className={`px-2 py-0.5 rounded text-[10px] font-semibold border cursor-pointer ${
                        serviceChargeInput === preset.val
                          ? 'bg-emerald-600 text-white border-emerald-600'
                          : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100'
                      }`}
                    >
                      {preset.label}
                    </button>
                  ))}
                </div>
              </div>

              {/* Max Cashier Discount Input */}
              <div className="p-3.5 rounded-xl border border-slate-200 bg-white space-y-2">
                <label className="block text-xs font-bold text-slate-800">
                  Maks. Diskon Kasir (%)
                </label>
                <div className="relative">
                  <input
                    type="number"
                    step="0.01"
                    min={0}
                    max={100}
                    required
                    value={maxCashierDiscInput}
                    onChange={(e) => setMaxCashierDiscInput(e.target.value)}
                    className="w-full px-3 py-2 pr-8 rounded-lg border border-slate-300 text-sm font-extrabold text-amber-600 focus:outline-none focus:border-blue-600"
                  />
                  <span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">
                    %
                  </span>
                </div>
                <div className="flex flex-wrap gap-1 pt-1">
                  {[
                    { label: '0% (Wajib PIN)', val: '0' },
                    { label: '10% (Standar)', val: '10' },
                    { label: '20% (Longgar)', val: '20' },
                  ].map((preset) => (
                    <button
                      key={preset.val}
                      type="button"
                      onClick={() => setMaxCashierDiscInput(preset.val)}
                      className={`px-2 py-0.5 rounded text-[10px] font-semibold border cursor-pointer ${
                        maxCashierDiscInput === preset.val
                          ? 'bg-amber-500 text-white border-amber-500'
                          : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100'
                      }`}
                    >
                      {preset.label}
                    </button>
                  ))}
                </div>
              </div>
            </div>

            <div className="flex items-center justify-between pt-2 border-t border-slate-100">
              <span className="text-[11px] text-slate-500">
                Perubahan langsung berlaku pada perhitungan keranjang Kasir POS &amp; pembuatan B2B Sales Order baru.
              </span>
              <Button
                type="submit"
                variant="primary"
                loading={savingTax}
                icon={<CheckCircle2 className="w-4 h-4" />}
              >
                Simpan Tarif PPN &amp; Service Charge
              </Button>
            </div>
          </form>
        </Card>

        {/* Live Simulator & Multi-Outlet Tax Table (5 cols) */}
        <div className="lg:col-span-5 space-y-4">
          <Card
            title="Simulasi Perhitungan Struk"
            subtitle="Contoh simulasi transaksi Rp 100.000 dengan tarif di samping"
          >
            <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 space-y-2 text-xs tabular-nums">
              <div className="flex justify-between text-slate-600">
                <span>Subtotal Penjualan Bersih</span>
                <span className="font-bold text-slate-900">
                  {formatIDR(simulation.sampleSubtotal)}
                </span>
              </div>
              <div className="flex justify-between text-slate-600">
                <span>Service Charge ({simulation.scRate}%)</span>
                <span className="font-semibold text-emerald-700">
                  +{formatIDR(simulation.scAmount)}
                </span>
              </div>
              <div className="flex justify-between text-slate-600">
                <span>PPN ({simulation.taxRate}%)</span>
                <span className="font-semibold text-blue-700">
                  +{formatIDR(simulation.taxAmount)}
                </span>
              </div>
              <div className="pt-2 border-t border-slate-200 flex justify-between items-baseline">
                <span className="font-extrabold text-slate-900 flex items-center gap-1">
                  <Calculator className="w-3.5 h-3.5 text-blue-600" />
                  Total Tagihan Pelanggan
                </span>
                <span className="text-base font-black text-blue-600">
                  {formatIDR(simulation.grandTotal)}
                </span>
              </div>
            </div>

            {/* Daftar Tarif PPN & Service Charge Seluruh Outlet */}
            <div className="mt-4">
              <div className="text-xs font-bold text-slate-700 mb-2">
                Status Tarif di Seluruh Outlet ({outletsConfig.length})
              </div>
              <div className="divide-y divide-slate-100 border border-slate-200 rounded-xl overflow-hidden text-xs">
                {outletsConfig.map((o) => (
                  <div
                    key={o.id}
                    className={`px-3 py-2 flex items-center justify-between ${
                      o.id === targetOutletId ? 'bg-blue-50/60' : 'bg-white'
                    }`}
                  >
                    <div className="flex items-center gap-2 min-w-0">
                      <Store className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                      <span className="font-bold text-slate-800 truncate">{o.name}</span>
                      <Badge variant="neutral" size="xs">
                        {o.code}
                      </Badge>
                    </div>
                    <div className="flex items-center gap-2 shrink-0 tabular-nums">
                      <Badge variant="primary" size="xs">
                        PPN {parseFloat(o.tax_rate)}%
                      </Badge>
                      <Badge variant="success" size="xs">
                        SC {parseFloat(o.service_charge_rate)}%
                      </Badge>
                      <button
                        type="button"
                        onClick={() => {
                          setApplyToAllOutlets(false);
                          handleSelectTargetOutlet(o.id);
                        }}
                        className="text-[11px] font-semibold text-blue-600 hover:underline cursor-pointer ml-1"
                      >
                        Pilih
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </Card>
        </div>
      </div>

      {/* Section 2: Tabel Master Diskon & Voucher Promo */}
      <Card
        padding="none"
        className="overflow-hidden"
      >
        <div className="p-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-sm font-bold text-slate-900">
              Daftar Master Diskon &amp; Program Promo ({discounts.length})
            </h3>
            <p className="text-xs text-slate-500">
              Master diskon yang aktif dapat langsung dipilih dengan 1 klik oleh Kasir di layar POS maupun Sales Order B2B.
            </p>
          </div>
          <Button
            variant="primary"
            size="sm"
            icon={<Plus className="w-3.5 h-3.5" />}
            onClick={openCreateDiscountModal}
          >
            Tambah Program Diskon
          </Button>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                <th className="py-3 px-4">Kode Diskon</th>
                <th className="py-3 px-4">Nama Program &amp; Deskripsi</th>
                <th className="py-3 px-4">Tipe &amp; Besaran</th>
                <th className="py-3 px-4">Syarat Min. Belanja</th>
                <th className="py-3 px-4">Kanal &amp; Cakupan Outlet</th>
                <th className="py-3 px-4">Status</th>
                <th className="py-3 px-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {discounts.length === 0 ? (
                <tr>
                  <td colSpan={7} className="py-8 text-center text-slate-400">
                    Belum ada Master Diskon terdaftar. Klik &ldquo;Tambah Program Diskon&rdquo; untuk membuat baru.
                  </td>
                </tr>
              ) : (
                discounts.map((disc) => (
                  <tr key={disc.id} className="hover:bg-slate-50/80">
                    <td className="py-3 px-4">
                      <span className="font-mono font-bold text-blue-700 bg-blue-50 border border-blue-200 px-2.5 py-1 rounded-lg">
                        {disc.code}
                      </span>
                    </td>
                    <td className="py-3 px-4">
                      <div className="font-bold text-slate-900">{disc.name}</div>
                      {disc.description && (
                        <div className="text-[11px] text-slate-500">{disc.description}</div>
                      )}
                    </td>
                    <td className="py-3 px-4 tabular-nums">
                      <div className="font-extrabold text-emerald-700">
                        {disc.type === 'percent'
                          ? `Diskon ${parseFloat(disc.value)}%`
                          : `Potongan ${formatIDR(disc.value)}`}
                      </div>
                      {disc.type === 'percent' && disc.max_discount && (
                        <div className="text-[10px] text-slate-500">
                          Maks. Potongan: {formatIDR(disc.max_discount)}
                        </div>
                      )}
                    </td>
                    <td className="py-3 px-4 tabular-nums font-semibold text-slate-700">
                      {parseFloat(disc.min_purchase) > 0
                        ? formatIDR(disc.min_purchase)
                        : 'Tanpa Minimum'}
                    </td>
                    <td className="py-3 px-4">
                      <div className="flex flex-wrap items-center gap-1.5">
                        <Badge
                          variant={
                            disc.applies_to === 'both'
                              ? 'primary'
                              : disc.applies_to === 'pos'
                                ? 'info'
                                : 'purple'
                          }
                          size="xs"
                        >
                          {disc.applies_to === 'both'
                            ? 'POS & B2B'
                            : disc.applies_to === 'pos'
                              ? 'Khusus POS'
                              : 'Khusus B2B'}
                        </Badge>
                        <Badge variant="neutral" size="xs">
                          {disc.outlet ? disc.outlet.name : 'Seluruh Outlet'}
                        </Badge>
                      </div>
                    </td>
                    <td className="py-3 px-4">
                      <button
                        type="button"
                        onClick={() => handleToggleDiscountActive(disc)}
                        className="cursor-pointer"
                      >
                        <Badge variant={disc.is_active ? 'success' : 'danger'} dot>
                          {disc.is_active ? 'Aktif' : 'Nonaktif'}
                        </Badge>
                      </button>
                    </td>
                    <td className="py-3 px-4 text-right">
                      <div className="inline-flex items-center gap-1.5">
                        <Button
                          variant="outline"
                          size="xs"
                          icon={<Edit3 className="w-3.5 h-3.5" />}
                          onClick={() => openEditDiscountModal(disc)}
                        >
                          Edit
                        </Button>
                        <Button
                          variant="ghost"
                          size="xs"
                          icon={<Trash2 className="w-3.5 h-3.5 text-rose-600" />}
                          onClick={() => handleDeleteDiscount(disc)}
                        />
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </Card>

      {/* Modal Buat / Edit Master Diskon */}
      <Modal
        isOpen={showDiscountModal}
        onClose={() => setShowDiscountModal(false)}
        title={editingDiscount ? `Edit Master Diskon • ${editingDiscount.code}` : 'Buat Master Diskon Baru'}
        subtitle="Program diskon akan tampil sebagai pilihan cepat di Kasir POS dan B2B Sales Order"
        maxWidth="lg"
      >
        <form onSubmit={handleSaveDiscount} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Kode Diskon / Voucher
              </label>
              <input
                type="text"
                required
                value={discCode}
                onChange={(e) => setDiscCode(e.target.value.toUpperCase())}
                placeholder="Contoh: MEMBER-10"
                className="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs font-mono font-bold text-slate-900 uppercase"
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Nama Program Diskon
              </label>
              <input
                type="text"
                required
                value={discName}
                onChange={(e) => setDiscName(e.target.value)}
                placeholder="Contoh: Diskon Member SiBotol 10%"
                className="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs font-semibold text-slate-900"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">
              Deskripsi / Keterangan Singkat (Opsional)
            </label>
            <input
              type="text"
              value={discDescription}
              onChange={(e) => setDiscDescription(e.target.value)}
              placeholder="Contoh: Berlaku untuk pembelian produk minuman botol minimal Rp 100.000"
              className="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs text-slate-900"
            />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Tipe Potongan
              </label>
              <select
                value={discType}
                onChange={(e) => setDiscType(e.target.value as 'percent' | 'fixed')}
                className="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs font-semibold text-slate-900 bg-white"
              >
                <option value="percent">Persentase (%)</option>
                <option value="fixed">Nominal Rupiah (Rp)</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                {discType === 'percent' ? 'Besaran Diskon (%)' : 'Nominal Potongan (IDR)'}
              </label>
              <input
                type="number"
                step="0.01"
                min={0.01}
                max={discType === 'percent' ? 100 : undefined}
                required
                value={discValue}
                onChange={(e) => setDiscValue(e.target.value)}
                className="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs font-bold text-slate-900"
              />
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Minimal Belanja (IDR)
              </label>
              <input
                type="number"
                step="500"
                min={0}
                value={discMinPurchase}
                onChange={(e) => setDiscMinPurchase(e.target.value)}
                className="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs font-semibold text-slate-900"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            {discType === 'percent' && (
              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  Plafon Maks. Diskon (IDR)
                </label>
                <input
                  type="number"
                  step="1000"
                  min={0}
                  value={discMaxDiscount}
                  onChange={(e) => setDiscMaxDiscount(e.target.value)}
                  placeholder="Kosongkan jika tanpa batas"
                  className="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs text-slate-900"
                />
              </div>
            )}

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Berlaku di Kanal
              </label>
              <select
                value={discAppliesTo}
                onChange={(e) => setDiscAppliesTo(e.target.value as 'both' | 'pos' | 'b2b')}
                className="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs font-semibold text-slate-900 bg-white"
              >
                <option value="both">Counter POS &amp; B2B SO</option>
                <option value="pos">Khusus Counter POS</option>
                <option value="b2b">Khusus B2B Sales Order</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Cakupan Outlet
              </label>
              <select
                value={discScopeOutletId}
                onChange={(e) => setDiscScopeOutletId(e.target.value)}
                className="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs font-semibold text-slate-900 bg-white"
              >
                <option value="global">Seluruh Outlet Perusahaan</option>
                {outletsConfig.map((o) => (
                  <option key={o.id} value={String(o.id)}>
                    Khusus {o.name} ({o.code})
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="pt-2 flex items-center justify-between border-t border-slate-100">
            <label className="inline-flex items-center gap-2 cursor-pointer select-none">
              <input
                type="checkbox"
                checked={discIsActive}
                onChange={(e) => setDiscIsActive(e.target.checked)}
                className="w-4 h-4 rounded border-slate-300 text-blue-600"
              />
              <span className="text-xs font-bold text-slate-800">
                Status Program Diskon Aktif
              </span>
            </label>

            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => setShowDiscountModal(false)}
              >
                Batal
              </Button>
              <Button
                type="submit"
                variant="primary"
                loading={savingDiscount}
                icon={<CheckCircle2 className="w-4 h-4" />}
              >
                {editingDiscount ? 'Simpan Perubahan' : 'Simpan Master Diskon'}
              </Button>
            </div>
          </div>
        </form>
      </Modal>
    </div>
  );
};

