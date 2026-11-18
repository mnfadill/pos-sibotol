import React, { useCallback, useEffect, useState } from 'react';
import { Calendar, Eye, FileText, RefreshCw, Search } from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { formatDateTime } from '../../lib/format';
import type { UserActivityLogEntry } from '../../types/pos';
import { Badge, type BadgeVariant } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Modal } from '../ui/Modal';
import { Pagination } from '../ui/Pagination';
import { useToast } from '../ui/ToastProvider';

export const UserActivityLogTab: React.FC = () => {
  const { toast } = useToast();

  const [logs, setLogs] = useState<UserActivityLogEntry[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [search, setSearch] = useState<string>('');
  const [category, setCategory] = useState<'all' | 'users' | 'auth'>('all');
  const [actionEvent, setActionEvent] = useState<string>('');
  const [actorId, setActorId] = useState<string>('');
  const [dateFrom, setDateFrom] = useState<string>('');
  const [dateTo, setDateTo] = useState<string>('');
  const [page, setPage] = useState<number>(1);
  const [meta, setMeta] = useState<{ current_page: number; last_page: number; total: number }>({
    current_page: 1,
    last_page: 1,
    total: 0,
  });
  const [filterOptions, setFilterOptions] = useState<{
    actions: Array<{ value: string; label: string; category: string }>;
    actors: Array<{ id: number; name: string; username: string }>;
  }>({
    actions: [],
    actors: [],
  });

  const [inspectLog, setInspectLog] = useState<UserActivityLogEntry | null>(null);

  const fetchLogs = useCallback(
    async (targetPage = page) => {
      setLoading(true);
      try {
        const params: Record<string, string | number> = {
          page: targetPage,
          per_page: 20,
          category,
        };
        if (search.trim()) params.search = search.trim();
        if (actionEvent) params.action_event = actionEvent;
        if (actorId) params.actor_id = actorId;
        if (dateFrom) params.date_from = dateFrom;
        if (dateTo) params.date_to = dateTo;

        const res = await api.get<{
          data: UserActivityLogEntry[];
          meta: { current_page: number; last_page: number; total: number };
          filter_options: typeof filterOptions;
        }>('/user-management/activity-logs', { params });

        setLogs(res.data.data);
        setMeta(res.data.meta);
        if (res.data.filter_options) {
          setFilterOptions(res.data.filter_options);
        }
      } catch (err) {
        toast({
          type: 'error',
          title: 'Gagal memuat Activity Log',
          description: extractErrorMessage(err),
        });
      } finally {
        setLoading(false);
      }
    },
    [page, category, search, actionEvent, actorId, dateFrom, dateTo, toast],
  );

  useEffect(() => {
    fetchLogs(page);
  }, [fetchLogs, page]);

  const severityVariant = (sev: UserActivityLogEntry['severity']): BadgeVariant => {
    if (sev === 'high') return 'danger';
    if (sev === 'medium') return 'warning';
    return 'info';
  };

  return (
    <div className="space-y-4">
      {/* Filter Bar */}
      <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-2xs space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-2 flex-1">
            <div className="relative min-w-[200px] flex-1 max-w-xs">
              <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Cari pelaku, IP, atau perubahan..."
                className="w-full h-9 pl-9 pr-3 text-xs bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:bg-white focus:border-blue-500"
              />
            </div>

            <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs">
              {(
                [
                  { id: 'all', label: 'Semua' },
                  { id: 'users', label: 'User & Role' },
                  { id: 'auth', label: 'Login & Sesi' },
                ] as const
              ).map((c) => (
                <button
                  key={c.id}
                  type="button"
                  onClick={() => {
                    setCategory(c.id);
                    setPage(1);
                  }}
                  className={`px-2.5 py-1 rounded-md font-semibold transition cursor-pointer ${
                    category === c.id
                      ? 'bg-white text-slate-900 shadow-2xs'
                      : 'text-slate-600 hover:text-slate-900'
                  }`}
                >
                  {c.label}
                </button>
              ))}
            </div>

            <select
              value={actionEvent}
              onChange={(e) => {
                setActionEvent(e.target.value);
                setPage(1);
              }}
              className="h-9 px-3 text-xs bg-slate-50 border border-slate-200 rounded-lg text-slate-700"
            >
              <option value="">Semua Aktivitas</option>
              {filterOptions.actions.map((a) => (
                <option key={a.value} value={a.value}>
                  {a.label}
                </option>
              ))}
            </select>

            <select
              value={actorId}
              onChange={(e) => {
                setActorId(e.target.value);
                setPage(1);
              }}
              className="h-9 px-3 text-xs bg-slate-50 border border-slate-200 rounded-lg text-slate-700"
            >
              <option value="">Semua Pelaku</option>
              {filterOptions.actors.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.name} (@{u.username})
                </option>
              ))}
            </select>
          </div>

          <Button
            variant="outline"
            size="sm"
            onClick={() => fetchLogs(page)}
            icon={<RefreshCw className="w-3.5 h-3.5" />}
          >
            Refresh
          </Button>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-xs text-slate-600">
          <Calendar className="w-3.5 h-3.5 text-slate-400" />
          <span>Rentang Tanggal:</span>
          <input
            type="date"
            value={dateFrom}
            onChange={(e) => {
              setDateFrom(e.target.value);
              setPage(1);
            }}
            className="h-8 px-2.5 bg-slate-50 border border-slate-200 rounded-lg"
          />
          <span>s/d</span>
          <input
            type="date"
            value={dateTo}
            onChange={(e) => {
              setDateTo(e.target.value);
              setPage(1);
            }}
            className="h-8 px-2.5 bg-slate-50 border border-slate-200 rounded-lg"
          />
          {(dateFrom || dateTo || actionEvent || actorId || search) && (
            <button
              type="button"
              onClick={() => {
                setDateFrom('');
                setDateTo('');
                setActionEvent('');
                setActorId('');
                setSearch('');
                setPage(1);
              }}
              className="text-blue-600 font-semibold hover:underline ml-2 cursor-pointer"
            >
              Reset Filter
            </button>
          )}
        </div>
      </div>

      {/* Activity Table */}
      <div className="bg-white border border-slate-200 rounded-xl shadow-2xs overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
                <th className="py-3 px-4">Waktu</th>
                <th className="py-3 px-4">Pelaku</th>
                <th className="py-3 px-4">Aktivitas</th>
                <th className="py-3 px-4">Target / Entitas</th>
                <th className="py-3 px-4">Outlet / IP</th>
                <th className="py-3 px-4 text-right">Detail</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 text-xs">
              {loading ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-500">
                    Memuat riwayat aktivitas...
                  </td>
                </tr>
              ) : logs.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-slate-500">
                    Belum ada log aktivitas yang sesuai filter.
                  </td>
                </tr>
              ) : (
                logs.map((l) => (
                  <tr key={l.id} className="hover:bg-slate-50/80 transition">
                    <td className="py-3 px-4 text-slate-600 whitespace-nowrap">
                      {formatDateTime(l.created_at)}
                    </td>

                    <td className="py-3 px-4">
                      <div className="font-bold text-slate-900">{l.actor_name || 'System'}</div>
                      {l.actor_role && (
                        <div className="text-[11px] text-slate-500">{l.actor_role}</div>
                      )}
                    </td>

                    <td className="py-3 px-4">
                      <Badge variant={severityVariant(l.severity)} size="xs">
                        {l.label}
                      </Badge>
                    </td>

                    <td className="py-3 px-4">
                      {l.target_name ? (
                        <div>
                          <div className="font-semibold text-slate-800">{l.target_name}</div>
                          <div className="text-[11px] text-slate-500">@{l.target_username}</div>
                        </div>
                      ) : l.entity_type ? (
                        <span className="text-slate-700 font-mono text-[11px]">
                          {l.entity_type} #{l.entity_id ?? '-'}
                        </span>
                      ) : (
                        <span className="text-slate-400">&mdash;</span>
                      )}
                    </td>

                    <td className="py-3 px-4 text-[11px] text-slate-500">
                      <div>{l.outlet ? `${l.outlet.code} - ${l.outlet.name}` : 'Global / Pusat'}</div>
                      {l.ip_address && <div className="font-mono">{l.ip_address}</div>}
                    </td>

                    <td className="py-3 px-4 text-right">
                      <Button
                        variant="ghost"
                        size="xs"
                        onClick={() => setInspectLog(l)}
                        icon={<Eye className="w-3.5 h-3.5" />}
                      >
                        Perubahan
                      </Button>
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

      {/* INSPECT AUDIT DIFF MODAL */}
      <Modal
        open={Boolean(inspectLog)}
        onClose={() => setInspectLog(null)}
        title={inspectLog ? `Detail Audit: ${inspectLog.label}` : 'Detail Audit'}
        subtitle={inspectLog ? `${formatDateTime(inspectLog.created_at)} • IP ${inspectLog.ip_address ?? '-'}` : ''}
        icon={<FileText className="w-5 h-5" />}
        maxWidth="2xl"
      >
        {inspectLog && (
          <div className="space-y-4 text-xs">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3.5 rounded-xl bg-slate-50 border border-slate-200">
              <div>
                <div className="text-[11px] text-slate-500">Pelaku</div>
                <div className="font-bold text-slate-900">
                  {inspectLog.actor_name || 'System'} ({inspectLog.actor_role || '-'})
                </div>
              </div>
              <div>
                <div className="text-[11px] text-slate-500">Entitas / Target</div>
                <div className="font-bold text-slate-900">
                  {inspectLog.target_name
                    ? `${inspectLog.target_name} (@${inspectLog.target_username})`
                    : `${inspectLog.entity_type ?? '-'} #${inspectLog.entity_id ?? '-'}`}
                </div>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <div className="font-bold text-slate-700 mb-1">Data Sebelum (Old Values)</div>
                <pre className="p-3 rounded-xl bg-slate-900 text-slate-100 text-[11px] font-mono overflow-x-auto max-h-64">
                  {inspectLog.old_values
                    ? JSON.stringify(inspectLog.old_values, null, 2)
                    : '// Tidak ada data lama'}
                </pre>
              </div>
              <div>
                <div className="font-bold text-slate-700 mb-1">Data Sesudah (New Values)</div>
                <pre className="p-3 rounded-xl bg-slate-900 text-emerald-300 text-[11px] font-mono overflow-x-auto max-h-64">
                  {inspectLog.new_values
                    ? JSON.stringify(inspectLog.new_values, null, 2)
                    : '// Tidak ada data baru'}
                </pre>
              </div>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
};
