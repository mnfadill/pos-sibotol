import React, { useEffect, useState } from 'react';
import {
  Activity,
  Filter,
  Search,
  RefreshCw,
  User,
  Clock,
  Calendar,
  Building2,
  FileText,
  ChevronRight,
  Eye,
} from 'lucide-react';
import { api, extractErrorMessage } from '../../lib/api';
import { Badge, type BadgeVariant } from '../ui/Badge';
import { Button } from '../ui/Button';
import { Card } from '../ui/Card';
import { EmptyState } from '../ui/EmptyState';
import { Modal } from '../ui/Modal';
import { Pagination } from '../ui/Pagination';
import { useToast } from '../ui/ToastProvider';
import type { GlobalActivityLog } from '../../types/pos';

export const GlobalActivityLogView: React.FC = () => {
  const { toast } = useToast();
  const [logs, setLogs] = useState<GlobalActivityLog[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [page, setPage] = useState<number>(1);
  const [lastPage, setLastPage] = useState<number>(1);
  const [total, setTotal] = useState<number>(0);

  // Summary statistics
  const [summary, setSummary] = useState<{
    today_total_activities: number;
    breakdown_by_shift: Record<string, number>;
  } | null>(null);

  // Filters
  const [search, setSearch] = useState<string>('');
  const [moduleFilter, setModuleFilter] = useState<string>('');
  const [actionFilter, setActionFilter] = useState<string>('');
  const [dateFrom, setDateFrom] = useState<string>('');
  const [dateTo, setDateTo] = useState<string>('');

  const [selectedLog, setSelectedLog] = useState<GlobalActivityLog | null>(null);

  useEffect(() => {
    fetchActivityLogs(1);
  }, [moduleFilter, actionFilter, dateFrom, dateTo]);

  const fetchActivityLogs = async (targetPage = 1) => {
    setLoading(true);
    try {
      const res = await api.get<{
        data: GlobalActivityLog[];
        current_page: number;
        last_page: number;
        total: number;
        summary: {
          today_total_activities: number;
          breakdown_by_shift: Record<string, number>;
        };
      }>('/activity-logs', {
        params: {
          page: targetPage,
          search: search || undefined,
          module_name: moduleFilter || undefined,
          action_type: actionFilter || undefined,
          date_from: dateFrom || undefined,
          date_to: dateTo || undefined,
        },
      });

      setLogs(res.data.data);
      setPage(res.data.current_page);
      setLastPage(res.data.last_page);
      setTotal(res.data.total);
      setSummary(res.data.summary);
    } catch (err) {
      toast({
        type: 'error',
        title: 'Gagal Memuat Activity Logs',
        description: extractErrorMessage(err),
      });
    } finally {
      setLoading(false);
    }
  };

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    fetchActivityLogs(1);
  };

  const getModuleBadge = (mod: string) => {
    switch (mod.toLowerCase()) {
      case 'sales':
        return <Badge variant="info">Sales</Badge>;
      case 'invoices':
      case 'receivables':
        return <Badge variant="warning">Piutang / AR</Badge>;
      case 'procurement':
        return <Badge variant="neutral">Procurement</Badge>;
      case 'inventory':
        return <Badge variant="success">Inventory</Badge>;
      case 'auth':
        return <Badge variant="purple">Shift / Auth</Badge>;
      default:
        return <Badge variant="neutral">{mod}</Badge>;
    }
  };

  const getActionBadgeVariant = (action: string): BadgeVariant => {
    switch (action.toLowerCase()) {
      case 'create':
      case 'checkin':
        return 'success';
      case 'update':
      case 'switch_shift':
        return 'info';
      case 'delete':
      case 'cancel':
        return 'danger';
      case 'pay':
        return 'warning';
      default:
        return 'neutral';
    }
  };

  return (
    <div className="space-y-4">
      {/* Shift Activity Summary Stats */}
      {summary && (
        <div className="grid grid-cols-1 sm:grid-cols-4 gap-3 bg-white p-4 rounded-xl border border-slate-200 shadow-2xs">
          <div className="sm:border-r border-slate-100 pr-4">
            <div className="text-xs text-slate-500 font-medium">Total Aktivitas Hari Ini</div>
            <div className="text-xl font-bold text-slate-900 mt-0.5">
              {summary.today_total_activities} Aksi
            </div>
          </div>
          {Object.entries(summary.breakdown_by_shift).map(([shiftName, count]) => (
            <div key={shiftName} className="sm:border-r border-slate-100 pr-4 last:border-0">
              <div className="text-xs text-slate-500 font-medium flex items-center gap-1">
                <Clock className="w-3.5 h-3.5 text-blue-500" />
                {shiftName || 'Tanpa Shift'}
              </div>
              <div className="text-xl font-bold text-blue-900 mt-0.5">{count} Aksi</div>
            </div>
          ))}
        </div>
      )}

      {/* Filter Card */}
      <Card>
        <form onSubmit={handleSearchSubmit} className="space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-2 text-xs font-bold uppercase text-slate-600">
              <Filter className="w-4 h-4 text-blue-600" />
              <span>Filter Aktivitas Karyawan &amp; Shift</span>
            </div>
            <button
              type="button"
              onClick={() => {
                setSearch('');
                setModuleFilter('');
                setActionFilter('');
                setDateFrom('');
                setDateTo('');
                fetchActivityLogs(1);
              }}
              className="text-xs font-semibold text-blue-600 hover:text-blue-800"
            >
              Reset Filter
            </button>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2.5 text-xs">
            <div>
              <label className="block text-[11px] font-semibold text-slate-500 mb-1">Cari Kata Kunci</label>
              <div className="relative">
                <input
                  type="text"
                  placeholder="Username, No Dokumen, Catatan..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="w-full pl-8 pr-2.5 py-1.5 rounded-lg border border-slate-200 bg-slate-50 text-xs"
                />
                <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-2 pointer-events-none" />
              </div>
            </div>

            <div>
              <label className="block text-[11px] font-semibold text-slate-500 mb-1">Modul Aplikasi</label>
              <select
                value={moduleFilter}
                onChange={(e) => setModuleFilter(e.target.value)}
                className="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 bg-slate-50 text-xs"
              >
                <option value="">Semua Modul</option>
                <option value="sales">Sales &amp; Counter Sales</option>
                <option value="invoices">Invoices &amp; AR</option>
                <option value="receivables">Pembayaran Piutang</option>
                <option value="inventory">Inventory &amp; Stock</option>
                <option value="procurement">Procurement</option>
                <option value="auth">Shift &amp; Check-in</option>
              </select>
            </div>

            <div>
              <label className="block text-[11px] font-semibold text-slate-500 mb-1">Tipe Aksi</label>
              <select
                value={actionFilter}
                onChange={(e) => setActionFilter(e.target.value)}
                className="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 bg-slate-50 text-xs"
              >
                <option value="">Semua Aksi</option>
                <option value="create">Create (Tambah)</option>
                <option value="update">Update (Ubah)</option>
                <option value="delete">Delete (Hapus)</option>
                <option value="pay">Pay (Bayar)</option>
                <option value="checkin">Check-in Shift</option>
                <option value="switch_shift">Switch Shift</option>
              </select>
            </div>

            <div>
              <label className="block text-[11px] font-semibold text-slate-500 mb-1">Tanggal Mulai</label>
              <input
                type="date"
                value={dateFrom}
                onChange={(e) => setDateFrom(e.target.value)}
                className="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 bg-slate-50 text-xs"
              >
              </input>
            </div>

            <div>
              <label className="block text-[11px] font-semibold text-slate-500 mb-1">Tanggal Akhir</label>
              <input
                type="date"
                value={dateTo}
                onChange={(e) => setDateTo(e.target.value)}
                className="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 bg-slate-50 text-xs"
              >
              </input>
            </div>
          </div>

          <div className="flex justify-end pt-1">
            <Button variant="primary" size="xs" type="submit" loading={loading} leftIcon={<Search className="w-3.5 h-3.5" />}>
              Terapkan Filter
            </Button>
          </div>
        </form>
      </Card>

      {/* Activity Logs Table */}
      <Card
        title="Daftar Log Aktivitas & Identitas Operasional Shift"
        subtitle={`Total ${total} riwayat aktivitas tercatat.`}
      >
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50 text-slate-500 font-bold uppercase">
                <th className="py-3 px-3.5">Waktu</th>
                <th className="py-3 px-3.5">User &amp; Karyawan</th>
                <th className="py-3 px-3.5">Shift &amp; Tgl Kerja</th>
                <th className="py-3 px-3.5">Outlet</th>
                <th className="py-3 px-3.5">Modul</th>
                <th className="py-3 px-3.5">Aksi</th>
                <th className="py-3 px-3.5">Deskripsi Aktivitas</th>
                <th className="py-3 px-3.5 text-right">Rincian</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {logs.map((log) => (
                <tr key={log.id} className="hover:bg-slate-50/70">
                  <td className="py-3 px-3.5 text-slate-500 font-mono whitespace-nowrap">
                    {new Date(log.created_at).toLocaleString('id-ID')}
                  </td>
                  <td className="py-3 px-3.5">
                    <div className="font-bold text-slate-900 flex items-center gap-1">
                      <User className="w-3 h-3 text-slate-400" />
                      @{log.username_snapshot}
                    </div>
                    <div className="text-[11px] text-slate-500">{log.employee_name_snapshot}</div>
                  </td>
                  <td className="py-3 px-3.5">
                    {log.shift_name_snapshot ? (
                      <div>
                        <Badge variant="info">{log.shift_name_snapshot}</Badge>
                        {log.work_date && (
                          <div className="text-[10px] text-slate-400 mt-0.5">{log.work_date}</div>
                        )}
                      </div>
                    ) : (
                      <span className="text-slate-400">-</span>
                    )}
                  </td>
                  <td className="py-3 px-3.5">
                    <Badge variant="neutral">{log.outlet?.code ?? '-'}</Badge>
                  </td>
                  <td className="py-3 px-3.5">{getModuleBadge(log.module_name)}</td>
                  <td className="py-3 px-3.5">
                    <Badge variant={getActionBadgeVariant(log.action_type)}>
                      {log.action_type.toUpperCase()}
                    </Badge>
                  </td>
                  <td className="py-3 px-3.5 max-w-md">
                    <div className="text-slate-800 line-clamp-2">{log.description}</div>
                  </td>
                  <td className="py-3 px-3.5 text-right">
                    <Button
                      variant="outline"
                      size="xs"
                      leftIcon={<Eye className="w-3.5 h-3.5" />}
                      onClick={() => setSelectedLog(log)}
                    >
                      Detail
                    </Button>
                  </td>
                </tr>
              ))}
              {logs.length === 0 && !loading && (
                <tr>
                  <td colSpan={8} className="py-8 text-center text-slate-400">
                    Tidak ada log aktivitas yang cocok dengan filter.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        {total > 25 && (
          <div className="mt-4 pt-3 border-t border-slate-100">
            <Pagination
              currentPage={page}
              lastPage={lastPage}
              total={total}
              onPageChange={(p) => fetchActivityLogs(p)}
            />
          </div>
        )}
      </Card>

      {/* Log Detail Modal */}
      <Modal
        isOpen={selectedLog !== null}
        onClose={() => setSelectedLog(null)}
        title="Rincian Log Aktivitas Pengguna"
        subtitle={`ID: #${selectedLog?.id ?? ''} • ${selectedLog?.module_name ?? ''} • ${selectedLog?.action_type ?? ''}`}
        maxWidth="lg"
        footer={
          <div className="flex justify-end">
            <Button variant="outline" onClick={() => setSelectedLog(null)}>
              Tutup
            </Button>
          </div>
        }
      >
        {selectedLog && (
          <div className="space-y-4 text-xs">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-slate-50 p-3.5 rounded-xl border border-slate-200">
              <div>
                <span className="text-slate-400">Username Snapshot:</span>
                <span className="font-bold text-slate-900 ml-1.5">@{selectedLog.username_snapshot}</span>
              </div>
              <div>
                <span className="text-slate-400">Nama Karyawan:</span>
                <span className="font-semibold text-slate-900 ml-1.5">{selectedLog.employee_name_snapshot}</span>
              </div>
              <div>
                <span className="text-slate-400">Shift Operasional:</span>
                <span className="font-semibold text-blue-700 ml-1.5">{selectedLog.shift_name_snapshot ?? '-'}</span>
              </div>
              <div>
                <span className="text-slate-400">Tanggal Kerja (Work Date):</span>
                <span className="font-semibold text-slate-900 ml-1.5">{selectedLog.work_date ?? '-'}</span>
              </div>
              <div>
                <span className="text-slate-400">Entity:</span>
                <span className="font-mono text-slate-800 ml-1.5">{selectedLog.entity_type} #{selectedLog.entity_id}</span>
              </div>
              <div>
                <span className="text-slate-400">IP Address:</span>
                <span className="font-mono text-slate-800 ml-1.5">{selectedLog.ip_address ?? '-'}</span>
              </div>
            </div>

            <div>
              <div className="font-bold text-slate-700 mb-1">Deskripsi:</div>
              <div className="p-3 bg-white border border-slate-200 rounded-lg text-slate-800">
                {selectedLog.description}
              </div>
            </div>

            {(selectedLog.old_values || selectedLog.new_values) && (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <div className="font-bold text-slate-700 mb-1">Nilai Sebelum (Old Values):</div>
                  <pre className="p-3 bg-slate-900 text-slate-100 rounded-lg text-[11px] overflow-x-auto max-h-48 font-mono">
                    {JSON.stringify(selectedLog.old_values, null, 2) || '(None)'}
                  </pre>
                </div>
                <div>
                  <div className="font-bold text-slate-700 mb-1">Nilai Sesudah (New Values):</div>
                  <pre className="p-3 bg-slate-900 text-emerald-300 rounded-lg text-[11px] overflow-x-auto max-h-48 font-mono">
                    {JSON.stringify(selectedLog.new_values, null, 2) || '(None)'}
                  </pre>
                </div>
              </div>
            )}
          </div>
        )}
      </Modal>
    </div>
  );
};
