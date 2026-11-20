<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\OutletInventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\Audit\AuditLogger;
use App\Services\InventoryEngineService;
use App\Services\PricingAndCostService;
use App\Support\PosContext;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProcurementController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditLogger $audit,
        private readonly PricingAndCostService $pricing,
        private readonly InventoryEngineService $inventoryEngine,
    ) {}

    public function index(): JsonResponse
    {
        $user = $this->context->requireUser();

        $suppliers = Supplier::query()->withoutGlobalScopes()
            ->with(['principals:id,code,name', 'supplierProducts'])
            ->where('organization_id', $user->organization_id)
            ->orderBy('name')
            ->get();

        $warehouses = Warehouse::query()->withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN type = 'central' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        $purchaseRequests = PurchaseRequest::query()
            ->with(['warehouse:id,code,name,type', 'outlet:id,code,name', 'requester:id,name', 'approver:id,name', 'items'])
            ->where('organization_id', $user->organization_id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $purchaseOrders = PurchaseOrder::query()->withoutGlobalScopes()
            ->with(['supplier', 'targetWarehouse', 'outlet', 'items', 'receipts.items'])
            ->where('organization_id', $user->organization_id)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        $purchaseInvoices = PurchaseInvoice::query()
            ->with(['supplier:id,code,name', 'purchaseOrder:id,po_number'])
            ->where('organization_id', $user->organization_id)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        $purchaseReturns = PurchaseReturn::query()
            ->with(['supplier:id,code,name', 'purchaseOrder:id,po_number', 'warehouse:id,code,name', 'items'])
            ->where('organization_id', $user->organization_id)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return response()->json([
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
            'purchase_requests' => $purchaseRequests,
            'purchase_orders' => $purchaseOrders,
            'purchase_invoices' => $purchaseInvoices,
            'purchase_returns' => $purchaseReturns,
        ]);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:120'],
            'npwp' => ['nullable', 'string', 'max:64'],
            'bank_name' => ['nullable', 'string', 'max:64'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'bank_account_holder' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'principal_ids' => ['nullable', 'array'],
            'principal_ids.*' => ['integer', 'exists:principals,id'],
        ]);

        $supplier = Supplier::query()->withoutGlobalScopes()->create([
            'organization_id' => $user->organization_id,
            'code' => strtoupper(trim((string) $validated['code'])),
            'name' => $validated['name'],
            'contact_person' => $validated['contact_person'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'npwp' => $validated['npwp'] ?? null,
            'bank_name' => $validated['bank_name'] ?? null,
            'bank_account_number' => $validated['bank_account_number'] ?? null,
            'bank_account_holder' => $validated['bank_account_holder'] ?? null,
            'city' => $validated['city'] ?? null,
            'address' => $validated['address'] ?? null,
            'payment_term_days' => (int) ($validated['payment_term_days'] ?? 30),
            'lead_time_days' => (int) ($validated['lead_time_days'] ?? 3),
            'is_active' => true,
        ]);

        if (! empty($validated['principal_ids'])) {
            $supplier->principals()->sync($validated['principal_ids']);
        }

        return response()->json(['supplier' => $supplier->load('principals')], 201);
    }

    public function storePurchaseRequest(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'needed_by_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.uom_code' => ['nullable', 'string', 'max:24'],
            'items.*.requested_qty' => ['required', 'numeric', 'gt:0'],
            'items.*.estimated_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->findOrFail((int) $validated['warehouse_id']);

        return DB::transaction(function () use ($user, $warehouse, $validated): JsonResponse {
            $prNumber = sprintf('PR-%s-%04d', now()->format('Ymd'), random_int(1, 9999));

            $pr = PurchaseRequest::query()->create([
                'organization_id' => $user->organization_id,
                'warehouse_id' => $warehouse->id,
                'outlet_id' => $warehouse->outlet_id,
                'pr_number' => $prNumber,
                'status' => 'submitted',
                'needed_by_date' => $validated['needed_by_date'] ?? now()->addDays(5)->toDateString(),
                'notes' => $validated['notes'] ?? null,
                'requested_by' => $user->id,
            ]);

            foreach ($validated['items'] as $row) {
                $variant = ProductVariant::query()->withoutGlobalScopes()
                    ->with('product')
                    ->findOrFail((int) $row['product_variant_id']);

                $uom = $this->pricing->resolveUnitConversion(
                    variant: $variant,
                    uomCode: $row['uom_code'] ?? null,
                    uomQty: $row['requested_qty'],
                );

                PurchaseRequestItem::query()->create([
                    'purchase_request_id' => $pr->id,
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'uom_code' => $uom['uom_code'],
                    'conversion_factor' => $uom['conversion_factor'],
                    'requested_qty' => $uom['uom_quantity'],
                    'approved_qty' => $uom['uom_quantity'],
                    'estimated_unit_cost' => number_format((float) ($row['estimated_unit_cost'] ?? $variant->cost_price ?? 0), 2, '.', ''),
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            $this->audit->record(
                AuditAction::PURCHASE_REQUEST_CREATED,
                $pr,
                newValues: [
                    'pr_number' => $pr->pr_number,
                    'warehouse' => $warehouse->code,
                ],
                actor: $user,
            );

            return response()->json([
                'purchase_request' => $pr->load(['warehouse', 'outlet', 'requester', 'items']),
            ], 201);
        });
    }

    public function approvePurchaseRequest(Request $request, int $prId): JsonResponse
    {
        $user = $this->context->requireUser();

        $pr = PurchaseRequest::query()
            ->with('items')
            ->where('organization_id', $user->organization_id)
            ->findOrFail($prId);

        if (! in_array($pr->status, ['draft', 'submitted'], true)) {
            throw new BusinessRuleException('INVALID_PR_STATUS', 'Only draft or submitted Purchase Requests can be approved.', 422);
        }

        $pr->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $this->audit->record(
            AuditAction::PURCHASE_REQUEST_APPROVED,
            $pr,
            newValues: ['pr_number' => $pr->pr_number, 'status' => 'approved'],
            actor: $user,
        );

        return response()->json([
            'purchase_request' => $pr->fresh(['warehouse', 'outlet', 'requester', 'approver', 'items']),
        ]);
    }

    public function storePurchaseOrder(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'target_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'purchase_request_id' => ['nullable', 'integer', 'exists:purchase_requests,id'],
            'expected_date' => ['nullable', 'date'],
            'additional_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.product_unit_id' => ['nullable', 'integer'],
            'items.*.uom_code' => ['nullable', 'string', 'max:24'],
            'items.*.ordered_qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->findOrFail((int) $validated['target_warehouse_id']);

        return DB::transaction(function () use ($user, $warehouse, $validated): JsonResponse {
            $poNumber = sprintf('PO-%s-%04d', now()->format('Ymd'), random_int(1, 9999));

            $subtotal = 0.0;
            $itemPayloads = [];

            foreach ($validated['items'] as $row) {
                $variant = ProductVariant::query()->withoutGlobalScopes()
                    ->with('product')
                    ->findOrFail((int) $row['product_variant_id']);

                $qty = (float) $row['ordered_qty'];
                $cost = (float) $row['unit_cost'];
                $lineTotal = round($qty * $cost, 2);
                $subtotal += $lineTotal;

                $uom = $this->pricing->resolveUnitConversion(
                    variant: $variant,
                    productUnitId: isset($row['product_unit_id']) ? (int) $row['product_unit_id'] : null,
                    uomCode: isset($row['uom_code']) ? (string) $row['uom_code'] : null,
                    uomQty: $qty,
                    lockUnit: true,
                );

                $itemPayloads[] = [
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'product_unit_id' => $uom['product_unit']?->id,
                    'sku' => $variant->sku,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'uom_code' => $uom['uom_code'],
                    'uom_quantity' => $uom['uom_quantity'],
                    'conversion_factor' => $uom['conversion_factor'],
                    'ordered_qty' => number_format($qty, 3, '.', ''),
                    'received_qty' => '0.000',
                    'damaged_qty' => '0.000',
                    'unit_cost' => number_format($cost, 2, '.', ''),
                    'line_total' => number_format($lineTotal, 2, '.', ''),
                ];
            }

            $additionalCost = isset($validated['additional_cost']) ? round((float) $validated['additional_cost'], 2) : 0.0;
            $taxTotal = round($subtotal * 0.11, 2);
            $grandTotal = round($subtotal + $taxTotal + $additionalCost, 2);

            $po = PurchaseOrder::query()->withoutGlobalScopes()->create([
                'organization_id' => $user->organization_id,
                'supplier_id' => (int) $validated['supplier_id'],
                'target_warehouse_id' => $warehouse->id,
                'outlet_id' => $warehouse->outlet_id,
                'purchase_request_id' => $validated['purchase_request_id'] ?? null,
                'po_number' => $poNumber,
                'status' => 'ordered',
                'order_date' => now()->toDateString(),
                'expected_date' => $validated['expected_date'] ?? now()->addDays(3)->toDateString(),
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'tax_total' => number_format($taxTotal, 2, '.', ''),
                'additional_cost' => number_format($additionalCost, 2, '.', ''),
                'grand_total' => number_format($grandTotal, 2, '.', ''),
                'paid_total' => '0.00',
                'payment_status' => 'unpaid',
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
                'approved_by' => $user->id,
            ]);

            foreach ($itemPayloads as $payload) {
                PurchaseOrderItem::query()->create(array_merge($payload, [
                    'purchase_order_id' => $po->id,
                ]));
            }

            if (! empty($validated['purchase_request_id'])) {
                PurchaseRequest::query()
                    ->where('organization_id', $user->organization_id)
                    ->where('id', (int) $validated['purchase_request_id'])
                    ->update(['status' => 'converted']);
            }

            $this->audit->record(
                AuditAction::PURCHASE_ORDER_CREATED,
                $po,
                newValues: [
                    'po_number' => $po->po_number,
                    'target_warehouse' => $warehouse->code,
                    'grand_total' => $po->grand_total,
                ],
                actor: $user,
            );

            return response()->json([
                'purchase_order' => $po->load(['supplier', 'targetWarehouse', 'items']),
            ], 201);
        });
    }

    /**
     * Goods Receiving for a Purchase Order (supports full/partial receive, damaged items,
     * Multi-UOM conversion, Batch & Expiry tracking, Landed Cost & Moving Average Cost).
     * Automatically adds accepted base stock into the target warehouse (Central or Branch)
     * and writes a StockMovement ledger entry.
     */
    public function receiveGoods(Request $request, int $poId): JsonResponse
    {
        $user = $this->context->requireUser();

        $po = PurchaseOrder::query()->withoutGlobalScopes()
            ->with([
                'items.variant' => static fn ($q) => $q->withoutGlobalScopes()->withTrashed(),
                'items.variant.product' => static fn ($q) => $q->withoutGlobalScopes()->withTrashed(),
                'targetWarehouse',
                'supplier',
            ])
            ->where('organization_id', $user->organization_id)
            ->findOrFail($poId);

        if (! in_array($po->status, ['ordered', 'approved', 'partially_received'], true)) {
            throw new BusinessRuleException('INVALID_PO_STATUS', 'This Purchase Order is already fully received or cancelled.', 422);
        }

        $validated = $request->validate([
            'delivery_note_number' => ['nullable', 'string', 'max:64'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required_with:items', 'integer'],
            'items.*.accepted_qty' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.damaged_qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.batch_number' => ['nullable', 'string', 'max:64'],
            'items.*.lot_number' => ['nullable', 'string', 'max:64'],
            'items.*.manufacturing_date' => ['nullable', 'date'],
            'items.*.expiry_date' => ['nullable', 'date'],
        ]);

        $overrides = collect($validated['items'] ?? [])->keyBy('id');

        return DB::transaction(function () use ($user, $po, $overrides, $validated): JsonResponse {
            $grNumber = sprintf('GR-%s-%04d', now()->format('Ymd'), random_int(1, 9999));

            $receipt = GoodsReceipt::query()->withoutGlobalScopes()->create([
                'organization_id' => $user->organization_id,
                'purchase_order_id' => $po->id,
                'warehouse_id' => $po->target_warehouse_id,
                'outlet_id' => $po->outlet_id,
                'gr_number' => $grNumber,
                'status' => 'completed',
                'delivery_note_number' => $validated['delivery_note_number'] ?? ('SJ-'.now()->format('His')),
                'received_at' => now(),
                'received_by' => $user->id,
                'notes' => $validated['notes'] ?? null,
            ]);

            $allFullyReceived = true;
            $poSubtotal = max(1.0, (float) $po->subtotal);
            $poAdditionalCost = (float) ($po->additional_cost ?? 0);

            foreach ($po->items as $poItem) {
                $remainingQty = max(0.0, (float) $poItem->ordered_qty - (float) $poItem->received_qty);
                if ($remainingQty <= 0) {
                    continue;
                }

                $override = $overrides->get($poItem->id);
                $acceptedUomQty = $override !== null
                    ? Quantity::normalize($override['accepted_qty'])
                    : Quantity::normalize($remainingQty);
                $damagedUomQty = $override !== null && isset($override['damaged_qty'])
                    ? Quantity::normalize($override['damaged_qty'])
                    : '0.000';

                $newCumulativeReceived = Quantity::add($poItem->received_qty, $acceptedUomQty);
                $newCumulativeDamaged = Quantity::add($poItem->damaged_qty, $damagedUomQty);
                $discrepancyUomQty = Quantity::sub(Quantity::normalize($remainingQty), Quantity::add($acceptedUomQty, $damagedUomQty));

                if (Quantity::compare($newCumulativeReceived, $poItem->ordered_qty) < 0) {
                    $allFullyReceived = false;
                }

                $factor = Quantity::normalize($poItem->conversion_factor ?? '1.000');
                if (Quantity::compare($factor, '0.000') <= 0) {
                    $factor = '1.000';
                }

                // Convert from PO Item packaging UOM (e.g. DUS) into base unit (e.g. PCS)
                $acceptedBaseQty = Quantity::mul($acceptedUomQty, $factor);
                $damagedBaseQty = Quantity::mul($damagedUomQty, $factor);

                // Compute landed base unit cost (including proportional additional_cost)
                $lineShareRatio = ((float) $poItem->line_total) / $poSubtotal;
                $allocatedAdditional = $poAdditionalCost * $lineShareRatio;
                $orderedBaseQty = max(1.0, (float) Quantity::mul($poItem->ordered_qty, $factor));
                $basePurchaseUnitCost = ((float) $poItem->unit_cost) / ((float) $factor);
                $landedBaseUnitCost = number_format($basePurchaseUnitCost + ($allocatedAdditional / $orderedBaseQty), 2, '.', '');

                $variant = $poItem->variant ?? ProductVariant::query()
                    ->withoutGlobalScopes()
                    ->withTrashed()
                    ->with(['product' => static fn ($q) => $q->withoutGlobalScopes()->withTrashed()])
                    ->find($poItem->product_variant_id);
                $product = $variant?->product ?? Product::query()
                    ->withoutGlobalScopes()
                    ->withTrashed()
                    ->find($poItem->product_id);
                $resolvedUomCode = (string) ($poItem->uom_code ?? $product?->unit ?? 'PCS');

                // Receive into InventoryBatch if batch_number provided or product tracks batch/expiry
                $batchNumber = $override['batch_number'] ?? null;
                $expiryDate = $override['expiry_date'] ?? null;
                $mfgDate = $override['manufacturing_date'] ?? null;
                $lotNumber = $override['lot_number'] ?? null;

                $batchModel = null;
                if (
                    $variant !== null
                    && (
                        ($batchNumber !== null && trim((string) $batchNumber) !== '')
                        || (bool) ($product?->track_batch ?? false)
                        || (bool) ($product?->track_expiry ?? false)
                    )
                ) {
                    $effectiveBatchNo = ($batchNumber !== null && trim((string) $batchNumber) !== '')
                        ? (string) $batchNumber
                        : sprintf('BATCH-%s-%s', now()->format('Ymd'), $poItem->id);

                    $batchModel = $this->inventoryEngine->receiveIntoBatch(
                        organizationId: (int) $user->organization_id,
                        warehouseId: (int) $po->target_warehouse_id,
                        outletId: $po->outlet_id ? (int) $po->outlet_id : null,
                        variant: $variant,
                        batchNumber: $effectiveBatchNo,
                        acceptedBaseQty: $acceptedBaseQty,
                        damagedBaseQty: $damagedBaseQty,
                        lotNumber: $lotNumber,
                        manufacturingDate: $mfgDate,
                        expiryDate: $expiryDate,
                        unitCost: $landedBaseUnitCost,
                        supplierId: (int) $po->supplier_id,
                        goodsReceiptId: $receipt->id,
                    );
                }

                GoodsReceiptItem::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $poItem->id,
                    'product_variant_id' => $poItem->product_variant_id,
                    'batch_id' => $batchModel?->id,
                    'uom_code' => $resolvedUomCode,
                    'conversion_factor' => $factor,
                    'batch_number' => $batchModel?->batch_number ?? $batchNumber,
                    'manufacturing_date' => $mfgDate,
                    'expiry_date' => $expiryDate,
                    'unit_cost' => $poItem->unit_cost,
                    'landed_unit_cost' => $landedBaseUnitCost,
                    'received_qty' => Quantity::add($acceptedUomQty, $damagedUomQty),
                    'accepted_qty' => $acceptedUomQty,
                    'damaged_qty' => $damagedUomQty,
                    'discrepancy_qty' => $discrepancyUomQty,
                ]);

                $poItem->update([
                    'received_qty' => $newCumulativeReceived,
                    'damaged_qty' => $newCumulativeDamaged,
                ]);

                // Lock target WarehouseInventory & compute existing stock before update
                $whInv = WarehouseInventory::query()->withoutGlobalScopes()
                    ->where('warehouse_id', $po->target_warehouse_id)
                    ->where('product_variant_id', $poItem->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                $beforeWhQty = $whInv ? Quantity::normalize($whInv->quantity) : '0.000';
                $afterWhQty = Quantity::add($beforeWhQty, $acceptedBaseQty);
                $beforeDamagedQty = $whInv ? Quantity::normalize($whInv->damaged_quantity ?? '0.000') : '0.000';
                $afterDamagedQty = Quantity::add($beforeDamagedQty, $damagedBaseQty);

                // Update Moving Weighted Average Cost (HPP)
                if ($variant !== null) {
                    $this->pricing->applyMovingAverageCost(
                        variant: $variant,
                        existingBaseQty: $beforeWhQty,
                        receivedBaseQty: $acceptedBaseQty,
                        landedBaseUnitCost: $landedBaseUnitCost,
                        warehouseId: (int) $po->target_warehouse_id,
                        outletId: $po->outlet_id ? (int) $po->outlet_id : null,
                        userId: $user->id,
                        reason: sprintf('Goods Receipt %s (%s)', $receipt->gr_number, $po->po_number),
                    );
                }

                if ($whInv === null) {
                    WarehouseInventory::query()->withoutGlobalScopes()->create([
                        'organization_id' => $user->organization_id,
                        'warehouse_id' => $po->target_warehouse_id,
                        'outlet_id' => $po->outlet_id,
                        'product_id' => $poItem->product_id,
                        'product_variant_id' => $poItem->product_variant_id,
                        'quantity' => $afterWhQty,
                        'reserved_quantity' => '0.000',
                        'in_transit_quantity' => '0.000',
                        'damaged_quantity' => $afterDamagedQty,
                        'quarantine_quantity' => '0.000',
                        'reorder_level' => '20.000',
                    ]);
                } else {
                    $whInv->update([
                        'quantity' => $afterWhQty,
                        'damaged_quantity' => $afterDamagedQty,
                    ]);
                }

                // If target warehouse is a Branch Warehouse, also sync OutletInventory
                if ($po->outlet_id !== null) {
                    $outletInv = OutletInventory::query()->withoutGlobalScopes()
                        ->where('outlet_id', $po->outlet_id)
                        ->where('product_variant_id', $poItem->product_variant_id)
                        ->lockForUpdate()
                        ->first();

                    $beforeOut = $outletInv ? Quantity::normalize($outletInv->quantity) : '0.000';
                    $afterOut = Quantity::add($beforeOut, $acceptedBaseQty);

                    if ($outletInv === null) {
                        OutletInventory::query()->withoutGlobalScopes()->create([
                            'outlet_id' => $po->outlet_id,
                            'warehouse_id' => $po->target_warehouse_id,
                            'product_id' => $poItem->product_id,
                            'product_variant_id' => $poItem->product_variant_id,
                            'quantity' => $afterOut,
                            'reorder_level' => '15.000',
                            'version' => 1,
                        ]);
                    } else {
                        $outletInv->update([
                            'quantity' => $afterOut,
                            'version' => $outletInv->version + 1,
                        ]);
                    }
                }

                // Record StockMovement in ledger for BOTH Central Warehouse (outlet_id = null) and Branch Warehouse
                $this->inventoryEngine->recordLedgerMovement(
                    organizationId: (int) $user->organization_id,
                    outletId: $po->outlet_id ? (int) $po->outlet_id : null,
                    warehouseId: (int) $po->target_warehouse_id,
                    productId: (int) $poItem->product_id,
                    productVariantId: (int) $poItem->product_variant_id,
                    type: StockMovementType::Receive,
                    quantityChange: $acceptedBaseQty,
                    quantityBefore: $beforeWhQty,
                    quantityAfter: $afterWhQty,
                    referenceType: 'GoodsReceipt',
                    referenceId: $receipt->id,
                    userId: $user->id,
                    notes: sprintf('Goods Receipt %s (%s)', $receipt->gr_number, $po->po_number),
                    batchId: $batchModel?->id,
                    uomCode: $resolvedUomCode,
                    uomQuantity: $acceptedUomQty,
                    conversionFactor: $factor,
                    unitCost: $landedBaseUnitCost,
                );
            }

            $po->update([
                'status' => $allFullyReceived ? 'received' : 'partially_received',
                'supplier_invoice_number' => $validated['supplier_invoice_number'] ?? $po->supplier_invoice_number,
            ]);

            // Auto-create PurchaseInvoice if supplier_invoice_number provided
            if (! empty($validated['supplier_invoice_number'])) {
                PurchaseInvoice::query()->firstOrCreate(
                    [
                        'organization_id' => $user->organization_id,
                        'invoice_number' => trim((string) $validated['supplier_invoice_number']),
                    ],
                    [
                        'supplier_id' => $po->supplier_id,
                        'purchase_order_id' => $po->id,
                        'goods_receipt_id' => $receipt->id,
                        'status' => 'unpaid',
                        'invoice_date' => now()->toDateString(),
                        'due_date' => now()->addDays((int) ($po->supplier->payment_term_days ?: 30))->toDateString(),
                        'subtotal' => $po->subtotal,
                        'tax_total' => $po->tax_total,
                        'additional_cost' => $po->additional_cost ?? '0.00',
                        'grand_total' => $po->grand_total,
                        'paid_total' => '0.00',
                        'balance_due' => $po->grand_total,
                        'created_by' => $user->id,
                    ],
                );
            }

            $this->audit->record(
                AuditAction::GOODS_RECEIPT_COMPLETED,
                $receipt,
                newValues: [
                    'gr_number' => $receipt->gr_number,
                    'po_number' => $po->po_number,
                    'warehouse_id' => $po->target_warehouse_id,
                    'po_status' => $po->status,
                ],
                actor: $user,
            );

            return response()->json([
                'goods_receipt' => $receipt->load('items'),
                'purchase_order' => $po->fresh(['supplier', 'targetWarehouse', 'items', 'receipts.items']),
            ]);
        });
    }

    public function storePurchaseReturn(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        $validated = $request->validate([
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        $po = PurchaseOrder::query()->withoutGlobalScopes()
            ->with('targetWarehouse')
            ->where('organization_id', $user->organization_id)
            ->findOrFail((int) $validated['purchase_order_id']);

        return DB::transaction(function () use ($user, $po, $validated): JsonResponse {
            $returnNumber = sprintf('PRET-%s-%04d', now()->format('Ymd'), random_int(1, 9999));
            $totalAmount = 0.0;
            $itemPayloads = [];

            foreach ($validated['items'] as $row) {
                $variant = ProductVariant::query()->withoutGlobalScopes()
                    ->withTrashed()
                    ->with(['product' => static fn ($q) => $q->withoutGlobalScopes()->withTrashed()])
                    ->findOrFail((int) $row['product_variant_id']);

                $qty = Quantity::normalize($row['quantity']);
                $cost = (float) $row['unit_cost'];
                $lineTotal = round((float) $qty * $cost, 2);
                $totalAmount += $lineTotal;

                $itemPayloads[] = [
                    '_variant' => $variant,
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'uom_code' => $variant->product?->unit ?? 'PCS',
                    'quantity' => $qty,
                    'unit_cost' => number_format($cost, 2, '.', ''),
                    'line_total' => number_format($lineTotal, 2, '.', ''),
                    'reason' => $row['reason'] ?? $validated['reason'],
                ];
            }

            $purchaseReturn = PurchaseReturn::query()->create([
                'organization_id' => $user->organization_id,
                'supplier_id' => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'warehouse_id' => $po->target_warehouse_id,
                'return_number' => $returnNumber,
                'status' => 'completed',
                'return_date' => now()->toDateString(),
                'total_amount' => number_format($totalAmount, 2, '.', ''),
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($itemPayloads as $payload) {
                /** @var ProductVariant $variant */
                $variant = $payload['_variant'];
                unset($payload['_variant']);

                PurchaseReturnItem::query()->create(array_merge($payload, [
                    'purchase_return_id' => $purchaseReturn->id,
                ]));

                $whInv = WarehouseInventory::query()->withoutGlobalScopes()
                    ->where('warehouse_id', $po->target_warehouse_id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first();

                $beforeQty = $whInv ? Quantity::normalize($whInv->quantity) : '0.000';
                $afterQty = Quantity::maxZero(Quantity::sub($beforeQty, $payload['quantity']));

                if ($whInv !== null) {
                    $whInv->update(['quantity' => $afterQty]);
                }

                if ($po->outlet_id !== null) {
                    $outletInv = OutletInventory::query()->withoutGlobalScopes()
                        ->where('outlet_id', $po->outlet_id)
                        ->where('product_variant_id', $variant->id)
                        ->lockForUpdate()
                        ->first();
                    if ($outletInv !== null) {
                        $outletInv->update([
                            'quantity' => Quantity::maxZero(Quantity::sub($outletInv->quantity, $payload['quantity'])),
                            'version' => $outletInv->version + 1,
                        ]);
                    }
                }

                $this->inventoryEngine->recordLedgerMovement(
                    organizationId: (int) $user->organization_id,
                    outletId: $po->outlet_id ? (int) $po->outlet_id : null,
                    warehouseId: (int) $po->target_warehouse_id,
                    productId: $variant->product_id,
                    productVariantId: $variant->id,
                    type: StockMovementType::PurchaseReturn,
                    quantityChange: Quantity::negate($payload['quantity']),
                    quantityBefore: $beforeQty,
                    quantityAfter: $afterQty,
                    referenceType: 'PurchaseReturn',
                    referenceId: $purchaseReturn->id,
                    userId: $user->id,
                    notes: sprintf('Purchase Return %s (%s)', $returnNumber, $po->po_number),
                    uomCode: $payload['uom_code'],
                    uomQuantity: $payload['quantity'],
                    unitCost: $payload['unit_cost'],
                );
            }

            $this->audit->record(
                AuditAction::PURCHASE_RETURN_CREATED,
                $purchaseReturn,
                newValues: [
                    'return_number' => $purchaseReturn->return_number,
                    'po_number' => $po->po_number,
                    'total_amount' => $purchaseReturn->total_amount,
                ],
                actor: $user,
            );

            return response()->json([
                'purchase_return' => $purchaseReturn->load(['supplier', 'purchaseOrder', 'warehouse', 'items']),
            ], 201);
        });
    }
}
