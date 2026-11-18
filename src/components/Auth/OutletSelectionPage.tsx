import React, { useMemo, useState } from 'react';
import {
  ArrowRight,
  Building2,
  CheckCircle2,
  Clock,
  Globe,
  LogOut,
  MapPin,
  Monitor,
  Plus,
  Search,
  ShieldCheck,
  Star,
  Store,
  Warehouse,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime } from '../../lib/format';
import type { OutletInfo, UserSession } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';

interface Props {
  session: UserSession;
  onSessionReady: (session: UserSession) => void;
  onOpenCompanyDashboard?: () => void;
  onLogout: () => void;
}

export const OutletSelectionPage: React.FC<Props> = ({
  session,
  onSessionReady,
  onOpenCompanyDashboard,
  onLogout,
}) => {
  const [search, setSearch] = useState<string>('');
  const [filterMode, setFilterMode] = useState<'all' | 'favorites' | 'recent'>('all');
  const [cityFilter, setCityFilter] = useState<string>('ALL');
  const [selectedTerminalByOutlet, setSelectedTerminalByOutlet] = useState<Record<number, number>>(
    () => {
      const initial: Record<number, number> = {};
      session.available_outlets.forEach((o) => {
        if (o.terminals && o.terminals.length > 0) {
          initial[o.id] = o.terminals[0].id;
        }
      });
      return initial;
    },
  );
  const [enteringOutletId, setEnteringOutletId] = useState<number | null>(null);
  const [togglingFavId, setTogglingFavId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Create New Outlet Modal (Super Admin / Management)
  const [showCreateModal, setShowCreateModal] = useState<boolean>(false);
  const [newCode, setNewCode] = useState<string>('');
  const [newName, setNewName] = useState<string>('');
  const [newCity, setNewCity] = useState<string>('');
  const [newProvince, setNewProvince] = useState<string>('Jawa Barat');
  const [newAddress, setNewAddress] = useState<string>('');
  const [newPhone, setNewPhone] = useState<string>('');
  const [creatingOutlet, setCreatingOutlet] = useState<boolean>(false);

  const cities = useMemo(() => {
    const set = new Set<string>();
    session.available_outlets.forEach((o) => {
      if (o.city) set.add(o.city);
    });
    return Array.from(set);
  }, [session.available_outlets]);

  const filteredOutlets = useMemo(() => {
    return session.available_outlets.filter((outlet) => {
      if (filterMode === 'favorites' && !outlet.is_favorite) return false;
      if (filterMode === 'recent' && !outlet.last_accessed_at) return false;
      if (cityFilter !== 'ALL' && outlet.city !== cityFilter) return false;

      if (search.trim() !== '') {
        const q = search.toLowerCase();
        const matchName = outlet.name.toLowerCase().includes(q);
        const matchCode = outlet.code.toLowerCase().includes(q);
        const matchCity = (outlet.city ?? '').toLowerCase().includes(q);
        const matchAddr = (outlet.address ?? '').toLowerCase().includes(q);
        const matchWh = (outlet.warehouse?.name ?? '').toLowerCase().includes(q);
        return matchName || matchCode || matchCity || matchAddr || matchWh;
      }

      return true;
    });
  }, [session.available_outlets, filterMode, cityFilter, search]);

  const handleEnterOutlet = async (outlet: OutletInfo) => {
    setError(null);
    setEnteringOutletId(outlet.id);
    try {
      const terminalId = selectedTerminalByOutlet[outlet.id] ?? outlet.terminals?.[0]?.id ?? null;
      const res = await api.post<{ session: UserSession }>('/auth/select-outlet', {
        outlet_id: outlet.id,
        pos_terminal_id: terminalId,
        warehouse_id: outlet.warehouse?.id ?? null,
      });

      const activeOutlet = res.data.session.active_outlet;
      const activeTerminal = res.data.session.active_terminal;
      if (activeOutlet) {
        localStorage.setItem('pos_outlet_id', String(activeOutlet.id));
      }
      if (activeTerminal) {
        localStorage.setItem('pos_terminal_id', String(activeTerminal.id));
      }

      onSessionReady(res.data.session);
    } catch (err) {
      setError(extractErrorMessage(err));
    } finally {
      setEnteringOutletId(null);
    }
  };

  const handleToggleFavorite = async (outletId: number, e: React.MouseEvent) => {
    e.stopPropagation();
    setTogglingFavId(outletId);
    try {
      const res = await api.post<{ session: UserSession }>(`/auth/outlets/${outletId}/favorite`);
      onSessionReady(res.data.session);
    } catch (err) {
      setError(extractErrorMessage(err));
    } finally {
      setTogglingFavId(null);
    }
  };

  const handleCreateOutlet = async (e: React.FormEvent) => {
    e.preventDefault();
    setCreatingOutlet(true);
    setError(null);
    try {
      await api.post('/company/outlets', {
        code: newCode,
        name: newName,
        city: newCity,
        province: newProvince,
        address: newAddress,
        phone: newPhone || undefined,
      });
      const meRes = await api.get<{ session: UserSession }>('/auth/me');
      onSessionReady(meRes.data.session);
      setShowCreateModal(false);
      setNewCode('');
      setNewName('');
      setNewCity('');
      setNewAddress('');
      setNewPhone('');
    } catch (err) {
      setError(extractErrorMessage(err));
    } finally {
      setCreatingOutlet(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-100 flex flex-col">
      {/* Top Identity Bar */}
      <header className="bg-slate-900 text-white border-b border-slate-800 px-6 py-4">
        <div className="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center shadow-sm shadow-blue-600/30">
              <Store className="w-5 h-5 text-white" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <span className="text-base font-extrabold tracking-tight">SiBotol</span>
                <span className="text-xs px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-300 font-semibold border border-blue-400/20">
                  Sub-Distributor & Multi-Outlet POS
                </span>
              </div>
              <p className="text-xs text-slate-400">
                {session.organization.name} ({session.organization.code})
              </p>
            </div>
          </div>

          <div className="flex items-center gap-3">
            {session.user.can_view_company_dashboard && onOpenCompanyDashboard && (
              <Button
                variant="primary"
                size="sm"
                leftIcon={<Globe className="w-4 h-4" />}
                onClick={onOpenCompanyDashboard}
              >
                Company Consolidated Dashboard
              </Button>
            )}
            <div className="flex items-center gap-2.5 bg-slate-800/90 border border-slate-700 rounded-xl px-3.5 py-2">
              <ShieldCheck className="w-4 h-4 text-emerald-400" />
              <div className="text-xs">
                <div className="font-bold text-white">{session.user.name}</div>
                <div className="text-slate-400">{session.user.role_display}</div>
              </div>
            </div>
            <Button
              variant="outline"
              size="sm"
              onClick={onLogout}
              leftIcon={<LogOut className="w-4 h-4" />}
              className="bg-slate-800 border-slate-700 text-slate-200 hover:bg-slate-700"
            >
              Keluar
            </Button>
          </div>
        </div>
      </header>

      {/* Main Content */}
      <main className="flex-1 max-w-7xl w-full mx-auto px-6 py-8 space-y-6">
        {/* Welcome Hero Banner */}
        <div className="bg-white rounded-2xl border border-slate-200/80 p-6 md:p-8 shadow-sm flex flex-col md:flex-row md:items-center md:justify-between gap-6">
          <div className="space-y-2">
            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-50 text-blue-700 text-xs font-bold">
              <Building2 className="w-3.5 h-3.5" />
              Ekosistem Multi-Outlet Terpusat
            </div>
            <h1 className="text-2xl md:text-3xl font-extrabold text-slate-900 tracking-tight">
              Welcome Back, {session.user.name.split(' ')[0]}!
            </h1>
            <p className="text-sm text-slate-600 max-w-2xl">
              Pilih outlet operasional yang ingin Anda kelola. Seluruh modul Kasir POS, B2B Sales
              Order, Gudang Cabang, Stock Transfer, dan Piutang (AR) akan otomatis terikat pada
              outlet yang Anda pilih.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            {session.central_warehouses && session.central_warehouses.length > 0 && (
              <div className="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-xs">
                <div className="font-bold text-slate-700 flex items-center gap-1.5">
                  <Warehouse className="w-4 h-4 text-blue-600" />
                  Gudang Pusat Terhubung
                </div>
                <div className="text-slate-900 font-extrabold mt-0.5">
                  {session.central_warehouses[0].name} ({session.central_warehouses[0].code})
                </div>
              </div>
            )}

            {session.user.is_super_admin && (
              <Button
                variant="outline"
                leftIcon={<Plus className="w-4 h-4" />}
                onClick={() => setShowCreateModal(true)}
              >
                Tambah Outlet Baru
              </Button>
            )}
          </div>
        </div>

        {error && (
          <div className="p-4 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700 font-medium">
            {error}
          </div>
        )}

        {/* Search & Filter Bar */}
        <div className="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-sm flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-4">
          <div className="relative flex-1">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Cari nama outlet, kode cabang (TNG-BSD, JKT-SNP), kota, atau gudang..."
              className="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600"
            />
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <div className="inline-flex rounded-xl bg-slate-100 p-1">
              {(
                [
                  { id: 'all', label: `Semua Outlet (${session.available_outlets.length})` },
                  { id: 'favorites', label: 'Favorit' },
                  { id: 'recent', label: 'Terakhir Diakses' },
                ] as const
              ).map((tab) => (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => setFilterMode(tab.id)}
                  className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all ${
                    filterMode === tab.id
                      ? 'bg-white text-blue-600 shadow-xs'
                      : 'text-slate-600 hover:text-slate-900'
                  }`}
                >
                  {tab.label}
                </button>
              ))}
            </div>

            {cities.length > 1 && (
              <select
                value={cityFilter}
                onChange={(e) => setCityFilter(e.target.value)}
                className="px-3 py-2 rounded-xl border border-slate-200 bg-white text-xs font-semibold text-slate-700 focus:outline-none focus:border-blue-600"
              >
                <option value="ALL">Semua Kota / Wilayah</option>
                {cities.map((city) => (
                  <option key={city} value={city}>
                    {city}
                  </option>
                ))}
              </select>
            )}
          </div>
        </div>

        {/* Outlet Cards Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
          {filteredOutlets.map((outlet) => {
            const isEntering = enteringOutletId === outlet.id;
            const selectedTermId =
              selectedTerminalByOutlet[outlet.id] ?? outlet.terminals?.[0]?.id ?? 0;

            return (
              <div
                key={outlet.id}
                className="bg-white rounded-2xl border border-slate-200/90 hover:border-blue-500/60 shadow-xs hover:shadow-md transition-all p-6 flex flex-col justify-between gap-5"
              >
                <div className="space-y-4">
                  {/* Card Top Header */}
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-start gap-3.5">
                      <div className="w-12 h-12 rounded-2xl bg-blue-50 border border-blue-100 text-blue-600 flex items-center justify-center shrink-0">
                        <Store className="w-6 h-6" />
                      </div>
                      <div>
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-xs font-mono font-bold px-2.5 py-0.5 rounded-md bg-slate-900 text-white">
                            {outlet.code}
                          </span>
                          <Badge variant="success" dot>
                            Aktif
                          </Badge>
                          {outlet.city && <Badge variant="info">{outlet.city}</Badge>}
                        </div>
                        <h2 className="text-lg font-extrabold text-slate-900 mt-1.5">
                          {outlet.name}
                        </h2>
                      </div>
                    </div>

                    <button
                      type="button"
                      disabled={togglingFavId === outlet.id}
                      onClick={(e) => handleToggleFavorite(outlet.id, e)}
                      title={outlet.is_favorite ? 'Hapus dari Favorit' : 'Tandai Favorit'}
                      className={`p-2 rounded-xl border transition-colors ${
                        outlet.is_favorite
                          ? 'bg-amber-50 border-amber-200 text-amber-500'
                          : 'bg-slate-50 border-slate-200 text-slate-400 hover:text-amber-500'
                      }`}
                    >
                      <Star className={`w-4 h-4 ${outlet.is_favorite ? 'fill-amber-400' : ''}`} />
                    </button>
                  </div>

                  {/* Location & Warehouse Metadata */}
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-slate-50 rounded-xl p-3.5 border border-slate-200/70 text-xs">
                    <div className="flex items-start gap-2 text-slate-600">
                      <MapPin className="w-4 h-4 text-slate-400 shrink-0 mt-0.5" />
                      <div>
                        <div className="font-semibold text-slate-800">Lokasi Cabang</div>
                        <div className="line-clamp-2 mt-0.5">{outlet.address || outlet.city}</div>
                      </div>
                    </div>

                    <div className="flex items-start gap-2 text-slate-600">
                      <Warehouse className="w-4 h-4 text-blue-600 shrink-0 mt-0.5" />
                      <div>
                        <div className="font-semibold text-slate-800">Gudang Cabang</div>
                        {outlet.warehouse ? (
                          <div className="mt-0.5 font-medium text-blue-700">
                            {outlet.warehouse.name} ({outlet.warehouse.code})
                          </div>
                        ) : (
                          <div className="mt-0.5 text-slate-400">Gudang Lokal Outlet</div>
                        )}
                      </div>
                    </div>
                  </div>

                  {/* POS Terminal Selection for Counter Operations */}
                  {outlet.terminals && outlet.terminals.length > 0 && (
                    <div className="space-y-1.5">
                      <label className="block text-[11px] font-bold uppercase tracking-wider text-slate-400">
                        Terminal POS Kasir (Opsional untuk Backoffice)
                      </label>
                      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        {outlet.terminals.map((term) => {
                          const isSelected = selectedTermId === term.id;
                          return (
                            <button
                              key={term.id}
                              type="button"
                              onClick={() =>
                                setSelectedTerminalByOutlet((prev) => ({
                                  ...prev,
                                  [outlet.id]: term.id,
                                }))
                              }
                              className={`flex items-center justify-between px-3 py-2 rounded-xl border text-left text-xs transition-all ${
                                isSelected
                                  ? 'bg-blue-50/80 border-blue-600 text-blue-900 font-bold'
                                  : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300'
                              }`}
                            >
                              <span className="flex items-center gap-2 truncate">
                                <Monitor className="w-3.5 h-3.5 text-blue-600 shrink-0" />
                                <span className="truncate">{term.name}</span>
                              </span>
                              {isSelected && (
                                <CheckCircle2 className="w-3.5 h-3.5 text-blue-600 shrink-0" />
                              )}
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  )}
                </div>

                {/* Footer Action */}
                <div className="pt-3 border-t border-slate-100 flex items-center justify-between gap-3">
                  <div className="text-[11px] text-slate-400 flex items-center gap-1.5">
                    <Clock className="w-3.5 h-3.5" />
                    {outlet.last_accessed_at
                      ? `Terakhir diakses: ${formatDateTime(outlet.last_accessed_at)}`
                      : `PPN ${outlet.tax_rate}%`}
                  </div>

                  <Button
                    variant="primary"
                    loading={isEntering}
                    rightIcon={<ArrowRight className="w-4 h-4" />}
                    onClick={() => handleEnterOutlet(outlet)}
                  >
                    Masuk ke {outlet.name}
                  </Button>
                </div>
              </div>
            );
          })}
        </div>
      </main>

      {/* Modal Tambah Outlet Baru Dinamis */}
      <Modal
        open={showCreateModal}
        onClose={() => setShowCreateModal(false)}
        title="Tambah Outlet & Gudang Cabang Baru"
        description="Sistem akan otomatis membuat Outlet, Gudang Cabang (Branch Warehouse), dan Terminal POS."
      >
        <form onSubmit={handleCreateOutlet} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">
                Kode Outlet (Mis: BKS-KTA)
              </label>
              <input
                type="text"
                required
                value={newCode}
                onChange={(e) => setNewCode(e.target.value.toUpperCase())}
                placeholder="BKS-KTA"
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Nama Outlet</label>
              <input
                type="text"
                required
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder="Outlet Bekasi"
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Kota</label>
              <input
                type="text"
                required
                value={newCity}
                onChange={(e) => setNewCity(e.target.value)}
                placeholder="Bekasi"
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
            <div>
              <label className="block text-xs font-bold text-slate-700 mb-1">Provinsi</label>
              <input
                type="text"
                value={newProvince}
                onChange={(e) => setNewProvince(e.target.value)}
                placeholder="Jawa Barat"
                className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">Alamat Lengkap</label>
            <input
              type="text"
              required
              value={newAddress}
              onChange={(e) => setNewAddress(e.target.value)}
              placeholder="Jl. Ahmad Yani No. 10, Bekasi"
              className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
            />
          </div>

          <div>
            <label className="block text-xs font-bold text-slate-700 mb-1">Nomor Telepon</label>
            <input
              type="text"
              value={newPhone}
              onChange={(e) => setNewPhone(e.target.value)}
              placeholder="021-889900"
              className="w-full px-3 py-2 rounded-xl border border-slate-300 text-sm"
            />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" type="button" onClick={() => setShowCreateModal(false)}>
              Batal
            </Button>
            <Button variant="primary" type="submit" loading={creatingOutlet}>
              Simpan & Buat Gudang Cabang
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  );
};

