<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\StockAdjustmentType;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\OutletInventory;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\Audit\AuditLogger;
use App\Services\Outbox\OutboxService;
use App\Support\PosContext;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Operational Inventory Control:
 *  - Atomic Stock Deduction on order completion (SELECT ... FOR UPDATE in deterministic variant order)
 *  - Atomic Stock Restoration on void / restocked refund
 *  - Manual Stock Adjustment (damaged / lost / expired / correction) with Supervisor PIN
 *  - Stock Opname reconciliation with Supervisor PIN
 */
final class InventoryService
{
    public function __construct(
        private readonly PosContext $context,
        private readonly SequenceService $sequences,
        private readonly SupervisorAuthService $supervisorAuth,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
        private readonly InventoryEngineService $engine,
    ) {}

    /**
     * Deduct inventory for all stock-tracked items in a completed order.
     * Supports Multi-UOM conversion (e.g. 1 PACK = 6 PCS) and Batch FEFO allocation.
     * MUST be called inside an active DB::transaction().
     */
    public function deductForOrder(Order $order, Outlet $outlet): void
    {
        $order->loadMissing(['items.product', 'items.variant']);

        $branchWarehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('outlet_id', $outlet->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->first();

        // Aggregate required base quantities per variant ID in ascending ID order to prevent deadlocks
        /** @var array<int, array{variant: ProductVariant, qty: string, uom_code: string, uom_qty: string, factor: string, unit_cost: string, batch_id: int|null}> $byVariant */
        $byVariant = [];

        foreach ($order->items as $item) {
            if (! $item->product->track_stock || $item->product->product_type === 'non_inventory') {
                continue;
            }

            $factor = Quantity::normalize($item->conversion_factor ?? '1.000');
            if (Quantity::compare($factor, '0.000') <= 0) {
                $factor = '1.000';
            }

            $baseItemQty = Quantity::mul($item->quantity, $factor);
            $baseUnitCost = bcdiv((string) ($item->unit_cost ?? '0.00'), $factor, 2);

            $vid = $item->product_variant_id;
            if (! isset($byVariant[$vid])) {
                $byVariant[$vid] = [
                    'variant' => $item->variant,
                    'qty' => '0.000',
                    'uom_code' => (string) ($item->uom_code ?? $item->product->unit ?? 'PCS'),
                    'uom_qty' => '0.000',
                    'factor' => $factor,
                    'unit_cost' => $baseUnitCost,
                    'batch_id' => $item->batch_id ? (int) $item->batch_id : null,
                ];
            }
            $byVariant[$vid]['qty'] = Quantity::add($byVariant[$vid]['qty'], $baseItemQty);
            $byVariant[$vid]['uom_qty'] = Quantity::add($byVariant[$vid]['uom_qty'], $item->quantity);
        }

        if ($byVariant === []) {
            return;
        }

        ksort($byVariant);

        foreach ($byVariant as $variantId => $entry) {
            $variant = $entry['variant'];
            $deductQty = $entry['qty'];
            $delta = Quantity::negate($deductQty);

            $inventory = $this->lockOrCreateInventory($outlet->id, $variant->product_id, $variantId);
            $before = Quantity::normalize($inventory->quantity);
            $after = Quantity::add($before, $delta);

            if (! $outlet->allow_negative_stock && Quantity::isNegative($after)) {
                throw new BusinessRuleException(
                    'INSUFFICIENT_STOCK',
                    sprintf(
                        'Insufficient stock for "%s - %s" (available: %s, requested: %s).',
                        $variant->product->name,
                        $variant->name,
                        $before,
                        $deductQty,
                    ),
                    422,
                    [
                        'product_variant_id' => $variantId,
                        'sku' => $variant->sku,
                        'available' => $before,
                        'requested' => $deductQty,
                    ],
                );
            }

            // FEFO Batch allocation if warehouse exists
            $allocatedBatchId = $entry['batch_id'];
            if ($branchWarehouse !== null && ($allocatedBatchId !== null || $variant->product->track_batch || $variant->product->track_expiry)) {
                $batch = $this->engine->allocateBatchFefo(
                    organizationId: (int) $outlet->organization_id,
                    warehouseId: $branchWarehouse->id,
                    variant: $variant,
                    requiredBaseQty: $deductQty,
                    preferredBatchId: $allocatedBatchId,
                    deductFromBatch: true,
                );
                if ($batch !== null) {
                    $allocatedBatchId = $batch->id;
                    $order->items()
                        ->where('product_variant_id', $variantId)
                        ->whereNull('batch_id')
                        ->update(['batch_id' => $batch->id]);
                }
            }

            $this->applyInventoryDelta(
                inventory: $inventory,
                before: $before,
                delta: $delta,
                after: $after,
                movementType: StockMovementType::Sale,
                referenceType: 'Order',
                referenceId: $order->id,
                userId: $order->user_id,
                notes: sprintf('Sale %s', $order->order_number),
                batchId: $allocatedBatchId,
                uomCode: $entry['uom_code'],
                uomQuantity: $entry['uom_qty'],
                conversionFactor: $entry['factor'],
                unitCost: $entry['unit_cost'],
            );
        }
    }

    /**
     * Restore stock when a completed order is voided.
     * MUST be called inside an active DB::transaction().
     */
    public function restoreForVoid(Order $order): void
    {
        $order->loadMissing(['items.product', 'items.variant']);

        /** @var array<int, array{variant: ProductVariant, qty: string}> $byVariant */
        $byVariant = [];

        foreach ($order->items as $item) {
            if (! $item->product->track_stock || $item->product->product_type === 'non_inventory') {
                continue;
            }

            $netSold = Quantity::sub($item->quantity, $item->refunded_quantity);
            if (Quantity::compare($netSold, '0.000') <= 0) {
                continue;
            }

            $factor = Quantity::normalize($item->conversion_factor ?? '1.000');
            if (Quantity::compare($factor, '0.000') <= 0) {
                $factor = '1.000';
            }
            $baseNetSold = Quantity::mul($netSold, $factor);

            $vid = $item->product_variant_id;
            if (! isset($byVariant[$vid])) {
                $byVariant[$vid] = ['variant' => $item->variant, 'qty' => '0.000'];
            }
            $byVariant[$vid]['qty'] = Quantity::add($byVariant[$vid]['qty'], $baseNetSold);
        }

        ksort($byVariant);

        foreach ($byVariant as $variantId => $entry) {
            $variant = $entry['variant'];
            $delta = $entry['qty'];
            $inventory = $this->lockOrCreateInventory($order->outlet_id, $variant->product_id, $variantId);
            $before = Quantity::normalize($inventory->quantity);
            $after = Quantity::add($before, $delta);

            $this->applyInventoryDelta(
                inventory: $inventory,
                before: $before,
                delta: $delta,
                after: $after,
                movementType: StockMovementType::Void,
                referenceType: 'Order',
                referenceId: $order->id,
                userId: $order->voided_by,
                notes: sprintf('Void %s', $order->order_number),
                uomCode: strtoupper((string) ($variant->product->unit ?: 'PCS')),
                uomQuantity: $delta,
                conversionFactor: '1.000',
                unitCost: (string) ($variant->average_cost ?? $variant->cost_price ?? '0.00'),
            );
        }
    }

    /**
     * Restore stock for refunded items when `restock = true`.
     * MUST be called inside an active DB::transaction().
     */
    public function restoreForRefund(Refund $refund): void
    {
        if (! $refund->restock) {
            return;
        }

        $refund->loadMissing(['items.orderItem.product', 'items.orderItem.variant']);

        foreach ($refund->items as $refundItem) {
            $orderItem = $refundItem->orderItem;
            if (! $orderItem->product->track_stock || $orderItem->product->product_type === 'non_inventory') {
                continue;
            }

            $factor = Quantity::normalize($orderItem->conversion_factor ?? '1.000');
            if (Quantity::compare($factor, '0.000') <= 0) {
                $factor = '1.000';
            }

            $delta = Quantity::mul(Quantity::normalize($refundItem->quantity), $factor);
            $inventory = $this->lockOrCreateInventory(
                $refund->outlet_id,
                $orderItem->product_id,
                $refundItem->product_variant_id,
            );
            $before = Quantity::normalize($inventory->quantity);
            $after = Quantity::add($before, $delta);

            $this->applyInventoryDelta(
                inventory: $inventory,
                before: $before,
                delta: $delta,
                after: $after,
                movementType: StockMovementType::Refund,
                referenceType: 'Refund',
                referenceId: $refund->id,
                userId: $refund->user_id,
                notes: sprintf('Refund %s', $refund->refund_number),
                uomCode: strtoupper((string) ($orderItem->product->unit ?: 'PCS')),
                uomQuantity: $delta,
                conversionFactor: '1.000',
                unitCost: (string) ($orderItem->unit_cost ?? '0.00'),
            );
        }
    }

    /**
     * Manual Stock Adjustment (Damaged / Lost / Expired / Correction) requiring Supervisor PIN.
     *
     * @param  list<array{product_variant_id: int, quantity: string|int|float, notes?: string|null}>  $items
     */
    public function createManualAdjustment(
        StockAdjustmentType $type,
        array $items,
        string $reason,
        ?string $notes,
        string $supervisorPin,
    ): StockAdjustment {
        if ($type === StockAdjustmentType::Opname) {
            throw new BusinessRuleException('INVALID_ADJUSTMENT_TYPE', 'Use the opname endpoint for stock opname.', 422);
        }

        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();
        $terminalId = $this->context->terminalId();

        $supervisor = $this->supervisorAuth->authorizeByPin(
            $supervisorPin,
            sprintf('Manual stock adjustment (%s): %s', $type->value, $reason),
            $outlet->id,
            Permission::InventoryAdjust,
        );

        return DB::transaction(function () use ($type, $items, $reason, $notes, $user, $outlet, $terminalId, $supervisor): StockAdjustment {
            $adjustmentNumber = $this->sequences->next($outlet, 'ADJ');
            $now = CarbonImmutable::now();

            $adjustment = StockAdjustment::query()->create([
                'outlet_id' => $outlet->id,
                'pos_terminal_id' => $terminalId,
                'adjustment_number' => $adjustmentNumber,
                'type' => $type,
                'status' => 'completed',
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $user->id,
                'authorized_by' => $supervisor->id,
                'authorized_at' => $now,
                'completed_at' => $now,
            ]);

            $oldSnapshots = [];
            $newSnapshots = [];

            // Sort items by variant ID to acquire locks in deterministic order
            usort($items, static fn (array $a, array $b): int => ((int) $a['product_variant_id']) <=> ((int) $b['product_variant_id']));

            foreach ($items as $line) {
                $variant = ProductVariant::query()->with('product')->findOrFail((int) $line['product_variant_id']);
                $rawQty = Quantity::normalize($line['quantity']);

                if (Quantity::isZero($rawQty)) {
                    throw new BusinessRuleException('ZERO_ADJUSTMENT_QUANTITY', 'Adjustment quantity cannot be zero.', 422);
                }

                // For shrinkage types (damaged/lost/expired), always deduct the positive magnitude
                if ($type->isShrinkage()) {
                    $absQty = ltrim($rawQty, '-');
                    $delta = Quantity::negate($absQty);
                } else {
                    $delta = $rawQty;
                }

                $inventory = $this->lockOrCreateInventory($outlet->id, $variant->product_id, $variant->id);
                $before = Quantity::normalize($inventory->quantity);
                $after = Quantity::add($before, $delta);

                if (! $outlet->allow_negative_stock && Quantity::isNegative($after)) {
                    throw new BusinessRuleException(
                        'INSUFFICIENT_STOCK',
                        sprintf(
                            'Adjustment would result in negative stock for "%s - %s" (current: %s, change: %s).',
                            $variant->product->name,
                            $variant->name,
                            $before,
                            $delta,
                        ),
                        422,
                    );
                }

                StockAdjustmentItem::query()->create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_variant_id' => $variant->id,
                    'system_quantity' => $before,
                    'counted_quantity' => null,
                    'quantity_change' => $delta,
                    'notes' => $line['notes'] ?? null,
                ]);

                $itemBatchId = isset($line['batch_id']) ? (int) $line['batch_id'] : null;
                $itemUomCode = isset($line['uom_code']) && trim((string) $line['uom_code']) !== ''
                    ? strtoupper(trim((string) $line['uom_code']))
                    : strtoupper((string) ($variant->product->unit ?: 'PCS'));

                // If adjustment is tied to a specific batch, adjust batch stock as well
                if ($itemBatchId !== null) {
                    $batch = InventoryBatch::query()->lockForUpdate()->find($itemBatchId);
                    if ($batch !== null) {
                        $batch->update([
                            'quantity' => Quantity::maxZero(Quantity::add($batch->quantity, $delta)),
                        ]);
                    }
                }

                $this->applyInventoryDelta(
                    inventory: $inventory,
                    before: $before,
                    delta: $delta,
                    after: $after,
                    movementType: StockMovementType::Adjustment,
                    referenceType: 'StockAdjustment',
                    referenceId: $adjustment->id,
                    userId: $user->id,
                    notes: sprintf('%s (%s): %s', $adjustmentNumber, $type->value, $reason),
                    batchId: $itemBatchId,
                    uomCode: $itemUomCode,
                    uomQuantity: ltrim($delta, '-'),
                    conversionFactor: '1.000',
                    unitCost: (string) ($variant->average_cost ?? $variant->cost_price ?? '0.00'),
                );

                $oldSnapshots[] = [
                    'product_variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->product->name.' - '.$variant->name,
                    'quantity' => $before,
                ];
                $newSnapshots[] = [
                    'product_variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->product->name.' - '.$variant->name,
                    'quantity_change' => $delta,
                    'quantity' => $after,
                ];
            }

            $this->audit->record(
                AuditAction::STOCK_ADJUSTED,
                $adjustment,
                oldValues: ['items' => $oldSnapshots],
                newValues: [
                    'adjustment_number' => $adjustment->adjustment_number,
                    'type' => $type->value,
                    'reason' => $reason,
                    'authorized_by_id' => $supervisor->id,
                    'authorized_by_name' => $supervisor->name,
                    'items' => $newSnapshots,
                ],
            );

            $this->outbox->record('stock.adjusted', $adjustment, $adjustment->uuid, [
                'adjustment_number' => $adjustment->adjustment_number,
                'type' => $type->value,
                'reason' => $reason,
                'authorized_by_id' => $supervisor->id,
                'authorized_by_name' => $supervisor->name,
                'items' => $newSnapshots,
            ]);

            return $adjustment->load(['items.variant.product', 'creator', 'authorizer']);
        });
    }

    /**
     * Stock Opname physical count reconciliation requiring Supervisor PIN.
     *
     * @param  list<array{product_variant_id: int, counted_quantity: string|int|float, notes?: string|null}>  $items
     */
    public function completeStockOpname(
        array $items,
        ?string $notes,
        string $supervisorPin,
    ): StockAdjustment {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();
        $terminalId = $this->context->terminalId();

        $supervisor = $this->supervisorAuth->authorizeByPin(
            $supervisorPin,
            'Stock opname reconciliation',
            $outlet->id,
            Permission::InventoryOpname,
        );

        return DB::transaction(function () use ($items, $notes, $user, $outlet, $terminalId, $supervisor): StockAdjustment {
            $adjustmentNumber = $this->sequences->next($outlet, 'OPN');
            $now = CarbonImmutable::now();

            $adjustment = StockAdjustment::query()->create([
                'outlet_id' => $outlet->id,
                'pos_terminal_id' => $terminalId,
                'adjustment_number' => $adjustmentNumber,
                'type' => StockAdjustmentType::Opname,
                'status' => 'completed',
                'reason' => 'Stock Opname Reconciliation',
                'notes' => $notes,
                'created_by' => $user->id,
                'authorized_by' => $supervisor->id,
                'authorized_at' => $now,
                'completed_at' => $now,
            ]);

            $oldSnapshots = [];
            $newSnapshots = [];

            usort($items, static fn (array $a, array $b): int => ((int) $a['product_variant_id']) <=> ((int) $b['product_variant_id']));

            foreach ($items as $line) {
                $variant = ProductVariant::query()->with('product')->findOrFail((int) $line['product_variant_id']);
                $counted = Quantity::normalize($line['counted_quantity']);

                if (Quantity::isNegative($counted)) {
                    throw new BusinessRuleException('NEGATIVE_OPNAME_COUNT', 'Counted quantity cannot be negative.', 422);
                }

                $inventory = $this->lockOrCreateInventory($outlet->id, $variant->product_id, $variant->id);
                $before = Quantity::normalize($inventory->quantity);
                $delta = Quantity::sub($counted, $before);

                StockAdjustmentItem::query()->create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_variant_id' => $variant->id,
                    'system_quantity' => $before,
                    'counted_quantity' => $counted,
                    'quantity_change' => $delta,
                    'notes' => $line['notes'] ?? null,
                ]);

                $inventory->update([
                    'quantity' => $counted,
                    'version' => $inventory->version + 1,
                    'last_counted_at' => $now,
                ]);

                if (! Quantity::isZero($delta)) {
                    $branchWh = Warehouse::query()->withoutGlobalScopes()
                        ->where('outlet_id', $outlet->id)
                        ->where('is_active', true)
                        ->orderByDesc('is_default')
                        ->first();

                    StockMovement::query()->withoutGlobalScopes()->create([
                        'organization_id' => $branchWh?->organization_id ?? $outlet->organization_id,
                        'outlet_id' => $outlet->id,
                        'warehouse_id' => $branchWh?->id,
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'type' => StockMovementType::Opname,
                        'uom_code' => $variant->product->unit ? strtoupper((string) $variant->product->unit) : 'PCS',
                        'uom_quantity' => ltrim($delta, '-'),
                        'conversion_factor' => '1.000',
                        'quantity_change' => $delta,
                        'quantity_before' => $before,
                        'quantity_after' => $counted,
                        'unit_cost' => (string) ($variant->average_cost ?? $variant->cost_price ?? '0.00'),
                        'reference_type' => 'StockAdjustment',
                        'reference_id' => $adjustment->id,
                        'user_id' => $user->id,
                        'notes' => sprintf('Opname %s', $adjustmentNumber),
                    ]);

                    $this->outbox->record('stock.changed', $inventory, $inventory->id, [
                        'outlet_id' => $outlet->id,
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'sku' => $variant->sku,
                        'movement_type' => StockMovementType::Opname->value,
                        'quantity_before' => $before,
                        'quantity_change' => $delta,
                        'quantity_after' => $counted,
                        'reference_type' => 'StockAdjustment',
                        'reference_id' => $adjustment->id,
                    ], $outlet->id);
                }

                $oldSnapshots[] = [
                    'product_variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->product->name.' - '.$variant->name,
                    'system_quantity' => $before,
                ];
                $newSnapshots[] = [
                    'product_variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->product->name.' - '.$variant->name,
                    'system_quantity' => $before,
                    'counted_quantity' => $counted,
                    'variance' => $delta,
                ];
            }

            $this->audit->record(
                AuditAction::STOCK_OPNAME_COMPLETED,
                $adjustment,
                oldValues: ['items' => $oldSnapshots],
                newValues: [
                    'adjustment_number' => $adjustment->adjustment_number,
                    'authorized_by_id' => $supervisor->id,
                    'authorized_by_name' => $supervisor->name,
                    'notes' => $notes,
                    'items' => $newSnapshots,
                ],
            );

            $this->outbox->record('stock.opname_completed', $adjustment, $adjustment->uuid, [
                'adjustment_number' => $adjustment->adjustment_number,
                'authorized_by_id' => $supervisor->id,
                'authorized_by_name' => $supervisor->name,
                'items' => $newSnapshots,
            ]);

            return $adjustment->load(['items.variant.product', 'creator', 'authorizer']);
        });
    }

    private function lockOrCreateInventory(int $outletId, int $productId, int $variantId): OutletInventory
    {
        $inventory = OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $outletId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        if ($inventory !== null) {
            return $inventory;
        }

        OutletInventory::query()->withoutGlobalScopes()->create([
            'outlet_id' => $outletId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'quantity' => '0.000',
            'reorder_level' => '5.000',
            'version' => 0,
        ]);

        return OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $outletId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function applyInventoryDelta(
        OutletInventory $inventory,
        string $before,
        string $delta,
        string $after,
        StockMovementType $movementType,
        string $referenceType,
        int $referenceId,
        ?int $userId,
        string $notes,
        ?int $batchId = null,
        ?string $uomCode = null,
        ?string $uomQuantity = null,
        string $conversionFactor = '1.000',
        string $unitCost = '0.00',
    ): void {
        $branchWarehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('outlet_id', $inventory->outlet_id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->first();

        $inventory->update([
            'warehouse_id' => $branchWarehouse?->id ?? $inventory->warehouse_id,
            'quantity' => $after,
            'version' => $inventory->version + 1,
        ]);

        if ($branchWarehouse !== null) {
            WarehouseInventory::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'warehouse_id' => $branchWarehouse->id,
                    'product_variant_id' => $inventory->product_variant_id,
                ],
                [
                    'organization_id' => $branchWarehouse->organization_id,
                    'outlet_id' => $inventory->outlet_id,
                    'product_id' => $inventory->product_id,
                    'quantity' => $after,
                    'reorder_level' => $inventory->reorder_level,
                ],
            );
        }

        $inventory->loadMissing('product');
        $resolvedUom = $uomCode !== null && trim($uomCode) !== ''
            ? strtoupper(trim($uomCode))
            : strtoupper((string) ($inventory->product?->unit ?: 'PCS'));

        StockMovement::query()->withoutGlobalScopes()->create([
            'organization_id' => $branchWarehouse?->organization_id,
            'outlet_id' => $inventory->outlet_id,
            'warehouse_id' => $branchWarehouse?->id,
            'product_id' => $inventory->product_id,
            'product_variant_id' => $inventory->product_variant_id,
            'batch_id' => $batchId,
            'type' => $movementType,
            'uom_code' => $resolvedUom,
            'uom_quantity' => $uomQuantity ?? ltrim($delta, '-'),
            'conversion_factor' => $conversionFactor,
            'quantity_change' => $delta,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'unit_cost' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'user_id' => $userId,
            'notes' => $notes,
        ]);

        $this->outbox->record('stock.changed', $inventory, $inventory->id, [
            'outlet_id' => $inventory->outlet_id,
            'product_id' => $inventory->product_id,
            'product_variant_id' => $inventory->product_variant_id,
            'movement_type' => $movementType->value,
            'quantity_before' => $before,
            'quantity_change' => $delta,
            'quantity_after' => $after,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ], $inventory->outlet_id);
    }
}

