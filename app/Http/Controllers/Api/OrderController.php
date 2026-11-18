<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CalculateCartRequest;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Requests\Order\CheckoutOrderRequest;
use App\Http\Requests\Order\RefundOrderRequest;
use App\Http\Requests\Order\VoidOrderRequest;
use App\Models\Order;
use App\Services\OrderCheckoutService;
use App\Services\QrisPayloadService;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly OrderCheckoutService $checkoutService,
        private readonly QrisPayloadService $qris,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $outlet = $this->context->requireOutlet();
        $status = $request->string('status')->toString();
        $search = $request->string('search')->toString();

        $paginator = Order::query()
            ->with(['items', 'payments', 'cashier:id,name,username', 'terminal:id,code,name'])
            ->where('outlet_id', $outlet->id)
            ->when($status !== '', static fn ($q) => $q->where('status', $status))
            ->when($search !== '', static function ($q) use ($search): void {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(static function ($w) use ($like): void {
                    $w->whereRaw('LOWER(order_number) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(customer_name, \'\')) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(table_label, \'\')) LIKE ?', [$like]);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return response()->json($paginator);
    }

    /**
     * Lists all Held (Draft) orders in the current outlet across ALL terminals
     * so cashiers can resume any held order on their terminal.
     */
    public function heldOrders(): JsonResponse
    {
        $outlet = $this->context->requireOutlet();

        $orders = Order::query()
            ->with(['items', 'cashier:id,name,username', 'terminal:id,code,name'])
            ->where('outlet_id', $outlet->id)
            ->where('status', OrderStatus::Held->value)
            ->orderByDesc('held_at')
            ->get();

        return response()->json(['orders' => $orders]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load(['items', 'payments', 'refunds.items', 'cashier:id,name,username', 'terminal:id,code,name']);

        return response()->json(['order' => $order]);
    }

    public function calculate(CalculateCartRequest $request): JsonResponse
    {
        $calculation = $this->checkoutService->calculateCart($request->validated(), verifySupervisorOverrides: false);
        unset($calculation['_price_override_logs'], $calculation['_discount_override_log']);

        return response()->json(['calculation' => $calculation]);
    }

    public function generateQris(Request $request): JsonResponse
    {
        $outlet = $this->context->requireOutlet();
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:25'],
        ]);

        $reference = (string) ($validated['reference'] ?? ('QR-'.now()->format('His')));
        $payload = $this->qris->generateForDecimalAmount($outlet, (string) $validated['amount'], $reference);

        return response()->json([
            'qris_payload' => $payload,
            'merchant_name' => $outlet->qris_merchant_name ?: $outlet->name,
            'amount' => $validated['amount'],
            'reference' => $reference,
        ]);
    }

    public function hold(CalculateCartRequest $request): JsonResponse
    {
        $order = $this->checkoutService->holdOrder($request->validated());

        return response()->json(['order' => $order], 201);
    }

    public function resume(Order $order): JsonResponse
    {
        $resumed = $this->checkoutService->resumeOrder($order);

        return response()->json(['order' => $resumed]);
    }

    public function checkout(CheckoutOrderRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('X-Idempotency-Key');

        $order = $this->checkoutService->checkout(
            payload: $request->validated(),
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
        );

        return response()->json(['order' => $order], 201);
    }

    public function cancel(CancelOrderRequest $request, Order $order): JsonResponse
    {
        $cancelled = $this->checkoutService->cancelHeldOrder(
            order: $order,
            reason: (string) $request->validated('reason'),
        );

        return response()->json(['order' => $cancelled]);
    }

    public function void(VoidOrderRequest $request, Order $order): JsonResponse
    {
        $voided = $this->checkoutService->voidOrder(
            order: $order,
            reason: (string) $request->validated('reason'),
            supervisorPin: (string) $request->validated('supervisor_pin'),
        );

        return response()->json(['order' => $voided]);
    }

    public function refund(RefundOrderRequest $request, Order $order): JsonResponse
    {
        /** @var list<array{order_item_id: int, quantity: string|int|float}> $items */
        $items = $request->validated('items');

        $refund = $this->checkoutService->refundOrder(
            order: $order,
            items: $items,
            method: PaymentMethod::from((string) $request->validated('method')),
            reason: (string) $request->validated('reason'),
            restock: (bool) $request->validated('restock'),
            supervisorPin: (string) $request->validated('supervisor_pin'),
        );

        return response()->json(['refund' => $refund], 201);
    }
}

