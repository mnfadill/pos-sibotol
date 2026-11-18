<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OutletInventory;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Services\ShiftService;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly ShiftService $shifts,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $outlet = $this->context->requireOutlet();
        $period = $request->string('period', 'all')->toString();

        $since = match ($period) {
            'today' => Carbon::now()->startOfDay(),
            '7d' => Carbon::now()->subDays(7)->startOfDay(),
            '30d' => Carbon::now()->subDays(30)->startOfDay(),
            default => null,
        };

        $completedOrdersQuery = Order::query()
            ->where('outlet_id', $outlet->id)
            ->whereIn('status', [
                OrderStatus::Completed->value,
                OrderStatus::PartiallyRefunded->value,
            ])
            ->when($since !== null, static fn ($q) => $q->where('created_at', '>=', $since));

        $completedCount = (clone $completedOrdersQuery)->count();
        $grossSales = (float) ((clone $completedOrdersQuery)->sum('subtotal') ?? 0);
        $discountTotal = (float) ((clone $completedOrdersQuery)->sum('discount_total') ?? 0);
        $serviceTotal = (float) ((clone $completedOrdersQuery)->sum('service_charge_total') ?? 0);
        $taxTotal = (float) ((clone $completedOrdersQuery)->sum('tax_total') ?? 0);
        $grandTotalRevenue = (float) ((clone $completedOrdersQuery)->sum('grand_total') ?? 0);
        $refundedTotal = (float) ((clone $completedOrdersQuery)->sum('refunded_total') ?? 0);
        $netSales = max(0.0, $grossSales - $discountTotal - $refundedTotal);
        $averageOrderValue = $completedCount > 0 ? round($grandTotalRevenue / $completedCount, 2) : 0.0;

        $heldOrdersCount = Order::query()
            ->where('outlet_id', $outlet->id)
            ->where('status', OrderStatus::Held->value)
            ->count();

        $voidedOrRefundedCount = Order::query()
            ->where('outlet_id', $outlet->id)
            ->whereIn('status', [
                OrderStatus::Voided->value,
                OrderStatus::Refunded->value,
                OrderStatus::PartiallyRefunded->value,
            ])
            ->when($since !== null, static fn ($q) => $q->where('created_at', '>=', $since))
            ->count();

        // Payment breakdown for completed orders in period
        $paymentRows = Payment::query()
            ->select([
                'method',
                DB::raw('COUNT(*) as tx_count'),
                DB::raw('SUM(amount) as total_amount'),
            ])
            ->where('outlet_id', $outlet->id)
            ->where('status', 'settled')
            ->whereHas('order', static function ($q) use ($since): void {
                $q->whereIn('status', [
                    OrderStatus::Completed->value,
                    OrderStatus::PartiallyRefunded->value,
                ])->when($since !== null, static fn ($sq) => $sq->where('created_at', '>=', $since));
            })
            ->groupBy('method')
            ->get()
            ->keyBy(static fn ($row): string => $row->method instanceof PaymentMethod ? $row->method->value : (string) $row->method);

        $totalPaymentsSum = (float) $paymentRows->sum(static fn ($r) => (float) $r->total_amount);

        $methodLabels = [
            PaymentMethod::Cash->value => 'Tunai (Cash)',
            PaymentMethod::Qris->value => 'QRIS Dinamis',
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

        // Top selling product variants
        $topProducts = OrderItem::query()
            ->select([
                'product_variant_id',
                'product_name',
                'variant_name',
                'sku',
                DB::raw('SUM(quantity - refunded_quantity) as total_qty'),
                DB::raw('SUM(line_total) as total_revenue'),
            ])
            ->whereHas('order', static function ($q) use ($outlet, $since): void {
                $q->where('outlet_id', $outlet->id)
                    ->whereIn('status', [
                        OrderStatus::Completed->value,
                        OrderStatus::PartiallyRefunded->value,
                    ])
                    ->when($since !== null, static fn ($sq) => $sq->where('created_at', '>=', $since));
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

        // Low stock items
        $lowStockQuery = OutletInventory::query()
            ->with(['product:id,name,unit', 'variant:id,name,sku'])
            ->where('outlet_id', $outlet->id)
            ->lowStock();

        $lowStockCount = (clone $lowStockQuery)->count();

        $lowStockItems = $lowStockQuery
            ->orderBy('quantity')
            ->limit(6)
            ->get()
            ->map(static fn (OutletInventory $inv): array => [
                'id' => $inv->id,
                'product_variant_id' => $inv->product_variant_id,
                'product_name' => $inv->product?->name ?? '-',
                'variant_name' => $inv->variant?->name ?? '-',
                'sku' => $inv->variant?->sku ?? '-',
                'quantity' => $inv->quantity,
                'reorder_level' => $inv->reorder_level,
                'unit' => $inv->product?->unit ?? 'pcs',
            ])
            ->values()
            ->all();

        // Recent orders
        $recentOrders = Order::query()
            ->with(['items', 'payments', 'cashier:id,name,username', 'terminal:id,code,name'])
            ->where('outlet_id', $outlet->id)
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        // Recent operational audit logs for this outlet
        $recentActivities = AuditLog::query()
            ->where('outlet_id', $outlet->id)
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

        $activeShift = $this->context->shift();
        $activeShiftSummary = $activeShift ? $this->shifts->calculateLiveSummary($activeShift) : null;
        $activeWarehouse = $this->context->warehouse();

        // Sub-Distributor Outlet B2B Sales Orders, Receivables & Deliveries
        $b2bOrdersQuery = SalesOrder::query()
            ->where('outlet_id', $outlet->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->when($since !== null, static fn ($q) => $q->where('created_at', '>=', $since));

        $b2bOrdersCount = (clone $b2bOrdersQuery)->count();
        $b2bSalesTotal = (float) ((clone $b2bOrdersQuery)->sum('grand_total') ?? 0);

        $outletInvoicesQuery = Invoice::query()
            ->where('outlet_id', $outlet->id)
            ->whereIn('status', ['unpaid', 'partial', 'overdue'])
            ->where('balance_due', '>', 0);

        $outletReceivablesBalance = (float) ((clone $outletInvoicesQuery)->sum('balance_due') ?? 0);
        $outletOpenInvoicesCount = (clone $outletInvoicesQuery)->count();

        $totalStockUnits = (float) (OutletInventory::query()
            ->where('outlet_id', $outlet->id)
            ->sum('quantity') ?? 0);

        $activeDeliveriesCount = Delivery::query()
            ->where('outlet_id', $outlet->id)
            ->whereIn('status', ['preparing', 'in_transit'])
            ->count();

        $recentSalesOrders = SalesOrder::query()
            ->with(['customer:id,code,name,customer_group', 'warehouse:id,code,name'])
            ->where('outlet_id', $outlet->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'period' => $period,
            'outlet' => [
                'id' => $outlet->id,
                'code' => $outlet->code,
                'name' => $outlet->name,
                'city' => $outlet->city,
            ],
            'active_warehouse' => $activeWarehouse ? [
                'id' => $activeWarehouse->id,
                'code' => $activeWarehouse->code,
                'name' => $activeWarehouse->name,
                'type' => $activeWarehouse->type,
            ] : null,
            'kpis' => [
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
                'low_stock_count' => $lowStockCount,
                'b2b_sales_total' => number_format($b2bSalesTotal, 2, '.', ''),
                'b2b_orders_count' => $b2bOrdersCount,
                'combined_outlet_revenue' => number_format($grandTotalRevenue + $b2bSalesTotal, 2, '.', ''),
                'outlet_receivables_balance' => number_format($outletReceivablesBalance, 2, '.', ''),
                'outlet_open_invoices_count' => $outletOpenInvoicesCount,
                'total_stock_units' => number_format($totalStockUnits, 0, '.', ''),
                'active_deliveries_count' => $activeDeliveriesCount,
            ],
            'active_shift_summary' => $activeShiftSummary,
            'payment_breakdown' => $paymentBreakdown,
            'top_products' => $topProducts,
            'low_stock_items' => $lowStockItems,
            'recent_orders' => $recentOrders,
            'recent_sales_orders' => $recentSalesOrders,
            'recent_activities' => $recentActivities,
        ]);
    }
}

