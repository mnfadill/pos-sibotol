import React, { useCallback, useEffect, useState } from 'react';
import {
  Activity,
  Ban,
  CheckCircle2,
  KeyRound,
  Shield,
  Trash2,
  UserCheck,
  Users,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import type {
  ManagedRole,
  OutletInfo,
  UserManagementStats,
  UserSession,
} from '../../types/pos';
import { useToast } from '../ui/ToastProvider';
import { PermissionsMatrixTab } from './PermissionsMatrixTab';
import { RolesTab } from './RolesTab';
import { UserActivityLogTab } from './UserActivityLogTab';
import { UsersTab } from './UsersTab';

export type UserManagementSubTab = 'users' | 'roles' | 'permissions' | 'activity';

interface UserManagementPageProps {
  session: UserSession;
  initialSubTab?: UserManagementSubTab;
}

export const UserManagementPage: React.FC<UserManagementPageProps> = ({
  session,
  initialSubTab = 'users',
}) => {
  const { toast } = useToast();

  const [subTab, setSubTab] = useState<UserManagementSubTab>(initialSubTab);
  const [stats, setStats] = useState<UserManagementStats>({
    total: 0,
    active: 0,
    suspended: 0,
    deleted: 0,
    logged_in_today: 0,
    never_logged_in: 0,
  });
  const [outlets, setOutlets] = useState<OutletInfo[]>([]);
  const [roles, setRoles] = useState<
    Array<
      Pick<
        ManagedRole,
        'id' | 'name' | 'display_name' | 'level' | 'is_system' | 'is_active' | 'assignable'
      >
    >
  >([]);

  const perms = session.user.permissions ?? [];
  const isSuper = session.user.is_super_admin;
  const hasPerm = (p: string) => isSuper || perms.includes(p);

  const canViewUsers = hasPerm('users.view') || hasPerm('users.manage');
  const canCreateUsers = hasPerm('users.create') || hasPerm('users.manage');
  const canEditUsers = hasPerm('users.edit') || hasPerm('users.manage');
  const canDeleteUsers = hasPerm('users.delete') || hasPerm('users.manage');
  const canManageUsers = hasPerm('users.manage');

  const canViewRoles =
    hasPerm('roles.view') || hasPerm('roles.manage') || hasPerm('users.manage');
  const canCreateRoles = hasPerm('roles.create') || hasPerm('roles.manage');
  const canEditRoles = hasPerm('roles.edit') || hasPerm('roles.manage');
  const canDeleteRoles = hasPerm('roles.delete') || hasPerm('roles.manage');

  const canViewActivity =
    hasPerm('audit.view') || hasPerm('activity_logs.view') || hasPerm('users.manage');

  const fetchMeta = useCallback(async () => {
    try {
      const res = await api.get<{
        stats: UserManagementStats;
        outlets: OutletInfo[];
        roles: typeof roles;
      }>('/user-management/meta');
      setStats(res.data.stats);
      setOutlets(res.data.outlets);
      setRoles(res.data.roles);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat metadata User Management',
        description: extractErrorMessage(err),
      });
    }
  }, [toast]);

  useEffect(() => {
    fetchMeta();
  }, [fetchMeta]);

  useEffect(() => {
    setSubTab(initialSubTab);
  }, [initialSubTab]);

  return (
    <div className="p-4 sm:p-6 max-w-7xl mx-auto space-y-5">
      {/* Page Header */}
      <div className="bg-white border border-slate-200 rounded-2xl p-5 shadow-2xs flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3.5">
          <div className="w-11 h-11 rounded-2xl bg-slate-900 text-white flex items-center justify-center shrink-0">
            <Users className="w-5 h-5" />
          </div>
          <div>
            <h1 className="text-lg font-extrabold text-slate-900">
              User, Role &amp; Permission Management (RBAC)
            </h1>
            <p className="text-xs text-slate-500">
              Kelola akun pengguna, role dinamis, permission granular per modul, isolasi
              multi-outlet, dan jejak audit keamanan.
            </p>
          </div>
        </div>

        {/* Sub-page Navigation Tabs */}
        <div className="inline-flex flex-wrap rounded-xl bg-slate-100 p-1 border border-slate-200 gap-1">
          {canViewUsers && (
            <button
              type="button"
              onClick={() => setSubTab('users')}
              className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-bold transition cursor-pointer ${
                subTab === 'users'
                  ? 'bg-white text-slate-900 shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              <Users className="w-3.5 h-3.5" />
              User Management
            </button>
          )}

          {canViewRoles && (
            <button
              type="button"
              onClick={() => setSubTab('roles')}
              className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-bold transition cursor-pointer ${
                subTab === 'roles'
                  ? 'bg-white text-slate-900 shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              <Shield className="w-3.5 h-3.5" />
              Role Management
            </button>
          )}

          {canViewRoles && (
            <button
              type="button"
              onClick={() => setSubTab('permissions')}
              className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-bold transition cursor-pointer ${
                subTab === 'permissions'
                  ? 'bg-white text-slate-900 shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              <KeyRound className="w-3.5 h-3.5" />
              Permission Management
            </button>
          )}

          {canViewActivity && (
            <button
              type="button"
              onClick={() => setSubTab('activity')}
              className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-bold transition cursor-pointer ${
                subTab === 'activity'
                  ? 'bg-white text-slate-900 shadow-xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              <Activity className="w-3.5 h-3.5" />
              Activity Log
            </button>
          )}
        </div>
      </div>

      {/* User Statistics Cards */}
      <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <div className="bg-white border border-slate-200 rounded-xl p-3.5 shadow-2xs flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
            <Users className="w-4 h-4" />
          </div>
          <div>
            <div className="text-[11px] font-semibold text-slate-500">Total User</div>
            <div className="text-base font-extrabold text-slate-900">{stats.total}</div>
          </div>
        </div>

        <div className="bg-white border border-slate-200 rounded-xl p-3.5 shadow-2xs flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
            <CheckCircle2 className="w-4 h-4" />
          </div>
          <div>
            <div className="text-[11px] font-semibold text-slate-500">Akun Active</div>
            <div className="text-base font-extrabold text-emerald-700">{stats.active}</div>
          </div>
        </div>

        <div className="bg-white border border-slate-200 rounded-xl p-3.5 shadow-2xs flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
            <Ban className="w-4 h-4" />
          </div>
          <div>
            <div className="text-[11px] font-semibold text-slate-500">Suspended</div>
            <div className="text-base font-extrabold text-amber-700">{stats.suspended}</div>
          </div>
        </div>

        <div className="bg-white border border-slate-200 rounded-xl p-3.5 shadow-2xs flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0">
            <Trash2 className="w-4 h-4" />
          </div>
          <div>
            <div className="text-[11px] font-semibold text-slate-500">Soft Deleted</div>
            <div className="text-base font-extrabold text-rose-700">{stats.deleted}</div>
          </div>
        </div>

        <div className="bg-white border border-slate-200 rounded-xl p-3.5 shadow-2xs flex items-center gap-3 col-span-2 sm:col-span-1">
          <div className="w-9 h-9 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center shrink-0">
            <UserCheck className="w-4 h-4" />
          </div>
          <div>
            <div className="text-[11px] font-semibold text-slate-500">Login Hari Ini</div>
            <div className="text-base font-extrabold text-violet-700">{stats.logged_in_today}</div>
          </div>
        </div>
      </div>

      {/* Active Sub-page Content */}
      {subTab === 'users' && canViewUsers && (
        <UsersTab
          outlets={outlets}
          roles={roles}
          canCreate={canCreateUsers}
          canEdit={canEditUsers}
          canDelete={canDeleteUsers}
          canManage={canManageUsers}
          onStatsChange={setStats}
        />
      )}

      {subTab === 'roles' && canViewRoles && (
        <RolesTab
          canCreate={canCreateRoles}
          canEdit={canEditRoles}
          canDelete={canDeleteRoles}
          onRolesChanged={fetchMeta}
        />
      )}

      {subTab === 'permissions' && canViewRoles && (
        <PermissionsMatrixTab
          canEditRoles={canEditRoles}
          isSuperAdmin={isSuper}
          onPermissionsSaved={fetchMeta}
        />
      )}

      {subTab === 'activity' && canViewActivity && <UserActivityLogTab />}
    </div>
  );
};
