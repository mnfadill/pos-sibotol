<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\OutletInventory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\Audit\AuditLogger;
use App\Support\PosContext;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WarehouseAndTransferController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $activeOutlet = $this->context->outlet();
        $activeWarehouse = $this->context->warehouse();

        $invLoader = static function ($q): void {
            $q->whereHas('variant', static fn ($vq) => $vq->whereHas('product'))
                ->with(['variant.product:id,name,unit']);
        };

        $centralWarehouses = Warehouse::query()->withoutGlobalScopes()
            ->with(['inventories' => $invLoader])
            ->where('organization_id', $user->organization_id)
            ->where('type', 'central')
            ->where('is_active', true)
            ->get();

        $branchWarehouse = null;
        if ($activeOutlet !== null) {
            $branchWarehouse = Warehouse::query()->withoutGlobalScopes()
                ->with(['inventories' => $invLoader])
                ->where('outlet_id', $activeOutlet->id)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->first();
        }

        $transfersQuery = StockTransfer::query()->withoutGlobalScopes()
            ->with([
                'sourceWarehouse:id,code,name,type',
                'destinationWarehouse:id,code,name,type',
                'destinationOutlet:id,code,name,city',
                'requester:id,name,username',
                'approver:id,name,username',
                'receiver:id,name,username',
                'items',
            ])
            ->where('organization_id', $user->organization_id);

        if ($activeOutlet !== null && ! $request->boolean('all_outlets', false)) {
            $transfersQuery->where(static function ($q) use ($activeOutlet, $branchWarehouse): void {
                $q->where('destination_outlet_id', $activeOutlet->id)
                    ->orWhere('source_outlet_id', $activeOutlet->id);
                if ($branchWarehouse !== null) {
                    $q->orWhere('destination_warehouse_id', $branchWarehouse->id)
                        ->orWhere('source_warehouse_id', $branchWarehouse->id);
                }
            });
        }

        $transfers = $transfersQuery->orderByDesc('created_at')->limit(50)->get();

        return response()->json([
            'active_outlet_id' => $activeOutlet?->id,
            'active_warehouse' => $branchWarehouse ?? $activeWarehouse,
            'central_warehouses' => $centralWarehouses,
            'branch_warehouse' => $branchWarehouse,
            'transfers' => $transfers,
        ]);
    }

    /**
     * Step 1: Outlet requests stock transfer from Central Warehouse.
     */
    public function requestTransfer(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();
        $destWarehouse = $this->context->requireWarehouse();

        $validated = $request->validate([
            'source_warehouse_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.requested_qty' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $sourceWarehouse = isset($validated['source_warehouse_id'])
            ? Warehouse::query()->withoutGlobalScopes()
                ->where('organization_id', $user->organization_id)
                ->findOrFail((int) $validated['source_warehouse_id'])
            : Warehouse::query()->withoutGlobalScopes()
                ->where('organization_id', $user->organization_id)
                ->where('type', 'central')
                ->where('is_active', true)
                ->firstOrFail();

        if ($sourceWarehouse->id === $destWarehouse->id) {
            throw new BusinessRuleException('INVALID_TRANSFER_WAREHOUSE', 'Source and destination warehouse cannot be the same.', 422);
        }

        return DB::transaction(function () use ($user, $outlet, $sourceWarehouse, $destWarehouse, $validated): JsonResponse {
            $transferNumber = sprintf('TRF-%s-%s-%04d', $outlet->code, now()->format('Ymd'), random_int(1, 9999));

            $transfer = StockTransfer::query()->withoutGlobalScopes()->create([
                'organization_id' => $user->organization_id,
                'transfer_number' => $transferNumber,
                'source_warehouse_id' => $sourceWarehouse->id,
                'destination_warehouse_id' => $destWarehouse->id,
                'source_outlet_id' => $sourceWarehouse->outlet_id,
                'destination_outlet_id' => $outlet->id,
                'status' => 'requested',
                'requested_by' => $user->id,
                'requested_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $row) {
                $variant = ProductVariant::query()->withoutGlobalScopes()
                    ->with('product')
                    ->findOrFail((int) $row['product_variant_id']);

                $qty = Quantity::normalize($row['requested_qty']);

                StockTransferItem::query()->create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'requested_qty' => $qty,
                    'approved_qty' => $qty,
                    'shipped_qty' => '0.000',
                    'received_qty' => '0.000',
                    'damaged_qty' => '0.000',
                    'discrepancy_qty' => '0.000',
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            $this->audit->record(
                AuditAction::STOCK_TRANSFER_REQUESTED,
                $transfer,
                newValues: [
                    'transfer_number' => $transfer->transfer_number,
                    'source_warehouse' => $sourceWarehouse->code,
                    'destination_warehouse' => $destWarehouse->code,
                    'destination_outlet_id' => $outlet->id,
                ],
                actor: $user,
            );

            return response()->json([
                'transfer' => $transfer->load(['sourceWarehouse', 'destinationWarehouse', 'destinationOutlet', 'items']),
            ], 201);
        });
    }

    /**
     * Step 2 & 3: Central Warehouse approves, picks, and ships the stock transfer.
     * Deducts stock from source warehouse and places it in in_transit_quantity on destination warehouse.
     */
    public function approveAndShip(Request $request, int $transferId): JsonResponse
    {
        $user = $this->context->requireUser();

        $transfer = StockTransfer::query()->withoutGlobalScopes()
            ->with(['items.variant.product', 'sourceWarehouse', 'destinationWarehouse'])
            ->where('organization_id', $user->organization_id)
            ->findOrFail($transferId);

        if (! in_array($transfer->status, ['requested', 'approved'], true)) {
            throw new BusinessRuleException('INVALID_TRANSFER_STATUS', 'Only requested or approved transfers can be shipped.', 422);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required_with:items', 'integer'],
            'items.*.shipped_qty' => ['required_with:items', 'numeric', 'min:0'],
        ]);

        $shippedOverrides = collect($validated['items'] ?? [])->keyBy('id');

        return DB::transaction(function () use ($user, $transfer, $shippedOverrides, $validated): JsonResponse {
            foreach ($transfer->items as $item) {
                $override = $shippedOverrides->get($item->id);
                $shippedQty = $override !== null
                    ? Quantity::normalize($override['shipped_qty'])
                    : Quantity::normalize($item->requested_qty);

                $sourceInv = WarehouseInventory::query()->withoutGlobalScopes()
                    ->where('warehouse_id', $transfer->source_warehouse_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                $available = $sourceInv ? Quantity::normalize($sourceInv->quantity) : '0.000';
                $afterSource = Quantity::sub($available, $shippedQty);

                if (Quantity::isNegative($afterSource)) {
                    throw new BusinessRuleException(
                        'INSUFFICIENT_CENTRAL_STOCK',
                        sprintf(
                            'Insufficient stock in %s for SKU %s (available: %s, requested ship: %s).',
                            $transfer->sourceWarehouse->name,
                            $item->sku,
                            $available,
                            $shippedQty,
                        ),
                        422,
                    );
                }

                $sourceInv->update([
                    'quantity' => $afterSource,
                ]);

                // Record TransferOut ledger movement on Source Warehouse (Central or Branch)
                StockMovement::query()->withoutGlobalScopes()->create([
                    'organization_id' => $user->organization_id,
                    'outlet_id' => $transfer->source_outlet_id,
                    'warehouse_id' => $transfer->source_warehouse_id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'batch_id' => $item->batch_id,
                    'type' => StockMovementType::TransferOut,
                    'uom_code' => $item->uom_code ?? $item->variant->product->unit ?? 'PCS',
                    'uom_quantity' => $shippedQty,
                    'conversion_factor' => $item->conversion_factor ?? '1.000',
                    'quantity_change' => Quantity::negate($shippedQty),
                    'quantity_before' => $available,
                    'quantity_after' => $afterSource,
                    'unit_cost' => $item->variant->average_cost ?? $item->variant->cost_price ?? '0.00',
                    'reference_type' => 'StockTransfer',
                    'reference_id' => $transfer->id,
                    'user_id' => $user->id,
                    'notes' => sprintf('Transfer Ship %s to %s', $transfer->transfer_number, $transfer->destinationWarehouse->code),
                ]);

                // If source warehouse belongs to an outlet, sync its OutletInventory as well
                if ($transfer->source_outlet_id !== null) {
                    $srcOutletInv = OutletInventory::query()->withoutGlobalScopes()
                        ->where('outlet_id', $transfer->source_outlet_id)
                        ->where('product_variant_id', $item->product_variant_id)
                        ->lockForUpdate()
                        ->first();
                    if ($srcOutletInv !== null) {
                        $srcOutletInv->update([
                            'quantity' => $afterSource,
                            'version' => $srcOutletInv->version + 1,
                        ]);
                    }
                }

                // Increment in-transit on destination warehouse
                $destInv = WarehouseInventory::query()->withoutGlobalScopes()
                    ->where('warehouse_id', $transfer->destination_warehouse_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($destInv === null) {
                    WarehouseInventory::query()->withoutGlobalScopes()->create([
                        'organization_id' => $user->organization_id,
                        'warehouse_id' => $transfer->destination_warehouse_id,
                        'outlet_id' => $transfer->destination_outlet_id,
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'quantity' => '0.000',
                        'reserved_quantity' => '0.000',
                        'in_transit_quantity' => $shippedQty,
                        'reorder_level' => '15.000',
                    ]);
                } else {
                    $destInv->update([
                        'in_transit_quantity' => Quantity::add($destInv->in_transit_quantity, $shippedQty),
                    ]);
                }

                $item->update([
                    'approved_qty' => $shippedQty,
                    'shipped_qty' => $shippedQty,
                ]);
            }

            $transfer->update([
                'status' => 'shipped',
                'approved_by' => $user->id,
                'shipped_by' => $user->id,
                'approved_at' => now(),
                'shipped_at' => now(),
                'notes' => $validated['notes'] ?? $transfer->notes,
            ]);

            $this->audit->record(
                AuditAction::STOCK_TRANSFER_SHIPPED,
                $transfer,
                newValues: [
                    'transfer_number' => $transfer->transfer_number,
                    'status' => 'shipped',
                    'source_warehouse_id' => $transfer->source_warehouse_id,
                    'destination_warehouse_id' => $transfer->destination_warehouse_id,
                ],
                actor: $user,
            );

            return response()->json([
                'transfer' => $transfer->fresh(['sourceWarehouse', 'destinationWarehouse', 'destinationOutlet', 'items']),
            ]);
        });
    }

    /**
     * Step 4: Destination Branch Warehouse / Outlet receives the shipped transfer.
     * Supports partial receiving & discrepancy tracking, and increments Branch Warehouse + Outlet inventory.
     */
    public function receiveTransfer(Request $request, int $transferId): JsonResponse
    {
        $user = $this->context->requireUser();

        $transfer = StockTransfer::query()->withoutGlobalScopes()
            ->with(['items.variant.product', 'sourceWarehouse', 'destinationWarehouse', 'destinationOutlet'])
            ->where('organization_id', $user->organization_id)
            ->findOrFail($transferId);

        if ($transfer->status !== 'shipped') {
            throw new BusinessRuleException('INVALID_TRANSFER_STATUS', 'Only shipped transfers can be received.', 422);
        }

        if ($transfer->destination_outlet_id !== null && ! $user->canAccessOutlet((int) $transfer->destination_outlet_id)) {
            throw new BusinessRuleException('OUTLET_ACCESS_DENIED', 'You cannot receive stock for an outlet you do not have access to.', 403);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required_with:items', 'integer'],
            'items.*.received_qty' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.damaged_qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $receivedOverrides = collect($validated['items'] ?? [])->keyBy('id');

        return DB::transaction(function () use ($user, $transfer, $receivedOverrides, $validated): JsonResponse {
            $hasDiscrepancy = false;

            foreach ($transfer->items as $item) {
                $override = $receivedOverrides->get($item->id);
                $shippedQty = Quantity::normalize($item->shipped_qty);
                $receivedQty = $override !== null
                    ? Quantity::normalize($override['received_qty'])
                    : $shippedQty;
                $damagedQty = $override !== null && isset($override['damaged_qty'])
                    ? Quantity::normalize($override['damaged_qty'])
                    : '0.000';

                $discrepancyQty = Quantity::sub($shippedQty, Quantity::add($receivedQty, $damagedQty));
                if (Quantity::compare($receivedQty, $shippedQty) < 0) {
                    $hasDiscrepancy = true;
                }

                // Update Destination WarehouseInventory
                $destWhInv = WarehouseInventory::query()->withoutGlobalScopes()
                    ->where('warehouse_id', $transfer->destination_warehouse_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                $beforeWhQty = $destWhInv ? Quantity::normalize($destWhInv->quantity) : '0.000';
                $afterWhQty = Quantity::add($beforeWhQty, $receivedQty);
                $inTransitBefore = $destWhInv ? Quantity::normalize($destWhInv->in_transit_quantity) : '0.000';
                $inTransitAfter = max(0.0, (float) Quantity::sub($inTransitBefore, $shippedQty));

                if ($destWhInv === null) {
                    WarehouseInventory::query()->withoutGlobalScopes()->create([
                        'organization_id' => $user->organization_id,
                        'warehouse_id' => $transfer->destination_warehouse_id,
                        'outlet_id' => $transfer->destination_outlet_id,
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'quantity' => $afterWhQty,
                        'reserved_quantity' => '0.000',
                        'in_transit_quantity' => '0.000',
                        'damaged_quantity' => $damagedQty,
                        'quarantine_quantity' => '0.000',
                        'reorder_level' => '15.000',
                    ]);
                } else {
                    $destWhInv->update([
                        'quantity' => $afterWhQty,
                        'in_transit_quantity' => number_format($inTransitAfter, 3, '.', ''),
                        'damaged_quantity' => Quantity::add($destWhInv->damaged_quantity ?? '0.000', $damagedQty),
                    ]);
                }

                // Sync Destination OutletInventory & StockMovement ledger
                if ($transfer->destination_outlet_id !== null) {
                    $outletInv = OutletInventory::query()->withoutGlobalScopes()
                        ->where('outlet_id', $transfer->destination_outlet_id)
                        ->where('product_variant_id', $item->product_variant_id)
                        ->lockForUpdate()
                        ->first();

                    $beforeOutletQty = $outletInv ? Quantity::normalize($outletInv->quantity) : '0.000';
                    $afterOutletQty = Quantity::add($beforeOutletQty, $receivedQty);

                    if ($outletInv === null) {
                        OutletInventory::query()->withoutGlobalScopes()->create([
                            'outlet_id' => $transfer->destination_outlet_id,
                            'warehouse_id' => $transfer->destination_warehouse_id,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'quantity' => $afterOutletQty,
                            'reorder_level' => '15.000',
                            'version' => 1,
                        ]);
                    } else {
                        $outletInv->update([
                            'warehouse_id' => $transfer->destination_warehouse_id,
                            'quantity' => $afterOutletQty,
                            'version' => $outletInv->version + 1,
                        ]);
                    }

                    StockMovement::query()->withoutGlobalScopes()->create([
                        'organization_id' => $user->organization_id,
                        'outlet_id' => $transfer->destination_outlet_id,
                        'warehouse_id' => $transfer->destination_warehouse_id,
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'batch_id' => $item->batch_id,
                        'type' => StockMovementType::TransferIn,
                        'uom_code' => $item->uom_code ?? $item->variant->product->unit ?? 'PCS',
                        'uom_quantity' => $receivedQty,
                        'conversion_factor' => $item->conversion_factor ?? '1.000',
                        'quantity_change' => $receivedQty,
                        'quantity_before' => $beforeOutletQty,
                        'quantity_after' => $afterOutletQty,
                        'unit_cost' => $item->variant->average_cost ?? $item->variant->cost_price ?? '0.00',
                        'reference_type' => 'StockTransfer',
                        'reference_id' => $transfer->id,
                        'user_id' => $user->id,
                        'notes' => sprintf('Transfer Receive %s from %s', $transfer->transfer_number, $transfer->sourceWarehouse->code),
                    ]);
                }

                $item->update([
                    'received_qty' => $receivedQty,
                    'damaged_qty' => $damagedQty,
                    'discrepancy_qty' => $discrepancyQty,
                    'notes' => $override['notes'] ?? $item->notes,
                ]);
            }

            $finalStatus = $hasDiscrepancy ? 'partially_received' : 'received';

            $transfer->update([
                'status' => $finalStatus,
                'received_by' => $user->id,
                'received_at' => now(),
                'notes' => $validated['notes'] ?? $transfer->notes,
            ]);

            $this->audit->record(
                AuditAction::STOCK_TRANSFER_RECEIVED,
                $transfer,
                newValues: [
                    'transfer_number' => $transfer->transfer_number,
                    'status' => $finalStatus,
                    'destination_outlet_id' => $transfer->destination_outlet_id,
                    'destination_warehouse_id' => $transfer->destination_warehouse_id,
                ],
                actor: $user,
                overrides: ['outlet_id' => $transfer->destination_outlet_id],
            );

            return response()->json([
                'transfer' => $transfer->fresh(['sourceWarehouse', 'destinationWarehouse', 'destinationOutlet', 'items']),
            ]);
        });
    }
}

