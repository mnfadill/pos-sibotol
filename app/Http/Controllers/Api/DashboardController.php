<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\OutletInventory;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\ShiftService;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly ShiftService $shifts,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $canViewAllOutlets = $user->isSuperAdmin() || $user->hasPermission(Permission::CompanyDashboardView);

        // Determine accessible outlets for this user
        $accessibleOutletsQuery = Outlet::query()->withoutGlobalScopes()
            ->with(['warehouses' => static fn ($q) => $q->where('is_active', true)->orderByDesc('is_default')])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('id');

        if (! $canViewAllOutlets) {
            $accessibleIds = $user->accessibleOutletIds();
            $accessibleOutletsQuery->whereIn('id', $accessibleIds === [] ? [0] : $accessibleIds);
        }

        $accessibleOutlets = $accessibleOutletsQuery->get();
        $accessibleOutletIds = $accessibleOutlets->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // Resolve requested scope ('all' or 'outlet') and outlet_id with strict RBAC
        $requestedScope = $request->string('scope', '')->toString();
        $requestedOutletId = $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null;

        if ($requestedOutletId !== null && $requestedOutletId > 0) {
            if (! $canViewAllOutlets && ! in_array($requestedOutletId, $accessibleOutletIds, true)) {
                throw new BusinessRuleException(
                    'OUTLET_ACCESS_DENIED',
                    'Anda tidak memiliki akses untuk melihat data outlet tersebut.',
                    403,
                );
            }
            $scope = 'outlet';
        } elseif ($requestedScope === 'all') {
            $scope = $canViewAllOutlets ? 'all' : 'outlet';
        } elseif ($requestedScope === 'outlet') {
            $scope = 'outlet';
        } else {
            // If an active outlet is bound in PosContext, default to that outlet for backward compatibility;
            // otherwise Super Admin defaults to 'all' outlets aggregation.
            $scope = ($this->context->outlet() !== null || ! $canViewAllOutlets) ? 'outlet' : 'all';
        }

        $selectedOutlet = null;
        if ($scope === 'outlet') {
            $targetId = $requestedOutletId ?: $this->context->outlet()?->id ?: ($accessibleOutletIds[0] ?? null);
            $selectedOutlet = $accessibleOutlets->firstWhere('id', $targetId);
            if ($selectedOutlet === null && $targetId !== null) {
                $selectedOutlet = Outlet::query()->withoutGlobalScopes()
                    ->with(['warehouses' => static fn ($q) => $q->where('is_active', true)->orderByDesc('is_default')])
                    ->where('organization_id', $orgId)
                    ->find($targetId);
            }
            if ($selectedOutlet === null) {
                throw new BusinessRuleException('OUTLET_REQUIRED', 'Pilih outlet aktif untuk melihat ringkasan dashboard.', 422);
            }
        }

        // Resolve period & date window (including previous comparison window)
        $period = $request->string('period', '30d')->toString();
        if (! in_array($period, ['today', '7d', '30d', 'custom', 'all'], true)) {
            $period = '30d';
        }

        $now = Carbon::now();
        $startAt = null;
        $endAt = $now->copy()->endOfDay();
        $prevStartAt = null;
        $prevEndAt = null;
        $previousPeriodLabel = null;

        if ($period === 'today') {
            $startAt = $now->copy()->startOfDay();
            $prevStartAt = $now->copy()->subDay()->startOfDay();
            $prevEndAt = $now->copy()->subDay()->endOfDay();
            $previousPeriodLabel = 'vs kemarin';
        } elseif ($period === '7d') {
            $startAt = $now->copy()->subDays(6)->startOfDay();
            $prevStartAt = $now->copy()->subDays(13)->startOfDay();
            $prevEndAt = $now->copy()->subDays(7)->endOfDay();
            $previousPeriodLabel = 'vs 7 hari sebelumnya';
        } elseif ($period === '30d') {
            $startAt = $now->copy()->subDays(29)->startOfDay();
            $prevStartAt = $now->copy()->subDays(59)->startOfDay();
            $prevEndAt = $now->copy()->subDays(30)->endOfDay();
            $previousPeriodLabel = 'vs 30 hari sebelumnya';
        } elseif ($period === 'custom') {
            $rawFrom = $request->string('date_from', '')->toString();
            $rawTo = $request->string('date_to', '')->toString();
            try {
                $startAt = $rawFrom !== '' ? Carbon::parse($rawFrom)->startOfDay() : $now->copy()->subDays(29)->startOfDay();
                $endAt = $rawTo !== '' ? Carbon::parse($rawTo)->endOfDay() : $now->copy()->endOfDay();
                if ($startAt->gt($endAt)) {
                    [$startAt, $endAt] = [$endAt->copy()->startOfDay(), $startAt->copy()->endOfDay()];
                }
                $daysSpan = max(1, (int) $startAt->diffInDays($endAt) + 1);
                $prevStartAt = $startAt->copy()->subDays($daysSpan)->startOfDay();
                $prevEndAt = $startAt->copy()->subDay()->endOfDay();
                $previousPeriodLabel = sprintf('vs %d hari sebelumnya', $daysSpan);
            } catch (\Throwable) {
                $period = '30d';
                $startAt = $now->copy()->subDays(29)->startOfDay();
                $prevStartAt = $now->copy()->subDays(59)->startOfDay();
                $prevEndAt = $now->copy()->subDays(30)->endOfDay();
                $previousPeriodLabel = 'vs 30 hari sebelumnya';
            }
        }

        $targetOutletIds = $scope === 'all'
            ? $accessibleOutletIds
            : [(int) $selectedOutlet->id];

        $safeOutletIds = $targetOutletIds === [] ? [0] : $targetOutletIds;

        // Role-based section visibility
        $role = strtolower((string) ($user->role ?? ''));
        $isWarehouseOnly = $role === 'warehouse_staff';
        $isCashierOnly = $role === 'cashier';
        $isFinance = $role === 'finance';
        $isSalesRep = $role === 'sales_rep';

        $sections = [
            'sales_analytics' => ! $isWarehouseOnly,
            'outlet_comparison' => $canViewAllOutlets && $scope === 'all',
            'inventory_overview' => $canViewAllOutlets || in_array($role, ['outlet_manager', 'supervisor', 'warehouse_staff'], true),
            'receivables' => $canViewAllOutlets || in_array($role, ['outlet_manager', 'supervisor', 'finance', 'sales_rep'], true),
            'shift_summary' => $scope === 'outlet' && ($canViewAllOutlets || in_array($role, ['cashier', 'supervisor', 'outlet_manager'], true)),
            'b2b_orders' => ! $isCashierOnly,
            'payment_breakdown' => ! $isWarehouseOnly,
            'audit_feed' => $canViewAllOutlets || in_array($role, ['outlet_manager', 'supervisor', 'finance'], true),
        ];

        $forceRefresh = $request->boolean('refresh');
        $cacheKey = sprintf(
            'unified_dashboard:org:%d:scope:%s:outlet:%d:period:%s:from:%s:to:%s:v:%s',
            $orgId,
            $scope,
            $selectedOutlet?->id ?? 0,
            $period,
            $startAt?->toDateString() ?? 'all',
            $endAt->toDateString(),
            (string) Cache::get("pos_cat:org:{$orgId}:v_stock", 1),
        );

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $payload = Cache::remember($cacheKey, 25, function () use (
            $orgId,
            $scope,
            $selectedOutlet,
            $accessibleOutlets,
            $safeOutletIds,
            $canViewAllOutlets,
            $period,
            $startAt,
            $endAt,
            $prevStartAt,
            $prevEndAt,
            $previousPeriodLabel,
        ): array {
            $completedStatuses = [
                OrderStatus::Completed->value,
                OrderStatus::PartiallyRefunded->value,
            ];

            // 1. Single aggregate query for Counter POS orders in active period
            $orderAgg = Order::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->whereIn('outlet_id', $safeOutletIds)
                ->whereIn('status', $completedStatuses)
                ->when($startAt !== null, static fn ($q) => $q->whereBetween('created_at', [$startAt, $endAt]))
                ->selectRaw('
                    COUNT(*) as completed_count,
                    COALESCE(SUM(subtotal), 0) as gross_sales,
                    COALESCE(SUM(discount_total), 0) as discount_total,
                    COALESCE(SUM(service_charge_total), 0) as service_total,
                    COALESCE(SUM(tax_total), 0) as tax_total,
                    COALESCE(SUM(grand_total), 0) as grand_total,
                    COALESCE(SUM(refunded_total), 0) as refunded_total
                ')
                ->first();

            $completedCount = (int) ($orderAgg->completed_count ?? 0);
            $grossSales = (float) ($orderAgg->gross_sales ?? 0);
            $discountTotal = (float) ($orderAgg->discount_total ?? 0);
            $serviceTotal = (float) ($orderAgg->service_total ?? 0);
            $taxTotal = (float) ($orderAgg->tax_total ?? 0);
            $grandTotalRevenue = (float) ($orderAgg->grand_total ?? 0);
            $refundedTotal = (float) ($orderAgg->refunded_total ?? 0);
            $netSales = max(0.0, $grossSales - $discountTotal - $refundedTotal);

            // 2. Single aggregate query for B2B Sales Orders in active period
            $b2bAgg = SalesOrder::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->whereIn('outlet_id', $safeOutletIds)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->when($startAt !== null, static fn ($q) => $q->whereBetween('created_at', [$startAt, $endAt]))
                ->selectRaw('COUNT(*) as b2b_count, COALESCE(SUM(grand_total), 0) as b2b_total')
                ->first();

            $b2bOrdersCount = (int) ($b2bAgg->b2b_count ?? 0);
            $b2bSalesTotal = (float) ($b2bAgg->b2b_total ?? 0);

            $totalSales = $grandTotalRevenue + $b2bSalesTotal;
            $totalTransactions = $completedCount + $b2bOrdersCount;
            $averageOrderValue = $totalTransactions > 0 ? round($totalSales / $totalTransactions, 2) : 0.0;

            // 3. Previous period comparison (when date-bounded)
            $prevTotalSales = null;
            $prevTotalTransactions = null;
            $salesGrowthPct = null;
            $transactionsGrowthPct = null;

            if ($prevStartAt !== null && $prevEndAt !== null) {
                $prevOrderAgg = Order::query()->withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->whereIn('outlet_id', $safeOutletIds)
                    ->whereIn('status', $completedStatuses)
                    ->whereBetween('created_at', [$prevStartAt, $prevEndAt])
                    ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as total')
                    ->first();

                $prevB2bAgg = SalesOrder::query()->withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->whereIn('outlet_id', $safeOutletIds)
                    ->whereNotIn('status', ['draft', 'cancelled'])
                    ->whereBetween('created_at', [$prevStartAt, $prevEndAt])
                    ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as total')
                    ->first();

                $prevTotalSales = (float) ($prevOrderAgg->total ?? 0) + (float) ($prevB2bAgg->total ?? 0);
                $prevTotalTransactions = (int) ($prevOrderAgg->cnt ?? 0) + (int) ($prevB2bAgg->cnt ?? 0);

                $salesGrowthPct = $prevTotalSales > 0
                    ? round((($totalSales - $prevTotalSales) / $prevTotalSales) * 100, 1)
                    : ($totalSales > 0 ? 100.0 : 0.0);

                $transactionsGrowthPct = $prevTotalTransactions > 0
                    ? round((($totalTransactions - $prevTotalTransactions) / $prevTotalTransactions) * 100, 1)
                    : ($totalTransactions > 0 ? 100.0 : 0.0);
            }

            // 4. Held / voided counts
            $heldOrdersCount = Order::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->whereIn('outlet_id', $safeOutletIds)
                ->where('status', OrderStatus::Held->value)
                ->count();

            $voidedOrRefundedCount = Order::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->whereIn('outlet_id', $safeOutletIds)
                ->whereIn('status', [
                    OrderStatus::Voided->value,
                    OrderStatus::Refunded->value,
                    OrderStatus::PartiallyRefunded->value,
                ])
                ->when($startAt !== null, static fn ($q) => $q->whereBetween('created_at', [$startAt, $endAt]))
                ->count();

            // 5. Accounts Receivable (open & overdue invoices)
            $todayDate = Carbon::today()->toDateString();
            $arAgg = Invoice::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->whereIn('outlet_id', $safeOutletIds)
                ->whereIn('status', ['unpaid', 'partial', 'overdue'])
                ->where('balance_due', '>', 0)
                ->selectRaw("
                    COUNT(*) as open_count,
                    COALESCE(SUM(balance_due), 0) as total_balance,
                    SUM(CASE WHEN due_date < ? OR status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                    COALESCE(SUM(CASE WHEN due_date < ? OR status = 'overdue' THEN balance_due ELSE 0 END), 0) as overdue_amount
                ", [$todayDate, $todayDate])
                ->first();

            $totalReceivables = (float) ($arAgg->total_balance ?? 0);
            $openInvoicesCount = (int) ($arAgg->open_count ?? 0);
            $overdueInvoicesCount = (int) ($arAgg->overdue_count ?? 0);
            $overdueReceivablesAmount = (float) ($arAgg->overdue_amount ?? 0);

            // 6. Inventory & Warehouse Summary
            $centralWarehouses = Warehouse::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('type', 'central')
                ->where('is_active', true)
                ->get(['id', 'code', 'name', 'type', 'city']);

            $centralWarehouseIds = $centralWarehouses->pluck('id')->all();
            $centralStockQty = (float) (WarehouseInventory::query()->withoutGlobalScopes()
                ->whereIn('warehouse_id', $centralWarehouseIds === [] ? [0] : $centralWarehouseIds)
                ->sum('quantity') ?? 0);

            $branchStockQty = (float) (OutletInventory::query()->withoutGlobalScopes()
                ->whereIn('outlet_id', $safeOutletIds)
                ->sum('quantity') ?? 0);

            $lowStockQuery = OutletInventory::query()->withoutGlobalScopes()
                ->with([
                    'product:id,name,unit',
                    'variant:id,name,sku',
                    'outlet:id,code,name',
                ])
                ->whereIn('outlet_id', $safeOutletIds)
                ->whereColumn('quantity', '<=', 'reorder_level');

            $lowStockCount = (clone $lowStockQuery)->count();
            $lowStockItems = $lowStockQuery
                ->orderBy('quantity')
                ->limit(8)
                ->get()
                ->map(static fn (OutletInventory $inv): array => [
                    'id' => $inv->id,
                    'product_variant_id' => $inv->product_variant_id,
                    'product_name' => $inv->product?->name ?? '-',
                    'variant_name' => $inv->variant?->name ?? '-',
                    'sku' => $inv->variant?->sku ?? '-',
                    'outlet_id' => $inv->outlet_id,
                    'outlet_code' => $inv->outlet?->code ?? '-',
                    'outlet_name' => $inv->outlet?->name ?? '-',
                    'quantity' => $inv->quantity,
                    'reorder_level' => $inv->reorder_level,
                    'unit' => $inv->product?->unit ?? 'pcs',
                ])
                ->values()
                ->all();

            $activeDeliveriesCount = Delivery::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->whereIn('outlet_id', $safeOutletIds)
                ->whereIn('status', ['preparing', 'in_transit'])
                ->count();

            $pendingTransfersQuery = StockTransfer::query()->withoutGlobalScopes()
                ->with(['sourceWarehouse:id,code,name', 'destinationWarehouse:id,code,name', 'destinationOutlet:id,code,name'])
                ->where('organization_id', $orgId)
                ->when($scope === 'outlet', static fn ($q) => $q->whereIn('destination_outlet_id', $safeOutletIds))
                ->whereIn('status', ['requested', 'approved', 'shipped']);

            $pendingTransfersCount = (clone $pendingTransfersQuery)->count();
            $pendingTransfers = $pendingTransfersQuery
                ->orderByDesc('created_at')
                ->limit(6)
                ->get();

            // 7. Sales Trend Time-Series (daily buckets for 7d/30d/custom/all, or last 14 days for 'all')
            $trendStart = $startAt !== null
                ? $startAt->copy()->startOfDay()
                : Carbon::now()->subDays(13)->startOfDay();
            $trendEnd = $endAt->copy()->endOfDay();

            // Cap trend buckets to max 60 days if custom range is huge
            if ($trendStart->diffInDays($trendEnd) > 60) {
                $trendStart = $trendEnd->copy()->subDays(59)->startOfDay();
            }

            $counterDaily = Order::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->whereIn('outlet_id', $safeOutletIds)
                ->whereIn('status', $completedStatuses)
                ->whereBetween('created_at', [$trendStart, $trendEnd])
                ->selectRaw('DATE(created_at) as day, COUNT(*) as tx_count, COALESCE(SUM(grand_total), 0) as total')
                ->groupBy('day')
                ->get()
                ->keyBy('day');

            $b2bDaily = SalesOrder::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->whereIn('outlet_id', $safeOutletIds)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->whereBetween('created_at', [$trendStart, $trendEnd])
                ->selectRaw('DATE(created_at) as day, COUNT(*) as tx_count, COALESCE(SUM(grand_total), 0) as total')
                ->groupBy('day')
                ->get()
                ->keyBy('day');

            $salesTrend = [];
            $cursor = $trendStart->copy();
            while ($cursor->lte($trendEnd)) {
                $dayKey = $cursor->toDateString();
                $cRow = $counterDaily->get($dayKey);
                $bRow = $b2bDaily->get($dayKey);
                $cVal = $cRow ? (float) $cRow->total : 0.0;
                $bVal = $bRow ? (float) $bRow->total : 0.0;
                $txVal = ($cRow ? (int) $cRow->tx_count : 0) + ($bRow ? (int) $bRow->tx_count : 0);

                $salesTrend[] = [
                    'date' => $dayKey,
                    'label' => $cursor->format('d M'),
                    'counter_sales' => round($cVal, 2),
                    'b2b_sales' => round($bVal, 2),
                    'total_sales' => round($cVal + $bVal, 2),
                    'transactions_count' => $txVal,
                ];
                $cursor->addDay();
            }

            // 8. Payment Method Breakdown (Counter POS)
            $paymentRows = Payment::query()->withoutGlobalScopes()
                ->select([
                    'method',
                    DB::raw('COUNT(*) as tx_count'),
                    DB::raw('SUM(amount) as total_amount'),
                ])
                ->whereIn('outlet_id', $safeOutletIds)
                ->where('status', 'settled')
                ->whereHas('order', static function ($q) use ($orgId, $completedStatuses, $startAt, $endAt): void {
                    $q->withoutGlobalScopes()
                        ->where('organization_id', $orgId)
                        ->whereIn('status', $completedStatuses)
                        ->when($startAt !== null, static fn ($sq) => $sq->whereBetween('created_at', [$startAt, $endAt]));
                })
                ->groupBy('method')
                ->get()
                ->keyBy(static fn ($row): string => $row->method instanceof PaymentMethod ? $row->method->value : (string) $row->method);

            $totalPaymentsSum = (float) $paymentRows->sum(static fn ($r) => (float) $r->total_amount);

            $methodLabels = [
                PaymentMethod::Cash->value => 'Tunai (Cash)',
                PaymentMethod::Qris->value => 'QRIS',
                PaymentMethod::DebitCard->value => 'Kartu Debit',
                PaymentMethod::CreditCard->value => 'Kartu Kredit',
                PaymentMethod::EWallet->value => 'E-Wallet',
                PaymentMethod::BankTransfer->value => 'Transfer Bank',
            ];

            $paymentBreakdown = [];
            foreach ($methodLabels as $methodKey => $label) {
                $row = $paymentRows->get($methodKey);
                $amount = $row ? (float) $row->total_amount : 0.0;
                $txCount = $row ? (int) $row->tx_count : 0;
                $pct = $totalPaymentsSum > 0 ? round(($amount / $totalPaymentsSum) * 100, 1) : 0.0;

                $paymentBreakdown[] = [
                    'method' => $methodKey,
                    'label' => $label,
                    'total_amount' => number_format($amount, 2, '.', ''),
                    'transaction_count' => $txCount,
                    'percentage' => $pct,
                ];
            }

            // 9. Top Selling Products (active scope & period)
            $topProducts = OrderItem::query()
                ->select([
                    'product_variant_id',
                    'product_name',
                    'variant_name',
                    'sku',
                    DB::raw('SUM(quantity - refunded_quantity) as total_qty'),
                    DB::raw('SUM(line_total) as total_revenue'),
                ])
                ->whereHas('order', static function ($q) use ($orgId, $safeOutletIds, $completedStatuses, $startAt, $endAt): void {
                    $q->withoutGlobalScopes()
                        ->where('organization_id', $orgId)
                        ->whereIn('outlet_id', $safeOutletIds)
                        ->whereIn('status', $completedStatuses)
                        ->when($startAt !== null, static fn ($sq) => $sq->whereBetween('created_at', [$startAt, $endAt]));
                })
                ->groupBy('product_variant_id', 'product_name', 'variant_name', 'sku')
                ->orderByDesc('total_revenue')
                ->limit(6)
                ->get()
                ->map(static fn ($item): array => [
                    'product_variant_id' => (int) $item->product_variant_id,
                    'product_name' => (string) $item->product_name,
                    'variant_name' => (string) $item->variant_name,
                    'sku' => (string) $item->sku,
                    'total_qty' => number_format((float) $item->total_qty, 0, '.', ''),
                    'total_revenue' => number_format((float) $item->total_revenue, 2, '.', ''),
                ])
                ->values()
                ->all();

            // 10. Per-Outlet Comparison (Super Admin in 'all' scope) — ZERO N+1 grouped queries!
            $outletComparison = [];
            if ($canViewAllOutlets && $scope === 'all') {
                $counterByOutlet = Order::query()->withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->whereIn('outlet_id', $safeOutletIds)
                    ->whereIn('status', $completedStatuses)
                    ->when($startAt !== null, static fn ($q) => $q->whereBetween('created_at', [$startAt, $endAt]))
                    ->selectRaw('outlet_id, COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as rev')
                    ->groupBy('outlet_id')
                    ->get()
                    ->keyBy('outlet_id');

                $b2bByOutlet = SalesOrder::query()->withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->whereIn('outlet_id', $safeOutletIds)
                    ->whereNotIn('status', ['draft', 'cancelled'])
                    ->when($startAt !== null, static fn ($q) => $q->whereBetween('created_at', [$startAt, $endAt]))
                    ->selectRaw('outlet_id, COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as rev')
                    ->groupBy('outlet_id')
                    ->get()
                    ->keyBy('outlet_id');

                $stockByOutlet = OutletInventory::query()->withoutGlobalScopes()
                    ->whereIn('outlet_id', $safeOutletIds)
                    ->selectRaw('outlet_id, COALESCE(SUM(quantity), 0) as qty, SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END) as low_cnt')
                    ->groupBy('outlet_id')
                    ->get()
                    ->keyBy('outlet_id');

                $arByOutlet = Invoice::query()->withoutGlobalScopes()
                    ->where('organization_id', $orgId)
                    ->whereIn('outlet_id', $safeOutletIds)
                    ->whereIn('status', ['unpaid', 'partial', 'overdue'])
                    ->where('balance_due', '>', 0)
                    ->selectRaw('outlet_id, COALESCE(SUM(balance_due), 0) as ar_balance')
                    ->groupBy('outlet_id')
                    ->get()
                    ->keyBy('outlet_id');

                foreach ($accessibleOutlets as $out) {
                    $c = $counterByOutlet->get($out->id);
                    $b = $b2bByOutlet->get($out->id);
                    $s = $stockByOutlet->get($out->id);
                    $a = $arByOutlet->get($out->id);

                    $cRev = $c ? (float) $c->rev : 0.0;
                    $bRev = $b ? (float) $b->rev : 0.0;
                    $rowTotal = $cRev + $bRev;
                    $sharePct = $totalSales > 0 ? round(($rowTotal / $totalSales) * 100, 1) : 0.0;
                    $wh = $out->warehouses->first();

                    $outletComparison[] = [
                        'outlet_id' => $out->id,
                        'code' => $out->code,
                        'name' => $out->name,
                        'city' => $out->city ?? '-',
                        'warehouse_code' => $wh?->code ?? '-',
                        'warehouse_name' => $wh?->name ?? '-',
                        'counter_sales_revenue' => number_format($cRev, 2, '.', ''),
                        'counter_orders_count' => $c ? (int) $c->cnt : 0,
                        'b2b_sales_revenue' => number_format($bRev, 2, '.', ''),
                        'b2b_orders_count' => $b ? (int) $b->cnt : 0,
                        'total_revenue' => number_format($rowTotal, 2, '.', ''),
                        'revenue_share_pct' => $sharePct,
                        'stock_units' => number_format($s ? (float) $s->qty : 0.0, 0, '.', ''),
                        'low_stock_count' => $s ? (int) $s->low_cnt : 0,
                        'receivables_balance' => number_format($a ? (float) $a->ar_balance : 0.0, 2, '.', ''),
                    ];
                }

                usort($outletComparison, static fn (array $x, array $y): int => ((float) $y['total_revenue']) <=> ((float) $x['total_revenue']));
            }

            // 11. Recent Operational Activities
            $recentOrders = Order::query()->withoutGlobalScopes()
                ->with(['items', 'payments', 'cashier:id,name,username', 'terminal:id,code,name', 'outlet:id,code,name'])
                ->where('organization_id', $orgId)
                ->whereIn('outlet_id', $safeOutletIds)
                ->orderByDesc('created_at')
                ->limit(6)
                ->get();

            $recentSalesOrders = SalesOrder::query()->withoutGlobalScopes()
                ->with(['customer:id,code,name,customer_group', 'warehouse:id,code,name', 'outlet:id,code,name'])
                ->where('organization_id', $orgId)
                ->whereIn('outlet_id', $safeOutletIds)
                ->orderByDesc('created_at')
                ->limit(6)
                ->get();

            $dueInvoices = Invoice::query()->withoutGlobalScopes()
                ->with(['customer:id,code,name,customer_group', 'outlet:id,code,name'])
                ->where('organization_id', $orgId)
                ->whereIn('outlet_id', $safeOutletIds)
                ->whereIn('status', ['unpaid', 'partial', 'overdue'])
                ->where('balance_due', '>', 0)
                ->orderBy('due_date')
                ->limit(6)
                ->get()
                ->map(static function (Invoice $inv) use ($todayDate): array {
                    $dueDateStr = $inv->due_date ? $inv->due_date->toDateString() : '-';

                    return [
                        'id' => $inv->id,
                        'invoice_number' => $inv->invoice_number,
                        'status' => $inv->status,
                        'due_date' => $dueDateStr,
                        'is_overdue' => $dueDateStr !== '-' && $dueDateStr < $todayDate,
                        'grand_total' => $inv->grand_total,
                        'balance_due' => $inv->balance_due,
                        'customer' => $inv->customer ? [
                            'code' => $inv->customer->code,
                            'name' => $inv->customer->name,
                            'customer_group' => $inv->customer->customer_group,
                        ] : null,
                        'outlet' => $inv->outlet ? [
                            'code' => $inv->outlet->code,
                            'name' => $inv->outlet->name,
                        ] : null,
                    ];
                })
                ->values()
                ->all();

            $recentActivities = AuditLog::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->when($scope === 'outlet', static fn ($q) => $q->whereIn('outlet_id', $safeOutletIds))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(6)
                ->get([
                    'id',
                    'uuid',
                    'actor_name_snapshot',
                    'actor_role_snapshot',
                    'action_event',
                    'entity_type',
                    'entity_id',
                    'created_at',
                ]);

            $activeWarehouse = $selectedOutlet?->warehouses?->first();

            return [
                'scope' => $scope,
                'selected_outlet_id' => $selectedOutlet?->id,
                'period' => $period,
                'date_from' => $startAt?->toDateString(),
                'date_to' => $endAt->toDateString(),
                'previous_period_label' => $previousPeriodLabel,
                'outlet' => $selectedOutlet ? [
                    'id' => $selectedOutlet->id,
                    'code' => $selectedOutlet->code,
                    'name' => $selectedOutlet->name,
                    'city' => $selectedOutlet->city,
                ] : null,
                'active_warehouse' => $activeWarehouse ? [
                    'id' => $activeWarehouse->id,
                    'code' => $activeWarehouse->code,
                    'name' => $activeWarehouse->name,
                    'type' => $activeWarehouse->type,
                ] : null,
                'kpis' => [
                    // Unified primary KPIs
                    'total_sales' => number_format($totalSales, 2, '.', ''),
                    'previous_total_sales' => $prevTotalSales !== null ? number_format($prevTotalSales, 2, '.', '') : null,
                    'sales_growth_pct' => $salesGrowthPct,
                    'total_transactions' => $totalTransactions,
                    'previous_total_transactions' => $prevTotalTransactions,
                    'transactions_growth_pct' => $transactionsGrowthPct,
                    'total_receivables' => number_format($totalReceivables, 2, '.', ''),
                    'open_invoices_count' => $openInvoicesCount,
                    'overdue_invoices_count' => $overdueInvoicesCount,
                    'overdue_receivables_amount' => number_format($overdueReceivablesAmount, 2, '.', ''),
                    'low_stock_count' => $lowStockCount,
                    'central_warehouse_stock_qty' => number_format($centralStockQty, 0, '.', ''),
                    'branch_warehouses_stock_qty' => number_format($branchStockQty, 0, '.', ''),
                    'total_inventory_qty' => number_format(($scope === 'all' ? $centralStockQty : 0.0) + $branchStockQty, 0, '.', ''),
                    'pending_transfers_count' => $pendingTransfersCount,
                    'total_outlets' => $accessibleOutlets->count(),
                    // Backward-compatible KPI keys
                    'gross_sales' => number_format($grossSales, 2, '.', ''),
                    'discount_total' => number_format($discountTotal, 2, '.', ''),
                    'net_sales' => number_format($netSales, 2, '.', ''),
                    'tax_and_service_total' => number_format($serviceTotal + $taxTotal, 2, '.', ''),
                    'grand_total_revenue' => number_format($grandTotalRevenue, 2, '.', ''),
                    'refunded_total' => number_format($refundedTotal, 2, '.', ''),
                    'completed_orders_count' => $completedCount,
                    'average_order_value' => number_format($averageOrderValue, 2, '.', ''),
                    'held_orders_count' => $heldOrdersCount,
                    'voided_and_refunded_count' => $voidedOrRefundedCount,
                    'b2b_sales_total' => number_format($b2bSalesTotal, 2, '.', ''),
                    'b2b_orders_count' => $b2bOrdersCount,
                    'combined_outlet_revenue' => number_format($totalSales, 2, '.', ''),
                    'outlet_receivables_balance' => number_format($totalReceivables, 2, '.', ''),
                    'outlet_open_invoices_count' => $openInvoicesCount,
                    'total_stock_units' => number_format($branchStockQty, 0, '.', ''),
                    'active_deliveries_count' => $activeDeliveriesCount,
                ],
                'warehouse_summary' => [
                    'central_warehouses' => $centralWarehouses,
                    'central_stock_qty' => number_format($centralStockQty, 0, '.', ''),
                    'branch_stock_qty' => number_format($branchStockQty, 0, '.', ''),
                    'pending_transfers_count' => $pendingTransfersCount,
                    'active_deliveries_count' => $activeDeliveriesCount,
                ],
                'sales_trend' => $salesTrend,
                'outlet_comparison' => $outletComparison,
                'payment_breakdown' => $paymentBreakdown,
                'top_products' => $topProducts,
                'low_stock_items' => $lowStockItems,
                'recent_orders' => $recentOrders,
                'recent_sales_orders' => $recentSalesOrders,
                'due_invoices' => $dueInvoices,
                'pending_transfers' => $pendingTransfers,
                'recent_activities' => $recentActivities,
            ];
        });

        // Live non-cached context fields (active shift & user access)
        $activeShift = ($scope === 'outlet' && $this->context->outlet()?->id === $selectedOutlet?->id)
            ? $this->context->shift()
            : null;
        $activeShiftSummary = $activeShift ? $this->shifts->calculateLiveSummary($activeShift) : null;

        return response()->json(array_merge($payload, [
            'access' => [
                'can_view_all_outlets' => $canViewAllOutlets,
                'role' => $role,
                'sections' => $sections,
            ],
            'available_outlets' => $accessibleOutlets->map(static fn (Outlet $o): array => [
                'id' => $o->id,
                'code' => $o->code,
                'name' => $o->name,
                'city' => $o->city,
            ])->values()->all(),
            'active_shift_summary' => $activeShiftSummary,
        ]));
    }
}
