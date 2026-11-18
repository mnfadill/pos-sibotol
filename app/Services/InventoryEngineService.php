<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\InventoryBatch;
use App\Models\OutletInventory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Support\Quantity;
use Carbon\CarbonImmutable;

/**
 * Enterprise Inventory & Stock Ledger Engine for SiBotol Sub-Distributor POS:
 * - Supports Central Warehouse (outlet_id = null) and Branch Warehouses
 * - Tracks On Hand, Reserved, Available, In Transit, Damaged, and Quarantine stock
 * - Enforces Batch & Expiry FEFO (First Expired, First Out)
 * - Manages Stock Reservations for B2B Sales Orders without double-deducting on fulfillment
 */
final class InventoryEngineService
{
    /**
     * Allocate or validate a batch using FEFO (First Expired, First Out) rules.
     */
    public function allocateBatchFefo(
        int $organizationId,
        int $warehouseId,
        ProductVariant $variant,
        string $requiredBaseQty,
        ?int $preferredBatchId = null,
        bool $deductFromBatch = true,
    ): ?InventoryBatch {
        $variant->loadMissing('product');
        $today = CarbonImmutable::today()->toDateString();

        if ($preferredBatchId !== null && $preferredBatchId > 0) {
            $batch = InventoryBatch::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->find($preferredBatchId);

            if ($batch === null) {
                throw new BusinessRuleException('BATCH_NOT_FOUND', 'Selected inventory batch was not found in the active warehouse.', 422);
            }

            if ($batch->status !== 'available') {
                throw new BusinessRuleException(
                    'BATCH_UNAVAILABLE',
                    sprintf('Batch "%s" has status "%s" and cannot be sold or transferred.', $batch->batch_number, $batch->status),
                    422,
                );
            }

            if ($batch->expiry_date !== null && $batch->expiry_date->toDateString() < $today) {
                throw new BusinessRuleException(
                    'BATCH_EXPIRED',
                    sprintf('Batch "%s" expired on %s and cannot be sold.', $batch->batch_number, $batch->expiry_date->toDateString()),
                    422,
                );
            }

            if ($deductFromBatch) {
                $curQty = (string) ($batch->on_hand_quantity ?? '0.000');
                $afterBatchQty = Quantity::maxZero(Quantity::sub($curQty, $requiredBaseQty));
                $batch->update(['on_hand_quantity' => $afterBatchQty]);
            }

            return $batch;
        }

        // Automatic FEFO selection when product tracks batch or expiry (or if batches exist for this variant)
        $availableBatches = InventoryBatch::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variant->id)
            ->where('status', 'available')
            ->where('on_hand_quantity', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($availableBatches->isEmpty()) {
            return null;
        }

        $nonExpiredBatch = $availableBatches->first(static function (InventoryBatch $b) use ($today): bool {
            return $b->expiry_date === null || $b->expiry_date->toDateString() >= $today;
        });

        if ($nonExpiredBatch === null) {
            if ($variant->product->track_expiry) {
                throw new BusinessRuleException(
                    'BATCH_EXPIRED',
                    sprintf('All available stock batches for "%s - %s" have expired and cannot be sold.', $variant->product->name, $variant->name),
                    422,
                );
            }

            return null;
        }

        if ($deductFromBatch) {
            $curQty = (string) ($nonExpiredBatch->on_hand_quantity ?? '0.000');
            $afterBatchQty = Quantity::maxZero(Quantity::sub($curQty, $requiredBaseQty));
            $nonExpiredBatch->update(['on_hand_quantity' => $afterBatchQty]);
        }

        return $nonExpiredBatch;
    }

    /**
     * Create or receive into an InventoryBatch (e.g. during Goods Receipt or Stock Transfer Receive).
     */
    public function receiveIntoBatch(
        int $organizationId,
        int $warehouseId,
        ?int $outletId,
        ProductVariant $variant,
        string $batchNumber,
        string $acceptedBaseQty,
        string $damagedBaseQty = '0.000',
        ?string $lotNumber = null,
        ?string $manufacturingDate = null,
        ?string $expiryDate = null,
        string $unitCost = '0.00',
        ?int $supplierId = null,
        ?int $goodsReceiptId = null,
    ): InventoryBatch {
        $variant->loadMissing('product');
        $cleanBatchNo = strtoupper(trim($batchNumber));

        $batch = InventoryBatch::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variant->id)
            ->where('batch_number', $cleanBatchNo)
            ->lockForUpdate()
            ->first();

        $today = CarbonImmutable::today()->toDateString();
        $isExpired = $expiryDate !== null && $expiryDate !== '' && $expiryDate < $today;
        $status = $isExpired ? 'expired' : 'available';

        if ($batch === null) {
            return InventoryBatch::query()->create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'outlet_id' => $outletId,
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'batch_number' => $cleanBatchNo,
                'lot_number' => $lotNumber,
                'manufacturing_date' => $manufacturingDate ?: null,
                'expiry_date' => $expiryDate ?: null,
                'initial_quantity' => Quantity::normalize($acceptedBaseQty),
                'on_hand_quantity' => Quantity::normalize($acceptedBaseQty),
                'reserved_quantity' => '0.000',
                'damaged_quantity' => Quantity::normalize($damagedBaseQty),
                'quarantine_quantity' => '0.000',
                'unit_cost' => bcadd($unitCost, '0', 2),
                'status' => $status,
            ]);
        }

        $batch->update([
            'initial_quantity' => Quantity::add($batch->initial_quantity, $acceptedBaseQty),
            'on_hand_quantity' => Quantity::add((string) ($batch->on_hand_quantity ?? '0.000'), $acceptedBaseQty),
            'damaged_quantity' => Quantity::add($batch->damaged_quantity, $damagedBaseQty),
            'expiry_date' => $expiryDate ?: $batch->expiry_date?->toDateString(),
            'manufacturing_date' => $manufacturingDate ?: $batch->manufacturing_date?->toDateString(),
            'unit_cost' => bccomp($unitCost, '0', 2) > 0 ? bcadd($unitCost, '0', 2) : $batch->unit_cost,
            'status' => $status,
        ]);

        return $batch->fresh();
    }

    /**
     * Reserve stock for a B2B Sales Order or Outbound Stock Transfer.
     */
    public function reserveStock(
        int $organizationId,
        int $warehouseId,
        ?int $outletId,
        ProductVariant $variant,
        string $baseQty,
        string $referenceType,
        int $referenceId,
        ?int $batchId = null,
        ?int $userId = null,
        ?string $notes = null,
    ): StockReservation {
        $normalizedQty = Quantity::normalize($baseQty);

        $whInv = WarehouseInventory::query()->withoutGlobalScopes()
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variant->id)
            ->lockForUpdate()
            ->first();

        if ($whInv === null) {
            $whInv = WarehouseInventory::query()->withoutGlobalScopes()->create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'outlet_id' => $outletId,
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'quantity' => '0.000',
                'reserved_quantity' => $normalizedQty,
                'in_transit_quantity' => '0.000',
                'damaged_quantity' => '0.000',
                'quarantine_quantity' => '0.000',
                'reorder_level' => '15.000',
            ]);
        } else {
            $whInv->update([
                'reserved_quantity' => Quantity::add($whInv->reserved_quantity, $normalizedQty),
            ]);
        }

        if ($batchId !== null) {
            $batch = InventoryBatch::query()->lockForUpdate()->find($batchId);
            if ($batch !== null) {
                $batch->update([
                    'reserved_quantity' => Quantity::add($batch->reserved_quantity, $normalizedQty),
                ]);
            }
        }

        return StockReservation::query()->create([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'outlet_id' => $outletId,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'batch_id' => $batchId,
            'reserved_quantity' => $normalizedQty,
            'status' => 'active',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reserved_at' => CarbonImmutable::now(),
            'created_by' => $userId,
            'notes' => $notes,
        ]);
    }

    /**
     * Release active stock reservations (e.g. when a B2B Sales Order is cancelled).
     */
    public function releaseReservations(string $referenceType, int $referenceId): void
    {
        $reservations = StockReservation::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();

        $now = CarbonImmutable::now();

        foreach ($reservations as $res) {
            $whInv = WarehouseInventory::query()->withoutGlobalScopes()
                ->where('warehouse_id', $res->warehouse_id)
                ->where('product_variant_id', $res->product_variant_id)
                ->lockForUpdate()
                ->first();

            if ($whInv !== null) {
                $whInv->update([
                    'reserved_quantity' => Quantity::maxZero(Quantity::sub($whInv->reserved_quantity, $res->quantity)),
                ]);
            }

            if ($res->batch_id !== null) {
                $batch = InventoryBatch::query()->lockForUpdate()->find($res->batch_id);
                if ($batch !== null) {
                    $batch->update([
                        'reserved_quantity' => Quantity::maxZero(Quantity::sub($batch->reserved_quantity, $res->quantity)),
                    ]);
                }
            }

            $res->update([
                'status' => 'released',
                'released_at' => $now,
            ]);
        }
    }

    /**
     * Mark active reservations as consumed when a Sales Order is delivered/fulfilled.
     */
    public function consumeReservations(string $referenceType, int $referenceId): void
    {
        $reservations = StockReservation::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();

        $now = CarbonImmutable::now();

        foreach ($reservations as $res) {
            if ($res->batch_id !== null) {
                $batch = InventoryBatch::query()->lockForUpdate()->find($res->batch_id);
                if ($batch !== null) {
                    $batch->update([
                        'reserved_quantity' => Quantity::maxZero(Quantity::sub($batch->reserved_quantity, $res->quantity)),
                    ]);
                }
            }

            $res->update([
                'status' => 'consumed',
                'consumed_at' => $now,
            ]);
        }
    }

    /**
     * Record a StockMovement ledger entry (works for both Central Warehouse where outlet_id is null
     * and Branch Warehouses where outlet_id is populated).
     */
    public function recordLedgerMovement(
        int $organizationId,
        ?int $outletId,
        int $warehouseId,
        int $productId,
        int $productVariantId,
        StockMovementType $type,
        string $quantityChange,
        string $quantityBefore,
        string $quantityAfter,
        string $referenceType,
        int $referenceId,
        ?int $userId,
        string $notes,
        ?int $batchId = null,
        ?string $uomCode = null,
        ?string $uomQuantity = null,
        string $conversionFactor = '1.000',
        string $unitCost = '0.00',
    ): StockMovement {
        $resolvedUom = $uomCode !== null && trim($uomCode) !== ''
            ? strtoupper(trim($uomCode))
            : 'PCS';

        return StockMovement::query()->withoutGlobalScopes()->create([
            'organization_id' => $organizationId,
            'outlet_id' => $outletId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $productVariantId,
            'batch_id' => $batchId,
            'type' => $type,
            'uom_code' => $resolvedUom,
            'uom_quantity' => $uomQuantity ?? ltrim($quantityChange, '-'),
            'conversion_factor' => $conversionFactor,
            'quantity_change' => $quantityChange,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'unit_cost' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'user_id' => $userId,
            'notes' => $notes,
        ]);
    }
}

