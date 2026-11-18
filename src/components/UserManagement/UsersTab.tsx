import React, { useCallback, useEffect, useState } from 'react';
import {
  Ban,
  Building2,
  CheckCircle2,
  Copy,
  Eye,
  KeyRound,
  Lock,
  Pencil,
  Plus,
  RefreshCw,
  RotateCcw,
  Search,
  Shield,
  ShieldAlert,
  Trash2,
  UserCheck,
  UserPlus,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime } from '../../lib/format';
import type {
  AccountStatus,
  ManagedRole,
  ManagedUser,
  OutletInfo,
  UserManagementStats,
} from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import { Pagination } from '../ui/Pagination';
import { useToast } from '../ui/ToastProvider';

interface UsersTabProps {
  outlets: OutletInfo[];
  roles: Array<Pick<ManagedRole, 'id' | 'name' | 'display_name' | 'level' | 'is_system' | 'is_active' | 'assignable'>>;
  canCreate: boolean;
  canEdit: boolean;
  canDelete: boolean;
  canManage: boolean;
  onStatsChange: (stats: UserManagementStats) => void;
}

interface UserDetailPayload {
  user: ManagedUser;
  recent_activity: Array<{
    id: number;
    action_event: string;
    label: string;
    actor_name: string | null;
    is_actor: boolean;
    ip_address: string | null;
    created_at: string;
  }>;
}

export const UsersTab: React.FC<UsersTabProps> = ({
  outlets,
  roles,
  canCreate,
  canEdit,
  canDelete,
  canManage,
  onStatsChange,
}) => {
  const { toast } = useToast();

  const [users, setUsers] = useState<ManagedUser[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [search, setSearch] = useState<string>('');
  const [roleFilter, setRoleFilter] = useState<string>('');
  const [outletFilter, setOutletFilter] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<'ALL' | AccountStatus>('ALL');
  const [page, setPage] = useState<number>(1);
  const [meta, setMeta] = useState<{ current_page: number; last_page: number; total: number }>({
    current_page: 1,
    last_page: 1,
    total: 0,
  });

  // Create / Edit Modal
  const [formOpen, setFormOpen] = useState<boolean>(false);
  const [editingUser, setEditingUser] = useState<ManagedUser | null>(null);
  const [formSaving, setFormSaving] = useState<boolean>(false);
  const [formName, setFormName] = useState<string>('');
  const [formUsername, setFormUsername] = useState<string>('');
  const [formEmail, setFormEmail] = useState<string>('');
  const [formPassword, setFormPassword] = useState<string>('');
  const [formPin, setFormPin] = useState<string>('');
  const [formClearPin, setFormClearPin] = useState<boolean>(false);
  const [formRoleIds, setFormRoleIds] = useState<number[]>([]);
  const [formOutletIds, setFormOutletIds] = useState<number[]>([]);
  const [formDefaultOutletId, setFormDefaultOutletId] = useState<number | null>(null);
  const [formIsActive, setFormIsActive] = useState<boolean>(true);

  // Detail Modal
  const [detailOpen, setDetailOpen] = useState<boolean>(false);
  const [detailLoading, setDetailLoading] = useState<boolean>(false);
  const [detailData, setDetailData] = useState<UserDetailPayload | null>(null);

  // Suspend Modal
  const [suspendTarget, setSuspendTarget] = useState<ManagedUser | null>(null);
  const [suspendReason, setSuspendReason] = useState<string>('');
  const [suspending, setSuspending] = useState<boolean>(false);

  // Delete / Activate / Restore Confirmation Modal
  const [confirmAction, setConfirmAction] = useState<{
    type: 'delete' | 'activate' | 'restore';
    user: ManagedUser;
  } | null>(null);
  const [confirmLoading, setConfirmLoading] = useState<boolean>(false);

  // Reset Password Modal
  const [resetTarget, setResetTarget] = useState<ManagedUser | null>(null);
  const [resetMode, setResetMode] = useState<'generate' | 'manual'>('generate');
  const [resetPassword, setResetPassword] = useState<string>('');
  const [resetPasswordConfirm, setResetPasswordConfirm] = useState<string>('');
  const [resetRevokeSessions, setResetRevokeSessions] = useState<boolean>(true);
  const [resetLoading, setResetLoading] = useState<boolean>(false);
  const [generatedPassword, setGeneratedPassword] = useState<string | null>(null);

  const fetchUsers = useCallback(
    async (targetPage = page) => {
      setLoading(true);
      try {
        const params: Record<string, string | number> = {
          page: targetPage,
          per_page: 15,
          status: statusFilter,
        };
        if (search.trim()) params.search = search.trim();
        if (roleFilter) params.role_id = roleFilter;
        if (outletFilter) params.filter_outlet_id = outletFilter;

        const res = await api.get<{
          data: ManagedUser[];
          meta: { current_page: number; last_page: number; total: number };
          stats: UserManagementStats;
        }>('/user-management/users', { params });

        setUsers(res.data.data);
        setMeta(res.data.meta);
        if (res.data.stats) {
          onStatsChange(res.data.stats);
        }
      } catch (err) {
        toast({
          type: 'error',
          title: 'Gagal memuat daftar user',
          description: extractErrorMessage(err),
        });
      } finally {
        setLoading(false);
      }
    },
    [page, statusFilter, search, roleFilter, outletFilter, onStatsChange, toast],
  );

  useEffect(() => {
    fetchUsers(page);
  }, [fetchUsers, page]);

  const handleFilterSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    fetchUsers(1);
  };

  const openCreateModal = () => {
    setEditingUser(null);
    setFormName('');
    setFormUsername('');
    setFormEmail('');
    setFormPassword('');
    setFormPin('');
    setFormClearPin(false);
    const defaultRole = roles.find((r) => r.assignable && r.name === 'cashier') ?? roles.find((r) => r.assignable);
    setFormRoleIds(defaultRole ? [defaultRole.id] : []);
    const firstOutletId = outlets[0]?.id ?? null;
    setFormOutletIds(firstOutletId ? [firstOutletId] : []);
    setFormDefaultOutletId(firstOutletId);
    setFormIsActive(true);
    setFormOpen(true);
  };

  const openEditModal = (u: ManagedUser) => {
    setEditingUser(u);
    setFormName(u.name);
    setFormUsername(u.username);
    setFormEmail(u.email);
    setFormPassword('');
    setFormPin('');
    setFormClearPin(false);
    setFormRoleIds(u.roles.map((r) => r.id));
    setFormOutletIds(u.outlets.map((o) => o.id));
    setFormDefaultOutletId(u.default_outlet_id ?? u.outlets[0]?.id ?? null);
    setFormIsActive(u.status === 'ACTIVE');
    setFormOpen(true);
  };

  const openDetailModal = async (u: ManagedUser) => {
    setDetailOpen(true);
    setDetailLoading(true);
    setDetailData(null);
    try {
      const res = await api.get<UserDetailPayload>(`/user-management/users/${u.id}`);
      setDetailData(res.data);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat detail user',
        description: extractErrorMessage(err),
      });
      setDetailOpen(false);
    } finally {
      setDetailLoading(false);
    }
  };

  const toggleFormRole = (roleId: number) => {
    setFormRoleIds((prev) =>
      prev.includes(roleId) ? prev.filter((id) => id !== roleId) : [...prev, roleId],
    );
  };

  const toggleFormOutlet = (outletId: number) => {
    setFormOutletIds((prev) => {
      const next = prev.includes(outletId)
        ? prev.filter((id) => id !== outletId)
        : [...prev, outletId];
      if (!next.includes(formDefaultOutletId ?? -1)) {
        setFormDefaultOutletId(next[0] ?? null);
      }
      return next;
    });
  };

  const isSuperAdminSelected = roles.some(
    (r) => r.name === 'super_admin' && formRoleIds.includes(r.id),
  );

  const handleSaveUser = async (e: React.FormEvent) => {
    e.preventDefault();
    if (formRoleIds.length === 0) {
      toast({ type: 'warning', title: 'Pilih minimal satu role' });
      return;
    }
    if (!isSuperAdminSelected && formOutletIds.length === 0) {
      toast({ type: 'warning', title: 'Pilih minimal satu outlet penempatan' });
      return;
    }

    setFormSaving(true);
    try {
      if (editingUser) {
        const payload: Record<string, unknown> = {
          name: formName.trim(),
          username: formUsername.trim(),
          email: formEmail.trim(),
        };
        if (!editingUser.is_self) {
          payload.role_ids = formRoleIds;
          payload.outlet_ids = formOutletIds;
          payload.default_outlet_id = formDefaultOutletId;
        }
        if (formPin.trim()) {
          payload.pin = formPin.trim();
        } else if (formClearPin) {
          payload.clear_pin = true;
        }

        await api.put(`/user-management/users/${editingUser.id}`, payload);
        toast({ type: 'success', title: 'Data user berhasil diperbarui' });
      } else {
        await api.post('/user-management/users', {
          name: formName.trim(),
          username: formUsername.trim(),
          email: formEmail.trim(),
          password: formPassword,
          pin: formPin.trim() || null,
          role_ids: formRoleIds,
          outlet_ids: formOutletIds,
          default_outlet_id: formDefaultOutletId,
          is_active: formIsActive,
        });
        toast({ type: 'success', title: 'User baru berhasil ditambahkan' });
      }
      setFormOpen(false);
      fetchUsers(page);
    } catch (err) {
      toast({
        type: 'error',
        title: editingUser ? 'Gagal memperbarui user' : 'Gagal membuat user',
        description: extractErrorMessage(err),
      });
    } finally {
      setFormSaving(false);
    }
  };

  const handleSuspendSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!suspendTarget) return;
    setSuspending(true);
    try {
      await api.post(`/user-management/users/${suspendTarget.id}/suspend`, {
        reason: suspendReason.trim() || null,
      });
      toast({
        type: 'success',
        title: 'Akun di-suspend',
        description: `Seluruh sesi aktif ${suspendTarget.name} telah dicabut.`,
      });
      setSuspendTarget(null);
      setSuspendReason('');
      fetchUsers(page);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal men-suspend akun',
        description: extractErrorMessage(err),
      });
    } finally {
      setSuspending(false);
    }
  };

  const handleConfirmAction = async () => {
    if (!confirmAction) return;
    setConfirmLoading(true);
    try {
      const { type, user } = confirmAction;
      if (type === 'delete') {
        await api.delete(`/user-management/users/${user.id}`);
        toast({ type: 'success', title: `Akun ${user.name} dihapus (soft delete)` });
      } else if (type === 'activate') {
        await api.post(`/user-management/users/${user.id}/activate`);
        toast({ type: 'success', title: `Akun ${user.name} diaktifkan kembali` });
      } else if (type === 'restore') {
        await api.post(`/user-management/users/${user.id}/restore`);
        toast({
          type: 'success',
          title: `Akun ${user.name} dipulihkan`,
          description: 'Status akun saat ini SUSPENDED. Aktifkan untuk mengizinkan login.',
        });
      }
      setConfirmAction(null);
      fetchUsers(page);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Tindakan gagal',
        description: extractErrorMessage(err),
      });
    } finally {
      setConfirmLoading(false);
    }
  };

  const openResetPasswordModal = (u: ManagedUser) => {
    setResetTarget(u);
    setResetMode('generate');
    setResetPassword('');
    setResetPasswordConfirm('');
    setResetRevokeSessions(true);
    setGeneratedPassword(null);
  };

  const handleResetPasswordSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!resetTarget) return;
    setResetLoading(true);
    try {
      const payload: Record<string, unknown> = {
        mode: resetMode,
        revoke_sessions: resetRevokeSessions,
      };
      if (resetMode === 'manual') {
        payload.password = resetPassword;
        payload.password_confirmation = resetPasswordConfirm;
      }
      const res = await api.post<{ message: string; temporary_password: string | null }>(
        `/user-management/users/${resetTarget.id}/reset-password`,
        payload,
      );
      if (res.data.temporary_password) {
        setGeneratedPassword(res.data.temporary_password);
        toast({
          type: 'success',
          title: 'Password sementara berhasil dibuat',
          description: 'Salin password sementara sebelum menutup dialog ini.',
        });
      } else {
        toast({ type: 'success', title: 'Password berhasil direset' });
        setResetTarget(null);
      }
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal mereset password',
        description: extractErrorMessage(err),
      });
    } finally {
      setResetLoading(false);
    }
  };

  const renderStatusBadge = (status: AccountStatus) => {
    if (status === 'ACTIVE') {
      return (
        <Badge variant="success" dot>
          ACTIVE
        </Badge>
      );
    }
    if (status === 'SUSPENDED') {
      return (
        <Badge variant="warning" dot>
          SUSPENDED
        </Badge>
      );
    }
    return (
      <Badge variant="danger" dot>
        DELETED
      </Badge>
    );
  };

  return (
    <div className="space-y-4">
      {/* Filter & Actions Bar */}
      <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-2xs">
        <form
          onSubmit={handleFilterSubmit}
          className="flex flex-wrap items-center justify-between gap-3"
        >
          <div className="flex flex-wrap items-center gap-2.5 flex-1 min-w-[260px]">
            <div className="relative flex-1 min-w-[220px] max-w-sm">
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Cari nama, username, atau email..."
                className="w-full h-9 pl-9 pr-3 text-xs bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:bg-white focus:border-blue-500"
              />
            </div>

            <select
              value={roleFilter}
              onChange={(e) => {
                setRoleFilter(e.target.value);
                setPage(1);
              }}
              className="h-9 px-3 text-xs bg-slate-50 border border-slate-200 rounded-lg text-slate-700 focus:outline-none focus:border-blue-500"
            >
              <option value="">Semua Role</option>
              {roles.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.display_name}
                </option>
              ))}
            </select>

            <select
              value={outletFilter}
              onChange={(e) => {
                setOutletFilter(e.target.value);
                setPage(1);
              }}
              className="h-9 px-3 text-xs bg-slate-50 border border-slate-200 rounded-lg text-slate-700 focus:outline-none focus:border-blue-500"
            >
              <option value="">Semua Outlet</option>
              {outlets.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.code} - {o.name}
                </option>
              ))}
            </select>

            <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs">
              {(['ALL', 'ACTIVE', 'SUSPENDED', 'DELETED'] as const).map((st) => (
                <button
                  key={st}
                  type="button"
                  onClick={() => {
                    setStatusFilter(st);
                    setPage(1);
                  }}
                  className={`px-2.5 py-1 rounded-md font-semibold transition cursor-pointer ${
                    statusFilter === st
                      ? 'bg-white text-slate-900 shadow-2xs'
                      : 'text-slate-600 hover:text-slate-900'
                  }`}
                >
                  {st === 'ALL' ? 'Semua' : st}
                </button>
              ))}
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => fetchUsers(page)}
              icon={<RefreshCw className="w-3.5 h-3.5" />}
            >
              Refresh
            </Button>

            {canCreate && (
              <Button
                type="button"
                variant="primary"
                size="sm"
                onClick={openCreateModal}
                icon={<UserPlus className="w-3.5 h-3.5" />}
              >
                Tambah User
              </Button>
            )}
          </div>
        </form>
      </div>

      {/* Users Table */}
      <div className="bg-white border border-slate-200 rounded-xl shadow-2xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                <th className="py-3 px-4">User</th>
                <th className="py-3 px-4">Role</th>
                <th className="py-3 px-4">Penempatan Outlet</th>
                <th className="py-3 px-4">Status Akun</th>
                <th className="py-3 px-4">Terakhir Login</th>
                <th className="py-3 px-4 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 text-xs">
              {loading ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-500">
                    Memuat data user...
                  </td>
                </tr>
              ) : users.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-500">
                    Tidak ada user yang sesuai dengan filter.
                  </td>
                </tr>
              ) : (
                users.map((u) => (
                  <tr key={u.id} className="hover:bg-slate-50/80 transition">
                    <td className="py-3 px-4">
                      <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-xl bg-slate-900 text-white font-bold text-xs flex items-center justify-center shrink-0">
                          {u.name
                            .split(' ')
                            .slice(0, 2)
                            .map((n) => n[0])
                            .join('')
                            .toUpperCase()}
                        </div>
                        <div className="min-w-0">
                          <div className="font-bold text-slate-900 flex items-center gap-1.5">
                            <span className="truncate">{u.name}</span>
                            {u.is_self && (
                              <Badge variant="info" size="xs">
                                Anda
                              </Badge>
                            )}
                            {u.has_pin && (
                              <span
                                title="Memiliki PIN Otorisasi Supervisor"
                                className="text-[10px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200 font-semibold"
                              >
                                PIN
                              </span>
                            )}
                          </div>
                          <div className="text-[11px] text-slate-500 truncate">
                            @{u.username} &bull; {u.email}
                          </div>
                        </div>
                      </div>
                    </td>

                    <td className="py-3 px-4">
                      <div className="flex flex-wrap gap-1">
                        {u.roles.map((r) => (
                          <Badge
                            key={r.id}
                            variant={r.name === 'super_admin' ? 'purple' : 'primary'}
                            size="xs"
                          >
                            {r.display_name}
                          </Badge>
                        ))}
                      </div>
                    </td>

                    <td className="py-3 px-4">
                      {u.all_outlets_access ? (
                        <Badge variant="purple" size="xs">
                          Seluruh Outlet (Global)
                        </Badge>
                      ) : u.outlets.length === 0 ? (
                        <span className="text-slate-400 italic">Belum ditugaskan</span>
                      ) : (
                        <div className="flex flex-wrap gap-1 max-w-xs">
                          {u.outlets.map((o) => (
                            <span
                              key={o.id}
                              className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] border ${
                                o.is_default
                                  ? 'bg-emerald-50 text-emerald-800 border-emerald-200 font-semibold'
                                  : 'bg-slate-100 text-slate-700 border-slate-200'
                              }`}
                              title={o.is_default ? 'Outlet Default' : o.name}
                            >
                              <Building2 className="w-3 h-3" />
                              {o.name}
                              {o.is_default && u.outlets.length > 1 && (
                                <span className="text-[9px] uppercase bg-emerald-600 text-white px-1 rounded">
                                  Default
                                </span>
                              )}
                            </span>
                          ))}
                        </div>
                      )}
                    </td>

                    <td className="py-3 px-4">{renderStatusBadge(u.status)}</td>

                    <td className="py-3 px-4 text-slate-600">
                      {u.last_login_at ? (
                        formatDateTime(u.last_login_at)
                      ) : (
                        <span className="text-slate-400 italic">Belum pernah login</span>
                      )}
                    </td>

                    <td className="py-3 px-4 text-right">
                      <div className="inline-flex items-center justify-end gap-1">
                        <Button
                          variant="ghost"
                          size="xs"
                          onClick={() => openDetailModal(u)}
                          title="Lihat Detail"
                          icon={<Eye className="w-3.5 h-3.5" />}
                        />

                        {u.status !== 'DELETED' && canEdit && (
                          <>
                            <Button
                              variant="ghost"
                              size="xs"
                              onClick={() => openEditModal(u)}
                              title="Edit User"
                              icon={<Pencil className="w-3.5 h-3.5" />}
                            />
                            <Button
                              variant="ghost"
                              size="xs"
                              onClick={() => openResetPasswordModal(u)}
                              title="Reset Password"
                              icon={<KeyRound className="w-3.5 h-3.5 text-amber-600" />}
                            />
                          </>
                        )}

                        {u.status === 'ACTIVE' && canManage && !u.is_self && (
                          <Button
                            variant="ghost"
                            size="xs"
                            onClick={() => {
                              setSuspendTarget(u);
                              setSuspendReason('');
                            }}
                            title="Suspend Akun"
                            icon={<Ban className="w-3.5 h-3.5 text-amber-600" />}
                          />
                        )}

                        {u.status === 'SUSPENDED' && canManage && (
                          <Button
                            variant="ghost"
                            size="xs"
                            onClick={() => setConfirmAction({ type: 'activate', user: u })}
                            title="Aktifkan Kembali"
                            icon={<UserCheck className="w-3.5 h-3.5 text-emerald-600" />}
                          />
                        )}

                        {u.status !== 'DELETED' && canDelete && !u.is_self && (
                          <Button
                            variant="ghost"
                            size="xs"
                            onClick={() => setConfirmAction({ type: 'delete', user: u })}
                            title="Hapus Akun (Soft Delete)"
                            icon={<Trash2 className="w-3.5 h-3.5 text-rose-600" />}
                          />
                        )}

                        {u.status === 'DELETED' && canDelete && (
                          <Button
                            variant="outline"
                            size="xs"
                            onClick={() => setConfirmAction({ type: 'restore', user: u })}
                            icon={<RotateCcw className="w-3.5 h-3.5 text-blue-600" />}
                          >
                            Pulihkan
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        <Pagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          onPageChange={(p) => setPage(p)}
        />
      </div>

      {/* CREATE / EDIT USER MODAL */}
      <Modal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        title={editingUser ? `Edit User: ${editingUser.name}` : 'Tambah User Baru'}
        subtitle="Atur profil akun, role RBAC, dan penempatan outlet operasional."
        icon={<UserPlus className="w-5 h-5" />}
        maxWidth="2xl"
        footer={
          <div className="flex items-center justify-end gap-2">
            <Button variant="outline" size="sm" onClick={() => setFormOpen(false)}>
              Batal
            </Button>
            <Button
              variant="primary"
              size="sm"
              loading={formSaving}
              onClick={handleSaveUser}
              icon={<CheckCircle2 className="w-4 h-4" />}
            >
              {editingUser ? 'Simpan Perubahan' : 'Buat User'}
            </Button>
          </div>
        }
      >
        <form onSubmit={handleSaveUser} className="space-y-5 text-xs">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block font-bold text-slate-700 mb-1">Nama Lengkap *</label>
              <input
                type="text"
                required
                value={formName}
                onChange={(e) => setFormName(e.target.value)}
                placeholder="Contoh: Budi Santoso"
                className="w-full h-9 px-3 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
              />
            </div>

            <div>
              <label className="block font-bold text-slate-700 mb-1">Username *</label>
              <input
                type="text"
                required
                value={formUsername}
                onChange={(e) => setFormUsername(e.target.value)}
                placeholder="Contoh: kasir.senopati"
                className="w-full h-9 px-3 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
              />
            </div>

            <div>
              <label className="block font-bold text-slate-700 mb-1">Email *</label>
              <input
                type="email"
                required
                value={formEmail}
                onChange={(e) => setFormEmail(e.target.value)}
                placeholder="nama@sibotol.id"
                className="w-full h-9 px-3 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
              />
            </div>

            {!editingUser ? (
              <div>
                <label className="block font-bold text-slate-700 mb-1">
                  Password Awal * (Min. 8 karakter huruf &amp; angka)
                </label>
                <input
                  type="password"
                  required
                  minLength={8}
                  value={formPassword}
                  onChange={(e) => setFormPassword(e.target.value)}
                  placeholder="Minimal 8 karakter"
                  className="w-full h-9 px-3 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
                />
              </div>
            ) : (
              <div>
                <label className="block font-bold text-slate-700 mb-1">
                  PIN Supervisor (4-8 digit angka, opsional)
                </label>
                <div className="flex items-center gap-2">
                  <input
                    type="password"
                    inputMode="numeric"
                    value={formPin}
                    onChange={(e) => {
                      setFormPin(e.target.value);
                      if (e.target.value) setFormClearPin(false);
                    }}
                    placeholder={
                      editingUser.has_pin ? 'Kosongkan jika tidak diubah' : 'Belum ada PIN'
                    }
                    className="flex-1 h-9 px-3 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
                  />
                  {editingUser.has_pin && (
                    <label className="inline-flex items-center gap-1 text-[11px] text-rose-600 font-semibold cursor-pointer">
                      <input
                        type="checkbox"
                        checked={formClearPin}
                        onChange={(e) => {
                          setFormClearPin(e.target.checked);
                          if (e.target.checked) setFormPin('');
                        }}
                      />
                      Hapus PIN
                    </label>
                  )}
                </div>
              </div>
            )}
          </div>

          {!editingUser && (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block font-bold text-slate-700 mb-1">
                  PIN Otorisasi Supervisor (Opsional, 4-8 digit)
                </label>
                <input
                  type="password"
                  inputMode="numeric"
                  value={formPin}
                  onChange={(e) => setFormPin(e.target.value)}
                  placeholder="Contoh: 123456"
                  className="w-full h-9 px-3 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
                />
              </div>

              <div>
                <label className="block font-bold text-slate-700 mb-1">Status Awal Akun</label>
                <select
                  value={formIsActive ? 'ACTIVE' : 'SUSPENDED'}
                  onChange={(e) => setFormIsActive(e.target.value === 'ACTIVE')}
                  className="w-full h-9 px-3 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
                >
                  <option value="ACTIVE">ACTIVE (Dapat langsung login)</option>
                  <option value="SUSPENDED">SUSPENDED (Nonaktif sementara)</option>
                </select>
              </div>
            </div>
          )}

          {/* Role Assignment */}
          <div>
            <div className="flex items-center justify-between mb-1.5">
              <label className="font-bold text-slate-700 flex items-center gap-1.5">
                <Shield className="w-4 h-4 text-blue-600" />
                Role Akun *
              </label>
              {editingUser?.is_self && (
                <span className="text-[11px] text-amber-700 font-semibold">
                  Anda tidak dapat mengubah role akun Anda sendiri
                </span>
              )}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
              {roles
                .filter((r) => r.is_active || formRoleIds.includes(r.id))
                .map((r) => {
                  const checked = formRoleIds.includes(r.id);
                  const disabled = Boolean(editingUser?.is_self) || (!r.assignable && !checked);
                  return (
                    <label
                      key={r.id}
                      className={`flex items-center gap-2.5 p-2.5 rounded-xl border transition cursor-pointer ${
                        checked
                          ? 'bg-blue-50/70 border-blue-400 text-blue-950'
                          : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                      } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
                    >
                      <input
                        type="checkbox"
                        checked={checked}
                        disabled={disabled}
                        onChange={() => toggleFormRole(r.id)}
                        className="rounded text-blue-600"
                      />
                      <div className="min-w-0 flex-1">
                        <div className="font-bold truncate">{r.display_name}</div>
                        <div className="text-[10px] text-slate-500">Level {r.level}</div>
                      </div>
                    </label>
                  );
                })}
            </div>
          </div>

          {/* Outlet Assignment */}
          <div>
            <div className="flex items-center justify-between mb-1.5">
              <label className="font-bold text-slate-700 flex items-center gap-1.5">
                <Building2 className="w-4 h-4 text-emerald-600" />
                Penempatan Outlet {isSuperAdminSelected ? '(Super Admin otomatis mengakses seluruh outlet)' : '*'}
              </label>
              {formOutletIds.length > 1 && (
                <span className="text-[11px] text-slate-500">
                  User dengan &gt;1 outlet dapat memilih outlet saat login
                </span>
              )}
            </div>

            <div className="border border-slate-200 rounded-xl divide-y divide-slate-100 max-h-52 overflow-y-auto">
              {outlets.map((o) => {
                const checked = formOutletIds.includes(o.id);
                const isDefault = formDefaultOutletId === o.id;
                return (
                  <div
                    key={o.id}
                    className={`flex items-center justify-between px-3.5 py-2.5 ${
                      checked ? 'bg-emerald-50/40' : 'bg-white'
                    }`}
                  >
                    <label className="flex items-center gap-2.5 cursor-pointer flex-1">
                      <input
                        type="checkbox"
                        checked={checked}
                        onChange={() => toggleFormOutlet(o.id)}
                        className="rounded text-emerald-600"
                      />
                      <div>
                        <div className="font-bold text-slate-800">
                          {o.code} &mdash; {o.name}
                        </div>
                        {o.city && <div className="text-[11px] text-slate-500">{o.city}</div>}
                      </div>
                    </label>

                    {checked && (
                      <label className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-emerald-700 cursor-pointer">
                        <input
                          type="radio"
                          name="default_outlet"
                          checked={isDefault}
                          onChange={() => setFormDefaultOutletId(o.id)}
                        />
                        Outlet Default
                      </label>
                    )}
                  </div>
                );
              })}
            </div>
          </div>
        </form>
      </Modal>

      {/* USER DETAIL MODAL */}
      <Modal
        open={detailOpen}
        onClose={() => setDetailOpen(false)}
        title={detailData ? `Detail User: ${detailData.user.name}` : 'Detail User'}
        subtitle="Informasi akun, permission efektif, penempatan outlet, dan riwayat aktivitas terbaru."
        icon={<Eye className="w-5 h-5" />}
        maxWidth="2xl"
      >
        {detailLoading || !detailData ? (
          <div className="py-12 text-center text-xs text-slate-500">Memuat detail user...</div>
        ) : (
          <div className="space-y-5 text-xs">
            <div className="flex flex-wrap items-center justify-between gap-3 p-4 rounded-xl bg-slate-50 border border-slate-200">
              <div>
                <div className="text-sm font-bold text-slate-900">{detailData.user.name}</div>
                <div className="text-slate-500">
                  @{detailData.user.username} &bull; {detailData.user.email}
                </div>
              </div>
              <div className="flex items-center gap-2">
                {renderStatusBadge(detailData.user.status)}
                <Badge variant="neutral">
                  Sesi Aktif: {detailData.user.active_sessions ?? 0}
                </Badge>
              </div>
            </div>

            {detailData.user.status === 'SUSPENDED' && (
              <div className="p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-900">
                <div className="font-bold">Akun sedang di-suspend</div>
                <div className="text-[11px] mt-0.5">
                  Waktu: {formatDateTime(detailData.user.suspended_at)} &bull; Alasan:{' '}
                  {detailData.user.suspension_reason || 'Tidak disebutkan'}
                </div>
              </div>
            )}

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="p-3.5 rounded-xl border border-slate-200">
                <div className="font-bold text-slate-700 mb-2">Role &amp; Hak Akses</div>
                <div className="flex flex-wrap gap-1.5 mb-2">
                  {detailData.user.roles.map((r) => (
                    <Badge key={r.id} variant="primary">
                      {r.display_name} (Lv.{r.level})
                    </Badge>
                  ))}
                </div>
                <div className="text-[11px] text-slate-500">
                  Total Permission Efektif:{' '}
                  <span className="font-bold text-slate-800">
                    {detailData.user.permissions?.length ?? 0}
                  </span>
                </div>
              </div>

              <div className="p-3.5 rounded-xl border border-slate-200">
                <div className="font-bold text-slate-700 mb-2">Penempatan Outlet</div>
                {detailData.user.all_outlets_access ? (
                  <Badge variant="purple">Akses Seluruh Outlet (Super Admin)</Badge>
                ) : (
                  <div className="flex flex-wrap gap-1.5">
                    {detailData.user.outlets.map((o) => (
                      <Badge key={o.id} variant={o.is_default ? 'success' : 'neutral'}>
                        {o.code} - {o.name} {o.is_default ? '(Default)' : ''}
                      </Badge>
                    ))}
                  </div>
                )}
              </div>
            </div>

            {/* Effective permissions preview */}
            <div>
              <div className="font-bold text-slate-700 mb-1.5">Daftar Permission Efektif</div>
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200 max-h-36 overflow-y-auto flex flex-wrap gap-1">
                {(detailData.user.permissions ?? []).map((perm) => (
                  <span
                    key={perm}
                    className="px-2 py-0.5 rounded bg-white border border-slate-200 text-[11px] font-mono text-slate-700"
                  >
                    {perm}
                  </span>
                ))}
              </div>
            </div>

            {/* Recent Activity */}
            <div>
              <div className="font-bold text-slate-700 mb-1.5">Aktivitas &amp; Audit Terakhir</div>
              {detailData.recent_activity.length === 0 ? (
                <div className="p-4 text-center text-slate-400 border border-slate-200 rounded-xl">
                  Belum ada riwayat aktivitas.
                </div>
              ) : (
                <div className="border border-slate-200 rounded-xl divide-y divide-slate-100 max-h-48 overflow-y-auto">
                  {detailData.recent_activity.map((act) => (
                    <div
                      key={act.id}
                      className="px-3.5 py-2 flex items-center justify-between gap-2"
                    >
                      <div>
                        <span className="font-bold text-slate-800">{act.label}</span>
                        <span className="text-slate-500 ml-1.5">
                          oleh {act.actor_name || 'System'}
                        </span>
                      </div>
                      <div className="text-[11px] text-slate-500 shrink-0">
                        {formatDateTime(act.created_at)}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}
      </Modal>

      {/* SUSPEND MODAL */}
      <Modal
        open={Boolean(suspendTarget)}
        onClose={() => setSuspendTarget(null)}
        title={`Suspend Akun: ${suspendTarget?.name ?? ''}`}
        subtitle="Akun yang di-suspend tidak dapat login dan seluruh sesi aktifnya langsung dicabut."
        icon={<Ban className="w-5 h-5 text-amber-600" />}
        maxWidth="md"
        footer={
          <div className="flex items-center justify-end gap-2">
            <Button variant="outline" size="sm" onClick={() => setSuspendTarget(null)}>
              Batal
            </Button>
            <Button
              variant="warning"
              size="sm"
              loading={suspending}
              onClick={handleSuspendSubmit}
              icon={<Ban className="w-4 h-4" />}
            >
              Konfirmasi Suspend
            </Button>
          </div>
        }
      >
        <form onSubmit={handleSuspendSubmit} className="space-y-3 text-xs">
          <div>
            <label className="block font-bold text-slate-700 mb-1">
              Alasan Suspend (Dicatat di Audit Log)
            </label>
            <textarea
              rows={3}
              value={suspendReason}
              onChange={(e) => setSuspendReason(e.target.value)}
              placeholder="Contoh: Cuti panjang / audit internal / mutasi cabang..."
              className="w-full p-2.5 border border-slate-300 rounded-lg focus:outline-none focus:border-blue-500"
            />
          </div>
        </form>
      </Modal>

      {/* RESET PASSWORD MODAL */}
      <Modal
        open={Boolean(resetTarget)}
        onClose={() => {
          setResetTarget(null);
          setGeneratedPassword(null);
        }}
        title={`Reset Password: ${resetTarget?.name ?? ''}`}
        subtitle="Password disimpan dengan hashing aman dan tercatat pada Audit Log."
        icon={<KeyRound className="w-5 h-5 text-amber-600" />}
        maxWidth="md"
        footer={
          generatedPassword ? (
            <div className="flex items-center justify-end">
              <Button
                variant="primary"
                size="sm"
                onClick={() => {
                  setResetTarget(null);
                  setGeneratedPassword(null);
                }}
              >
                Selesai
              </Button>
            </div>
          ) : (
            <div className="flex items-center justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setResetTarget(null)}>
                Batal
              </Button>
              <Button
                variant="primary"
                size="sm"
                loading={resetLoading}
                onClick={handleResetPasswordSubmit}
                icon={<Lock className="w-4 h-4" />}
              >
                Reset Password
              </Button>
            </div>
          )
        }
      >
        {generatedPassword ? (
          <div className="space-y-3 text-xs">
            <div className="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-950">
              <div className="font-bold mb-1">Password Sementara Berhasil Dibuat</div>
              <p className="text-[11px] text-emerald-800">
                Berikan password sementara ini kepada <b>{resetTarget?.name}</b>. Password ini hanya
                ditampilkan satu kali.
              </p>
              <div className="mt-3 flex items-center justify-between gap-2 p-2.5 rounded-lg bg-white border border-emerald-300 font-mono text-sm font-bold">
                <span>{generatedPassword}</span>
                <Button
                  variant="outline"
                  size="xs"
                  onClick={() => {
                    navigator.clipboard?.writeText(generatedPassword);
                    toast({ type: 'info', title: 'Password disalin ke clipboard' });
                  }}
                  icon={<Copy className="w-3.5 h-3.5" />}
                >
                  Salin
                </Button>
              </div>
            </div>
          </div>
        ) : (
          <form onSubmit={handleResetPasswordSubmit} className="space-y-4 text-xs">
            <div className="grid grid-cols-2 gap-2">
              <button
                type="button"
                onClick={() => setResetMode('generate')}
                className={`p-3 rounded-xl border text-left transition cursor-pointer ${
                  resetMode === 'generate'
                    ? 'bg-blue-50 border-blue-400 text-blue-950'
                    : 'bg-white border-slate-200 text-slate-700'
                }`}
              >
                <div className="font-bold">Generate Otomatis</div>
                <div className="text-[11px] text-slate-500 mt-0.5">
                  Buat password acak 12 karakter
                </div>
              </button>

              <button
                type="button"
                onClick={() => setResetMode('manual')}
                className={`p-3 rounded-xl border text-left transition cursor-pointer ${
                  resetMode === 'manual'
                    ? 'bg-blue-50 border-blue-400 text-blue-950'
                    : 'bg-white border-slate-200 text-slate-700'
                }`}
              >
                <div className="font-bold">Input Manual</div>
                <div className="text-[11px] text-slate-500 mt-0.5">
                  Ketik password baru secara manual
                </div>
              </button>
            </div>

            {resetMode === 'manual' && (
              <div className="space-y-3">
                <div>
                  <label className="block font-bold text-slate-700 mb-1">
                    Password Baru * (Min. 8 karakter huruf &amp; angka)
                  </label>
                  <input
                    type="password"
                    required
                    minLength={8}
                    value={resetPassword}
                    onChange={(e) => setResetPassword(e.target.value)}
                    className="w-full h-9 px-3 border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block font-bold text-slate-700 mb-1">
                    Konfirmasi Password Baru *
                  </label>
                  <input
                    type="password"
                    required
                    minLength={8}
                    value={resetPasswordConfirm}
                    onChange={(e) => setResetPasswordConfirm(e.target.value)}
                    className="w-full h-9 px-3 border border-slate-300 rounded-lg"
                  />
                </div>
              </div>
            )}

            <label className="flex items-center gap-2 cursor-pointer text-slate-700">
              <input
                type="checkbox"
                checked={resetRevokeSessions}
                onChange={(e) => setResetRevokeSessions(e.target.checked)}
                className="rounded text-blue-600"
              />
              <span>Cabut seluruh sesi login aktif user ini</span>
            </label>
          </form>
        )}
      </Modal>

      {/* CONFIRM DELETE / ACTIVATE / RESTORE MODAL */}
      <Modal
        open={Boolean(confirmAction)}
        onClose={() => setConfirmAction(null)}
        title={
          confirmAction?.type === 'delete'
            ? 'Konfirmasi Hapus Akun (Soft Delete)'
            : confirmAction?.type === 'activate'
              ? 'Konfirmasi Aktifkan Akun'
              : 'Konfirmasi Pulihkan Akun'
        }
        icon={<ShieldAlert className="w-5 h-5 text-rose-600" />}
        maxWidth="md"
        footer={
          <div className="flex items-center justify-end gap-2">
            <Button variant="outline" size="sm" onClick={() => setConfirmAction(null)}>
              Batal
            </Button>
            <Button
              variant={confirmAction?.type === 'delete' ? 'danger' : 'success'}
              size="sm"
              loading={confirmLoading}
              onClick={handleConfirmAction}
            >
              {confirmAction?.type === 'delete'
                ? 'Ya, Hapus Akun'
                : confirmAction?.type === 'activate'
                  ? 'Ya, Aktifkan'
                  : 'Ya, Pulihkan'}
            </Button>
          </div>
        }
      >
        {confirmAction && (
          <div className="text-xs text-slate-700 space-y-2">
            {confirmAction.type === 'delete' && (
              <p>
                Apakah Anda yakin ingin menghapus akun <b>{confirmAction.user.name}</b> (@
                {confirmAction.user.username})? Akun akan di-<b>soft delete</b>, seluruh sesi aktif
                dicabut, dan histori transaksi tetap terjaga.
              </p>
            )}
            {confirmAction.type === 'activate' && (
              <p>
                Aktifkan kembali akun <b>{confirmAction.user.name}</b> (@
                {confirmAction.user.username})? User akan dapat login kembali sesuai role dan outlet
                yang ditugaskan.
              </p>
            )}
            {confirmAction.type === 'restore' && (
              <p>
                Pulihkan akun <b>{confirmAction.user.name}</b> dari status DELETED? Akun akan
                dipulihkan dalam keadaan <b>SUSPENDED</b> hingga Anda mengaktifkannya.
              </p>
            )}
          </div>
        )}
      </Modal>
    </div>
  );
};
