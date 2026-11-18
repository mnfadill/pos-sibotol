import React, { useCallback, useEffect, useState } from 'react';
import { Check, CheckCircle2, Lock, RefreshCw, Search, Shield } from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import type { PermissionModuleGroup } from '../../types/pos';
import { Badge } from '../ui/Badge';
import { Button } from '../ui/Button';
import { useToast } from '../ui/ToastProvider';

interface MatrixRole {
  id: number;
  display_name: string;
  is_system: boolean;
  is_active: boolean;
  is_super_admin: boolean;
  permission_ids: number[];
}

interface PermissionsMatrixTabProps {
  canEditRoles: boolean;
  isSuperAdmin: boolean;
  onPermissionsSaved: () => void;
}

const ACTION_LABELS: Record<string, string> = {
  view: 'View',
  create: 'Create',
  edit: 'Edit',
  delete: 'Delete',
  approve: 'Approve',
  cancel: 'Cancel',
  export: 'Export',
  manage: 'Manage',
};

export const PermissionsMatrixTab: React.FC<PermissionsMatrixTabProps> = ({
  canEditRoles,
  isSuperAdmin,
  onPermissionsSaved,
}) => {
  const { toast } = useToast();

  const [actions, setActions] = useState<string[]>([
    'view',
    'create',
    'edit',
    'delete',
    'approve',
    'cancel',
    'export',
    'manage',
  ]);
  const [modules, setModules] = useState<PermissionModuleGroup[]>([]);
  const [roles, setRoles] = useState<MatrixRole[]>([]);
  const [selectedRoleId, setSelectedRoleId] = useState<number | null>(null);
  const [draftPermissionIds, setDraftPermissionIds] = useState<number[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [saving, setSaving] = useState<boolean>(false);
  const [search, setSearch] = useState<string>('');

  const fetchMatrix = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get<{
        actions: string[];
        modules: PermissionModuleGroup[];
        roles: MatrixRole[];
      }>('/user-management/permissions');

      setActions(res.data.actions);
      setModules(res.data.modules);
      setRoles(res.data.roles);

      const currentRole =
        res.data.roles.find((r) => r.id === selectedRoleId) ??
        res.data.roles.find((r) => !r.is_super_admin) ??
        res.data.roles[0] ??
        null;

      if (currentRole) {
        setSelectedRoleId(currentRole.id);
        setDraftPermissionIds(currentRole.permission_ids);
      }
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat matriks permission',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  }, [selectedRoleId, toast]);

  useEffect(() => {
    fetchMatrix();
  }, []);

  const selectedRole = roles.find((r) => r.id === selectedRoleId) ?? null;

  const handleSelectRole = (role: MatrixRole) => {
    setSelectedRoleId(role.id);
    setDraftPermissionIds(role.permission_ids);
  };

  const canModifySelectedRole =
    Boolean(selectedRole) &&
    !selectedRole!.is_super_admin &&
    canEditRoles &&
    (isSuperAdmin || !selectedRole!.is_system);

  const togglePermission = (permId: number, grantable: boolean) => {
    if (!canModifySelectedRole || !grantable) return;
    setDraftPermissionIds((prev) =>
      prev.includes(permId) ? prev.filter((id) => id !== permId) : [...prev, permId],
    );
  };

  const toggleModuleRow = (mod: PermissionModuleGroup) => {
    if (!canModifySelectedRole) return;
    const grantableIds = mod.permissions.filter((p) => p.grantable).map((p) => p.id);
    const allChecked = grantableIds.every((id) => draftPermissionIds.includes(id));
    if (allChecked) {
      setDraftPermissionIds((prev) => prev.filter((id) => !grantableIds.includes(id)));
    } else {
      setDraftPermissionIds((prev) => Array.from(new Set([...prev, ...grantableIds])));
    }
  };

  const isDirty = (() => {
    if (!selectedRole) return false;
    const orig = [...selectedRole.permission_ids].sort((a, b) => a - b).join(',');
    const curr = [...draftPermissionIds].sort((a, b) => a - b).join(',');
    return orig !== curr;
  })();

  const handleSaveMatrix = async () => {
    if (!selectedRole || !canModifySelectedRole) return;
    setSaving(true);
    try {
      await api.put(`/user-management/roles/${selectedRole.id}`, {
        permission_ids: draftPermissionIds,
      });
      toast({
        type: 'success',
        title: `Permission role "${selectedRole.display_name}" berhasil disimpan`,
      });
      await fetchMatrix();
      onPermissionsSaved();
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal menyimpan permission',
        description: extractErrorMessage(err),
      });
    } finally {
      setSaving(false);
    }
  };

  const filteredModules = modules.filter((m) => {
    if (!search.trim()) return true;
    const q = search.toLowerCase();
    return (
      m.label.toLowerCase().includes(q) ||
      m.module.toLowerCase().includes(q) ||
      m.permissions.some((p) => p.name.toLowerCase().includes(q))
    );
  });

  return (
    <div className="space-y-4">
      {/* Role Selector & Toolbar */}
      <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-2xs space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div className="text-xs font-bold text-slate-800">
              Pilih Role untuk Mengatur Matriks Permission Granular
            </div>
            <div className="text-[11px] text-slate-500">
              Setiap modul mendukung permission View, Create, Edit, Delete, Approve, Cancel, Export,
              Manage serta aksi spesifik modul.
            </div>
          </div>

          <div className="flex items-center gap-2">
            <div className="relative">
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Cari modul / kode permission..."
                className="h-9 pl-9 pr-3 text-xs bg-slate-50 border border-slate-200 rounded-lg w-56 focus:outline-none focus:bg-white focus:border-blue-500"
              />
            </div>

            <Button
              variant="outline"
              size="sm"
              onClick={fetchMatrix}
              icon={<RefreshCw className="w-3.5 h-3.5" />}
            >
              Refresh
            </Button>

            {canModifySelectedRole && (
              <Button
                variant="primary"
                size="sm"
                disabled={!isDirty}
                loading={saving}
                onClick={handleSaveMatrix}
                icon={<CheckCircle2 className="w-4 h-4" />}
              >
                Simpan Permission Role
              </Button>
            )}
          </div>
        </div>

        {/* Role Pills */}
        <div className="flex flex-wrap gap-1.5 pt-1">
          {roles.map((r) => {
            const active = r.id === selectedRoleId;
            return (
              <button
                key={r.id}
                type="button"
                onClick={() => handleSelectRole(r)}
                className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold border transition cursor-pointer ${
                  active
                    ? 'bg-slate-900 text-white border-slate-900 shadow-xs'
                    : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100'
                }`}
              >
                <Shield className="w-3.5 h-3.5" />
                <span>{r.display_name}</span>
                <span
                  className={`px-1.5 py-0.2 rounded-full text-[10px] ${
                    active ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-700'
                  }`}
                >
                  {r.permission_ids.length}
                </span>
              </button>
            );
          })}
        </div>

        {selectedRole?.is_super_admin && (
          <div className="flex items-center gap-2 text-xs text-violet-800 bg-violet-50 border border-violet-200 px-3 py-2 rounded-lg">
            <Lock className="w-4 h-4 shrink-0" />
            <span>
              <b>Super Admin</b> secara otomatis memiliki seluruh permission pada semua modul dan
              tidak dapat dikurangi.
            </span>
          </div>
        )}
      </div>

      {/* Granular Permission Matrix Table */}
      <div className="bg-white border border-slate-200 rounded-xl shadow-2xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                <th className="py-3 px-4 min-w-[200px]">Modul Aplikasi</th>
                {actions.map((act) => (
                  <th key={act} className="py-3 px-2.5 text-center">
                    {ACTION_LABELS[act] ?? act}
                  </th>
                ))}
                <th className="py-3 px-4">Permission Spesifik Lainnya</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 text-xs">
              {loading ? (
                <tr>
                  <td colSpan={actions.length + 2} className="py-12 text-center text-slate-500">
                    Memuat matriks permission...
                  </td>
                </tr>
              ) : filteredModules.length === 0 ? (
                <tr>
                  <td colSpan={actions.length + 2} className="py-12 text-center text-slate-500">
                    Modul tidak ditemukan.
                  </td>
                </tr>
              ) : (
                filteredModules.map((mod) => {
                  const byAction = new Map(mod.permissions.map((p) => [p.action, p]));
                  const extras = mod.permissions.filter((p) => !p.is_standard_action);
                  const grantableIds = mod.permissions
                    .filter((p) => p.grantable)
                    .map((p) => p.id);
                  const allChecked =
                    grantableIds.length > 0 &&
                    grantableIds.every((id) => draftPermissionIds.includes(id));

                  return (
                    <tr key={mod.module} className="hover:bg-slate-50/70">
                      <td className="py-3 px-4">
                        <div className="flex items-center justify-between gap-2">
                          <div>
                            <div className="font-bold text-slate-900">{mod.label}</div>
                            <div className="text-[10px] font-mono text-slate-400">
                              {mod.module}.*
                            </div>
                          </div>
                          {canModifySelectedRole && grantableIds.length > 0 && (
                            <button
                              type="button"
                              onClick={() => toggleModuleRow(mod)}
                              className="text-[10px] font-semibold text-blue-600 hover:underline cursor-pointer"
                            >
                              {allChecked ? 'Reset' : 'Semua'}
                            </button>
                          )}
                        </div>
                      </td>

                      {actions.map((act) => {
                        const perm = byAction.get(act);
                        if (!perm) {
                          return (
                            <td key={act} className="py-3 px-2.5 text-center text-slate-300">
                              &mdash;
                            </td>
                          );
                        }
                        const checked = draftPermissionIds.includes(perm.id);
                        const disabled = !canModifySelectedRole || !perm.grantable;
                        return (
                          <td key={act} className="py-3 px-2.5 text-center">
                            <button
                              type="button"
                              disabled={disabled}
                              title={perm.name}
                              onClick={() => togglePermission(perm.id, perm.grantable)}
                              className={`w-6 h-6 rounded-lg inline-flex items-center justify-center border transition ${
                                checked
                                  ? 'bg-emerald-600 border-emerald-600 text-white'
                                  : 'bg-white border-slate-300 text-transparent hover:border-blue-400'
                              } ${disabled ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer'}`}
                            >
                              <Check className="w-3.5 h-3.5" />
                            </button>
                          </td>
                        );
                      })}

                      <td className="py-3 px-4">
                        {extras.length === 0 ? (
                          <span className="text-slate-300 text-[11px]">&mdash;</span>
                        ) : (
                          <div className="flex flex-wrap gap-1.5">
                            {extras.map((perm) => {
                              const checked = draftPermissionIds.includes(perm.id);
                              const disabled = !canModifySelectedRole || !perm.grantable;
                              return (
                                <button
                                  key={perm.id}
                                  type="button"
                                  disabled={disabled}
                                  onClick={() => togglePermission(perm.id, perm.grantable)}
                                  title={perm.name}
                                  className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-semibold border transition ${
                                    checked
                                      ? 'bg-emerald-50 border-emerald-300 text-emerald-800'
                                      : 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100'
                                  } ${disabled ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer'}`}
                                >
                                  {checked && <Check className="w-3 h-3 text-emerald-600" />}
                                  <span>{perm.action}</span>
                                </button>
                              );
                            })}
                          </div>
                        )}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        <div className="px-4 py-3 bg-slate-50 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600">
          <div>
            Role aktif:{' '}
            <Badge variant="primary" size="xs">
              {selectedRole?.display_name ?? '-'}
            </Badge>{' '}
            &bull; Total permission aktif:{' '}
            <span className="font-bold text-slate-900">{draftPermissionIds.length}</span>
          </div>
          {isDirty && (
            <div className="text-amber-700 font-semibold">
              Ada perubahan permission yang belum disimpan.
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
