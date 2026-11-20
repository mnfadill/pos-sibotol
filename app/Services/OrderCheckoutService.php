<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProductModifier;
use App\Models\ProductOutletSetting;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Outbox\OutboxService;
use App\Support\Money;
use App\Support\PosContext;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OrderCheckoutService
{
    public function __construct(
        private readonly PosContext $context,
        private readonly SequenceService $sequences,
        private readonly InventoryService $inventory,
        private readonly SupervisorAuthService $supervisorAuth,
        private readonly QrisPayloadService $qris,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
        private readonly PricingAndCostService $pricing,
    ) {}

    /**
     * Calculate cart totals, resolve outlet-specific prices & modifiers, and optionally verify supervisor overrides.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function calculateCart(array $payload, bool $verifySupervisorOverrides = false): array
    {
        $outlet = $this->context->requireOutlet();
        /** @var list<array<string, mixed>> $rawItems */
        $rawItems = $payload['items'] ?? [];

        if ($rawItems === []) {
            throw new BusinessRuleException('EMPTY_CART', 'Order must contain at least one item.', 422);
        }

        $customer = null;
        if (! empty($payload['customer_id'])) {
            $customer = Customer::query()->withoutGlobalScopes()
                ->where('organization_id', $outlet->organization_id)
                ->find((int) $payload['customer_id']);
        }

        $customerTier = isset($payload['customer_tier']) && trim((string) $payload['customer_tier']) !== ''
            ? trim((string) $payload['customer_tier'])
            : null;

        $variantIds = array_values(array_unique(array_map(
            static fn (array $item): int => (int) $item['product_variant_id'],
            $rawItems,
        )));

        $variants = ProductVariant::query()
            ->with(['product.modifiers'])
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');

        $outletSettings = ProductOutletSetting::query()->withoutGlobalScopes()
            ->where('outlet_id', $outlet->id)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->keyBy('product_variant_id');

        $supervisorPin = isset($payload['supervisor_pin']) ? trim((string) $payload['supervisor_pin']) : '';
        $authorizedSupervisor = null;

        $resolvedItems = [];
        $priceOverrideLogs = [];
        $subtotalMinor = 0;

        foreach ($rawItems as $index => $line) {
            $variantId = (int) $line['product_variant_id'];
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($variantId);

            if ($variant === null || ! $variant->is_active || ! $variant->product->is_active) {
                throw new BusinessRuleException(
                    'PRODUCT_UNAVAILABLE',
                    sprintf('Item #%d is inactive or does not exist.', $index + 1),
                    422,
                );
            }

            /** @var ProductOutletSetting|null $setting */
            $setting = $outletSettings->get($variantId);
            if ($setting !== null && ! $setting->is_available) {
                throw new BusinessRuleException(
                    'PRODUCT_OUT_OF_STOCK_OUTLET',
                    sprintf('"%s (%s)" is marked Out of Stock for outlet %s.', $variant->product->name, $variant->name, $outlet->name),
                    422,
                );
            }

            $qty = Quantity::normalize($line['quantity'] ?? 1);
            if (Quantity::compare($qty, '0.000') <= 0) {
                throw new BusinessRuleException('INVALID_QUANTITY', 'Item quantity must be greater than zero.', 422);
            }

            $productUnitId = isset($line['product_unit_id']) && $line['product_unit_id'] !== null && $line['product_unit_id'] !== ''
                ? (int) $line['product_unit_id']
                : null;
            $uomCodeReq = isset($line['uom_code']) ? trim((string) $line['uom_code']) : null;

            $uomResolution = $this->pricing->resolveUnitConversion(
                variant: $variant,
                productUnitId: $productUnitId,
                uomCode: $uomCodeReq,
                uomQty: $qty,
                lockUnit: $verifySupervisorOverrides,
            );

            $resolvedPriceInfo = $this->pricing->resolveSellingPrice(
                variant: $variant,
                outlet: $outlet,
                customer: $customer,
                productUnit: $uomResolution['product_unit'],
                uomQty: $qty,
                customerTier: $customerTier,
            );

            $effectiveUnitPriceDecimal = $resolvedPriceInfo['unit_price'];
            $originalUnitMinor = Money::toMinor($effectiveUnitPriceDecimal);
            $unitMinor = $originalUnitMinor;
            $priceOverrideBy = null;
            $priceOverrideReason = null;

            if (isset($line['unit_price_override']) && $line['unit_price_override'] !== null && $line['unit_price_override'] !== '') {
                $overrideMinor = Money::toMinor($line['unit_price_override']);
                if ($overrideMinor < 0) {
                    throw new BusinessRuleException('INVALID_PRICE_OVERRIDE', 'Price override cannot be negative.', 422);
                }

                if ($overrideMinor !== $originalUnitMinor) {
                    $unitMinor = $overrideMinor;
                    $priceOverrideReason = trim((string) ($line['price_override_reason'] ?? 'Manual price override'));

                    if ($verifySupervisorOverrides) {
                        $authorizedSupervisor ??= $this->supervisorAuth->authorizeByPin(
                            $supervisorPin,
                            sprintf('Price override on %s (%s)', $variant->product->name, $priceOverrideReason),
                            $outlet->id,
                            Permission::OverridesAuthorize,
                        );
                        $priceOverrideBy = $authorizedSupervisor->id;
                        $priceOverrideLogs[] = [
                            'product_variant_id' => $variant->id,
                            'sku' => $variant->sku,
                            'product_name' => $variant->product->name.' - '.$variant->name,
                            'original_unit_price' => Money::toDecimal($originalUnitMinor),
                            'override_unit_price' => Money::toDecimal($unitMinor),
                            'reason' => $priceOverrideReason,
                            'authorized_by_id' => $authorizedSupervisor->id,
                            'authorized_by_name' => $authorizedSupervisor->name,
                        ];
                    }
                }
            }

            /** @var list<int> $modifierIds */
            $modifierIds = array_values(array_unique(array_map('intval', $line['modifier_ids'] ?? [])));
            $productModifiers = $variant->product->modifiers->where('is_active', true)->keyBy('id');

            $modifiersSnapshot = [];
            $modifiersMinor = 0;

            foreach ($modifierIds as $modId) {
                /** @var ProductModifier|null $mod */
                $mod = $productModifiers->get($modId);
                if ($mod === null) {
                    throw new BusinessRuleException(
                        'INVALID_MODIFIER',
                        sprintf('Modifier #%d does not belong to "%s".', $modId, $variant->product->name),
                        422,
                    );
                }

                $modMinor = Money::toMinor($mod->price);
                $modifiersMinor += $modMinor;
                $modifiersSnapshot[] = [
                    'id' => $mod->id,
                    'group_name' => $mod->group_name,
                    'name' => $mod->name,
                    'price' => Money::toDecimal($modMinor),
                ];
            }

            $perUnitTotalMinor = $unitMinor + $modifiersMinor;
            $lineTotalMinor = Money::multiply($perUnitTotalMinor, $qty);
            $subtotalMinor += $lineTotalMinor;

            $resolvedItems[] = [
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'batch_id' => isset($line['batch_id']) && $line['batch_id'] !== null ? (int) $line['batch_id'] : null,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'sku' => $variant->sku,
                'product_unit_id' => $uomResolution['product_unit']?->id,
                'uom_code' => $uomResolution['uom_code'],
                'uom_quantity' => $uomResolution['uom_quantity'],
                'conversion_factor' => $uomResolution['conversion_factor'],
                'base_quantity' => $uomResolution['base_quantity'],
                'quantity' => $qty,
                'original_unit_price' => Money::toDecimal($originalUnitMinor),
                'unit_price' => Money::toDecimal($unitMinor),
                'unit_cost' => $resolvedPriceInfo['unit_cost'],
                'price_override_by' => $priceOverrideBy,
                'price_override_reason' => $priceOverrideReason,
                'modifiers' => $modifiersSnapshot,
                'modifiers_total' => Money::toDecimal($modifiersMinor),
                'line_total' => Money::toDecimal($lineTotalMinor),
                'notes' => isset($line['notes']) ? trim((string) $line['notes']) : null,
            ];
        }

        // Order-level discount calculation
        $discountType = $payload['discount_type'] ?? null;
        $discountValueRaw = $payload['discount_value'] ?? 0;
        $discountReason = isset($payload['discount_reason']) ? trim((string) $payload['discount_reason']) : null;
        $discountValueDecimal = '0.00';
        $discountTotalMinor = 0;
        $discountAuthorizedBy = null;
        $discountOverrideLog = null;

        if ($discountType !== null && $discountType !== '') {
            if (! in_array($discountType, ['percent', 'fixed'], true)) {
                throw new BusinessRuleException('INVALID_DISCOUNT_TYPE', 'Discount type must be percent or fixed.', 422);
            }

            if ($discountType === 'percent') {
                $percent = bcadd((string) $discountValueRaw, '0', 2);
                if (bccomp($percent, '0', 2) < 0 || bccomp($percent, '100', 2) > 0) {
                    throw new BusinessRuleException('INVALID_DISCOUNT_PERCENT', 'Discount percentage must be between 0 and 100.', 422);
                }
                $discountValueDecimal = $percent;
                $discountTotalMinor = Money::percentOf($subtotalMinor, $percent);
            } else {
                $fixedMinor = Money::toMinor($discountValueRaw);
                if ($fixedMinor < 0 || $fixedMinor > $subtotalMinor) {
                    throw new BusinessRuleException('INVALID_DISCOUNT_AMOUNT', 'Fixed discount cannot be negative or exceed subtotal.', 422);
                }
                $discountValueDecimal = Money::toDecimal($fixedMinor);
                $discountTotalMinor = $fixedMinor;
            }
        }

        $requiresSupervisorForDiscount = false;
        if ($discountTotalMinor > 0 && $subtotalMinor > 0) {
            $effectivePercent = bcdiv(bcmul((string) $discountTotalMinor, '100', 4), (string) $subtotalMinor, 2);
            if (bccomp($effectivePercent, (string) $outlet->max_cashier_discount_percent, 2) > 0) {
                $requiresSupervisorForDiscount = true;

                if ($verifySupervisorOverrides) {
                    $authorizedSupervisor ??= $this->supervisorAuth->authorizeByPin(
                        $supervisorPin,
                        sprintf('Order discount override (%s%%): %s', $effectivePercent, $discountReason ?? 'Manual discount'),
                        $outlet->id,
                        Permission::OverridesAuthorize,
                    );
                    $discountAuthorizedBy = $authorizedSupervisor->id;
                    $discountOverrideLog = [
                        'discount_type' => $discountType,
                        'discount_value' => $discountValueDecimal,
                        'discount_total' => Money::toDecimal($discountTotalMinor),
                        'effective_percent' => $effectivePercent,
                        'outlet_threshold_percent' => (string) $outlet->max_cashier_discount_percent,
                        'discount_reason' => $discountReason,
                        'authorized_by_id' => $authorizedSupervisor->id,
                        'authorized_by_name' => $authorizedSupervisor->name,
                    ];
                }
            }
        }

        $netAfterDiscountMinor = max(0, $subtotalMinor - $discountTotalMinor);
        $serviceChargeRate = (string) $outlet->service_charge_rate;
        $serviceChargeMinor = Money::percentOf($netAfterDiscountMinor, $serviceChargeRate);

        $taxableBaseMinor = $netAfterDiscountMinor + $serviceChargeMinor;
        $taxRate = (string) $outlet->tax_rate;
        $taxMinor = Money::percentOf($taxableBaseMinor, $taxRate);

        $grandTotalMinor = $taxableBaseMinor + $taxMinor;

        return [
            'items' => $resolvedItems,
            'subtotal_minor' => $subtotalMinor,
            'subtotal' => Money::toDecimal($subtotalMinor),
            'discount_type' => $discountTotalMinor > 0 ? $discountType : null,
            'discount_value' => $discountValueDecimal,
            'discount_total_minor' => $discountTotalMinor,
            'discount_total' => Money::toDecimal($discountTotalMinor),
            'discount_reason' => $discountReason,
            'discount_authorized_by' => $discountAuthorizedBy,
            'requires_supervisor_pin' => $requiresSupervisorForDiscount || $priceOverrideLogs !== [],
            'service_charge_rate' => $serviceChargeRate,
            'service_charge_total_minor' => $serviceChargeMinor,
            'service_charge_total' => Money::toDecimal($serviceChargeMinor),
            'tax_rate' => $taxRate,
            'tax_total_minor' => $taxMinor,
            'tax_total' => Money::toDecimal($taxMinor),
            'grand_total_minor' => $grandTotalMinor,
            'grand_total' => Money::toDecimal($grandTotalMinor),
            '_price_override_logs' => $priceOverrideLogs,
            '_discount_override_log' => $discountOverrideLog,
        ];
    }

    /**
     * Hold (park) an order as a draft so it can be resumed on any terminal in the same outlet.
     *
     * @param  array<string, mixed>  $payload
     */
    public function holdOrder(array $payload): Order
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();
        $terminal = $this->context->requireTerminal();
        $shift = $this->context->requireShift();

        $calc = $this->calculateCart($payload, verifySupervisorOverrides: false);

        return DB::transaction(function () use ($payload, $calc, $user, $outlet, $terminal, $shift): Order {
            $now = CarbonImmutable::now();
            $existingOrderId = isset($payload['order_id']) ? (int) $payload['order_id'] : null;
            $tierRef = isset($payload['customer_tier']) && trim((string) $payload['customer_tier']) !== ''
                ? 'tier_' . strtolower(trim((string) $payload['customer_tier']))
                : null;
            $defaultCustomerName = $tierRef ? 'Customer ' . strtoupper(trim((string) $payload['customer_tier'])) : null;

            if ($existingOrderId !== null && $existingOrderId > 0) {
                $order = Order::query()
                    ->where('outlet_id', $outlet->id)
                    ->lockForUpdate()
                    ->findOrFail($existingOrderId);

                if ($order->status !== OrderStatus::Held) {
                    throw new BusinessRuleException('ORDER_NOT_HELD', 'Only held draft orders can be updated.', 409);
                }

                $order->items()->delete();
                $order->update([
                    'pos_terminal_id' => $terminal->id,
                    'cashier_shift_id' => $shift->id,
                    'customer_name' => ! empty($payload['customer_name']) ? $payload['customer_name'] : ($order->customer_name ?: $defaultCustomerName),
                    'customer_phone' => $payload['customer_phone'] ?? $order->customer_phone,
                    'crm_customer_ref' => $payload['crm_customer_ref'] ?? ($order->crm_customer_ref ?: $tierRef),
                    'table_label' => $payload['table_label'] ?? $order->table_label,
                    'subtotal' => $calc['subtotal'],
                    'discount_type' => $calc['discount_type'],
                    'discount_value' => $calc['discount_value'],
                    'discount_total' => $calc['discount_total'],
                    'discount_reason' => $calc['discount_reason'],
                    'service_charge_rate' => $calc['service_charge_rate'],
                    'service_charge_total' => $calc['service_charge_total'],
                    'tax_rate' => $calc['tax_rate'],
                    'tax_total' => $calc['tax_total'],
                    'grand_total' => $calc['grand_total'],
                    'notes' => $payload['notes'] ?? $order->notes,
                    'held_at' => $now,
                    'locked_by_terminal_id' => null,
                    'locked_at' => null,
                ]);
            } else {
                $orderNumber = $this->sequences->next($outlet, 'ORD');
                $order = Order::query()->create([
                    'organization_id' => $outlet->organization_id,
                    'outlet_id' => $outlet->id,
                    'pos_terminal_id' => $terminal->id,
                    'cashier_shift_id' => $shift->id,
                    'user_id' => $user->id,
                    'order_number' => $orderNumber,
                    'status' => OrderStatus::Held,
                    'customer_name' => ! empty($payload['customer_name']) ? $payload['customer_name'] : $defaultCustomerName,
                    'customer_phone' => $payload['customer_phone'] ?? null,
                    'crm_customer_ref' => $payload['crm_customer_ref'] ?? $tierRef,
                    'table_label' => $payload['table_label'] ?? null,
                    'subtotal' => $calc['subtotal'],
                    'discount_type' => $calc['discount_type'],
                    'discount_value' => $calc['discount_value'],
                    'discount_total' => $calc['discount_total'],
                    'discount_reason' => $calc['discount_reason'],
                    'service_charge_rate' => $calc['service_charge_rate'],
                    'service_charge_total' => $calc['service_charge_total'],
                    'tax_rate' => $calc['tax_rate'],
                    'tax_total' => $calc['tax_total'],
                    'grand_total' => $calc['grand_total'],
                    'notes' => $payload['notes'] ?? null,
                    'held_at' => $now,
                ]);
            }

            foreach ($calc['items'] as $itemData) {
                $order->items()->create($itemData);
            }

            $this->audit->record(
                AuditAction::ORDER_HELD,
                $order,
                oldValues: null,
                newValues: [
                    'order_number' => $order->order_number,
                    'table_label' => $order->table_label,
                    'customer_name' => $order->customer_name,
                    'grand_total' => $order->grand_total,
                    'item_count' => count($calc['items']),
                ],
            );

            return $order->load(['items', 'cashier', 'terminal']);
        });
    }

    /**
     * Resume a held order on the active terminal (supports cross-terminal handoff within the same outlet).
     */
    public function resumeOrder(Order $order): Order
    {
        $outlet = $this->context->requireOutlet();
        $terminal = $this->context->requireTerminal();

        return DB::transaction(function () use ($order, $outlet, $terminal): Order {
            $locked = Order::query()
                ->where('outlet_id', $outlet->id)
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($locked->status !== OrderStatus::Held) {
                throw new BusinessRuleException(
                    'ORDER_NOT_HELD',
                    sprintf('Order %s cannot be resumed because its status is %s.', $locked->order_number, $locked->status->value),
                    409,
                );
            }

            $previousTerminalId = $locked->pos_terminal_id;
            $previousLockedBy = $locked->locked_by_terminal_id;

            $locked->update([
                'locked_by_terminal_id' => $terminal->id,
                'locked_at' => CarbonImmutable::now(),
            ]);

            $this->audit->record(
                AuditAction::ORDER_RESUMED,
                $locked,
                oldValues: [
                    'created_terminal_id' => $previousTerminalId,
                    'locked_by_terminal_id' => $previousLockedBy,
                ],
                newValues: [
                    'order_number' => $locked->order_number,
                    'resumed_on_terminal_id' => $terminal->id,
                    'resumed_on_terminal_code' => $terminal->code,
                ],
            );

            return $locked->load(['items', 'cashier', 'terminal']);
        });
    }

    /**
     * Atomic Checkout & Split Payment Engine.
     *
     * Executes inside DB::transaction():
     *  1. Validates cart, outlet pricing, discounts, and supervisor overrides
     *  2. Validates single or split payment legs (Cash + Digital)
     *  3. Persists Order + OrderItems + Payments
     *  4. Atomically deducts stock (SELECT ... FOR UPDATE)
     *  5. Writes Audit Logs (ORDER_CREATED, PAYMENT_PROCESSED, plus override logs)
     *  6. Enqueues Transactional Outbox event (`order.completed`) for CRM sync
     *
     * @param  array<string, mixed>  $payload
     */
    public function checkout(array $payload, ?string $idempotencyKey = null): Order
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();
        $terminal = $this->context->requireTerminal();
        $shift = $this->context->requireShift();

        $calc = $this->calculateCart($payload, verifySupervisorOverrides: true);

        /** @var list<array<string, mixed>> $rawPayments */
        $rawPayments = $payload['payments'] ?? [];
        if ($rawPayments === []) {
            throw new BusinessRuleException('PAYMENT_REQUIRED', 'At least one payment leg is required.', 422);
        }

        $grandTotalMinor = $calc['grand_total_minor'];
        $resolvedPayments = $this->validateAndNormalizePayments($rawPayments, $grandTotalMinor);

        return DB::transaction(function () use (
            $payload,
            $calc,
            $resolvedPayments,
            $user,
            $outlet,
            $terminal,
            $shift,
            $idempotencyKey,
        ): Order {
            $now = CarbonImmutable::now();
            $existingOrderId = isset($payload['order_id']) ? (int) $payload['order_id'] : null;
            $customerId = ! empty($payload['customer_id']) ? (int) $payload['customer_id'] : null;
            $tierRef = isset($payload['customer_tier']) && trim((string) $payload['customer_tier']) !== ''
                ? 'tier_' . strtolower(trim((string) $payload['customer_tier']))
                : null;
            $defaultCustomerName = $tierRef ? 'Customer ' . strtoupper(trim((string) $payload['customer_tier'])) : null;
            $hasDebt = $resolvedPayments['debt_minor'] > 0;
            $paymentScheme = isset($payload['payment_scheme']) ? (string) $payload['payment_scheme'] : ($hasDebt ? ($resolvedPayments['applied_total_minor'] > 0 ? 'partial' : 'tempo') : 'full');
            if (in_array($paymentScheme, ['partial', 'tempo'], true) && ! $hasDebt && $resolvedPayments['applied_total_minor'] === 0) {
                $hasDebt = true;
            }

            if (($hasDebt || in_array($paymentScheme, ['partial', 'tempo'], true)) && empty($customerId) && empty(trim((string) ($payload['customer_name'] ?? '')))) {
                throw new BusinessRuleException(
                    'CUSTOMER_REQUIRED_FOR_DEBT',
                    'Untuk transaksi Partial Payment (DP) atau Credit / Tempo, pelanggan wajib dipilih terlebih dahulu.',
                    422,
                );
            }

            $paymentTerm = $hasDebt ? 'tempo' : 'cash';
            $requestedDueDate = ! empty($payload['due_date']) ? CarbonImmutable::parse((string) $payload['due_date'])->toDateString() : null;
            $dueDate = $hasDebt ? ($requestedDueDate ?? $now->addDays(14)->toDateString()) : null;

            if ($existingOrderId !== null && $existingOrderId > 0) {
                $order = Order::query()
                    ->where('outlet_id', $outlet->id)
                    ->lockForUpdate()
                    ->findOrFail($existingOrderId);

                if ($order->status !== OrderStatus::Held) {
                    throw new BusinessRuleException(
                        'ORDER_ALREADY_PROCESSED',
                        sprintf('Order %s has already been %s.', $order->order_number, $order->status->value),
                        409,
                    );
                }

                $order->items()->delete();
                $order->update([
                    'pos_terminal_id' => $terminal->id,
                    'cashier_shift_id' => $shift->id,
                    'user_id' => $user->id,
                    'customer_id' => $customerId ?? $order->customer_id,
                    'status' => OrderStatus::Completed,
                    'payment_term' => $paymentTerm,
                    'due_date' => $dueDate,
                    'customer_name' => ! empty($payload['customer_name']) ? $payload['customer_name'] : ($order->customer_name ?: $defaultCustomerName),
                    'customer_phone' => $payload['customer_phone'] ?? $order->customer_phone,
                    'crm_customer_ref' => $payload['crm_customer_ref'] ?? ($order->crm_customer_ref ?: $tierRef),
                    'table_label' => $payload['table_label'] ?? $order->table_label,
                    'subtotal' => $calc['subtotal'],
                    'discount_type' => $calc['discount_type'],
                    'discount_value' => $calc['discount_value'],
                    'discount_total' => $calc['discount_total'],
                    'discount_reason' => $calc['discount_reason'],
                    'discount_authorized_by' => $calc['discount_authorized_by'],
                    'service_charge_rate' => $calc['service_charge_rate'],
                    'service_charge_total' => $calc['service_charge_total'],
                    'tax_rate' => $calc['tax_rate'],
                    'tax_total' => $calc['tax_total'],
                    'grand_total' => $calc['grand_total'],
                    'paid_total' => $resolvedPayments['paid_total'],
                    'change_total' => $resolvedPayments['change_total'],
                    'notes' => $payload['notes'] ?? $order->notes,
                    'locked_by_terminal_id' => null,
                    'locked_at' => null,
                    'completed_at' => $now,
                ]);
            } else {
                $orderNumber = $this->sequences->next($outlet, 'INV');
                $order = Order::query()->create([
                    'organization_id' => $outlet->organization_id,
                    'outlet_id' => $outlet->id,
                    'pos_terminal_id' => $terminal->id,
                    'cashier_shift_id' => $shift->id,
                    'user_id' => $user->id,
                    'customer_id' => $customerId,
                    'order_number' => $orderNumber,
                    'status' => OrderStatus::Completed,
                    'payment_term' => $paymentTerm,
                    'due_date' => $dueDate,
                    'customer_name' => ! empty($payload['customer_name']) ? $payload['customer_name'] : $defaultCustomerName,
                    'customer_phone' => $payload['customer_phone'] ?? null,
                    'crm_customer_ref' => $payload['crm_customer_ref'] ?? $tierRef,
                    'table_label' => $payload['table_label'] ?? null,
                    'subtotal' => $calc['subtotal'],
                    'discount_type' => $calc['discount_type'],
                    'discount_value' => $calc['discount_value'],
                    'discount_total' => $calc['discount_total'],
                    'discount_reason' => $calc['discount_reason'],
                    'discount_authorized_by' => $calc['discount_authorized_by'],
                    'service_charge_rate' => $calc['service_charge_rate'],
                    'service_charge_total' => $calc['service_charge_total'],
                    'tax_rate' => $calc['tax_rate'],
                    'tax_total' => $calc['tax_total'],
                    'grand_total' => $calc['grand_total'],
                    'paid_total' => $resolvedPayments['paid_total'],
                    'change_total' => $resolvedPayments['change_total'],
                    'notes' => $payload['notes'] ?? null,
                    'completed_at' => $now,
                ]);
            }

            foreach ($calc['items'] as $itemData) {
                $order->items()->create($itemData);
            }

            $paymentSummaries = [];
            foreach ($resolvedPayments['legs'] as $legIndex => $leg) {
                if ($leg['amount_minor'] <= 0 && Money::toMinor($leg['tendered_amount']) <= 0) {
                    continue;
                }

                $method = $leg['method'];
                $qrisPayload = $leg['qris_payload'];

                if ($method === PaymentMethod::Qris && ($qrisPayload === null || $qrisPayload === '') && $leg['amount_minor'] > 0) {
                    $qrisPayload = $this->qris->generate(
                        $outlet,
                        $leg['amount_minor'],
                        $order->order_number.'-'.($legIndex + 1),
                    );
                }

                $payment = Payment::query()->create([
                    'order_id' => $order->id,
                    'outlet_id' => $outlet->id,
                    'cashier_shift_id' => $shift->id,
                    'user_id' => $user->id,
                    'method' => $method,
                    'status' => PaymentStatus::Completed,
                    'amount' => $leg['amount'],
                    'tendered_amount' => $leg['tendered_amount'],
                    'change_amount' => $leg['change_amount'],
                    'provider' => $leg['provider'],
                    'reference_number' => $leg['reference_number'],
                    'card_last4' => $leg['card_last4'],
                    'qris_payload' => $qrisPayload,
                    'idempotency_key' => $idempotencyKey,
                    'paid_at' => $now,
                ]);

                $paymentSummaries[] = [
                    'payment_uuid' => $payment->uuid,
                    'method' => $payment->method->value,
                    'amount' => $payment->amount,
                    'tendered_amount' => $payment->tendered_amount,
                    'change_amount' => $payment->change_amount,
                    'provider' => $payment->provider,
                    'reference_number' => $payment->reference_number,
                ];
            }

            // Link customer & create Invoice record when customer is linked or when transaction has debt (DP / Tempo)
            $linkedCustomer = null;
            if ($order->customer_id) {
                $linkedCustomer = Customer::query()->withoutGlobalScopes()
                    ->where('organization_id', $outlet->organization_id)
                    ->lockForUpdate()
                    ->find($order->customer_id);
            }

            if ($hasDebt || $linkedCustomer !== null) {
                $resolvedCustomerName = trim((string) ($order->customer_name ?: 'Walk-in Pelanggan POS'));
                if ($linkedCustomer === null) {
                    $linkedCustomer = Customer::query()->withoutGlobalScopes()
                        ->where('organization_id', $outlet->organization_id)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($resolvedCustomerName)])
                        ->lockForUpdate()
                        ->first();
                }

                if ($linkedCustomer === null) {
                    $custCode = sprintf('CUST-POS-%s-%04d', $outlet->code, random_int(1, 9999));
                    $linkedCustomer = Customer::query()->withoutGlobalScopes()->create([
                        'organization_id' => $outlet->organization_id,
                        'outlet_id' => $outlet->id,
                        'code' => $custCode,
                        'name' => $resolvedCustomerName,
                        'company_name' => $resolvedCustomerName,
                        'phone' => $order->customer_phone,
                        'customer_group' => 'retailer',
                        'pricing_tier' => isset($payload['customer_tier']) ? (string) $payload['customer_tier'] : 'TIER_1',
                        'payment_term_days' => 14,
                        'credit_limit' => 50000000,
                        'outstanding_balance' => 0,
                        'is_active' => true,
                    ]);
                }

                if (! $order->customer_id) {
                    $order->update([
                        'customer_id' => $linkedCustomer->id,
                        'customer_name' => $linkedCustomer->name,
                        'customer_phone' => $linkedCustomer->phone ?: $order->customer_phone,
                    ]);
                }

                $termDays = max(1, (int) ($linkedCustomer->payment_term_days ?: 14));
                $invoiceDueDate = $requestedDueDate ?? ($hasDebt ? $now->addDays($termDays)->toDateString() : $now->toDateString());
                if ($hasDebt && $order->due_date !== $invoiceDueDate) {
                    $order->update(['due_date' => $invoiceDueDate]);
                }

                $invoiceStatus = ! $hasDebt
                    ? 'paid'
                    : ($resolvedPayments['applied_total_minor'] > 0 ? 'partial' : 'unpaid');

                $activeShiftCheckin = $this->context->employeeShiftCheckin();

                $createdInvoice = Invoice::query()->withoutGlobalScopes()->create([
                    'organization_id' => $outlet->organization_id,
                    'outlet_id' => $outlet->id,
                    'customer_id' => $linkedCustomer->id,
                    'order_id' => $order->id,
                    'invoice_number' => 'AR-' . $order->order_number,
                    'status' => $invoiceStatus,
                    'issue_date' => $now->toDateString(),
                    'due_date' => $invoiceDueDate,
                    'subtotal' => $order->subtotal,
                    'discount_total' => $order->discount_total,
                    'tax_total' => $order->tax_total,
                    'grand_total' => $order->grand_total,
                    'paid_total' => $resolvedPayments['applied_total'],
                    'balance_due' => $resolvedPayments['debt_total'],
                    'notes' => sprintf(
                        'Transaksi POS %s (%s). Total: Rp %s, Dibayar: Rp %s, Sisa Piutang: Rp %s',
                        $order->order_number,
                        $linkedCustomer->name,
                        number_format((float) $order->grand_total, 0, ',', '.'),
                        number_format((float) $resolvedPayments['applied_total'], 0, ',', '.'),
                        number_format((float) $resolvedPayments['debt_total'], 0, ',', '.'),
                    ),
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                    'shift_id' => $activeShiftCheckin?->shift_id,
                    'employee_shift_checkin_id' => $activeShiftCheckin?->id,
                    'work_date' => $activeShiftCheckin?->work_date ?? $now->toDateString(),
                ]);

                // Record each initial payment / DP as a separate CustomerPayment & PaymentAllocation record so installment history is never overwritten
                foreach ($resolvedPayments['legs'] as $legIdx => $leg) {
                    if ($leg['amount_minor'] <= 0) {
                        continue;
                    }
                    $arMethod = match ($leg['method']) {
                        PaymentMethod::Cash => 'cash',
                        PaymentMethod::Qris => 'qris',
                        default => 'bank_transfer',
                    };
                    $payNum = sprintf(
                        'PAY-%s-%s-%s-%d',
                        $outlet->code,
                        $now->format('Ymd'),
                        substr(preg_replace('/\D/', '', $order->order_number) ?: (string) $order->id, -4),
                        $legIdx + 1,
                    );
                    $custPayment = CustomerPayment::query()->withoutGlobalScopes()->create([
                        'organization_id' => $outlet->organization_id,
                        'outlet_id' => $outlet->id,
                        'customer_id' => $linkedCustomer->id,
                        'payment_number' => $payNum,
                        'method' => $arMethod,
                        'amount' => $leg['amount'],
                        'allocated_amount' => $leg['amount'],
                        'unallocated_amount' => '0.00',
                        'reference_number' => $leg['reference_number'] ?: $order->order_number,
                        'payment_date' => $now->toDateString(),
                        'notes' => $hasDebt
                            ? sprintf('Pembayaran Awal (DP) POS %s', $order->order_number)
                            : sprintf('Pembayaran Lunas POS %s', $order->order_number),
                        'received_by' => $user->id,
                        'shift_id' => $activeShiftCheckin?->shift_id,
                        'employee_shift_checkin_id' => $activeShiftCheckin?->id,
                        'work_date' => $activeShiftCheckin?->work_date ?? $now->toDateString(),
                    ]);

                    PaymentAllocation::query()->create([
                        'customer_payment_id' => $custPayment->id,
                        'invoice_id' => $createdInvoice->id,
                        'amount' => $leg['amount'],
                    ]);
                }

                if ($hasDebt) {
                    $newOutstanding = round((float) $linkedCustomer->outstanding_balance + (float) $resolvedPayments['debt_total'], 2);
                    $linkedCustomer->update([
                        'outstanding_balance' => number_format($newOutstanding, 2, '.', ''),
                    ]);
                }
            }

            // Atomic stock deduction (always reads & locks live DB rows; never uses cache)
            $this->inventory->deductForOrder($order, $outlet);
            \App\Services\PosCatalogCacheService::bumpStock((int) $outlet->id, $this->context->warehouse()?->id);

            // Audit supervisor overrides if any occurred
            foreach ($calc['_price_override_logs'] as $overrideLog) {
                $this->audit->record(
                    AuditAction::PRICE_OVERRIDE,
                    $order,
                    oldValues: ['unit_price' => $overrideLog['original_unit_price']],
                    newValues: $overrideLog,
                );
            }

            if ($calc['_discount_override_log'] !== null) {
                $this->audit->record(
                    AuditAction::DISCOUNT_OVERRIDE,
                    $order,
                    oldValues: ['max_cashier_discount_percent' => (string) $outlet->max_cashier_discount_percent],
                    newValues: $calc['_discount_override_log'],
                );
            }

            $this->audit->record(
                AuditAction::ORDER_CREATED,
                $order,
                oldValues: null,
                newValues: [
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                    'subtotal' => $order->subtotal,
                    'discount_total' => $order->discount_total,
                    'service_charge_total' => $order->service_charge_total,
                    'tax_total' => $order->tax_total,
                    'grand_total' => $order->grand_total,
                    'items' => array_map(static fn (array $i): array => [
                        'sku' => $i['sku'],
                        'name' => $i['product_name'].' - '.$i['variant_name'],
                        'quantity' => $i['quantity'],
                        'unit_price' => $i['unit_price'],
                        'line_total' => $i['line_total'],
                    ], $calc['items']),
                ],
            );

            $this->audit->record(
                AuditAction::PAYMENT_PROCESSED,
                $order,
                oldValues: null,
                newValues: [
                    'order_number' => $order->order_number,
                    'grand_total' => $order->grand_total,
                    'paid_total' => $order->paid_total,
                    'change_total' => $order->change_total,
                    'is_split_payment' => count($paymentSummaries) > 1,
                    'idempotency_key' => $idempotencyKey,
                    'payments' => $paymentSummaries,
                ],
            );

            // Transactional Outbox event for CRM sync
            $this->outbox->record('order.completed', $order, $order->uuid, [
                'order_uuid' => $order->uuid,
                'order_number' => $order->order_number,
                'outlet_id' => $outlet->id,
                'outlet_code' => $outlet->code,
                'pos_terminal_id' => $terminal->id,
                'cashier_shift_id' => $shift->id,
                'cashier_id' => $user->id,
                'cashier_name' => $user->name,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'crm_customer_ref' => $order->crm_customer_ref,
                'subtotal' => $order->subtotal,
                'discount_total' => $order->discount_total,
                'service_charge_total' => $order->service_charge_total,
                'tax_total' => $order->tax_total,
                'grand_total' => $order->grand_total,
                'paid_total' => $order->paid_total,
                'change_total' => $order->change_total,
                'completed_at' => $now->toIso8601String(),
                'items' => $calc['items'],
                'payments' => $paymentSummaries,
            ]);

            return $order->load(['items', 'payments', 'cashier', 'terminal', 'customer', 'invoice.allocations.customerPayment']);
        });
    }

    /**
     * Cancel a held (draft) order before payment.
     */
    public function cancelHeldOrder(Order $order, string $reason): Order
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();

        return DB::transaction(function () use ($order, $reason, $user, $outlet): Order {
            $locked = Order::query()
                ->where('outlet_id', $outlet->id)
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($locked->status !== OrderStatus::Held) {
                throw new BusinessRuleException(
                    'CANNOT_CANCEL_NON_HELD_ORDER',
                    'Only held draft orders can be cancelled. Completed orders must be voided or refunded.',
                    409,
                );
            }

            $oldStatus = $locked->status->value;
            $locked->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => CarbonImmutable::now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
                'locked_by_terminal_id' => null,
                'locked_at' => null,
            ]);

            $this->audit->record(
                AuditAction::ORDER_CANCELLED,
                $locked,
                oldValues: ['status' => $oldStatus],
                newValues: [
                    'order_number' => $locked->order_number,
                    'status' => OrderStatus::Cancelled->value,
                    'cancel_reason' => $reason,
                    'grand_total' => $locked->grand_total,
                ],
            );

            return $locked->load(['items', 'cashier']);
        });
    }

    /**
     * Void a completed transaction (requires Supervisor PIN, restores inventory, voids payments).
     */
    public function voidOrder(Order $order, string $reason, string $supervisorPin): Order
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();

        $supervisor = $this->supervisorAuth->authorizeByPin(
            $supervisorPin,
            sprintf('Void order %s: %s', $order->order_number, $reason),
            $outlet->id,
            Permission::OrdersVoid,
        );

        return DB::transaction(function () use ($order, $reason, $user, $outlet, $supervisor): Order {
            $locked = Order::query()
                ->where('outlet_id', $outlet->id)
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($locked->status !== OrderStatus::Completed) {
                throw new BusinessRuleException(
                    'ORDER_CANNOT_BE_VOIDED',
                    sprintf('Order %s has status "%s" and cannot be voided.', $locked->order_number, $locked->status->value),
                    409,
                );
            }

            $oldSnapshot = [
                'status' => $locked->status->value,
                'grand_total' => $locked->grand_total,
                'paid_total' => $locked->paid_total,
            ];

            $now = CarbonImmutable::now();

            $locked->update([
                'status' => OrderStatus::Voided,
                'voided_at' => $now,
                'voided_by' => $user->id,
                'void_authorized_by' => $supervisor->id,
                'void_reason' => $reason,
            ]);

            $locked->payments()->update([
                'status' => PaymentStatus::Voided->value,
            ]);

            $this->inventory->restoreForVoid($locked);

            $this->audit->record(
                AuditAction::TRANSACTION_VOIDED,
                $locked,
                oldValues: $oldSnapshot,
                newValues: [
                    'order_number' => $locked->order_number,
                    'status' => OrderStatus::Voided->value,
                    'void_reason' => $reason,
                    'voided_by_id' => $user->id,
                    'authorized_by_id' => $supervisor->id,
                    'authorized_by_name' => $supervisor->name,
                ],
            );

            $this->outbox->record('order.voided', $locked, $locked->uuid, [
                'order_uuid' => $locked->uuid,
                'order_number' => $locked->order_number,
                'void_reason' => $reason,
                'authorized_by_id' => $supervisor->id,
                'authorized_by_name' => $supervisor->name,
                'voided_at' => $now->toIso8601String(),
            ]);

            return $locked->load(['items', 'payments', 'cashier']);
        });
    }

    /**
     * Process a full or partial refund for a completed order (requires Supervisor PIN).
     *
     * @param  list<array{order_item_id: int, quantity: string|int|float}>  $items
     */
    public function refundOrder(
        Order $order,
        array $items,
        PaymentMethod $method,
        string $reason,
        bool $restock,
        string $supervisorPin,
    ): Refund {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();
        $terminal = $this->context->requireTerminal();
        $shift = $this->context->requireShift();

        $supervisor = $this->supervisorAuth->authorizeByPin(
            $supervisorPin,
            sprintf('Refund on order %s: %s', $order->order_number, $reason),
            $outlet->id,
            Permission::OrdersRefund,
        );

        return DB::transaction(function () use (
            $order,
            $items,
            $method,
            $reason,
            $restock,
            $user,
            $outlet,
            $terminal,
            $shift,
            $supervisor,
        ): Refund {
            $lockedOrder = Order::query()
                ->where('outlet_id', $outlet->id)
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (! $lockedOrder->status->isRefundable()) {
                throw new BusinessRuleException(
                    'ORDER_NOT_REFUNDABLE',
                    sprintf('Order %s (%s) cannot be refunded.', $lockedOrder->order_number, $lockedOrder->status->value),
                    409,
                );
            }

            $orderItemsById = $lockedOrder->items->keyBy('id');
            $refundAmountMinor = 0;
            $preparedLines = [];

            foreach ($items as $line) {
                $orderItemId = (int) $line['order_item_id'];
                /** @var OrderItem|null $orderItem */
                $orderItem = $orderItemsById->get($orderItemId);

                if ($orderItem === null) {
                    throw new BusinessRuleException('INVALID_REFUND_ITEM', 'Order item does not belong to this order.', 422);
                }

                $refundQty = Quantity::normalize($line['quantity']);
                $remainingQty = Quantity::sub($orderItem->quantity, $orderItem->refunded_quantity);

                if (Quantity::compare($refundQty, '0.000') <= 0 || Quantity::compare($refundQty, $remainingQty) > 0) {
                    throw new BusinessRuleException(
                        'REFUND_QUANTITY_EXCEEDED',
                        sprintf(
                            'Refund quantity (%s) exceeds refundable quantity (%s) for "%s".',
                            $refundQty,
                            $remainingQty,
                            $orderItem->product_name,
                        ),
                        422,
                    );
                }

                // Pro-rata line amount
                $perUnitMinor = Money::toMinor($orderItem->unit_price) + Money::toMinor($orderItem->modifiers_total);
                $lineRefundMinor = Money::multiply($perUnitMinor, $refundQty);
                $refundAmountMinor += $lineRefundMinor;

                $preparedLines[] = [
                    'order_item' => $orderItem,
                    'quantity' => $refundQty,
                    'amount_minor' => $lineRefundMinor,
                    'amount' => Money::toDecimal($lineRefundMinor),
                ];
            }

            // Cap refund amount to remaining refundable order total
            $maxRefundableMinor = Money::toMinor($lockedOrder->grand_total) - Money::toMinor($lockedOrder->refunded_total);
            $finalRefundMinor = min($refundAmountMinor, $maxRefundableMinor);

            if ($finalRefundMinor <= 0) {
                throw new BusinessRuleException('ZERO_REFUND_AMOUNT', 'Calculated refund amount must be greater than zero.', 422);
            }

            $refundNumber = $this->sequences->next($outlet, 'REF');

            $refund = Refund::query()->create([
                'order_id' => $lockedOrder->id,
                'outlet_id' => $outlet->id,
                'pos_terminal_id' => $terminal->id,
                'cashier_shift_id' => $shift->id,
                'user_id' => $user->id,
                'authorized_by' => $supervisor->id,
                'refund_number' => $refundNumber,
                'method' => $method,
                'amount' => Money::toDecimal($finalRefundMinor),
                'restock' => $restock,
                'reason' => $reason,
            ]);

            foreach ($preparedLines as $pl) {
                /** @var OrderItem $oi */
                $oi = $pl['order_item'];
                $oi->update([
                    'refunded_quantity' => Quantity::add($oi->refunded_quantity, $pl['quantity']),
                ]);

                RefundItem::query()->create([
                    'refund_id' => $refund->id,
                    'order_item_id' => $oi->id,
                    'product_variant_id' => $oi->product_variant_id,
                    'quantity' => $pl['quantity'],
                    'amount' => $pl['amount'],
                ]);
            }

            $lockedOrder->refresh();
            $allItemsFullyRefunded = $lockedOrder->items->every(
                static fn (OrderItem $i): bool => Quantity::compare($i->refunded_quantity, $i->quantity) >= 0
            );

            $newRefundedTotalMinor = Money::toMinor($lockedOrder->refunded_total) + $finalRefundMinor;
            $newStatus = $allItemsFullyRefunded ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded;

            $oldOrderState = [
                'status' => $lockedOrder->status->value,
                'refunded_total' => $lockedOrder->refunded_total,
            ];

            $lockedOrder->update([
                'status' => $newStatus,
                'refunded_total' => Money::toDecimal($newRefundedTotalMinor),
            ]);

            $this->inventory->restoreForRefund($refund);

            $this->audit->record(
                AuditAction::REFUND_PROCESSED,
                $refund,
                oldValues: $oldOrderState,
                newValues: [
                    'refund_number' => $refund->refund_number,
                    'order_number' => $lockedOrder->order_number,
                    'order_status' => $newStatus->value,
                    'amount' => $refund->amount,
                    'method' => $method->value,
                    'restock' => $restock,
                    'reason' => $reason,
                    'authorized_by_id' => $supervisor->id,
                    'authorized_by_name' => $supervisor->name,
                ],
            );

            $this->outbox->record('order.refunded', $refund, $refund->uuid, [
                'refund_uuid' => $refund->uuid,
                'refund_number' => $refund->refund_number,
                'order_uuid' => $lockedOrder->uuid,
                'order_number' => $lockedOrder->order_number,
                'amount' => $refund->amount,
                'method' => $method->value,
                'restock' => $restock,
                'reason' => $reason,
                'authorized_by_id' => $supervisor->id,
                'authorized_by_name' => $supervisor->name,
            ]);

            return $refund->load(['items.orderItem', 'order', 'authorizer']);
        });
    }

    /**
     * Validates single or split payment legs.
     *
     * Rules:
     *  - Sum of `amount` across all legs must equal `grandTotalMinor`.
     *  - Non-cash legs cannot have tendered > amount (no cash change on digital payments).
     *  - Cash legs may tender >= amount; change = tendered - amount.
     *
     * @param  list<array<string, mixed>>  $rawPayments
     * @return array{
     *     paid_total: string,
     *     change_total: string,
     *     legs: list<array{
     *         method: PaymentMethod,
     *         amount_minor: int,
     *         amount: string,
     *         tendered_amount: string,
     *         change_amount: string,
     *         provider: string|null,
     *         reference_number: string|null,
     *         card_last4: string|null,
     *         qris_payload: string|null
     *     }>
     * }
     */
    private function validateAndNormalizePayments(array $rawPayments, int $grandTotalMinor): array
    {
        $legs = [];
        $appliedTotalMinor = 0;
        $tenderedTotalMinor = 0;
        $changeTotalMinor = 0;

        foreach ($rawPayments as $index => $raw) {
            $method = PaymentMethod::tryFrom((string) ($raw['method'] ?? ''));
            if ($method === null) {
                throw new BusinessRuleException(
                    'INVALID_PAYMENT_METHOD',
                    sprintf('Payment leg #%d has an invalid payment method.', $index + 1),
                    422,
                );
            }

            $amountMinor = Money::toMinor($raw['amount'] ?? 0);
            if ($amountMinor < 0) {
                throw new BusinessRuleException(
                    'INVALID_PAYMENT_AMOUNT',
                    sprintf('Payment leg #%d amount cannot be negative.', $index + 1),
                    422,
                );
            }

            $remainingBillMinor = max(0, $grandTotalMinor - $appliedTotalMinor);

            if ($method->isCash()) {
                $tenderedMinor = isset($raw['tendered_amount']) && $raw['tendered_amount'] !== null && $raw['tendered_amount'] !== ''
                    ? Money::toMinor($raw['tendered_amount'])
                    : $amountMinor;

                if ($tenderedMinor < 0) {
                    throw new BusinessRuleException(
                        'INVALID_PAYMENT_AMOUNT',
                        sprintf('Cash tendered on leg #%d cannot be negative.', $index + 1),
                        422,
                    );
                }

                // If cashier entered a partial cash nominal in tendered_amount (< amount),
                // or entered cash tendered > remaining bill, automatically normalize applied amount:
                if ($tenderedMinor < $amountMinor) {
                    $amountMinor = $tenderedMinor;
                }
                if ($amountMinor > $remainingBillMinor) {
                    $amountMinor = $remainingBillMinor;
                }

                $changeMinor = max(0, $tenderedMinor - $amountMinor);
            } else {
                $tenderedMinor = $amountMinor;
                $changeMinor = 0;

                if ($amountMinor > 0 && $method->requiresReference() && empty($raw['reference_number'])) {
                    throw new BusinessRuleException(
                        'PAYMENT_REFERENCE_REQUIRED',
                        sprintf('%s requires an approval/reference number on leg #%d.', $method->label(), $index + 1),
                        422,
                    );
                }
            }

            $appliedTotalMinor += $amountMinor;
            $tenderedTotalMinor += $tenderedMinor;
            $changeTotalMinor += $changeMinor;

            $legs[] = [
                'method' => $method,
                'amount_minor' => $amountMinor,
                'amount' => Money::toDecimal($amountMinor),
                'tendered_amount' => Money::toDecimal($tenderedMinor),
                'change_amount' => Money::toDecimal($changeMinor),
                'provider' => isset($raw['provider']) ? trim((string) $raw['provider']) : null,
                'reference_number' => isset($raw['reference_number']) ? trim((string) $raw['reference_number']) : null,
                'card_last4' => isset($raw['card_last4']) ? substr(preg_replace('/\D/', '', (string) $raw['card_last4']) ?? '', -4) : null,
                'qris_payload' => isset($raw['qris_payload']) ? trim((string) $raw['qris_payload']) : null,
            ];
        }

        if ($appliedTotalMinor > $grandTotalMinor) {
            throw new BusinessRuleException(
                'PAYMENT_TOTAL_MISMATCH',
                sprintf(
                    'Total applied payment (%s) cannot exceed order grand total (%s).',
                    Money::toDecimal($appliedTotalMinor),
                    Money::toDecimal($grandTotalMinor),
                ),
                422,
                [
                    'applied_total' => Money::toDecimal($appliedTotalMinor),
                    'grand_total' => Money::toDecimal($grandTotalMinor),
                ],
            );
        }

        $debtMinor = max(0, $grandTotalMinor - $appliedTotalMinor);

        return [
            'paid_total' => Money::toDecimal($tenderedTotalMinor),
            'applied_total' => Money::toDecimal($appliedTotalMinor),
            'applied_total_minor' => $appliedTotalMinor,
            'change_total' => Money::toDecimal($changeTotalMinor),
            'debt_minor' => $debtMinor,
            'debt_total' => Money::toDecimal($debtMinor),
            'legs' => $legs,
        ];
    }
}

