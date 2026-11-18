import React, { useEffect, useState } from 'react';
import { LoginAndSessionModal } from './components/Auth/LoginAndSessionModal';
import { AuditLogHistoryPage } from './components/Audit/AuditLogHistoryPage';
import { CatalogPricingPage } from './components/Catalog/CatalogPricingPage';
import { TaxAndDiscountMasterPage } from './components/Catalog/TaxAndDiscountMasterPage';
import { CheckoutScreen } from './components/Checkout/CheckoutScreen';
import { CompanyDashboardPage } from './components/Dashboard/CompanyDashboardPage';
import { DashboardPage } from './components/Dashboard/DashboardPage';
import { AccountsReceivablePage } from './components/Finance/AccountsReceivablePage';
import { InventoryControlPage } from './components/Inventory/InventoryControlPage';
import { MasterProductPage } from './components/MasterProduct/MasterProductPage';
import { MasterPricingPage } from './components/MasterPricing/MasterPricingPage';
import { MasterUnitIndexPage } from './components/MasterUnit/MasterUnitIndexPage';
import { MasterCatalogAttrPage } from './components/MasterData/MasterCatalogAttrPage';
import { TierManagementPage } from './components/TierManagement/TierManagementPage';
import { type ActiveTab, AppSidebar } from './components/Layout/AppSidebar';
import { CashierHeaderBar } from './components/Layout/CashierHeaderBar';
import { OrdersManagementPage } from './components/Orders/OrdersManagementPage';
import { ProcurementPage } from './components/Procurement/ProcurementPage';
import { SalesOrdersAndCustomersPage } from './components/Sales/SalesOrdersAndCustomersPage';
import { ShiftHistoryPage } from './components/Shift/ShiftHistoryPage';
import { ShiftManagementModal } from './components/Shift/ShiftManagementModal';
import { WelcomeBackShiftCheckinModal } from './components/Shift/WelcomeBackShiftCheckinModal';
import { SwitchShiftModal } from './components/Shift/SwitchShiftModal';
import { WarehouseAndTransferPage } from './components/Warehouse/WarehouseAndTransferPage';
import { UserManagementPage } from './components/UserManagement/UserManagementPage';
import { ToastProvider } from './components/ui/ToastProvider';
import { ErrorBoundary } from './components/ui/ErrorBoundary';
import { api, getStoredToken, setStoredToken } from './lib/api';
import type { InventoryRecord, OrderRecord, UserSession } from './types/pos';

const isStaffAccount = (user?: UserSession['user'] | null): boolean => {
  if (!user || user.is_super_admin) return false;
  const role = (user.role || '').toLowerCase();
  return (
    role === 'cashier' ||
    role === 'warehouse_staff' ||
    role === 'sales_rep' ||
    role === 'supervisor' ||
    (!user.is_super_admin && role !== 'owner' && role !== 'super_admin')
  );
};

export function App() {
  const [session, setSession] = useState<UserSession | null>(null);
  const [bootstrapping, setBootstrapping] = useState<boolean>(true);
  const [activeTab, setActiveTab] = useState<ActiveTab>('dashboard');
  const [sidebarCollapsed, setSidebarCollapsed] = useState<boolean>(false);
  const [showShiftModal, setShowShiftModal] = useState<boolean>(false);
  const [showEmployeeShiftModal, setShowEmployeeShiftModal] = useState<boolean>(false);
  const [showSwitchShiftModal, setShowSwitchShiftModal] = useState<boolean>(false);
  const [forceTerminalSelection, setForceTerminalSelection] = useState<boolean>(false);
  const [heldOrdersCount, setHeldOrdersCount] = useState<number>(0);
  const [lowStockCount, setLowStockCount] = useState<number>(0);

  useEffect(() => {
    const token = getStoredToken();
    if (!token) {
      setBootstrapping(false);
      return;
    }
    refreshSession();
  }, []);

  const syncOutletOperationalCounters = async (sess: UserSession) => {
    if (sess.active_outlet && sess.active_terminal) {
      try {
        const [heldRes, invRes] = await Promise.all([
          api.get<{ orders: OrderRecord[] }>('/orders/held'),
          api.get<{ inventories: InventoryRecord[] }>('/inventory?low_stock_only=1'),
        ]);
        setHeldOrdersCount(heldRes.data.orders.length);
        setLowStockCount(invRes.data.inventories.length);
      } catch {
        setHeldOrdersCount(0);
        setLowStockCount(0);
      }
    }
  };

  const refreshSession = async () => {
    try {
      const res = await api.get<{ session: UserSession }>('/auth/me');
      const sess = res.data.session;
      setSession(sess);

      if (sess.active_outlet && sess.active_terminal) {
        await syncOutletOperationalCounters(sess);

        // Check operational employee shift check-in first
        if (sess.requires_employee_shift_checkin || !sess.active_employee_shift) {
          setShowEmployeeShiftModal(true);
        }

        const isStaff = isStaffAccount(sess.user);
        if (isStaff && (sess.requires_open_shift || sess.active_shift === null)) {
          setShowShiftModal(true);
        } else if (sess.requires_open_shift && activeTab === 'checkout') {
          setShowShiftModal(true);
        }
      }
    } catch {
      setStoredToken(null);
      setSession(null);
    } finally {
      setBootstrapping(false);
    }
  };

  const handleQuickSwitchOutlet = async (outletId: number) => {
    try {
      const res = await api.post<{ session: UserSession }>('/auth/switch-outlet', {
        outlet_id: outletId,
      });
      const updated = res.data.session;
      setSession(updated);
      setForceTerminalSelection(false);
      await syncOutletOperationalCounters(updated);
    } catch {
      await refreshSession();
    }
  };

  const handleOpenCompanyDashboardFromSelector = async () => {
    if (!session) return;
    if (!session.active_outlet && session.available_outlets.length > 0) {
      await handleQuickSwitchOutlet(session.available_outlets[0].id);
    }
    setForceTerminalSelection(false);
    setActiveTab('company_dashboard');
  };

  const handleLogout = async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // ignore
    }
    setStoredToken(null);
    setSession(null);
    setForceTerminalSelection(false);
  };

  if (bootstrapping) {
    return (
      <div className="min-h-screen bg-slate-100 flex items-center justify-center text-sm font-semibold text-slate-600">
        Memuat sesi operasional SiBotol Sub-Distributor &amp; POS...
      </div>
    );
  }

  if (!session || session.requires_terminal_selection || forceTerminalSelection) {
    return (
      <ToastProvider>
        <LoginAndSessionModal
          session={session}
          onSessionUpdated={async (updated) => {
            setSession(updated);
            setForceTerminalSelection(false);
            await syncOutletOperationalCounters(updated);

            if (updated.active_outlet && (updated.requires_employee_shift_checkin || !updated.active_employee_shift)) {
              setShowEmployeeShiftModal(true);
            }

            const isStaff = isStaffAccount(updated.user);
            if (isStaff && (updated.requires_open_shift || updated.active_shift === null)) {
              setShowShiftModal(true);
            } else if (updated.requires_open_shift && activeTab === 'checkout') {
              setShowShiftModal(true);
            }
          }}
          onOpenCompanyDashboard={handleOpenCompanyDashboardFromSelector}
        />
      </ToastProvider>
    );
  }

  return (
    <ToastProvider>
      <div className="min-h-screen bg-slate-100 text-slate-900 flex">
        <AppSidebar
          session={session}
          activeTab={activeTab}
          collapsed={sidebarCollapsed}
          heldOrdersCount={heldOrdersCount}
          lowStockCount={lowStockCount}
          onSelectTab={setActiveTab}
          onToggleCollapse={() => setSidebarCollapsed((prev) => !prev)}
        />

        <div className="flex-1 flex flex-col min-w-0">
          <CashierHeaderBar
            session={session}
            activeTab={activeTab}
            heldOrdersCount={heldOrdersCount}
            lowStockCount={lowStockCount}
            onSelectTab={setActiveTab}
            onOpenShiftModal={() => setShowShiftModal(true)}
            onOpenEmployeeShiftModal={() => {
              if (!session.active_employee_shift) {
                setShowEmployeeShiftModal(true);
              } else {
                setShowSwitchShiftModal(true);
              }
            }}
            onSwitchTerminal={() => setForceTerminalSelection(true)}
            onQuickSwitchOutlet={handleQuickSwitchOutlet}
            onToggleSidebar={() => setSidebarCollapsed((prev) => !prev)}
            onLogout={handleLogout}
          />

          <main className="flex-1 overflow-x-hidden">
            <ErrorBoundary fallbackTitle="Terjadi Masalah Pada Modul Ini">
            {(activeTab === 'company_dashboard' || activeTab === 'finance_reports') && (
              <CompanyDashboardPage
                onSelectOutletWorkspace={async (outletId) => {
                  await handleQuickSwitchOutlet(outletId);
                  setActiveTab('dashboard');
                }}
                onBackToOutletSelection={() => setForceTerminalSelection(true)}
              />
            )}

            {activeTab === 'dashboard' && session.active_outlet && (
              <DashboardPage
                outlet={session.active_outlet}
                onNavigate={setActiveTab}
                onOpenShiftModal={() => setShowShiftModal(true)}
              />
            )}

            {activeTab === 'checkout' && session.active_outlet && (
              <CheckoutScreen
                outlet={session.active_outlet}
                hasOpenShift={session.active_shift !== null}
                onRequireOpenShift={() => setShowShiftModal(true)}
                onOrderCompletedOrHeld={refreshSession}
              />
            )}

            {(activeTab === 'sales_orders' || activeTab === 'master_customers') && (
              <SalesOrdersAndCustomersPage
                activeOutletId={session.active_outlet?.id}
                activeOutletName={session.active_outlet?.name}
                initialTab={activeTab === 'master_customers' ? 'customers' : 'sales_orders'}
              />
            )}

            {(activeTab === 'receivables' || activeTab === 'finance_payments') && (
              <AccountsReceivablePage
                activeOutletId={session.active_outlet?.id}
                activeOutletName={session.active_outlet?.name}
                canViewCompanyScope={
                  session.user.role === 'super_admin' ||
                  session.user.role === 'owner' ||
                  session.user.role === 'finance'
                }
              />
            )}

            {(activeTab === 'warehouse' || activeTab === 'warehouse_stock') && (
              <WarehouseAndTransferPage
                activeOutletId={session.active_outlet?.id}
                activeOutletName={session.active_outlet?.name}
                initialTab={activeTab === 'warehouse_stock' ? 'central_stock' : 'transfers'}
              />
            )}

            {(activeTab === 'procurement' ||
              activeTab === 'procurement_receiving' ||
              activeTab === 'procurement_history') && (
              <ProcurementPage
                initialTab={
                  activeTab === 'procurement_receiving'
                    ? 'receiving'
                    : activeTab === 'procurement_history'
                      ? 'history'
                      : 'po'
                }
              />
            )}

            {activeTab === 'orders' && (
              <OrdersManagementPage outlet={session.active_outlet} />
            )}

            {activeTab === 'shifts' && (
              <ShiftHistoryPage
                activeShift={session.active_shift}
                onOpenShiftModal={() => setShowShiftModal(true)}
              />
            )}

            {activeTab === 'catalog' && session.active_outlet && (
              <CatalogPricingPage outlet={session.active_outlet} />
            )}

            {activeTab === 'tier_management' && <TierManagementPage />}

            {activeTab === 'master_products' && <MasterProductPage />}

            {activeTab === 'master_categories' && <MasterCatalogAttrPage mode="categories" />}

            {activeTab === 'master_brands' && <MasterCatalogAttrPage mode="brands" />}

            {activeTab === 'master_suppliers' && <MasterCatalogAttrPage mode="suppliers" />}

            {activeTab === 'master_pricing' && <MasterPricingPage />}

            {activeTab === 'master_units' && <MasterUnitIndexPage />}

            {activeTab === 'pricing_rules' && session.active_outlet && (
              <TaxAndDiscountMasterPage
                outlet={session.active_outlet}
                onConfigUpdated={refreshSession}
              />
            )}

            {(activeTab === 'inventory' ||
              activeTab === 'inventory_stock' ||
              activeTab === 'inventory_movements') && (
              <InventoryControlPage
                initialTab={
                  activeTab === 'inventory_stock'
                    ? 'stock'
                    : activeTab === 'inventory_movements'
                      ? 'movements'
                      : 'adjust'
                }
              />
            )}

            {(activeTab === 'user_management' || activeTab === 'role_management') && (
              <UserManagementPage
                session={session}
                initialSubTab={activeTab === 'role_management' ? 'roles' : 'users'}
              />
            )}

            {activeTab === 'audit' && <AuditLogHistoryPage />}
            </ErrorBoundary>
          </main>
        </div>

        <ShiftManagementModal
          isOpen={showShiftModal}
          activeShift={session.active_shift}
          user={session.user}
          mandatoryOpen={
            isStaffAccount(session.user)
              ? session.active_shift === null
              : (session.requires_open_shift && activeTab === 'checkout')
          }
          onClose={() => setShowShiftModal(false)}
          onShiftChanged={refreshSession}
        />

        {/* Operational Employee Shift Check-in Modal */}
        <WelcomeBackShiftCheckinModal
          isOpen={showEmployeeShiftModal}
          session={session}
          onSuccess={async () => {
            setShowEmployeeShiftModal(false);
            await refreshSession();
          }}
          onClose={() => setShowEmployeeShiftModal(false)}
          isMandatory={session.active_employee_shift === null}
        />

        {/* Operational Shift Switch Modal */}
        <SwitchShiftModal
          isOpen={showSwitchShiftModal}
          session={session}
          onSuccess={async () => {
            setShowSwitchShiftModal(false);
            await refreshSession();
          }}
          onClose={() => setShowSwitchShiftModal(false)}
        />
      </div>
    </ToastProvider>
  );
}

export default App;
