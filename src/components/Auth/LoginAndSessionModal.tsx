import React, { useState } from 'react';
import { KeyRound, Lock, ShieldCheck, Store, UserCheck } from 'lucide-react';
import { api, extractErrorMessage, setStoredToken } from '../../lib/api';
import type { UserSession } from '../../types/pos';
import { Button } from '../ui/Button';
import { OutletSelectionPage } from './OutletSelectionPage';

interface LoginAndSessionModalProps {
  session: UserSession | null;
  onSessionUpdated: (updated: UserSession) => void;
  onOpenCompanyDashboard?: () => void;
}

const DEMO_ACCOUNTS = [
  { label: 'Super Admin (Semua Outlet)', login: 'admin', role: 'Super Admin • 4 Outlet + Pusat' },
  { label: 'Kasir Tangerang', login: 'kasir.tng', role: 'Cashier • TNG-BSD Only' },
  { label: 'Kasir Jakarta', login: 'kasir.jkt', role: 'Cashier • JKT-SNP Only' },
  { label: 'Kasir Bandung', login: 'kasir.bdg', role: 'Cashier • BDG-DGO Only' },
  { label: 'Kasir Subang', login: 'kasir.sbg', role: 'Cashier • SBG-KTA Only' },
  { label: 'Kepala Gudang Pusat', login: 'gudang.pusat', role: 'Warehouse • WH-CENTRAL' },
  { label: 'Sales B2B Grosir', login: 'sales.b2b', role: 'Sales Rep • TNG & JKT' },
  { label: 'Finance & Piutang (AR)', login: 'finance.ar', role: 'Finance • Lintas Outlet' },
];

export const LoginAndSessionModal: React.FC<LoginAndSessionModalProps> = ({
  session,
  onSessionUpdated,
  onOpenCompanyDashboard,
}) => {
  const [loginInput, setLoginInput] = useState<string>('admin');
  const [password, setPassword] = useState<string>('password123');
  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  const handleLoginSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setLoading(true);

    try {
      const response = await api.post<{
        token: string;
        session: UserSession;
      }>('/auth/login', {
        login: loginInput.trim(),
        password,
        device_name: 'SiBotol Sub-Distributor Web',
      });

      setStoredToken(response.data.token);
      onSessionUpdated(response.data.session);
    } catch (err) {
      setError(extractErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = () => {
    setStoredToken(null);
    localStorage.removeItem('pos_outlet_id');
    localStorage.removeItem('pos_terminal_id');
    window.location.reload();
  };

  // Stage 2: Dedicated Outlet Selection Page once authenticated
  if (session) {
    return (
      <OutletSelectionPage
        session={session}
        onSessionReady={onSessionUpdated}
        onOpenCompanyDashboard={onOpenCompanyDashboard}
        onLogout={handleLogout}
      />
    );
  }

  // Stage 1: Dedicated Login Page
  return (
    <div className="min-h-screen bg-slate-100 text-slate-900 flex items-center justify-center p-4">
      <div className="w-full max-w-2xl bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden">
        {/* Top Brand Header */}
        <div className="px-6 py-5 bg-gradient-to-r from-slate-900 via-blue-900 to-indigo-900 text-white flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3.5">
            <div className="w-11 h-11 rounded-xl bg-blue-600 border border-blue-400/30 flex items-center justify-center">
              <Store className="w-6 h-6 text-white" />
            </div>
            <div>
              <h1 className="text-lg font-extrabold tracking-tight">SiBotol Sub-Distributor POS</h1>
              <p className="text-xs text-blue-200">
                Multi-Outlet Management, Central & Branch Warehouse, B2B Sales Order & AR
              </p>
            </div>
          </div>

          <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/10 text-xs font-semibold">
            <ShieldCheck className="w-3.5 h-3.5 text-emerald-300" />
            RBAC Multi-Outlet
          </div>
        </div>

        <div className="p-6 md:p-8 space-y-6">
          {error && (
            <div className="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-xs text-rose-700 font-medium">
              {error}
            </div>
          )}

          <form onSubmit={handleLoginSubmit} className="space-y-5">
            <div className="space-y-1">
              <h2 className="text-base font-bold text-slate-900">Masuk ke Akun Operasional</h2>
              <p className="text-xs text-slate-500">
                Setelah berhasil masuk, Anda akan diarahkan ke halaman pemilihan Outlet sesuai hak
                akses akun Anda.
              </p>
            </div>

            <div className="space-y-3.5">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                  Username atau Email
                </label>
                <div className="relative">
                  <UserCheck className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                  <input
                    type="text"
                    required
                    value={loginInput}
                    onChange={(e) => setLoginInput(e.target.value)}
                    placeholder="admin / kasir.tng / kasir.jkt / sales.b2b"
                    className="w-full pl-10 pr-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                  Password
                </label>
                <div className="relative">
                  <Lock className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                  <input
                    type="password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    className="w-full pl-10 pr-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600"
                  />
                </div>
              </div>
            </div>

            {/* Quick Demo Role Switcher */}
            <div className="pt-2 border-t border-slate-100">
              <div className="flex items-center justify-between mb-2">
                <span className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                  Akun Demo Sub-Distributor (Klik 1x untuk isi otomatis)
                </span>
                <span className="text-[11px] text-slate-400 flex items-center gap-1">
                  <KeyRound className="w-3 h-3" /> PIN Supervisor: <b>123456</b>
                </span>
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                {DEMO_ACCOUNTS.map((acc) => {
                  const active = loginInput === acc.login;
                  return (
                    <button
                      key={acc.login}
                      type="button"
                      onClick={() => {
                        setLoginInput(acc.login);
                        setPassword('password123');
                        setError(null);
                      }}
                      className={`text-left px-3 py-2 rounded-xl border transition-all ${
                        active
                          ? 'bg-blue-50/80 border-blue-600 text-blue-900 shadow-xs'
                          : 'bg-slate-50 hover:bg-slate-100 border-slate-200 text-slate-700'
                      }`}
                    >
                      <div className="text-xs font-bold truncate">{acc.label}</div>
                      <div className="text-[10px] text-slate-500 truncate">{acc.role}</div>
                    </button>
                  );
                })}
              </div>
            </div>

            <Button type="submit" variant="primary" size="lg" loading={loading} className="w-full">
              Masuk & Pilih Outlet Operasional
            </Button>
          </form>
        </div>
      </div>
    </div>
  );
};
