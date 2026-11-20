<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerPayment;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\OutletInventory;
use App\Models\PaymentAllocation;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\WarehouseInventory;
use App\Services\ActivityTrackingService;
use App\Services\Audit\AuditLogger;
use App\Services\InventoryEngineService;
use App\Services\PricingAndCostService;
use App\Support\PosContext;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CustomerAndSalesOrderController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditLogger $audit,
        private readonly PricingAndCostService $pricing,
        private readonly InventoryEngineService $inventoryEngine,
        private readonly ActivityTrackingService $activityTracker,
    ) {}

    public function customers(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->outlet();
        $group = $request->string('customer_group')->toString();
        $search = $request->string('search')->toString();

        $customers = Customer::query()->withoutGlobalScopes()
            ->with(['outlet:id,code,name', 'priceList:id,code,name,type', 'tier:id,code,name,color', 'addresses'])
            ->where('organization_id', $user->organization_id)
            ->when($outlet !== null && ! $request->boolean('all_outlets', false), static function ($q) use ($outlet): void {
                $q->where(static function ($w) use ($outlet): void {
                    $w->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id);
                });
            })
            ->when($group !== '', static fn ($q) => $q->where('customer_group', $group))
            ->when($search !== '', static function ($q) use ($search): void {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(static function ($w) use ($like): void {
                    $w->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(company_name, \'\')) LIKE ?', [$like]);
                });
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'customers' => $customers,
        ]);
    }

    public function showCustomer(Request $request, int $customerId): JsonResponse
    {
        $user = $this->context->requireUser();

        $customer = Customer::query()->withoutGlobalScopes()
            ->with(['outlet:id,code,name', 'priceList:id,code,name,type', 'tier:id,code,name,color', 'addresses'])
            ->where('organization_id', $user->organization_id)
            ->findOrFail($customerId);

        $canRevealNik = $user->isSuperAdmin()
            || $user->hasAnyPermission('customers.manage', 'users.manage', 'receivables.manage')
            || $user->hasRole(
                \App\Enums\RoleName::SuperAdmin,
                \App\Enums\RoleName::Owner,
                \App\Enums\RoleName::AdminOutlet,
                \App\Enums\RoleName::OutletManager,
                \App\Enums\RoleName::Finance,
            );

        $invoices = Invoice::query()->withoutGlobalScopes()
            ->with([
                'outlet:id,code,name',
                'salesOrder:id,so_number',
                'order:id,order_number,completed_at,payment_term',
                'allocations.customerPayment:id,payment_number,method,amount,payment_date,reference_number,notes',
                'creator:id,name,username',
            ])
            ->where('organization_id', $user->organization_id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $posOrders = \App\Models\Order::query()->withoutGlobalScopes()
            ->with([
                'outlet:id,code,name',
                'items',
                'payments',
                'invoice:id,order_id,invoice_number,status,grand_total,paid_total,balance_due,due_date',
                'cashier:id,name,username',
            ])
            ->where('organization_id', $user->organization_id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $customerPayments = CustomerPayment::query()->withoutGlobalScopes()
            ->with([
                'outlet:id,code,name',
                'allocations.invoice:id,invoice_number,grand_total,paid_total,balance_due,status',
                'receiver:id,name,username',
            ])
            ->where('organization_id', $user->organization_id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $customerPayload = $customer->toArray();
        $customerPayload['can_reveal_nik'] = $canRevealNik;
        if ($canRevealNik && $request->boolean('reveal_nik', false)) {
            $customerPayload['raw_nik'] = $customer->rawNik();
        }

        return response()->json([
            'customer' => $customerPayload,
            'invoices' => $invoices,
            'orders' => $posOrders,
            'payments' => $customerPayments,
        ]);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->outlet();

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'nik' => ['nullable', 'string', 'max:32'],
            'customer_group' => ['nullable', 'string', 'in:retailer,wholesaler,reseller,business,walk_in,retail'],
            'pricing_tier' => ['nullable', 'string', 'max:32'],
            'tier_id' => ['nullable', 'integer', 'exists:tiers,id'],
            'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'email' => ['nullable', 'email', 'max:120'],
            'npwp' => ['nullable', 'string', 'max:64'],
            'sales_pic' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'customer_status' => ['nullable', 'string', 'in:active,hold,blacklist'],
            'addresses' => ['nullable', 'array'],
            'addresses.*.label' => ['required_with:addresses', 'string', 'max:64'],
            'addresses.*.recipient_name' => ['nullable', 'string', 'max:120'],
            'addresses.*.phone' => ['nullable', 'string', 'max:32'],
            'addresses.*.address' => ['required_with:addresses', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:64'],
            'addresses.*.is_default' => ['nullable', 'boolean'],
        ]);

        return DB::transaction(function () use ($user, $outlet, $validated): JsonResponse {
            $rawCode = isset($validated['code']) ? strtoupper(trim((string) $validated['code'])) : '';
            if ($rawCode === '') {
                $prefix = $outlet ? $outlet->code : 'POS';
                $rawCode = sprintf('CUST-%s-%s', $prefix, strtoupper(substr(uniqid(), -5)));
            }

            $customerGroup = $validated['customer_group'] ?? 'retailer';
            if ($customerGroup === 'retail') {
                $customerGroup = 'retailer';
            }

            $pricingTier = $validated['pricing_tier'] ?? 'retail';
            $creditLimit = isset($validated['credit_limit']) ? (float) $validated['credit_limit'] : 25000000.0;
            $paymentTermDays = isset($validated['payment_term_days']) ? (int) $validated['payment_term_days'] : 14;

            $customer = Customer::query()->withoutGlobalScopes()->create([
                'organization_id' => $user->organization_id,
                'outlet_id' => $outlet?->id,
                'tier_id' => $validated['tier_id'] ?? null,
                'price_list_id' => $validated['price_list_id'] ?? null,
                'code' => $rawCode,
                'name' => trim((string) $validated['name']),
                'company_name' => ! empty($validated['company_name']) ? trim((string) $validated['company_name']) : trim((string) $validated['name']),
                'customer_group' => $customerGroup,
                'pricing_tier' => $pricingTier,
                'phone' => trim((string) $validated['phone']),
                'email' => $validated['email'] ?? null,
                'npwp' => $validated['npwp'] ?? null,
                'nik' => ! empty($validated['nik']) ? preg_replace('/\s+/', '', (string) $validated['nik']) : null,
                'sales_pic' => $validated['sales_pic'] ?? null,
                'city' => $validated['city'] ?? $outlet?->city,
                'address' => $validated['address'] ?? null,
                'credit_limit' => number_format($creditLimit, 2, '.', ''),
                'payment_term_days' => $paymentTermDays,
                'outstanding_balance' => '0.00',
                'customer_status' => $validated['customer_status'] ?? 'active',
                'is_active' => ($validated['customer_status'] ?? 'active') !== 'blacklist',
            ]);

            if (! empty($validated['addresses'])) {
                foreach ($validated['addresses'] as $idx => $addr) {
                    CustomerAddress::query()->create([
                        'customer_id' => $customer->id,
                        'label' => $addr['label'],
                        'recipient_name' => $addr['recipient_name'] ?? $customer->name,
                        'phone' => $addr['phone'] ?? $customer->phone,
                        'address' => $addr['address'],
                        'city' => $addr['city'] ?? $customer->city,
                        'is_default' => (bool) ($addr['is_default'] ?? ($idx === 0)),
                    ]);
                }
            } elseif (! empty($validated['address'])) {
                CustomerAddress::query()->create([
                    'customer_id' => $customer->id,
                    'label' => 'Alamat Utama',
                    'recipient_name' => $customer->name,
                    'phone' => $customer->phone,
                    'address' => $validated['address'],
                    'city' => $customer->city,
                    'is_default' => true,
                ]);
            }

            return response()->json([
                'customer' => $customer->load(['outlet', 'priceList', 'addresses']),
            ], 201);
        });
    }

    public function updateCustomer(Request $request, int $customerId): JsonResponse
    {
        $user = $this->context->requireUser();

        $customer = Customer::query()->withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($customerId);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'customer_group' => ['sometimes', 'required', 'string', 'in:retailer,wholesaler,reseller,business,walk_in'],
            'pricing_tier' => ['sometimes', 'required', 'string', 'in:retail,reseller,wholesale,vip'],
            'tier_id' => ['nullable', 'integer', 'exists:tiers,id'],
            'price_list_id' => ['nullable', 'integer', 'exists:price_lists,id'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:120'],
            'npwp' => ['nullable', 'string', 'max:64'],
            'nik' => ['nullable', 'string', 'max:32'],
            'sales_pic' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['sometimes', 'required', 'numeric', 'min:0'],
            'payment_term_days' => ['sometimes', 'required', 'integer', 'min:0', 'max:180'],
            'customer_status' => ['sometimes', 'required', 'string', 'in:active,hold,blacklist'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['credit_limit'])) {
            $validated['credit_limit'] = number_format((float) $validated['credit_limit'], 2, '.', '');
        }
        if (isset($validated['customer_status'])) {
            $validated['is_active'] = $validated['customer_status'] !== 'blacklist';
        }

        $customer->update($validated);

        return response()->json([
            'customer' => $customer->fresh(['outlet', 'priceList', 'addresses']),
        ]);
    }

    public function salesOrders(Request $request): JsonResponse
    {
        $outlet = $this->context->requireOutlet();
        $status = $request->string('status')->toString();

        $orders = SalesOrder::query()
            ->with([
                'customer',
                'warehouse:id,code,name',
                'outlet:id,code,name,city',
                'items',
                'deliveries',
                'invoice',
                'creator:id,name,username',
            ])
            ->where('outlet_id', $outlet->id)
            ->when($status !== '', static fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $deliveries = Delivery::query()
            ->with(['salesOrder.customer', 'warehouse:id,code,name'])
            ->where('outlet_id', $outlet->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $salesReturns = SalesReturn::query()
            ->with(['customer:id,code,name', 'salesOrder:id,so_number', 'items'])
            ->where('outlet_id', $outlet->id)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return response()->json([
            'outlet_id' => $outlet->id,
            'sales_orders' => $orders,
            'deliveries' => $deliveries,
            'sales_returns' => $salesReturns,
        ]);
    }

    /**
     * Creates a B2B Sales Order for the active fulfillment outlet:
     * - Supports Multi-UOM packaging (e.g. 2 DUS = 48 PCS)
     * - Automatically resolves Customer Price List / Customer Tier Price & HPP snapshot
     * - Validates outlet available stock (On Hand - Reserved - Damaged - Quarantine)
     * - Validates Customer Status & Credit Limit if payment_type = 'tempo'
     * - Reserves stock in the active outlet's Branch Warehouse + creates StockReservation
     */
    public function storeSalesOrder(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();
        $warehouse = $this->context->requireWarehouse();

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'payment_type' => ['required', 'string', 'in:cash,transfer,qris,tempo'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'approve_credit_override' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.product_unit_id' => ['nullable', 'integer'],
            'items.*.uom_code' => ['nullable', 'string', 'max:24'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $customer = Customer::query()->withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->findOrFail((int) $validated['customer_id']);

        if ($customer->outlet_id !== null && (int) $customer->outlet_id !== $outlet->id && ! $user->canAccessOutlet((int) $customer->outlet_id)) {
            throw new BusinessRuleException('CUSTOMER_OUTLET_MISMATCH', 'Customer does not belong to your accessible outlet.', 403);
        }

        if ($customer->customer_status === 'blacklist' || ! $customer->is_active) {
            throw new BusinessRuleException(
                'CUSTOMER_BLACKLISTED',
                sprintf('Pelanggan "%s" berstatus Blacklist / Nonaktif dan tidak dapat melakukan transaksi.', $customer->name),
                422,
            );
        }

        if ($customer->customer_status === 'hold' && $validated['payment_type'] === 'tempo' && ! ($validated['approve_credit_override'] ?? false)) {
            throw new BusinessRuleException(
                'CUSTOMER_ON_HOLD',
                sprintf('Pelanggan "%s" sedang dalam status Credit Hold. Gunakan pembayaran tunai atau aktifkan Credit Override.', $customer->name),
                422,
            );
        }

        return DB::transaction(function () use ($user, $outlet, $warehouse, $customer, $validated): JsonResponse {
            $subtotal = 0.0;
            $discountTotal = 0.0;
            $itemRows = [];

            foreach ($validated['items'] as $row) {
                $variant = ProductVariant::query()->withoutGlobalScopes()
                    ->with('product')
                    ->findOrFail((int) $row['product_variant_id']);

                $qty = (float) $row['quantity'];

                $uomResolution = $this->pricing->resolveUnitConversion(
                    variant: $variant,
                    productUnitId: isset($row['product_unit_id']) ? (int) $row['product_unit_id'] : null,
                    uomCode: isset($row['uom_code']) ? (string) $row['uom_code'] : null,
                    uomQty: $qty,
                    lockUnit: true,
                );

                $requiredBaseQty = (float) $uomResolution['base_quantity'];

                // Check stock in active outlet & branch warehouse
                $outletInv = OutletInventory::query()->withoutGlobalScopes()
                    ->where('outlet_id', $outlet->id)
                    ->where('product_variant_id', $variant->id)
                    ->first();

                $whInv = WarehouseInventory::query()->withoutGlobalScopes()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_variant_id', $variant->id)
                    ->first();

                $availableQty = $whInv !== null
                    ? (float) $whInv->available_quantity
                    : ($outletInv ? (float) $outletInv->quantity : 0.0);

                if (! $outlet->allow_negative_stock && $variant->product->track_stock && $variant->product->product_type !== 'non_inventory' && $availableQty < $requiredBaseQty) {
                    throw new BusinessRuleException(
                        'INSUFFICIENT_OUTLET_STOCK',
                        sprintf(
                            'Stok gudang %s tidak mencukupi untuk "%s - %s" (Tersedia: %s %s, Diminta: %s %s = %s %s).',
                            $outlet->name,
                            $variant->product->name,
                            $variant->name,
                            number_format($availableQty, 0),
                            $variant->product->unit ?? 'PCS',
                            number_format($qty, 0),
                            $uomResolution['uom_code'],
                            number_format($requiredBaseQty, 0),
                            $variant->product->unit ?? 'PCS',
                        ),
                        422,
                    );
                }

                $priceInfo = $this->pricing->resolveSellingPrice(
                    variant: $variant,
                    outlet: $outlet,
                    customer: $customer,
                    productUnit: $uomResolution['product_unit'],
                    uomQty: $qty,
                );

                if (isset($row['unit_price']) && $row['unit_price'] !== null && $row['unit_price'] !== '') {
                    $unitPrice = (float) $row['unit_price'];
                } else {
                    $unitPrice = (float) $priceInfo['unit_price'];
                }

                $disc = isset($row['discount_amount']) ? (float) $row['discount_amount'] : 0.0;
                $lineGross = round($qty * $unitPrice, 2);
                $lineNet = max(0.0, round($lineGross - $disc, 2));

                $subtotal += $lineGross;
                $discountTotal += $disc;

                $itemRows[] = [
                    '_variant' => $variant,
                    '_base_qty' => $uomResolution['base_quantity'],
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'product_unit_id' => $uomResolution['product_unit']?->id,
                    'batch_id' => isset($row['batch_id']) && $row['batch_id'] !== null ? (int) $row['batch_id'] : null,
                    'sku' => $variant->sku,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->name,
                    'uom_code' => $uomResolution['uom_code'],
                    'uom_quantity' => $uomResolution['uom_quantity'],
                    'conversion_factor' => $uomResolution['conversion_factor'],
                    'base_quantity' => $uomResolution['base_quantity'],
                    'ordered_qty' => number_format($qty, 3, '.', ''),
                    'fulfilled_qty' => '0.000',
                    'unit_price' => number_format($unitPrice, 2, '.', ''),
                    'unit_cost' => $priceInfo['unit_cost'],
                    'discount_amount' => number_format($disc, 2, '.', ''),
                    'line_total' => number_format($lineNet, 2, '.', ''),
                ];
            }

            $taxable = max(0.0, $subtotal - $discountTotal);
            $taxRate = (float) $outlet->tax_rate;
            $taxTotal = round($taxable * ($taxRate / 100), 2);
            $grandTotal = round($taxable + $taxTotal, 2);

            // Credit Limit Control when payment_type = 'tempo'
            $creditLimitWarning = false;
            $paymentType = (string) $validated['payment_type'];
            $termDays = $paymentType === 'tempo'
                ? (int) ($validated['payment_term_days'] ?? ($customer->payment_term_days ?: 30))
                : 0;

            if ($paymentType === 'tempo') {
                $projectedBalance = (float) $customer->outstanding_balance + $grandTotal;
                $creditLimit = (float) $customer->credit_limit;

                if ($projectedBalance > $creditLimit) {
                    $creditLimitWarning = true;
                    $canOverride = (bool) ($validated['approve_credit_override'] ?? false)
                        && $user->hasPermission(Permission::CreditLimitApprove);

                    if (! $canOverride) {
                        throw new BusinessRuleException(
                            'CREDIT_LIMIT_EXCEEDED',
                            sprintf(
                                'Piutang pelanggan "%s" akan melebihi batas kredit (Saldo saat ini: Rp %s + Order: Rp %s > Limit: Rp %s). Membutuhkan persetujuan Credit Limit Override.',
                                $customer->name,
                                number_format((float) $customer->outstanding_balance, 0, ',', '.'),
                                number_format($grandTotal, 0, ',', '.'),
                                number_format($creditLimit, 0, ',', '.'),
                            ),
                            422,
                            [
                                'customer_id' => $customer->id,
                                'outstanding_balance' => $customer->outstanding_balance,
                                'order_grand_total' => number_format($grandTotal, 2, '.', ''),
                                'credit_limit' => $customer->credit_limit,
                            ],
                        );
                    }
                }
            }

            $soNumber = sprintf('SO-%s-%s-%04d', $outlet->code, now()->format('Ymd'), random_int(1, 9999));
            $activeShiftCheckin = $this->context->employeeShiftCheckin();

            $salesOrder = SalesOrder::query()->create([
                'organization_id' => $user->organization_id,
                'outlet_id' => $outlet->id,
                'warehouse_id' => $warehouse->id,
                'customer_id' => $customer->id,
                'so_number' => $soNumber,
                'status' => 'confirmed',
                'payment_type' => $paymentType,
                'payment_term_days' => $termDays,
                'due_date' => $termDays > 0 ? now()->addDays($termDays)->toDateString() : now()->toDateString(),
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'discount_total' => number_format($discountTotal, 2, '.', ''),
                'tax_total' => number_format($taxTotal, 2, '.', ''),
                'grand_total' => number_format($grandTotal, 2, '.', ''),
                'credit_limit_warning' => $creditLimitWarning,
                'approved_by' => $user->id,
                'created_by' => $user->id,
                'shift_id' => $activeShiftCheckin?->shift_id,
                'employee_shift_checkin_id' => $activeShiftCheckin?->id,
                'work_date' => $activeShiftCheckin?->work_date ?? now()->toDateString(),
                'notes' => $validated['notes'] ?? null,
                'confirmed_at' => now(),
            ]);

            foreach ($itemRows as $itemRow) {
                /** @var ProductVariant $variantModel */
                $variantModel = $itemRow['_variant'];
                $baseReserveQty = (string) $itemRow['_base_qty'];
                unset($itemRow['_variant'], $itemRow['_base_qty']);

                SalesOrderItem::query()->create(array_merge($itemRow, [
                    'sales_order_id' => $salesOrder->id,
                ]));

                // Reserve base stock in WarehouseInventory & create StockReservation record
                if ($variantModel->product->track_stock && $variantModel->product->product_type !== 'non_inventory') {
                    $this->inventoryEngine->reserveStock(
                        organizationId: (int) $user->organization_id,
                        warehouseId: $warehouse->id,
                        outletId: $outlet->id,
                        variant: $variantModel,
                        baseQty: $baseReserveQty,
                        referenceType: 'SalesOrder',
                        referenceId: $salesOrder->id,
                        batchId: $itemRow['batch_id'] ?? null,
                        userId: $user->id,
                        notes: sprintf('Reserved for %s', $salesOrder->so_number),
                    );
                }
            }

            $this->audit->record(
                AuditAction::SALES_ORDER_CREATED,
                $salesOrder,
                newValues: [
                    'so_number' => $salesOrder->so_number,
                    'outlet_id' => $outlet->id,
                    'warehouse_id' => $warehouse->id,
                    'customer_id' => $customer->id,
                    'payment_type' => $salesOrder->payment_type,
                    'grand_total' => $salesOrder->grand_total,
                ],
                actor: $user,
            );

            $this->activityTracker->recordActivity(
                user: $user,
                outlet: $outlet,
                moduleName: 'sales',
                actionType: 'create',
                entityType: 'SalesOrder',
                entityId: $salesOrder->id,
                description: sprintf('Membuat Sales Order %s senilai Rp %s untuk pelanggan %s', $salesOrder->so_number, number_format((float) $salesOrder->grand_total, 0, ',', '.'), $customer->name),
                oldValues: null,
                newValues: ['so_number' => $salesOrder->so_number, 'grand_total' => $salesOrder->grand_total],
                shiftCheckin: $activeShiftCheckin,
            );

            return response()->json([
                'sales_order' => $salesOrder->load(['customer', 'warehouse', 'outlet', 'items']),
            ], 201);
        });
    }

    /**
     * Cancel an unfulfilled B2B Sales Order and immediately release its stock reservations.
     */
    public function cancelSalesOrder(Request $request, int $salesOrderId): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();

        $salesOrder = SalesOrder::query()
            ->with(['items', 'customer', 'warehouse'])
            ->where('outlet_id', $outlet->id)
            ->findOrFail($salesOrderId);

        if (in_array($salesOrder->status, ['invoiced', 'delivered', 'cancelled'], true)) {
            throw new BusinessRuleException(
                'CANNOT_CANCEL_SALES_ORDER',
                sprintf('Sales Order %s berstatus "%s" dan tidak dapat dibatalkan.', $salesOrder->so_number, $salesOrder->status),
                422,
            );
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($user, $salesOrder, $validated): JsonResponse {
            // Release all active reservations for this SalesOrder
            $this->inventoryEngine->releaseReservations('SalesOrder', $salesOrder->id);

            $salesOrder->update([
                'status' => 'cancelled',
                'notes' => trim(($salesOrder->notes ? $salesOrder->notes.' | ' : '').'Cancelled: '.($validated['reason'] ?? 'Dibatalkan oleh user')),
            ]);

            $this->audit->record(
                AuditAction::SALES_ORDER_CANCELLED,
                $salesOrder,
                newValues: [
                    'so_number' => $salesOrder->so_number,
                    'status' => 'cancelled',
                    'reason' => $validated['reason'] ?? 'Dibatalkan oleh user',
                ],
                actor: $user,
            );

            return response()->json([
                'sales_order' => $salesOrder->fresh(['customer', 'warehouse', 'outlet', 'items']),
            ]);
        });
    }

    /**
     * Fulfills a B2B Sales Order (Picking/Packing -> Delivery -> Stock Deduction -> Issue Invoice -> Record AR).
     * Consumes active stock reservations without double deduction and applies FEFO batch selection.
     */
    public function fulfillAndInvoice(Request $request, int $salesOrderId): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();

        $salesOrder = SalesOrder::query()
            ->with(['items.variant.product', 'customer', 'warehouse'])
            ->where('outlet_id', $outlet->id)
            ->findOrFail($salesOrderId);

        if (in_array($salesOrder->status, ['invoiced', 'cancelled'], true)) {
            throw new BusinessRuleException('SALES_ORDER_ALREADY_PROCESSED', 'This Sales Order has already been invoiced or cancelled.', 422);
        }

        $validated = $request->validate([
            'driver_name' => ['nullable', 'string', 'max:96'],
            'vehicle_number' => ['nullable', 'string', 'max:32'],
            'recipient_name' => ['nullable', 'string', 'max:96'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($user, $outlet, $salesOrder, $validated): JsonResponse {
            // 1. Deduct stock from OutletInventory & WarehouseInventory + consume reservations
            foreach ($salesOrder->items as $item) {
                $uomQty = Quantity::normalize($item->ordered_qty);
                $factor = Quantity::normalize($item->conversion_factor ?? '1.000');
                if (Quantity::compare($factor, '0.000') <= 0) {
                    $factor = '1.000';
                }
                $baseQty = Quantity::mul($uomQty, $factor);

                $outletInv = OutletInventory::query()->withoutGlobalScopes()
                    ->where('outlet_id', $outlet->id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                $before = $outletInv ? Quantity::normalize($outletInv->quantity) : '0.000';
                $after = Quantity::sub($before, $baseQty);

                if (! $outlet->allow_negative_stock && $item->variant->product->track_stock && $item->variant->product->product_type !== 'non_inventory' && Quantity::isNegative($after)) {
                    throw new BusinessRuleException(
                        'INSUFFICIENT_OUTLET_STOCK',
                        sprintf('Insufficient stock for SKU %s during fulfillment.', $item->sku),
                        422,
                    );
                }

                if ($outletInv !== null) {
                    $outletInv->update([
                        'quantity' => $after,
                        'version' => $outletInv->version + 1,
                    ]);
                }

                $whInv = WarehouseInventory::query()->withoutGlobalScopes()
                    ->where('warehouse_id', $salesOrder->warehouse_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($whInv !== null) {
                    $resAfter = Quantity::maxZero(Quantity::sub($whInv->reserved_quantity, $baseQty));
                    $whInv->update([
                        'quantity' => $after,
                        'reserved_quantity' => $resAfter,
                    ]);
                }

                // Allocate FEFO batch if tracked
                $allocatedBatchId = $item->batch_id ? (int) $item->batch_id : null;
                if ($allocatedBatchId !== null || $item->variant->product->track_batch || $item->variant->product->track_expiry) {
                    $batch = $this->inventoryEngine->allocateBatchFefo(
                        organizationId: (int) $user->organization_id,
                        warehouseId: (int) $salesOrder->warehouse_id,
                        variant: $item->variant,
                        requiredBaseQty: $baseQty,
                        preferredBatchId: $allocatedBatchId,
                        deductFromBatch: true,
                    );
                    if ($batch !== null) {
                        $allocatedBatchId = $batch->id;
                    }
                }

                $baseUnitCost = bcdiv((string) ($item->unit_cost ?? '0.00'), $factor, 2);

                $this->inventoryEngine->recordLedgerMovement(
                    organizationId: (int) $user->organization_id,
                    outletId: $outlet->id,
                    warehouseId: (int) $salesOrder->warehouse_id,
                    productId: (int) $item->product_id,
                    productVariantId: (int) $item->product_variant_id,
                    type: StockMovementType::SalesOrder,
                    quantityChange: Quantity::negate($baseQty),
                    quantityBefore: $before,
                    quantityAfter: $after,
                    referenceType: 'SalesOrder',
                    referenceId: $salesOrder->id,
                    userId: $user->id,
                    notes: sprintf('B2B Sales Order %s (%s)', $salesOrder->so_number, $salesOrder->customer->name),
                    batchId: $allocatedBatchId,
                    uomCode: (string) ($item->uom_code ?? $item->variant->product->unit ?? 'PCS'),
                    uomQuantity: $uomQty,
                    conversionFactor: $factor,
                    unitCost: $baseUnitCost,
                );

                $item->update([
                    'fulfilled_qty' => $uomQty,
                    'batch_id' => $allocatedBatchId,
                ]);
            }

            // Mark StockReservations as consumed
            $this->inventoryEngine->consumeReservations('SalesOrder', $salesOrder->id);

            // 2. Create Delivery Record
            $deliveryNumber = sprintf('DO-%s-%s-%04d', $outlet->code, now()->format('Ymd'), random_int(1, 9999));
            $delivery = Delivery::query()->create([
                'organization_id' => $user->organization_id,
                'outlet_id' => $outlet->id,
                'warehouse_id' => $salesOrder->warehouse_id,
                'sales_order_id' => $salesOrder->id,
                'delivery_number' => $deliveryNumber,
                'status' => 'delivered',
                'driver_name' => $validated['driver_name'] ?? 'Kurir Distribusi Internal',
                'vehicle_number' => $validated['vehicle_number'] ?? 'B 9128 SBT',
                'recipient_name' => $validated['recipient_name'] ?? $salesOrder->customer->name,
                'delivery_address' => $salesOrder->customer->address ?? $outlet->address,
                'dispatched_at' => now(),
                'delivered_at' => now(),
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            // 3. Issue Invoice & Update Accounts Receivable
            $isImmediatePaid = in_array($salesOrder->payment_type, ['cash', 'transfer', 'qris'], true);
            $invoiceNumber = sprintf('INV-%s-%s-%04d', $outlet->code, now()->format('Ymd'), random_int(1, 9999));
            $activeShiftCheckin = $this->context->employeeShiftCheckin();

            $invoice = Invoice::query()->create([
                'organization_id' => $user->organization_id,
                'outlet_id' => $outlet->id,
                'customer_id' => $salesOrder->customer_id,
                'sales_order_id' => $salesOrder->id,
                'invoice_number' => $invoiceNumber,
                'status' => $isImmediatePaid ? 'paid' : 'unpaid',
                'issue_date' => now()->toDateString(),
                'due_date' => $salesOrder->due_date?->toDateString() ?? now()->addDays(max(1, $salesOrder->payment_term_days))->toDateString(),
                'subtotal' => $salesOrder->subtotal,
                'discount_total' => $salesOrder->discount_total,
                'tax_total' => $salesOrder->tax_total,
                'grand_total' => $salesOrder->grand_total,
                'paid_total' => $isImmediatePaid ? $salesOrder->grand_total : '0.00',
                'balance_due' => $isImmediatePaid ? '0.00' : $salesOrder->grand_total,
                'notes' => sprintf('Tagihan Sales Order %s', $salesOrder->so_number),
                'created_by' => $user->id,
                'shift_id' => $activeShiftCheckin?->shift_id,
                'employee_shift_checkin_id' => $activeShiftCheckin?->id,
                'work_date' => $activeShiftCheckin?->work_date ?? now()->toDateString(),
            ]);

            if ($isImmediatePaid) {
                $payment = CustomerPayment::query()->create([
                    'organization_id' => $user->organization_id,
                    'outlet_id' => $outlet->id,
                    'customer_id' => $salesOrder->customer_id,
                    'payment_number' => sprintf('PAY-%s-%s-%04d', $outlet->code, now()->format('Ymd'), random_int(1, 9999)),
                    'method' => $salesOrder->payment_type === 'transfer' ? 'bank_transfer' : $salesOrder->payment_type,
                    'amount' => $salesOrder->grand_total,
                    'allocated_amount' => $salesOrder->grand_total,
                    'unallocated_amount' => '0.00',
                    'reference_number' => $salesOrder->so_number,
                    'payment_date' => now()->toDateString(),
                    'notes' => 'Direct settlement on B2B Sales Order fulfillment',
                    'received_by' => $user->id,
                    'shift_id' => $activeShiftCheckin?->shift_id,
                    'employee_shift_checkin_id' => $activeShiftCheckin?->id,
                    'work_date' => $activeShiftCheckin?->work_date ?? now()->toDateString(),
                ]);

                PaymentAllocation::query()->create([
                    'customer_payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $salesOrder->grand_total,
                ]);
            } else {
                // Tempo credit sale: increase customer's outstanding AR balance
                $customer = Customer::query()->withoutGlobalScopes()
                    ->lockForUpdate()
                    ->findOrFail($salesOrder->customer_id);

                $newBalance = round((float) $customer->outstanding_balance + (float) $invoice->balance_due, 2);
                $customer->update([
                    'outstanding_balance' => number_format($newBalance, 2, '.', ''),
                ]);
            }

            $salesOrder->update([
                'status' => 'invoiced',
                'delivered_at' => now(),
                'updated_by' => $user->id,
            ]);

            $this->audit->record(
                AuditAction::SALES_ORDER_FULFILLED,
                $salesOrder,
                newValues: [
                    'so_number' => $salesOrder->so_number,
                    'delivery_number' => $delivery->delivery_number,
                    'invoice_number' => $invoice->invoice_number,
                    'outlet_id' => $outlet->id,
                    'invoice_status' => $invoice->status,
                    'balance_due' => $invoice->balance_due,
                ],
                actor: $user,
            );

            $this->audit->record(
                AuditAction::INVOICE_ISSUED,
                $invoice,
                newValues: [
                    'invoice_number' => $invoice->invoice_number,
                    'outlet_id' => $outlet->id,
                    'customer_id' => $invoice->customer_id,
                    'grand_total' => $invoice->grand_total,
                    'balance_due' => $invoice->balance_due,
                ],
                actor: $user,
            );

            $this->activityTracker->recordActivity(
                user: $user,
                outlet: $outlet,
                moduleName: 'invoices',
                actionType: 'create',
                entityType: 'Invoice',
                entityId: $invoice->id,
                description: sprintf(
                    'Penerbitan Invoice %s untuk Sales Order %s senilai Rp %s (%s).',
                    $invoice->invoice_number,
                    $salesOrder->so_number,
                    number_format((float) $invoice->grand_total, 0, ',', '.'),
                    $invoice->status,
                ),
                oldValues: null,
                newValues: ['invoice_number' => $invoice->invoice_number, 'grand_total' => $invoice->grand_total, 'status' => $invoice->status],
                shiftCheckin: $activeShiftCheckin,
            );

            return response()->json([
                'sales_order' => $salesOrder->fresh(['customer', 'warehouse', 'outlet', 'items', 'deliveries', 'invoice']),
                'delivery' => $delivery,
                'invoice' => $invoice,
            ]);
        });
    }

    /**
     * Process a B2B Sales Return against an invoiced Sales Order.
     */
    public function storeSalesReturn(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();
        $warehouse = $this->context->requireWarehouse();

        $validated = $request->validate([
            'sales_order_id' => ['required', 'integer', 'exists:sales_orders,id'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.condition' => ['nullable', 'string', 'in:good,damaged,expired'],
        ]);

        $salesOrder = SalesOrder::query()
            ->with(['customer', 'invoice', 'items'])
            ->where('outlet_id', $outlet->id)
            ->findOrFail((int) $validated['sales_order_id']);

        return DB::transaction(function () use ($user, $outlet, $warehouse, $salesOrder, $validated): JsonResponse {
            $returnNumber = sprintf('SR-%s-%s-%04d', $outlet->code, now()->format('Ymd'), random_int(1, 9999));
            $totalAmount = 0.0;
            $itemPayloads = [];

            foreach ($validated['items'] as $row) {
                $variant = ProductVariant::query()->withoutGlobalScopes()
                    ->with('product')
                    ->findOrFail((int) $row['product_variant_id']);

                $qty = Quantity::normalize($row['quantity']);
                $unitPrice = (float) $row['unit_price'];
                $condition = $row['condition'] ?? 'good';
                $lineTotal = round((float) $qty * $unitPrice, 2);
                $totalAmount += $lineTotal;

                $itemPayloads[] = [
                    '_variant' => $variant,
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'uom_code' => $variant->product->unit ?? 'PCS',
                    'quantity' => $qty,
                    'unit_price' => number_format($unitPrice, 2, '.', ''),
                    'line_total' => number_format($lineTotal, 2, '.', ''),
                    'condition' => $condition,
                ];
            }

            $salesReturn = SalesReturn::query()->create([
                'organization_id' => $user->organization_id,
                'outlet_id' => $outlet->id,
                'warehouse_id' => $warehouse->id,
                'customer_id' => $salesOrder->customer_id,
                'sales_order_id' => $salesOrder->id,
                'invoice_id' => $salesOrder->invoice?->id,
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

                SalesReturnItem::query()->create(array_merge($payload, [
                    'sales_return_id' => $salesReturn->id,
                ]));

                $whInv = WarehouseInventory::query()->withoutGlobalScopes()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first();

                $outletInv = OutletInventory::query()->withoutGlobalScopes()
                    ->where('outlet_id', $outlet->id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first();

                $beforeQty = $outletInv ? Quantity::normalize($outletInv->quantity) : '0.000';
                $afterQty = $payload['condition'] === 'good'
                    ? Quantity::add($beforeQty, $payload['quantity'])
                    : $beforeQty;

                if ($outletInv !== null && $payload['condition'] === 'good') {
                    $outletInv->update([
                        'quantity' => $afterQty,
                        'version' => $outletInv->version + 1,
                    ]);
                }

                if ($whInv !== null) {
                    if ($payload['condition'] === 'good') {
                        $whInv->update(['quantity' => Quantity::add($whInv->quantity, $payload['quantity'])]);
                    } else {
                        $whInv->update(['damaged_quantity' => Quantity::add($whInv->damaged_quantity, $payload['quantity'])]);
                    }
                }

                $this->inventoryEngine->recordLedgerMovement(
                    organizationId: (int) $user->organization_id,
                    outletId: $outlet->id,
                    warehouseId: $warehouse->id,
                    productId: $variant->product_id,
                    productVariantId: $variant->id,
                    type: StockMovementType::SalesReturn,
                    quantityChange: $payload['quantity'],
                    quantityBefore: $beforeQty,
                    quantityAfter: $afterQty,
                    referenceType: 'SalesReturn',
                    referenceId: $salesReturn->id,
                    userId: $user->id,
                    notes: sprintf('Sales Return %s (%s - %s)', $returnNumber, $salesOrder->so_number, $payload['condition']),
                    uomCode: $payload['uom_code'],
                    uomQuantity: $payload['quantity'],
                );
            }

            // Credit Invoice & Customer AR if unpaid balance exists
            if ($salesOrder->invoice !== null && (float) $salesOrder->invoice->balance_due > 0) {
                $creditApplied = min((float) $salesOrder->invoice->balance_due, $totalAmount);
                $newBalanceDue = max(0.0, round((float) $salesOrder->invoice->balance_due - $creditApplied, 2));
                $salesOrder->invoice->update([
                    'balance_due' => number_format($newBalanceDue, 2, '.', ''),
                    'status' => $newBalanceDue <= 0 ? 'paid' : 'partial',
                ]);

                $customer = Customer::query()->withoutGlobalScopes()
                    ->lockForUpdate()
                    ->find($salesOrder->customer_id);
                if ($customer !== null) {
                    $newAr = max(0.0, round((float) $customer->outstanding_balance - $creditApplied, 2));
                    $customer->update(['outstanding_balance' => number_format($newAr, 2, '.', '')]);
                }
            }

            $this->audit->record(
                AuditAction::SALES_RETURN_PROCESSED,
                $salesReturn,
                newValues: [
                    'return_number' => $salesReturn->return_number,
                    'so_number' => $salesOrder->so_number,
                    'total_amount' => $salesReturn->total_amount,
                ],
                actor: $user,
            );

            return response()->json([
                'sales_return' => $salesReturn->load(['customer', 'salesOrder', 'items']),
            ], 201);
        });
    }
}
