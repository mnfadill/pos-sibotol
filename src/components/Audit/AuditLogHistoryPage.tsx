import React, { useEffect, useState } from 'react';
import {
  Activity,
  Code2,
  Database,
  Filter,
  GitCompare,
  Lock,
  RefreshCw,
  Search,
  Send,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { computePayloadDiff, formatDateTime } from '../../lib/format';
import type { AuditFilterOptions, AuditLogRecord } from '../../types/pos';
import { Badge, type BadgeVariant } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { Modal } from '../ui/Modal';
import { Pagination } from '../ui/Pagination';
import { useToast } from '../ui/ToastProvider';
import { GlobalActivityLogView } from './GlobalActivityLogView';

export const AuditLogHistoryPage: React.FC = () => {
  const { toast } = useToast();
  const [activeSubTab, setActiveSubTab] = useState<'system' | 'user_activity'>('user_activity');
  const [logs, setLogs] = useState<AuditLogRecord[]>([]);
  const [filterOptions, setFilterOptions] = useState<AuditFilterOptions>({
    actions: [],
    actors: [],
    outlets: [],
    terminals: [],
  });
  const [meta, setMeta] = useState({
    current_page: 1,
    last_page: 1,
    total: 0,
  });

  // Filters
  const [dateFrom, setDateFrom] = useState<string>('');
  const [dateTo, setDateTo] = useState<string>('');
  const [actorId, setActorId] = useState<string>('');
  const [actionEvent, setActionEvent] = useState<string>('');
  const [terminalId, setTerminalId] = useState<string>('');
  const [search, setSearch] = useState<string>('');

  const [selectedLog, setSelectedLog] = useState<AuditLogRecord | null>(null);
  const [diffMode, setDiffMode] = useState<'table' | 'side-by-side'>('table');
  const [loading, setLoading] = useState<boolean>(true);
  const [dispatching, setDispatching] = useState<boolean>(false);

  // Outbox Stats
  const [outboxStats, setOutboxStats] = useState<{
    pending: number;
    published: number;
    failed: number;
    dead: number;
  } | null>(null);

  useEffect(() => {
    fetchAuditLogs(1);
    fetchOutboxStats();
  }, [dateFrom, dateTo, actorId, actionEvent, terminalId]);

  const fetchAuditLogs = async (page = 1) => {
    setLoading(true);
    try {
      const res = await api.get<{
        data: AuditLogRecord[];
        meta: { current_page: number; last_page: number; total: number };
        filter_options: AuditFilterOptions;
      }>('/audit-logs', {
        params: {
          page,
          per_page: 25,
          date_from: dateFrom || undefined,
          date_to: dateTo || undefined,
          actor_id: actorId || undefined,
          action_event: actionEvent || undefined,
          pos_terminal_id: terminalId || undefined,
          search: search || undefined,
        },
      });
      setLogs(res.data.data);
      setMeta(res.data.meta);
      setFilterOptions(res.data.filter_options);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memuat Audit Log',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  const fetchOutboxStats = async () => {
    try {
      const res = await api.get<{
        stats: { pending: number; published: number; failed: number; dead: number };
      }>('/outbox');
      setOutboxStats(res.data.stats);
    } catch {
      // ignore
    }
  };

  const handleDispatchOutbox = async () => {
    setDispatching(true);
    try {
      await api.post('/outbox/dispatch');
      await fetchOutboxStats();
      toast({
        type: 'success',
        title: 'Sinkronisasi CRM Outbox Diproses',
        description: 'Pesan antrean transactional outbox berhasil dikirim.',
      });
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal memproses Outbox',
        description: extractErrorMessage(err),
      });
    } finally {
      setDispatching(false);
    }
  };

  const getActionBadge = (event: string) => {
    const found = filterOptions.actions.find((a) => a.value === event);
    const severity = found?.severity ?? 'low';

    const variant: BadgeVariant =
      severity === 'high' ? 'danger' : severity === 'medium' ? 'warning' : 'success';

    return (
      <Badge variant={variant} className="font-mono">
        {event}
      </Badge>
    );
  };

  const diffEntries = selectedLog
    ? computePayloadDiff(selectedLog.old_values, selectedLog.new_values)
    : [];

  return (
    <div className="p-5 space-y-4 max-w-[1600px] mx-auto">
      {/* Header & CRM Outbox Banner */}
      <div className="flex flex-wrap items-center justify-between gap-3 bg-white p-4 rounded-xl border border-slate-200 shadow-2xs">
        <div>
          <div className="flex items-center gap-2">
            <h2 className="text-base font-extrabold text-slate-900">
              History Log Activity &amp; Append-Only Audit Trail
            </h2>
            <Badge variant="success">
              <Lock className="w-3 h-3" />
              Append-Only Trigger Protected
            </Badge>
          </div>
          <p className="text-xs text-slate-500 mt-0.5">
            Merekam Actor Snapshot, Role Snapshot, IP Address, User-Agent, Timestamp ISO-8601, serta perbandingan JSON <code className="font-mono">old_values</code> vs <code className="font-mono">new_values</code>.
          </p>
        </div>

        {/* CRM Outbox Status Pill */}
        {outboxStats && (
          <div className="flex items-center gap-3 px-3.5 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs">
            <Database className="w-4 h-4 text-blue-600 shrink-0" />
            <div>
              <span className="text-slate-500 font-medium">CRM Outbox: </span>
              <span className="text-emerald-600 font-bold">
                {outboxStats.published} Terkirim
              </span>
              {' • '}
              <span className="text-amber-600 font-bold">
                {outboxStats.pending} Antrean
              </span>
            </div>
            {outboxStats.pending > 0 && (
              <Button
                variant="outline"
                size="xs"
                loading={dispatching}
                icon={<Send className="w-3 h-3 text-blue-600" />}
                onClick={handleDispatchOutbox}
              >
                Sinkronkan
              </Button>
            )}
          </div>
        )}
      </div>

      {/* Audit Sub-Tabs */}
      <div className="flex items-center gap-2 border-b border-slate-200 pb-2">
        <button
          type="button"
          onClick={() => setActiveSubTab('user_activity')}
          className={`px-4 py-2 rounded-lg text-xs font-bold transition flex items-center gap-2 ${
            activeSubTab === 'user_activity'
              ? 'bg-blue-600 text-white shadow-xs'
              : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'
          }`}
        >
          <Activity className="w-3.5 h-3.5" />
          Aktivitas Pengguna &amp; Shift (Global Activity Logs)
        </button>
        <button
          type="button"
          onClick={() => setActiveSubTab('system')}
          className={`px-4 py-2 rounded-lg text-xs font-bold transition flex items-center gap-2 ${
            activeSubTab === 'system'
              ? 'bg-blue-600 text-white shadow-xs'
              : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'
          }`}
        >
          <Lock className="w-3.5 h-3.5" />
          Audit Trail Sistem (System Events &amp; Payloads)
        </button>
      </div>

      {activeSubTab === 'user_activity' ? (
        <GlobalActivityLogView />
      ) : (
        <>

      {/* Multi-Criteria Filter Bar */}
      <Card padding="sm" className="space-y-3">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-1.5 text-xs font-bold text-slate-700">
            <Filter className="w-3.5 h-3.5 text-blue-600" />
            <span>Filter Audit Trail</span>
          </div>
          <button
            type="button"
            onClick={() => {
              setDateFrom('');
              setDateTo('');
              setActorId('');
              setActionEvent('');
              setTerminalId('');
              setSearch('');
              fetchAuditLogs(1);
            }}
            className="text-xs font-semibold text-blue-600 hover:text-blue-800 cursor-pointer"
          >
            Reset Semua Filter
          </button>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2.5 text-xs">
          <div>
            <label className="block text-[11px] font-semibold text-slate-500 mb-1">
              Dari Tanggal
            </label>
            <input
              type="date"
              value={dateFrom}
              onChange={(e) => setDateFrom(e.target.value)}
              className="w-full px-2.5 py-2 rounded-lg bg-slate-50 border border-slate-200 text-slate-900"
            />
          </div>

          <div>
            <label className="block text-[11px] font-semibold text-slate-500 mb-1">
              Sampai Tanggal
            </label>
            <input
              type="date"
              value={dateTo}
              onChange={(e) => setDateTo(e.target.value)}
              className="w-full px-2.5 py-2 rounded-lg bg-slate-50 border border-slate-200 text-slate-900"
            />
          </div>

          <div>
            <label className="block text-[11px] font-semibold text-slate-500 mb-1">
              Aktor (User)
            </label>
            <select
              value={actorId}
              onChange={(e) => setActorId(e.target.value)}
              className="w-full px-2.5 py-2 rounded-lg bg-slate-50 border border-slate-200 text-slate-900"
            >
              <option value="">Semua Aktor</option>
              {filterOptions.actors.map((act) => (
                <option key={act.id} value={act.id}>
                  {act.name} (@{act.username})
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-[11px] font-semibold text-slate-500 mb-1">
              Jenis Aktivitas (Action Event)
            </label>
            <select
              value={actionEvent}
              onChange={(e) => setActionEvent(e.target.value)}
              className="w-full px-2.5 py-2 rounded-lg bg-slate-50 border border-slate-200 text-slate-900"
            >
              <option value="">Semua Event</option>
              {filterOptions.actions.map((a) => (
                <option key={a.value} value={a.value}>
                  {a.value} ({a.category})
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-[11px] font-semibold text-slate-500 mb-1">
              Terminal POS
            </label>
            <select
              value={terminalId}
              onChange={(e) => setTerminalId(e.target.value)}
              className="w-full px-2.5 py-2 rounded-lg bg-slate-50 border border-slate-200 text-slate-900"
            >
              <option value="">Semua Terminal</option>
              {filterOptions.terminals.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.code} — {t.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-[11px] font-semibold text-slate-500 mb-1">
              Cari Entity / Kata Kunci
            </label>
            <div className="flex gap-1.5">
              <div className="relative flex-1">
                <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && fetchAuditLogs(1)}
                  placeholder="ID / Nama..."
                  className="w-full pl-8 pr-2 py-2 rounded-lg bg-slate-50 border border-slate-200 text-slate-900"
                />
              </div>
              <Button
                variant="outline"
                size="sm"
                onClick={() => fetchAuditLogs(1)}
                loading={loading}
                icon={<RefreshCw className="w-3.5 h-3.5" />}
              />
            </div>
          </div>
        </div>
      </Card>

      {/* Audit Logs Table */}
      <Card padding="none" className="overflow-hidden">
        {logs.length === 0 && !loading ? (
          <EmptyState
            icon={<Activity className="w-6 h-6" />}
            title="Tidak Ada Log Audit"
            description="Tidak ditemukan aktivitas yang cocok dengan filter yang dipilih."
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
                  <th className="py-3 px-4">Waktu (ISO-8601)</th>
                  <th className="py-3 px-4">Actor &amp; Role Snapshot</th>
                  <th className="py-3 px-4">Outlet &amp; Terminal</th>
                  <th className="py-3 px-4">Action Event</th>
                  <th className="py-3 px-4">Target Entity</th>
                  <th className="py-3 px-4">IP Address</th>
                  <th className="py-3 px-4 text-right">Payload Diff</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {logs.map((log) => (
                  <tr key={log.id} className="hover:bg-slate-50/80 transition">
                    <td className="py-3 px-4 whitespace-nowrap">
                      <div className="text-slate-900 font-semibold">
                        {formatDateTime(log.created_at)}
                      </div>
                      <div className="text-[10px] font-mono text-slate-400">
                        {log.created_at}
                      </div>
                    </td>

                    <td className="py-3 px-4">
                      <div className="font-bold text-slate-900">
                        {log.actor_name_snapshot ?? 'System / Anonymous'}
                      </div>
                      <div className="text-[11px] text-blue-600 font-medium">
                        {log.actor_role_snapshot ?? '-'}
                      </div>
                    </td>

                    <td className="py-3 px-4">
                      <div className="text-slate-800 font-semibold">
                        {log.outlet?.code ?? (log.outlet_id ? `Outlet #${log.outlet_id}` : 'Global')}
                      </div>
                      <div className="text-[11px] text-slate-400 font-mono">
                        {log.terminal?.code ??
                          (log.pos_terminal_id ? `Term #${log.pos_terminal_id}` : '-')}
                      </div>
                    </td>

                    <td className="py-3 px-4">{getActionBadge(log.action_event)}</td>

                    <td className="py-3 px-4 font-mono text-[11px] text-slate-700">
                      <span className="font-bold">{log.entity_type ?? '-'}</span>
                      {log.entity_id ? ` #${log.entity_id}` : ''}
                    </td>

                    <td className="py-3 px-4 font-mono text-[11px] text-slate-500">
                      {log.ip_address ?? '127.0.0.1'}
                    </td>

                    <td className="py-3 px-4 text-right">
                      <Button
                        variant="outline"
                        size="xs"
                        icon={<GitCompare className="w-3.5 h-3.5 text-blue-600" />}
                        onClick={() => setSelectedLog(log)}
                      >
                        Inspect Diff
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <Pagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          onPageChange={fetchAuditLogs}
        />
      </Card>

      {/* Payload Diff Viewer Modal */}
      <Modal
        isOpen={selectedLog !== null}
        onClose={() => setSelectedLog(null)}
        title={
          selectedLog ? `Audit Payload Diff • ${selectedLog.action_event}` : 'Payload Diff'
        }
        subtitle={
          selectedLog
            ? `UUID: ${selectedLog.uuid} • Timestamp: ${selectedLog.created_at}`
            : undefined
        }
        icon={<Code2 className="w-5 h-5" />}
        maxWidth="4xl"
      >
        {selectedLog && (
          <div className="space-y-4 text-xs">
            {/* Snapshot & Metadata Summary */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-[10px] uppercase text-slate-400 font-bold">
                  Actor Snapshot
                </span>
                <div className="font-bold text-slate-900 mt-0.5">
                  {selectedLog.actor_name_snapshot ?? '-'}
                </div>
                <div className="text-[11px] text-blue-600 font-medium">
                  {selectedLog.actor_role_snapshot ?? '-'}
                </div>
              </div>

              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-[10px] uppercase text-slate-400 font-bold">
                  Outlet &amp; Terminal
                </span>
                <div className="font-bold text-slate-900 mt-0.5">
                  {selectedLog.outlet?.name ?? `Outlet #${selectedLog.outlet_id ?? '-'}`}
                </div>
                <div className="text-[11px] text-slate-500 font-mono">
                  {selectedLog.terminal?.code ?? `Terminal #${selectedLog.pos_terminal_id ?? '-'}`}
                </div>
              </div>

              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-[10px] uppercase text-slate-400 font-bold">
                  Target Entity
                </span>
                <div className="font-mono font-bold text-slate-900 mt-0.5">
                  {selectedLog.entity_type} #{selectedLog.entity_id}
                </div>
                <div className="text-[11px] text-slate-500">
                  IP: {selectedLog.ip_address ?? '127.0.0.1'}
                </div>
              </div>

              <div className="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span className="text-[10px] uppercase text-slate-400 font-bold">
                  Request Context
                </span>
                <div className="font-mono text-[11px] text-slate-800 mt-0.5 truncate">
                  {String(selectedLog.metadata?.method ?? 'POST')}{' '}
                  {String(selectedLog.metadata?.path ?? '/api/v1')}
                </div>
                <div className="text-[10px] text-slate-400 truncate">
                  Req ID: {selectedLog.request_id ?? '-'}
                </div>
              </div>
            </div>

            {/* Mode Switch */}
            <div className="flex items-center justify-between">
              <span className="text-xs font-bold text-slate-700">
                Perbandingan Nilai Sebelum (<code className="font-mono">old_values</code>) vs Sesudah (<code className="font-mono">new_values</code>)
              </span>
              <div className="inline-flex bg-slate-100 p-1 rounded-lg border border-slate-200">
                <button
                  type="button"
                  onClick={() => setDiffMode('table')}
                  className={`px-3 py-1 rounded-md text-xs font-bold cursor-pointer ${
                    diffMode === 'table'
                      ? 'bg-white text-blue-600 shadow-2xs'
                      : 'text-slate-600'
                  }`}
                >
                  Field-by-Field Diff
                </button>
                <button
                  type="button"
                  onClick={() => setDiffMode('side-by-side')}
                  className={`px-3 py-1 rounded-md text-xs font-bold cursor-pointer ${
                    diffMode === 'side-by-side'
                      ? 'bg-white text-blue-600 shadow-2xs'
                      : 'text-slate-600'
                  }`}
                >
                  Raw JSON Side-by-Side
                </button>
              </div>
            </div>

            {diffMode === 'table' ? (
              <div className="border border-slate-200 rounded-xl overflow-hidden">
                <table className="w-full text-left border-collapse font-mono text-xs">
                  <thead>
                    <tr className="bg-slate-50 border-b border-slate-200 text-slate-500">
                      <th className="py-2.5 px-3">Field Key</th>
                      <th className="py-2.5 px-3">Old Value (Sebelum)</th>
                      <th className="py-2.5 px-3">New Value (Sesudah)</th>
                      <th className="py-2.5 px-3">Status</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {diffEntries.map((entry) => (
                      <tr
                        key={entry.key}
                        className={
                          entry.status === 'changed'
                            ? 'bg-amber-50/60'
                            : entry.status === 'added'
                              ? 'bg-emerald-50/50'
                              : entry.status === 'removed'
                                ? 'bg-rose-50/50'
                                : ''
                        }
                      >
                        <td className="py-2 px-3 font-bold text-slate-900">{entry.key}</td>
                        <td className="py-2 px-3 text-rose-700 break-all">
                          {entry.oldValue === undefined
                            ? '—'
                            : JSON.stringify(entry.oldValue)}
                        </td>
                        <td className="py-2 px-3 text-emerald-700 break-all">
                          {entry.newValue === undefined
                            ? '—'
                            : JSON.stringify(entry.newValue)}
                        </td>
                        <td className="py-2 px-3">
                          <Badge
                            variant={
                              entry.status === 'added'
                                ? 'success'
                                : entry.status === 'changed'
                                  ? 'warning'
                                  : entry.status === 'removed'
                                    ? 'danger'
                                    : 'neutral'
                            }
                          >
                            {entry.status.toUpperCase()}
                          </Badge>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="bg-slate-50 border border-slate-200 rounded-xl p-3">
                  <div className="text-xs font-bold text-rose-700 mb-2">
                    old_values (JSON)
                  </div>
                  <pre className="text-[11px] font-mono text-slate-800 overflow-x-auto">
                    {JSON.stringify(selectedLog.old_values, null, 2) || 'null'}
                  </pre>
                </div>
                <div className="bg-slate-50 border border-slate-200 rounded-xl p-3">
                  <div className="text-xs font-bold text-emerald-700 mb-2">
                    new_values (JSON)
                  </div>
                  <pre className="text-[11px] font-mono text-slate-800 overflow-x-auto">
                    {JSON.stringify(selectedLog.new_values, null, 2) || 'null'}
                  </pre>
                </div>
              </div>
            )}
          </div>
        )}
      </Modal>
        </>
      )}
    </div>
  );
};
