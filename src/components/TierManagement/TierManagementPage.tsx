import React, { useEffect, useState } from 'react';
import {
  AlertTriangle,
  Boxes,
  CheckCircle2,
  ChevronRight,
  Layers,
  Package,
  Plus,
  RefreshCw,
  Search,
  ShieldCheck,
  Tag,
  Trash2,
  Users,
  X,
} from 'lucide-react';
import { api } from '../../lib/api';
import type { DynamicTier } from '../../types/pos';
import { CreateTierWizardModal } from './CreateTierWizardModal';
import { TierDetailPage } from './TierDetailPage';

export const TierManagementPage: React.FC = () => {
  const [tiers, setTiers] = useState<DynamicTier[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [actionMessage, setActionMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Stepper Modal State
  const [showWizardModal, setShowWizardModal] = useState<boolean>(false);

  // Selected Tier for Detail View
  const [selectedTierId, setSelectedTierId] = useState<number | null>(null);

  const loadTiers = async () => {
    setLoading(true);
    try {
      const res = await api.get<{ tiers: DynamicTier[] }>('/tiers');
      setTiers(res.data.tiers || []);
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal memuat daftar Tier.',
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadTiers();
  }, []);

  const handleDeleteTier = async (t: DynamicTier) => {
    if (!confirm(`Hapus Tier "${t.name}"? Pastikan tidak ada pelanggan aktif yang bergantung pada Tier ini.`)) {
      return;
    }

    try {
      await api.delete(`/tiers/${t.id}`);
      setActionMessage({ type: 'success', text: `Tier "${t.name}" berhasil dihapus.` });
      await loadTiers();
    } catch (err: any) {
      setActionMessage({
        type: 'error',
        text: err?.response?.data?.message || 'Gagal menghapus Tier.',
      });
    }
  };

  const filteredTiers = tiers.filter(
    (t) =>
      t.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
      t.code.toLowerCase().includes(searchTerm.toLowerCase())
  );

  // If a Tier is selected, render the dedicated Detail View
  if (selectedTierId !== null) {
    return <TierDetailPage tierId={selectedTierId} onBack={() => { setSelectedTierId(null); loadTiers(); }} />;
  }

  const totalAssignedProducts = tiers.reduce((acc, t) => acc + (t.total_products_count || 0), 0);
  const totalCustomers = tiers.reduce((acc, t) => acc + (t.customers_count || 0), 0);

  return (
    <div className="p-6 space-y-6">
      {/* Top Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-800 flex items-center gap-2">
            <Layers className="w-7 h-7 text-indigo-600" />
            Manajemen Tier & Konfigurasi Harga Produk
          </h1>
          <p className="text-xs text-slate-500 mt-1">
            Buat Tier baru dinamis dan tetapkan harga jual produk mandiri per Tier dalam satu alur terintegrasi POS.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            type="button"
            onClick={loadTiers}
            disabled={loading}
            className="flex items-center gap-1.5 px-3 py-2 bg-white border border-slate-200 text-slate-700 rounded-xl hover:bg-slate-50 transition text-xs font-semibold shadow-xs cursor-pointer"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            Muat Ulang
          </button>

          <button
            type="button"
            onClick={() => setShowWizardModal(true)}
            className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition text-xs font-bold shadow-sm cursor-pointer"
          >
            <Plus className="w-4 h-4" />
            Buat Tier Baru (+ Atur Harga)
          </button>
        </div>
      </div>

      {/* Notifications */}
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

      {/* Metrics Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Total Tier Aktif</span>
            <span className="p-2 bg-indigo-50 rounded-xl text-indigo-600">
              <Layers className="w-5 h-5" />
            </span>
          </div>
          <p className="text-2xl font-black text-slate-800 mt-2">{tiers.length}</p>
          <span className="text-[11px] text-slate-400">Kategori harga penjualan</span>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-500">
              Produk Terkonfigurasi
            </span>
            <span className="p-2 bg-emerald-50 rounded-xl text-emerald-600">
              <Package className="w-5 h-5" />
            </span>
          </div>
          <p className="text-2xl font-black text-slate-800 mt-2">{totalAssignedProducts}</p>
          <span className="text-[11px] text-slate-400">Total item di seluruh tier</span>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs">
          <div className="flex items-center justify-between">
            <span className="text-xs font-bold uppercase tracking-wider text-slate-500">
              Pelanggan Terhubung
            </span>
            <span className="p-2 bg-blue-50 rounded-xl text-blue-600">
              <Users className="w-5 h-5" />
            </span>
          </div>
          <p className="text-2xl font-black text-slate-800 mt-2">{totalCustomers}</p>
          <span className="text-[11px] text-slate-400">Customer menggunakan tier</span>
        </div>
      </div>

      {/* Search Bar */}
      <div className="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs flex items-center gap-3">
        <div className="relative flex-1">
          <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            placeholder="Cari berdasarkan nama tier atau kode..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
      </div>

      {/* Tiers List / Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {loading ? (
          <div className="col-span-full py-12 text-center text-slate-400">
            <RefreshCw className="w-6 h-6 animate-spin mx-auto mb-2 text-indigo-600" />
            Memuat daftar Tier...
          </div>
        ) : filteredTiers.length === 0 ? (
          <div className="col-span-full py-12 text-center text-slate-400 bg-white rounded-2xl border border-slate-200">
            Belum ada Tier ditemukan. Klik "Buat Tier Baru" di pojok kanan atas untuk memulai.
          </div>
        ) : (
          filteredTiers.map((t) => (
            <div
              key={t.id}
              className="bg-white rounded-2xl border border-slate-200/90 shadow-xs hover:shadow-md transition flex flex-col justify-between overflow-hidden"
            >
              <div className="p-5 space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <span
                      className="w-3.5 h-3.5 rounded-full shrink-0"
                      style={{ backgroundColor: t.color || '#2563EB' }}
                    ></span>
                    <h3 className="font-bold text-slate-900 text-sm">{t.name}</h3>
                  </div>
                  <span
                    className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ${
                      t.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'
                    }`}
                  >
                    {t.status === 'active' ? 'Aktif' : 'Nonaktif'}
                  </span>
                </div>

                <div className="flex items-center gap-2 text-xs">
                  <span className="font-mono bg-slate-100 text-slate-700 px-2 py-0.5 rounded font-bold">
                    {t.code}
                  </span>
                  <span className="text-slate-400">• Urutan: #{t.display_order}</span>
                </div>

                <p className="text-xs text-slate-500 line-clamp-2 min-h-[32px]">
                  {t.description || 'Tidak ada deskripsi khusus.'}
                </p>

                {/* Stats row */}
                <div className="pt-2 border-t border-slate-100 grid grid-cols-2 gap-2 text-xs">
                  <div className="bg-slate-50 p-2 rounded-xl">
                    <span className="text-[10px] text-slate-400 block font-semibold">Produk Ditetapkan</span>
                    <span className="font-extrabold text-slate-800">{t.total_products_count || 0} item</span>
                  </div>
                  <div className="bg-slate-50 p-2 rounded-xl">
                    <span className="text-[10px] text-slate-400 block font-semibold">Pelanggan Aktif</span>
                    <span className="font-extrabold text-slate-800">{t.customers_count || 0} customer</span>
                  </div>
                </div>
              </div>

              {/* Card Footer Actions */}
              <div className="p-3 bg-slate-50/70 border-t border-slate-100 flex items-center justify-between">
                <button
                  type="button"
                  onClick={() => handleDeleteTier(t)}
                  className="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition cursor-pointer"
                  title="Hapus Tier"
                >
                  <Trash2 className="w-4 h-4" />
                </button>

                <button
                  type="button"
                  onClick={() => setSelectedTierId(t.id)}
                  className="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-xs"
                >
                  Detail & Atur Harga
                  <ChevronRight className="w-3.5 h-3.5" />
                </button>
              </div>
            </div>
          ))
        )}
      </div>

      {/* Stepper Wizard Modal */}
      <CreateTierWizardModal
        isOpen={showWizardModal}
        onClose={() => setShowWizardModal(false)}
        onSuccess={() => {
          loadTiers();
          setActionMessage({
            type: 'success',
            text: 'Tier baru beserta konfigurasi harga produknya berhasil dibuat!',
          });
        }}
      />
    </div>
  );
};

