import React, { useCallback, useEffect, useState } from 'react';
import {
  CheckCircle2,
  Lock,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  Shield,
  ShieldAlert,
  Trash2,
  Users,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import type { ManagedRole, PermissionModuleGroup } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import { useToast } from '../ui/ToastProvider';

interface RolesTabProps {
  canCreate: boolean;
  canEdit: boolean;
  canDelete: boolean;
  onRolesChanged: () => void;
}

export const RolesTab: React.FC<RolesTabProps> = ({
  canCreate,
  canEdit,
  canDelete,
  onRolesChanged,
}) => {
  const { toast } = useToast();

  const [roles, setRoles] = useState<ManagedRole[]>([]);
  const [modules, setModules] = useState<PermissionModuleGroup[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [search, setSearch] = useState<string>('');

  // Create / Edit Role Modal
  const [formOpen, setFormOpen] = useState<boolean>(false);
  const [editingRole, setEditingRole] = useState<ManagedRole | null>(null);
  const [formSaving, setFormSaving] = useState<boolean>(false);
  const [formDisplayName, setFormDisplayName] = useState<string>('');
  const [formDescription, setFormDescription] = useState<string>('');
  const [formLevel, setFormLevel] = useState<number>(20);
  const [formIsActive, setFormIsActive] = useState<boolean>(true);
  const [formPermissionIds, setFormPermissionIds] = useState<number[]>([]);
  const [moduleSearch, setModuleSearch] = useState<string>('');

  // Delete Confirmation Modal
  const [deleteTarget, setDeleteTarget] = useState<ManagedRole | null>(null);
  const [deleting, setDeleting] = useState<boolean>(false);

  const fetchRolesAndPermissions = useCallback(async () => {
    setLoading(true);
    try {
      const [rolesRes, permsRes] = await Promise.all([
        api.get<{ data: ManagedRole[] }>('/user-management/roles', {
          params: search.trim() ? { search: search.trim() } : {},
        }),
        api.get<{ modules: PermissionModuleGroup[] }>('/user-management/permissions'),
      ]);
      setRoles(rolesRes.data.data);
      setModules(permsRes.data.modules);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat data role',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  }, [search, toast]);

  useEffect(() => {
    fetchRolesAndPermissions();
  }, [fetchRolesAndPermissions]);

  const openCreateModal = () => {
    setEditingRole(null);
    setFormDisplayName('');
    setFormDescription('');
    setFormLevel(25);
    setFormIsActive(true);
    setFormPermissionIds([]);
    setModuleSearch('');
    setFormOpen(true);
  };

  const openEditModal = async (role: ManagedRole) => {
    setEditingRole(role);
    setFormDisplayName(role.display_name);
    setFormDescription(role.description ?? '');
    setFormLevel(role.level);
    setFormIsActive(role.is_active);
    setModuleSearch('');
    try {
      const res = await api.get<{
        role: { permission_ids: number[] };
      }>(`/user-management/roles/${role.id}`);
      setFormPermissionIds(res.data.role.permission_ids);
      setFormOpen(true);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat detail permission role',
        description: extractErrorMessage(err),
      });
    }
  };

  const togglePermission = (permId: number) => {
    setFormPermissionIds((prev) =>
      prev.includes(permId) ? prev.filter((id) => id !== permId) : [...prev, permId],
    );
  };

  const toggleModuleAll = (mod: PermissionModuleGroup) => {
    const grantableIds = mod.permissions.filter((p) => p.grantable).map((p) => p.id);
    const allSelected = grantableIds.every((id) => formPermissionIds.includes(id));
    if (allSelected) {
      setFormPermissionIds((prev) => prev.filter((id) => !grantableIds.includes(id)));
    } else {
      setFormPermissionIds((prev) => Array.from(new Set([...prev, ...grantableIds])));
    }
  };

  const handleSaveRole = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formDisplayName.trim()) {
      toast({ type: 'warning', title: 'Nama role wajib diisi' });
      return;
    }

    setFormSaving(true);
    try {
      const payload = {
        display_name: formDisplayName.trim(),
        description: formDescription.trim() || null,
        level: formLevel,
        is_active: formIsActive,
        permission_ids: formPermissionIds,
      };

      if (editingRole) {
        await api.put(`/user-management/roles/${editingRole.id}`, payload);
        toast({ type: 'success', title: 'Role & permission berhasil diperbarui' });
      } else {
        await api.post('/user-management/roles', payload);
        toast({ type: 'success', title: 'Role baru berhasil dibuat' });
      }
      setFormOpen(false);
      await fetchRolesAndPermissions();
      onRolesChanged();
    } catch (err) {
      toast({
        type: 'error',
        title: editingRole ? 'Gagal memperbarui role' : 'Gagal membuat role',
        description: extractErrorMessage(err),
      });
    } finally {
      setFormSaving(false);
    }
  };

  const handleDeleteRole = async () => {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await api.delete(`/user-management/roles/${deleteTarget.id}`);
      toast({ type: 'success', title: `Role ${deleteTarget.display_name} dihapus` });
      setDeleteTarget(null);
      await fetchRolesAndPermissions();
      onRolesChanged();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menghapus role',
        description: extractErrorMessage(err),
      });
    } finally {
      setDeleting(false);
    }
  };

  const filteredModules = modules.filter((m) => {
    if (!moduleSearch.trim()) return true;
    const q = moduleSearch.toLowerCase();
    return (
      m.label.toLowerCase().includes(q) ||
      m.module.toLowerCase().includes(q) ||
      m.permissions.some(
        (p) =>
          p.name.toLowerCase().includes(q) ||
          (p.description && p.description.toLowerCase().includes(q)),
      )
    );
  });

  return (
    <div className="space-y-4">
      {/* Header & Filter Bar */}
      <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-2xs flex flex-wrap items-center justify-between gap-3">
        <div className="relative flex-1 min-w-[220px] max-w-sm">
          <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Cari nama atau kode role..."
            className="w-full h-9 pl-9 pr-3 text-xs bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:bg-white focus:border-blue-500"
          />
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={fetchRolesAndPermissions}
            icon={<RefreshCw className="w-3.5 h-3.5" />}
          >
            Refresh
          </Button>
          {canCreate && (
            <Button
              variant="primary"
              size="sm"
              onClick={openCreateModal}
              icon={<Plus className="w-3.5 h-3.5" />}
            >
              Tambah Role Baru
            </Button>
          )}
        </div>
      </div>

      {/* Roles Table */}
      <div className="bg-white border border-slate-200 rounded-xl shadow-2xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                <th className="py-3 px-4">Nama Role</th>
                <th className="py-3 px-4">Level</th>
                <th className="py-3 px-4">Tipe</th>
                <th className="py-3 px-4">User Aktif</th>
                <th className="py-3 px-4">Permission</th>
                <th className="py-3 px-4">Status</th>
                <th className="py-3 px-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 text-xs">
              {loading ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-slate-500">
                    Memuat daftar role...
                  </td>
                </tr>
              ) : roles.length === 0 ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-slate-500">
                    Tidak ada role yang ditemukan.
                  </td>
                </tr>
              ) : (
                roles.map((r) => (
                  <tr key={r.id} className="hover:bg-slate-50/80 transition">
                    <td className="py-3 px-4">
                      <div className="font-bold text-slate-900 flex items-center gap-2">
                        <Shield className="w-4 h-4 text-blue-600 shrink-0" />
                        <span>{r.display_name}</span>
                        <span className="text-[11px] font-mono text-slate-400">({r.name})</span>
                      </div>
                      {r.description && (
                        <div className="text-[11px] text-slate-500 mt-0.5 max-w-md">
                          {r.description}
                        </div>
                      )}
                    </td>

                    <td className="py-3 px-4 font-bold text-slate-700">{r.level}</td>

                    <td className="py-3 px-4">
                      {r.is_system ? (
                        <Badge variant="purple" size="xs">
                          Role Sistem
                        </Badge>
                      ) : (
                        <Badge variant="info" size="xs">
                          Custom Role
                        </Badge>
                      )}
                    </td>

                    <td className="py-3 px-4">
                      <span className="inline-flex items-center gap-1 font-semibold text-slate-800">
                        <Users className="w-3.5 h-3.5 text-slate-400" />
                        {r.users_count} user
                      </span>
                    </td>

                    <td className="py-3 px-4">
                      {r.is_super_admin ? (
                        <Badge variant="purple" size="xs">
                          Seluruh Permission ({r.permissions_count})
                        </Badge>
                      ) : (
                        <Badge variant="primary" size="xs">
                          {r.permissions_count} permission
                        </Badge>
                      )}
                    </td>

                    <td className="py-3 px-4">
                      {r.is_active ? (
                        <Badge variant="success" dot size="xs">
                          Aktif
                        </Badge>
                      ) : (
                        <Badge variant="neutral" dot size="xs">
                          Nonaktif
                        </Badge>
                      )}
                    </td>

                    <td className="py-3 px-4 text-right">
                      {r.is_super_admin ? (
                        <span className="inline-flex items-center gap-1 text-[11px] text-slate-400">
                          <Lock className="w-3.5 h-3.5" /> Terkunci
                        </span>
                      ) : (
                        <div className="inline-flex items-center justify-end gap-1">
                          {canEdit && r.editable && (
                            <Button
                              variant="outline"
                              size="xs"
                              onClick={() => openEditModal(r)}
                              icon={<Pencil className="w-3.5 h-3.5" />}
                            >
                              Edit &amp; Permission
                            </Button>
                          )}

                          {canDelete && (
                            <Button
                              variant="ghost"
                              size="xs"
                              disabled={!r.deletable}
                              onClick={() => setDeleteTarget(r)}
                              title={
                                r.users_count > 0
                                  ? `Tidak dapat dihapus: digunakan oleh ${r.users_count} user`
                                  : 'Hapus Role'
                              }
                              icon={<Trash2 className="w-3.5 h-3.5 text-rose-600" />}
                            />
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* CREATE / EDIT ROLE MODAL */}
      <Modal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        title={editingRole ? `Edit Role: ${editingRole.display_name}` : 'Tambah Role Baru'}
        subtitle="Konfigurasi nama role, level hierarki, dan permission granular per modul aplikasi."
        icon={<Shield className="w-5 h-5" />}
        maxWidth="3xl"
        footer={
          <div className="flex items-center justify-between gap-3">
            <div className="text-xs text-slate-600">
              Terpilih:{' '}
              <span className="font-bold text-blue-700">{formPermissionIds.length}</span> permission
            </div>
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" onClick={() => setFormOpen(false)}>
                Batal
              </Button>
              <Button
                variant="primary"
                size="sm"
                loading={formSaving}
                onClick={handleSaveRole}
                icon={<CheckCircle2 className="w-4 h-4" />}
              >
                {editingRole ? 'Simpan Perubahan' : 'Buat Role'}
              </Button>
            </div>
          </div>
        }
      >
        <form onSubmit={handleSaveRole} className="space-y-4 text-xs">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label className="block font-bold text-slate-700 mb-1">Nama Role *</label>
              <input
                type="text"
                required
                value={formDisplayName}
                onChange={(e) => setFormDisplayName(e.target.value)}
                placeholder="Contoh: Kepala Gudang Regional"
                className="w-full h-9 px-3 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
              />
            </div>

            <div>
              <label className="block font-bold text-slate-700 mb-1">
                Level Prioritas (0 - 99)
              </label>
              <input
                type="number"
                min={0}
                max={99}
                value={formLevel}
                onChange={(e) => setFormLevel(Number(e.target.value))}
                className="w-full h-9 px-3 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
              />
            </div>

            <div>
              <label className="block font-bold text-slate-700 mb-1">Status Role</label>
              <select
                value={formIsActive ? '1' : '0'}
                onChange={(e) => setFormIsActive(e.target.value === '1')}
                className="w-full h-9 px-3 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
              >
                <option value="1">Aktif</option>
                <option value="0">Nonaktif</option>
              </select>
            </div>
          </div>

          <div>
            <label className="block font-bold text-slate-700 mb-1">Deskripsi Role</label>
            <input
              type="text"
              value={formDescription}
              onChange={(e) => setFormDescription(e.target.value)}
              placeholder="Ringkasan tanggung jawab dan cakupan akses role ini..."
              className="w-full h-9 px-3 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
            />
          </div>

          {/* Granular Permissions Checklist */}
          <div>
            <div className="flex flex-wrap items-center justify-between gap-2 mb-2">
              <label className="font-bold text-slate-800">
                Permission Granular per Modul Aplikasi
              </label>
              <input
                type="text"
                value={moduleSearch}
                onChange={(e) => setModuleSearch(e.target.value)}
                placeholder="Filter modul atau permission..."
                className="h-8 px-3 text-xs border border-slate-300 rounded-lg w-56"
              />
            </div>

            <div className="space-y-3 max-h-80 overflow-y-auto pr-1">
              {filteredModules.map((mod) => {
                const grantableIds = mod.permissions.filter((p) => p.grantable).map((p) => p.id);
                const selectedInMod = mod.permissions.filter((p) =>
                  formPermissionIds.includes(p.id),
                ).length;
                const allSelected =
                  grantableIds.length > 0 &&
                  grantableIds.every((id) => formPermissionIds.includes(id));

                return (
                  <div
                    key={mod.module}
                    className="border border-slate-200 rounded-xl overflow-hidden"
                  >
                    <div className="px-3.5 py-2 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                      <div className="font-bold text-slate-800">
                        {mod.label}{' '}
                        <span className="text-[11px] font-normal text-slate-500">
                          ({selectedInMod}/{mod.permissions.length})
                        </span>
                      </div>
                      {grantableIds.length > 0 && (
                        <button
                          type="button"
                          onClick={() => toggleModuleAll(mod)}
                          className="text-[11px] font-semibold text-blue-600 hover:underline cursor-pointer"
                        >
                          {allSelected ? 'Batalkan Semua' : 'Pilih Semua'}
                        </button>
                      )}
                    </div>

                    <div className="p-3 grid grid-cols-1 sm:grid-cols-3 gap-2">
                      {mod.permissions.map((p) => {
                        const checked = formPermissionIds.includes(p.id);
                        return (
                          <label
                            key={p.id}
                            className={`flex items-start gap-2 p-2 rounded-lg border cursor-pointer transition ${
                              checked
                                ? 'bg-blue-50/70 border-blue-300 text-blue-950'
                                : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                            } ${!p.grantable ? 'opacity-50 cursor-not-allowed' : ''}`}
                          >
                            <input
                              type="checkbox"
                              checked={checked}
                              disabled={!p.grantable}
                              onChange={() => togglePermission(p.id)}
                              className="mt-0.5 rounded text-blue-600"
                            />
                            <div className="min-w-0">
                              <div className="font-bold uppercase text-[11px] tracking-wide">
                                {p.action}
                              </div>
                              <div className="text-[10px] font-mono text-slate-500 truncate">
                                {p.name}
                              </div>
                            </div>
                          </label>
                        );
                      })}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </form>
      </Modal>

      {/* DELETE ROLE MODAL */}
      <Modal
        open={Boolean(deleteTarget)}
        onClose={() => setDeleteTarget(null)}
        title="Konfirmasi Hapus Role"
        icon={<ShieldAlert className="w-5 h-5 text-rose-600" />}
        maxWidth="md"
        footer={
          <div className="flex items-center justify-end gap-2">
            <Button variant="outline" size="sm" onClick={() => setDeleteTarget(null)}>
              Batal
            </Button>
            <Button
              variant="danger"
              size="sm"
              loading={deleting}
              onClick={handleDeleteRole}
            >
              Ya, Hapus Role
            </Button>
          </div>
        }
      >
        <p className="text-xs text-slate-700">
          Apakah Anda yakin ingin menghapus role <b>{deleteTarget?.display_name}</b>? Role ini tidak
          sedang digunakan oleh user mana pun.
        </p>
      </Modal>
    </div>
  );
};
