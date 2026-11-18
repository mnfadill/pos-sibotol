<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\OutletInventory;
use App\Models\PosTerminal;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CompanyDashboardController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        if (! $user->hasPermission(Permission::CompanyDashboardView)) {
            throw new BusinessRuleException(
                'COMPANY_DASHBOARD_FORBIDDEN',
                'You do not have permission to view consolidated company analytics.',
                403,
            );
        }

        $orgId = $user->organization_id;

        $outlets = Outlet::query()->withoutGlobalScopes()
            ->with(['warehouses' => static fn ($q) => $q->where('is_active', true)->orderByDesc('is_default')])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $centralWarehouses = Warehouse::query()->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('type', 'central')
            ->where('is_active', true)
            ->get();

        $centralWarehouseIds = $centralWarehouses->pluck('id')->all();

        $centralStockQty = (float) (WarehouseInventory::query()->withoutGlobalScopes()
            ->whereIn('warehouse_id', $centralWarehouseIds === [] ? [0] : $centralWarehouseIds)
            ->sum('quantity') ?? 0);

        $branchStockQty = (float) (OutletInventory::query()->withoutGlobalScopes()
            ->whereIn('outlet_id', $outlets->pluck('id')->all())
            ->sum('quantity') ?? 0);

        // Counter sales across all outlets
        $counterOrdersQuery = Order::query()->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->whereIn('status', [
                OrderStatus::Completed->value,
                OrderStatus::PartiallyRefunded->value,
            ]);

        $totalCounterRevenue = (float) ((clone $counterOrdersQuery)->sum('grand_total') ?? 0);
        $totalCounterCount = (clone $counterOrdersQuery)->count();

        // B2B Sales Orders across all outlets
        $b2bOrdersQuery = SalesOrder::query()->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['draft', 'cancelled']);

        $totalB2bRevenue = (float) ((clone $b2bOrdersQuery)->sum('grand_total') ?? 0);
        $totalB2bCount = (clone $b2bOrdersQuery)->count();

        // Accounts Receivable across company
        $openInvoicesQuery = Invoice::query()->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->whereIn('status', ['unpaid', 'partial', 'overdue'])
            ->where('balance_due', '>', 0);

        $totalReceivables = (float) ((clone $openInvoicesQuery)->sum('balance_due') ?? 0);
        $openInvoicesCount = (clone $openInvoicesQuery)->count();

        $overdueInvoicesQuery = (clone $openInvoicesQuery)
            ->whereDate('due_date', '<', Carbon::today());
        $overdueCount = (clone $overdueInvoicesQuery)->count();
        $overdueAmount = (float) ((clone $overdueInvoicesQuery)->sum('balance_due') ?? 0);

        // Per-Outlet Performance comparison
        $outletPerformance = $outlets->map(static function (Outlet $outlet): array {
            $counterRev = (float) (Order::query()->withoutGlobalScopes()
                ->where('outlet_id', $outlet->id)
                ->whereIn('status', [OrderStatus::Completed->value, OrderStatus::PartiallyRefunded->value])
                ->sum('grand_total') ?? 0);

            $counterCnt = Order::query()->withoutGlobalScopes()
                ->where('outlet_id', $outlet->id)
                ->whereIn('status', [OrderStatus::Completed->value, OrderStatus::PartiallyRefunded->value])
                ->count();

            $b2bRev = (float) (SalesOrder::query()->withoutGlobalScopes()
                ->where('outlet_id', $outlet->id)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->sum('grand_total') ?? 0);

            $b2bCnt = SalesOrder::query()->withoutGlobalScopes()
                ->where('outlet_id', $outlet->id)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->count();

            $stockUnits = (float) (OutletInventory::query()->withoutGlobalScopes()
                ->where('outlet_id', $outlet->id)
                ->sum('quantity') ?? 0);

            $lowStockCount = OutletInventory::query()->withoutGlobalScopes()
                ->where('outlet_id', $outlet->id)
                ->whereColumn('quantity', '<=', 'reorder_level')
                ->count();

            $arBalance = (float) (Invoice::query()->withoutGlobalScopes()
                ->where('outlet_id', $outlet->id)
                ->whereIn('status', ['unpaid', 'partial', 'overdue'])
                ->sum('balance_due') ?? 0);

            $wh = $outlet->warehouses->first();

            return [
                'outlet_id' => $outlet->id,
                'code' => $outlet->code,
                'type' => $outlet->type ?? 'branch',
                'name' => $outlet->name,
                'city' => $outlet->city ?? '-',
                'warehouse_code' => $wh?->code ?? '-',
                'warehouse_name' => $wh?->name ?? '-',
                'counter_sales_revenue' => number_format($counterRev, 2, '.', ''),
                'counter_orders_count' => $counterCnt,
                'b2b_sales_revenue' => number_format($b2bRev, 2, '.', ''),
                'b2b_orders_count' => $b2bCnt,
                'total_revenue' => number_format($counterRev + $b2bRev, 2, '.', ''),
                'stock_units' => number_format($stockUnits, 0, '.', ''),
                'low_stock_count' => $lowStockCount,
                'receivables_balance' => number_format($arBalance, 2, '.', ''),
            ];
        })->values()->all();

        $pendingTransfers = StockTransfer::query()->withoutGlobalScopes()
            ->with(['sourceWarehouse:id,code,name', 'destinationWarehouse:id,code,name', 'destinationOutlet:id,code,name', 'items'])
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $recentInvoices = Invoice::query()->withoutGlobalScopes()
            ->with(['customer:id,code,name,customer_group', 'outlet:id,code,name'])
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        return response()->json([
            'kpis' => [
                'total_outlets' => $outlets->count(),
                'total_central_warehouses' => $centralWarehouses->count(),
                'total_counter_revenue' => number_format($totalCounterRevenue, 2, '.', ''),
                'total_counter_orders' => $totalCounterCount,
                'total_b2b_revenue' => number_format($totalB2bRevenue, 2, '.', ''),
                'total_b2b_orders' => $totalB2bCount,
                'total_company_revenue' => number_format($totalCounterRevenue + $totalB2bRevenue, 2, '.', ''),
                'central_warehouse_stock_qty' => number_format($centralStockQty, 0, '.', ''),
                'branch_warehouses_stock_qty' => number_format($branchStockQty, 0, '.', ''),
                'total_inventory_qty' => number_format($centralStockQty + $branchStockQty, 0, '.', ''),
                'total_receivables' => number_format($totalReceivables, 2, '.', ''),
                'open_invoices_count' => $openInvoicesCount,
                'overdue_invoices_count' => $overdueCount,
                'overdue_receivables_amount' => number_format($overdueAmount, 2, '.', ''),
            ],
            'central_warehouses' => $centralWarehouses,
            'outlet_performance' => $outletPerformance,
            'pending_transfers' => $pendingTransfers,
            'recent_invoices' => $recentInvoices,
        ]);
    }

    /**
     * Dynamically creates a new Outlet along with its dedicated Branch Warehouse,
     * default POS Terminal, and initial 0-stock inventory rows.
     */
    public function createOutlet(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        if (! $user->hasPermission(Permission::OutletsManage)) {
            throw new BusinessRuleException('FORBIDDEN', 'Only Super Admin or Management can create outlets.', 403);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:64'],
            'province' => ['nullable', 'string', 'max:64'],
            'region' => ['nullable', 'string', 'max:64'],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:30'],
        ]);

        return DB::transaction(function () use ($user, $validated): JsonResponse {
            $code = strtoupper(trim((string) $validated['code']));

            $outlet = Outlet::query()->withoutGlobalScopes()->create([
                'organization_id' => $user->organization_id,
                'code' => $code,
                'type' => 'branch',
                'name' => $validated['name'],
                'city' => $validated['city'],
                'province' => $validated['province'] ?? null,
                'region' => $validated['region'] ?? 'Jawa',
                'address' => $validated['address'],
                'phone' => $validated['phone'] ?? null,
                'timezone' => 'Asia/Jakarta',
                'tax_rate' => (string) ($validated['tax_rate'] ?? '11.00'),
                'service_charge_rate' => '0.00',
                'max_cashier_discount_percent' => '10.00',
                'allow_negative_stock' => false,
                'qris_merchant_name' => 'SIBOTOL '.strtoupper((string) $validated['city']),
                'is_active' => true,
            ]);

            $warehouse = Warehouse::query()->withoutGlobalScopes()->create([
                'organization_id' => $user->organization_id,
                'outlet_id' => $outlet->id,
                'code' => 'WH-'.$code,
                'name' => 'Branch Warehouse '.$validated['city'],
                'type' => 'branch',
                'city' => $validated['city'],
                'address' => $validated['address'],
                'phone' => $validated['phone'] ?? null,
                'is_default' => true,
                'is_active' => true,
            ]);

            $terminal = PosTerminal::query()->create([
                'outlet_id' => $outlet->id,
                'code' => 'POS-'.$code.'-01',
                'name' => 'Counter Kasir Utama '.$validated['city'],
                'device_identifier' => 'WEB-POS-'.$code.'-01',
                'is_active' => true,
            ]);

            // Initialize 0-balance inventory records for all active variants
            $variants = ProductVariant::query()->withoutGlobalScopes()
                ->where('organization_id', $user->organization_id)
                ->where('is_active', true)
                ->get();

            foreach ($variants as $variant) {
                OutletInventory::query()->withoutGlobalScopes()->firstOrCreate(
                    ['outlet_id' => $outlet->id, 'product_variant_id' => $variant->id],
                    [
                        'warehouse_id' => $warehouse->id,
                        'product_id' => $variant->product_id,
                        'quantity' => '0.000',
                        'reorder_level' => '15.000',
                        'version' => 1,
                    ],
                );

                WarehouseInventory::query()->withoutGlobalScopes()->firstOrCreate(
                    ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id],
                    [
                        'organization_id' => $user->organization_id,
                        'outlet_id' => $outlet->id,
                        'product_id' => $variant->product_id,
                        'quantity' => '0.000',
                        'reserved_quantity' => '0.000',
                        'in_transit_quantity' => '0.000',
                        'reorder_level' => '15.000',
                    ],
                );
            }

            return response()->json([
                'outlet' => $outlet,
                'warehouse' => $warehouse,
                'terminal' => $terminal,
            ], 201);
        });
    }
}

