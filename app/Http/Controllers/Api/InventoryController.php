<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\StockAdjustmentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ManualStockAdjustmentRequest;
use App\Http\Requests\Inventory\StockOpnameRequest;
use App\Models\OutletInventory;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InventoryController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly InventoryService $inventoryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $outlet = $this->context->requireOutlet();
        $search = $request->string('search')->toString();
        $lowStockOnly = $request->boolean('low_stock_only', false);

        $items = OutletInventory::query()
            ->with(['product.category:id,name', 'variant'])
            ->where('outlet_id', $outlet->id)
            ->whereHas('product')
            ->whereHas('variant')
            ->when($lowStockOnly, static fn ($q) => $q->lowStock())
            ->when($search !== '', static function ($q) use ($search): void {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(static function ($w) use ($like, $search): void {
                    $w->whereHas('product', static fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$like]))
                        ->orWhereHas('variant', static fn ($v) => $v->whereRaw('LOWER(sku) LIKE ?', [$like])->orWhere('barcode', $search));
                });
            })
            ->orderBy('product_id')
            ->orderBy('product_variant_id')
            ->get();

        return response()->json([
            'outlet_id' => $outlet->id,
            'inventories' => $items,
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $outlet = $this->context->requireOutlet();
        $variantId = $request->integer('product_variant_id');
        $type = $request->string('type')->toString();

        $paginator = StockMovement::query()
            ->with([
                'variant' => static fn ($q) => $q->withTrashed()->with([
                    'product' => static fn ($pq) => $pq->withTrashed()->select('id', 'name', 'unit'),
                ]),
                'user:id,name,username',
            ])
            ->where('outlet_id', $outlet->id)
            ->when($variantId > 0, static fn ($q) => $q->where('product_variant_id', $variantId))
            ->when($type !== '', static fn ($q) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 30))));

        return response()->json($paginator);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $outlet = $this->context->requireOutlet();
        $type = $request->string('type')->toString();

        $paginator = StockAdjustment::query()
            ->with([
                'items.variant' => static fn ($q) => $q->withTrashed()->with([
                    'product' => static fn ($pq) => $pq->withTrashed()->select('id', 'name', 'unit'),
                ]),
                'creator:id,name,username',
                'authorizer:id,name,username',
            ])
            ->where('outlet_id', $outlet->id)
            ->when($type !== '', static fn ($q) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return response()->json($paginator);
    }

    public function adjust(ManualStockAdjustmentRequest $request): JsonResponse
    {
        /** @var list<array{product_variant_id: int, quantity: string|int|float, notes?: string|null}> $items */
        $items = $request->validated('items');

        $adjustment = $this->inventoryService->createManualAdjustment(
            type: StockAdjustmentType::from((string) $request->validated('type')),
            items: $items,
            reason: (string) $request->validated('reason'),
            notes: $request->validated('notes'),
            supervisorPin: (string) $request->validated('supervisor_pin'),
        );

        return response()->json(['adjustment' => $adjustment], 201);
    }

    public function opname(StockOpnameRequest $request): JsonResponse
    {
        /** @var list<array{product_variant_id: int, counted_quantity: string|int|float, notes?: string|null}> $items */
        $items = $request->validated('items');

        $adjustment = $this->inventoryService->completeStockOpname(
            items: $items,
            notes: $request->validated('notes'),
            supervisorPin: (string) $request->validated('supervisor_pin'),
        );

        return response()->json(['adjustment' => $adjustment], 201);
    }
}

