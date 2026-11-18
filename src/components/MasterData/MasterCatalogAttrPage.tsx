import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Boxes,
  Building2,
  CheckCircle2,
  Edit3,
  Plus,
  RefreshCw,
  Search,
  Tag,
  Truck,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import type { Brand, Category, Principal } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import { useToast } from '../ui/ToastProvider';

export type MasterAttrMode = 'categories' | 'brands' | 'suppliers';

interface SupplierItem {
  id: number;
  code: string;
  name: string;
  contact_person?: string | null;
  phone?: string | null;
  email?: string | null;
  city?: string | null;
  address?: string | null;
  payment_term_days: number;
  lead_time_days?: number;
  is_active?: boolean;
}

interface Props {
  mode: MasterAttrMode;
}

export const MasterCatalogAttrPage: React.FC<Props> = ({ mode }) => {
  const { toast } = useToast();

  const [loading, setLoading] = useState<boolean>(true);
  const [search, setSearch] = useState<string>('');
  const [categories, setCategories] = useState<Category[]>([]);
  const [brands, setBrands] = useState<Brand[]>([]);
  const [principals, setPrincipals] = useState<Principal[]>([]);
  const [suppliers, setSuppliers] = useState<SupplierItem[]>([]);

  // Modal state
  const [modalOpen, setModalOpen] = useState<boolean>(false);
  const [saving, setSaving] = useState<boolean>(false);
  const [editingId, setEditingId] = useState<number | null>(null);

  // Category / Brand form
  const [code, setCode] = useState<string>('');
  const [name, setName] = useState<string>('');
  const [description, setDescription] = useState<string>('');
  const [color, setColor] = useState<string>('#059669');
  const [principalId, setPrincipalId] = useState<string>('');
  const [isActive, setIsActive] = useState<boolean>(true);

  // Supplier extra fields
  const [contactPerson, setContactPerson] = useState<string>('');
  const [phone, setPhone] = useState<string>('');
  const [email, setEmail] = useState<string>('');
  const [city, setCity] = useState<string>('');
  const [address, setAddress] = useState<string>('');
  const [paymentTermDays, setPaymentTermDays] = useState<string>('30');

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      if (mode === 'categories') {
        const res = await api.get<{ categories: Category[] }>('/master-data/categories');
        setCategories(res.data.categories ?? []);
      } else if (mode === 'brands') {
        const [bRes, pRes] = await Promise.all([
          api.get<{ brands: Brand[] }>('/master-data/brands'),
          api.get<{ principals: Principal[] }>('/master-data/principals'),
        ]);
        setBrands(bRes.data.brands ?? []);
        setPrincipals(pRes.data.principals ?? []);
      } else {
        const res = await api.get<{ suppliers: SupplierItem[] }>('/procurement');
        setSuppliers(res.data.suppliers ?? []);
      }
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat master data',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  }, [mode, toast]);

  useEffect(() => {
    setSearch('');
    fetchData();
  }, [fetchData, mode]);

  const openCreateModal = () => {
    setEditingId(null);
    setCode('');
    setName('');
    setDescription('');
    setColor('#059669');
    setPrincipalId('');
    setIsActive(true);
    setContactPerson('');
    setPhone('');
    setEmail('');
    setCity('');
    setAddress('');
    setPaymentTermDays('30');
    setModalOpen(true);
  };

  const openEditCategory = (c: Category) => {
    setEditingId(c.id);
    setCode(c.code || c.slug.toUpperCase().slice(0, 8));
    setName(c.name);
    setDescription(c.description || '');
    setColor(c.color || '#059669');
    setIsActive(c.is_active !== false);
    setModalOpen(true);
  };

  const openEditBrand = (b: Brand) => {
    setEditingId(b.id);
    setCode(b.code);
    setName(b.name);
    setDescription(b.description || '');
    setPrincipalId(b.principal_id ? String(b.principal_id) : '');
    setIsActive(b.is_active !== false);
    setModalOpen(true);
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;
    setSaving(true);
    try {
      const cleanCode =
        code.trim().toUpperCase() ||
        name
          .trim()
          .toUpperCase()
          .replace(/[^A-Z0-9]/g, '')
          .slice(0, 8) ||
        `CODE${Date.now().toString().slice(-3)}`;

      if (mode === 'categories') {
        if (editingId) {
          await api.put(`/master-data/categories/${editingId}`, {
            name: name.trim(),
            description: description.trim() || null,
            color,
            is_active: isActive,
          });
          toast({ type: 'success', title: 'Kategori berhasil diperbarui' });
        } else {
          await api.post('/master-data/categories', {
            code: cleanCode,
            name: name.trim(),
            description: description.trim() || null,
            color,
          });
          toast({ type: 'success', title: 'Kategori baru berhasil ditambahkan' });
        }
      } else if (mode === 'brands') {
        if (editingId) {
          await api.put(`/master-data/brands/${editingId}`, {
            name: name.trim(),
            principal_id: principalId ? Number(principalId) : null,
            description: description.trim() || null,
            is_active: isActive,
          });
          toast({ type: 'success', title: 'Brand berhasil diperbarui' });
        } else {
          await api.post('/master-data/brands', {
            code: cleanCode,
            name: name.trim(),
            principal_id: principalId ? Number(principalId) : null,
            description: description.trim() || null,
          });
          toast({ type: 'success', title: 'Brand baru berhasil ditambahkan' });
        }
      } else {
        await api.post('/procurement/suppliers', {
          code: cleanCode,
          name: name.trim(),
          contact_person: contactPerson.trim() || null,
          phone: phone.trim() || null,
          email: email.trim() || null,
          city: city.trim() || null,
          address: address.trim() || null,
          payment_term_days: Number(paymentTermDays) || 30,
        });
        toast({ type: 'success', title: 'Supplier baru berhasil ditambahkan' });
      }
      setModalOpen(false);
      await fetchData();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menyimpan data',
        description: extractErrorMessage(err),
      });
    } finally {
      setSaving(false);
    }
  };

  const filteredCategories = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return categories;
    return categories.filter(
      (c) => c.name.toLowerCase().includes(q) || (c.code && c.code.toLowerCase().includes(q)),
    );
  }, [categories, search]);

  const filteredBrands = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return brands;
    return brands.filter(
      (b) =>
        b.name.toLowerCase().includes(q) ||
        b.code.toLowerCase().includes(q) ||
        (b.principal?.name && b.principal.name.toLowerCase().includes(q)),
    );
  }, [brands, search]);

  const filteredSuppliers = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return suppliers;
    return suppliers.filter(
      (s) =>
        s.name.toLowerCase().includes(q) ||
        s.code.toLowerCase().includes(q) ||
        (s.city && s.city.toLowerCase().includes(q)),
    );
  }, [suppliers, search]);

  const headerMeta = {
    categories: {
      title: 'Master Kategori Produk',
      subtitle: 'Pengelompokan jenis minuman (Whisky, Vodka, Beer, Soju, Wine, RTD, dll.)',
      addLabel: 'Tambah Kategori',
      icon: <Boxes className="w-5 h-5" />,
    },
    brands: {
      title: 'Master Brand & Merk',
      subtitle: 'Daftar brand/merk produk beserta keterkaitan dengan Principal resmi',
      addLabel: 'Tambah Brand',
      icon: <Tag className="w-5 h-5" />,
    },
    suppliers: {
      title: 'Master Supplier & Principal',
      subtitle: 'Mitra pemasok utama, kontak PIC, kota, dan termin pembayaran (TOP)',
      addLabel: 'Tambah Supplier',
      icon: <Truck className="w-5 h-5" />,
    },
  }[mode];

  return (
    <div className="p-5 space-y-5 max-w-7xl mx-auto">
      {/* Header */}
      <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center">
            {headerMeta.icon}
          </div>
          <div>
            <h1 className="text-lg font-extrabold text-slate-900">{headerMeta.title}</h1>
            <p className="text-xs text-slate-500">{headerMeta.subtitle}</p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <div className="relative">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Cari kode atau nama..."
              className="h-9 pl-9 pr-3 text-xs bg-slate-50 border border-slate-200 rounded-lg w-56 focus:outline-none focus:bg-white focus:border-blue-600"
            />
          </div>
          <Button
            variant="outline"
            size="sm"
            onClick={fetchData}
            icon={<RefreshCw className="w-3.5 h-3.5" />}
          >
            Segarkan
          </Button>
          <Button
            variant="primary"
            size="sm"
            onClick={openCreateModal}
            icon={<Plus className="w-4 h-4" />}
          >
            {headerMeta.addLabel}
          </Button>
        </div>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl border border-slate-200 shadow-2xs overflow-hidden">
        <div className="overflow-x-auto">
          {mode === 'categories' && (
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                  <th className="py-3 px-4">Kode</th>
                  <th className="py-3 px-4">Nama Kategori</th>
                  <th className="py-3 px-4">Deskripsi</th>
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {loading ? (
                  <tr>
                    <td colSpan={5} className="py-10 text-center text-slate-400">
                      Memuat kategori...
                    </td>
                  </tr>
                ) : filteredCategories.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="py-10 text-center text-slate-400">
                      Tidak ada kategori ditemukan.
                    </td>
                  </tr>
                ) : (
                  filteredCategories.map((c) => (
                    <tr key={c.id} className="hover:bg-slate-50/70">
                      <td className="py-3 px-4 font-mono font-bold text-slate-700">
                        {c.code || c.slug}
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-2 font-bold text-slate-900">
                          <span
                            className="w-3 h-3 rounded-full shrink-0"
                            style={{ backgroundColor: c.color || '#059669' }}
                          />
                          <span>{c.name}</span>
                        </div>
                      </td>
                      <td className="py-3 px-4 text-slate-500">{c.description || '—'}</td>
                      <td className="py-3 px-4">
                        <Badge variant={c.is_active !== false ? 'success' : 'neutral'} dot size="xs">
                          {c.is_active !== false ? 'Aktif' : 'Nonaktif'}
                        </Badge>
                      </td>
                      <td className="py-3 px-4 text-right">
                        <Button
                          variant="outline"
                          size="xs"
                          onClick={() => openEditCategory(c)}
                          icon={<Edit3 className="w-3.5 h-3.5" />}
                        >
                          Edit
                        </Button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          )}

          {mode === 'brands' && (
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                  <th className="py-3 px-4">Kode Brand</th>
                  <th className="py-3 px-4">Nama Brand</th>
                  <th className="py-3 px-4">Principal</th>
                  <th className="py-3 px-4">Deskripsi</th>
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {loading ? (
                  <tr>
                    <td colSpan={6} className="py-10 text-center text-slate-400">
                      Memuat brand...
                    </td>
                  </tr>
                ) : filteredBrands.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-10 text-center text-slate-400">
                      Tidak ada brand ditemukan.
                    </td>
                  </tr>
                ) : (
                  filteredBrands.map((b) => (
                    <tr key={b.id} className="hover:bg-slate-50/70">
                      <td className="py-3 px-4 font-mono font-bold text-slate-700">{b.code}</td>
                      <td className="py-3 px-4 font-bold text-slate-900">{b.name}</td>
                      <td className="py-3 px-4">
                        {b.principal ? (
                          <Badge variant="info" size="xs">
                            {b.principal.name}
                          </Badge>
                        ) : (
                          <span className="text-slate-400">—</span>
                        )}
                      </td>
                      <td className="py-3 px-4 text-slate-500">{b.description || '—'}</td>
                      <td className="py-3 px-4">
                        <Badge variant={b.is_active !== false ? 'success' : 'neutral'} dot size="xs">
                          {b.is_active !== false ? 'Aktif' : 'Nonaktif'}
                        </Badge>
                      </td>
                      <td className="py-3 px-4 text-right">
                        <Button
                          variant="outline"
                          size="xs"
                          onClick={() => openEditBrand(b)}
                          icon={<Edit3 className="w-3.5 h-3.5" />}
                        >
                          Edit
                        </Button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          )}

          {mode === 'suppliers' && (
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase">
                  <th className="py-3 px-4">Kode Supplier</th>
                  <th className="py-3 px-4">Nama Supplier / Principal</th>
                  <th className="py-3 px-4">Kontak PIC &amp; Telepon</th>
                  <th className="py-3 px-4">Kota</th>
                  <th className="py-3 px-4">Termin (TOP)</th>
                  <th className="py-3 px-4">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {loading ? (
                  <tr>
                    <td colSpan={6} className="py-10 text-center text-slate-400">
                      Memuat daftar supplier...
                    </td>
                  </tr>
                ) : filteredSuppliers.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="py-10 text-center text-slate-400">
                      Tidak ada supplier ditemukan.
                    </td>
                  </tr>
                ) : (
                  filteredSuppliers.map((s) => (
                    <tr key={s.id} className="hover:bg-slate-50/70">
                      <td className="py-3 px-4 font-mono font-bold text-blue-700">{s.code}</td>
                      <td className="py-3 px-4">
                        <div className="font-bold text-slate-900">{s.name}</div>
                        {s.address && <div className="text-[11px] text-slate-500">{s.address}</div>}
                      </td>
                      <td className="py-3 px-4">
                        <div className="font-semibold text-slate-800">
                          {s.contact_person || '—'}
                        </div>
                        {s.phone && <div className="text-[11px] text-slate-500">{s.phone}</div>}
                      </td>
                      <td className="py-3 px-4 text-slate-700">{s.city || '—'}</td>
                      <td className="py-3 px-4">
                        <Badge variant="primary" size="xs">
                          {s.payment_term_days} Hari
                        </Badge>
                      </td>
                      <td className="py-3 px-4">
                        <Badge variant="success" dot size="xs">
                          Aktif
                        </Badge>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          )}
        </div>
      </div>

      {/* Create / Edit Modal */}
      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={
          editingId
            ? `Edit ${mode === 'categories' ? 'Kategori' : 'Brand'}`
            : headerMeta.addLabel
        }
        icon={<Building2 className="w-5 h-5" />}
        maxWidth="md"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="outline" size="sm" onClick={() => setModalOpen(false)}>
              Batal
            </Button>
            <Button
              variant="primary"
              size="sm"
              loading={saving}
              onClick={handleSave}
              icon={<CheckCircle2 className="w-4 h-4" />}
            >
              Simpan
            </Button>
          </div>
        }
      >
        <form onSubmit={handleSave} className="space-y-3.5 text-xs">
          {!editingId && (
            <div>
              <label className="block font-bold text-slate-700 mb-1">
                Kode (Kosongkan untuk otomatis)
              </label>
              <input
                type="text"
                value={code}
                onChange={(e) => setCode(e.target.value.toUpperCase())}
                placeholder="Contoh: WHISKY / CHIVAS / SUP-01"
                className="w-full h-9 px-3 border border-slate-300 rounded-lg font-mono uppercase"
              />
            </div>
          )}

          <div>
            <label className="block font-bold text-slate-700 mb-1">Nama *</label>
            <input
              type="text"
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Masukkan nama..."
              className="w-full h-9 px-3 border border-slate-300 rounded-lg font-semibold"
            />
          </div>

          {mode === 'categories' && (
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block font-bold text-slate-700 mb-1">Warna Label</label>
                <input
                  type="color"
                  value={color}
                  onChange={(e) => setColor(e.target.value)}
                  className="w-full h-9 p-1 border border-slate-300 rounded-lg cursor-pointer"
                />
              </div>
              <div>
                <label className="block font-bold text-slate-700 mb-1">Status</label>
                <select
                  value={isActive ? '1' : '0'}
                  onChange={(e) => setIsActive(e.target.value === '1')}
                  className="w-full h-9 px-3 border border-slate-300 rounded-lg"
                >
                  <option value="1">Aktif</option>
                  <option value="0">Nonaktif</option>
                </select>
              </div>
            </div>
          )}

          {mode === 'brands' && (
            <div>
              <label className="block font-bold text-slate-700 mb-1">Principal (Opsional)</label>
              <select
                value={principalId}
                onChange={(e) => setPrincipalId(e.target.value)}
                className="w-full h-9 px-3 border border-slate-300 rounded-lg"
              >
                <option value="">-- Tanpa Principal --</option>
                {principals.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </div>
          )}

          {mode !== 'suppliers' && (
            <div>
              <label className="block font-bold text-slate-700 mb-1">Deskripsi (Opsional)</label>
              <input
                type="text"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                className="w-full h-9 px-3 border border-slate-300 rounded-lg"
              />
            </div>
          )}

          {mode === 'suppliers' && (
            <>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-700 mb-1">Nama Kontak PIC</label>
                  <input
                    type="text"
                    value={contactPerson}
                    onChange={(e) => setContactPerson(e.target.value)}
                    className="w-full h-9 px-3 border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-700 mb-1">No. Telepon</label>
                  <input
                    type="text"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    className="w-full h-9 px-3 border border-slate-300 rounded-lg"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block font-bold text-slate-700 mb-1">Kota</label>
                  <input
                    type="text"
                    value={city}
                    onChange={(e) => setCity(e.target.value)}
                    className="w-full h-9 px-3 border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-700 mb-1">
                    Termin Pembayaran (Hari)
                  </label>
                  <input
                    type="number"
                    min="0"
                    value={paymentTermDays}
                    onChange={(e) => setPaymentTermDays(e.target.value)}
                    className="w-full h-9 px-3 border border-slate-300 rounded-lg"
                  />
                </div>
              </div>

              <div>
                <label className="block font-bold text-slate-700 mb-1">Alamat Lengkap</label>
                <input
                  type="text"
                  value={address}
                  onChange={(e) => setAddress(e.target.value)}
                  className="w-full h-9 px-3 border border-slate-300 rounded-lg"
                />
              </div>
            </>
          )}
        </form>
      </Modal>
    </div>
  );
};
