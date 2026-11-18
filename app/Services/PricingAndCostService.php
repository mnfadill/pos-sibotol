<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductOutletSetting;
use App\Models\ProductPriceHistory;
use App\Models\ProductTierPrice;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\Tier;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;

final class PricingAndCostService
{
    /**
     * Resolve UOM / packaging unit conversion for a product variant.
     *
     * @return array{
     *   product_unit: ProductUnit|null,
     *   uom_code: string,
     *   uom_quantity: string,
     *   conversion_factor: string,
     *   base_quantity: string
     * }
     */
    public function resolveUnitConversion(
        ProductVariant $variant,
        ?int $productUnitId = null,
        ?string $uomCode = null,
        string|int|float $uomQty = 1,
        bool $lockUnit = false,
    ): array {
        $variant->loadMissing('product');
        $normalizedUomQty = Quantity::normalize($uomQty);

        if (Quantity::compare($normalizedUomQty, '0.000') <= 0) {
            throw new BusinessRuleException('INVALID_QUANTITY', 'Quantity must be greater than zero.', 422);
        }

        $productUnit = null;

        if ($productUnitId !== null && $productUnitId > 0) {
            $productUnit = ProductUnit::query()
                ->where('product_id', $variant->product_id)
                ->where(static function ($q) use ($variant): void {
                    $q->whereNull('product_variant_id')
                        ->orWhere('product_variant_id', $variant->id);
                })
                ->find($productUnitId);

            if ($productUnit === null) {
                throw new BusinessRuleException(
                    'INVALID_PRODUCT_UNIT',
                    sprintf('Selected unit #%d does not belong to product "%s".', $productUnitId, $variant->product->name),
                    422,
                );
            }
        } elseif ($uomCode !== null && trim($uomCode) !== '') {
            $cleanCode = strtoupper(trim($uomCode));
            $productUnit = ProductUnit::query()
                ->where('product_id', $variant->product_id)
                ->where('unit_code', $cleanCode)
                ->where(static function ($q) use ($variant): void {
                    $q->where('product_variant_id', $variant->id)
                        ->orWhereNull('product_variant_id');
                })
                ->orderByRaw('CASE WHEN product_variant_id IS NOT NULL THEN 0 ELSE 1 END')
                ->first();
        }

        $baseUnitCode = strtoupper((string) ($variant->product->unit ?: 'PCS'));

        if ($productUnit !== null) {
            $conversionFactor = Quantity::normalize($productUnit->conversion_factor);
            if (Quantity::compare($conversionFactor, '0.000') <= 0) {
                $conversionFactor = '1.000';
            }
            $resolvedCode = strtoupper($productUnit->unit_code);

            if ($lockUnit && ! $productUnit->is_locked) {
                $productUnit->update(['is_locked' => true]);
            }
        } else {
            $conversionFactor = '1.000';
            $resolvedCode = $uomCode !== null && trim($uomCode) !== ''
                ? strtoupper(trim($uomCode))
                : $baseUnitCode;
        }

        $baseQty = Quantity::mul($normalizedUomQty, $conversionFactor);

        return [
            'product_unit' => $productUnit,
            'uom_code' => $resolvedCode,
            'uom_quantity' => $normalizedUomQty,
            'conversion_factor' => $conversionFactor,
            'base_quantity' => $baseQty,
        ];
    }

    /**
     * Unified Pricing Engine for Counter POS and B2B Sales Order.
     *
     * Priority Order:
     * 1. Customer-specific PriceListItem (Customer's assigned price_list_id or PriceList with customer_id)
     * 2. Customer Group / Tier PriceListItem (PriceList matching customer pricing_tier / customer_group with min_qty <= uomQty)
     * 3. Customer Tier price on ProductVariant (wholesale_price / reseller_price) scaled by conversion_factor
     * 4. Outlet-specific PriceListItem or ProductOutletSetting price_override scaled by conversion_factor
     * 5. ProductUnit selling_price (for multi-UOM e.g. PACK/DUS) or Variant retail price * conversion_factor
     *
     * @return array{
     *   unit_price: string,
     *   base_unit_price: string,
     *   unit_cost: string,
     *   base_unit_cost: string,
     *   min_selling_price: string,
     *   margin_amount: string,
     *   margin_percent: string,
     *   price_source: string
     * }
     */
    public function resolveSellingPrice(
        ProductVariant $variant,
        ?Outlet $outlet = null,
        ?Customer $customer = null,
        ?ProductUnit $productUnit = null,
        string|int|float $uomQty = 1,
        ?string $customerTier = null,
    ): array {
        $variant->loadMissing('product');
        $normalizedUomQty = Quantity::normalize($uomQty);
        $conversionFactor = $productUnit !== null
            ? Quantity::normalize($productUnit->conversion_factor)
            : '1.000';

        if (Quantity::compare($conversionFactor, '0.000') <= 0) {
            $conversionFactor = '1.000';
        }

        $today = CarbonImmutable::today()->toDateString();
        $resolvedUomPrice = null;
        $priceSource = 'base_retail';

        // 1. Check Customer-specific Price List
        if ($customer !== null) {
            $customerPriceListIds = PriceList::query()
                ->where('organization_id', $variant->product->organization_id)
                ->where('is_active', true)
                ->where(static function ($q) use ($customer): void {
                    $q->where('customer_id', $customer->id);
                    if ($customer->price_list_id !== null) {
                        $q->orWhere('id', $customer->price_list_id);
                    }
                })
                ->where(static function ($q) use ($today): void {
                    $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
                })
                ->where(static function ($q) use ($today): void {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
                })
                ->orderByDesc('priority')
                ->pluck('id');

            if ($customerPriceListIds->isNotEmpty()) {
                $plItem = $this->findMatchingPriceListItem(
                    $customerPriceListIds->all(),
                    $variant->id,
                    $productUnit?->id,
                    $normalizedUomQty,
                );

                if ($plItem !== null) {
                    $resolvedUomPrice = $this->scalePriceListItem($plItem, $productUnit, $conversionFactor);
                    $priceSource = 'customer_price_list';
                }
            }
        }

        // 2. Check Dynamic Tier Configuration (from database tiers & product_tier_prices)
        if ($resolvedUomPrice === null && ($customerTier !== null || ($customer !== null && $customer->tier_id !== null))) {
            $orgId = (int) $variant->product->organization_id;
            $tierId = $customer?->tier_id;
            $tierRecord = null;

            if ($tierId !== null) {
                $tierRecord = Tier::query()->withoutGlobalScopes()->where('organization_id', $orgId)->find($tierId);
            } elseif ($customerTier !== null && trim((string) $customerTier) !== '') {
                $rawTier = trim((string) $customerTier);
                $tierRecord = Tier::query()->withoutGlobalScopes()->where('organization_id', $orgId)
                    ->where(static function ($q) use ($rawTier): void {
                        $q->where('code', strtoupper($rawTier))
                            ->orWhere('id', is_numeric($rawTier) ? (int) $rawTier : 0);

                        $aliasCode = match (strtoupper($rawTier)) {
                            'A', 'TIER_A' => 'TIER_1',
                            'B', 'TIER_B' => 'TIER_2',
                            'C', 'TIER_C' => 'TIER_3',
                            'D', 'TIER_D' => 'TIER_4',
                            default => null,
                        };
                        if ($aliasCode !== null) {
                            $q->orWhere('code', $aliasCode);
                        }
                    })
                    ->first();
            }

            if ($tierRecord !== null) {
                $dynamicPrice = ProductTierPrice::query()->withoutGlobalScopes()
                    ->where('tier_id', $tierRecord->id)
                    ->where('product_id', $variant->product_id)
                    ->where(static fn ($q) => $q->where('product_variant_id', $variant->id)->orWhereNull('product_variant_id'))
                    ->when($productUnit !== null, static fn ($q) => $q->where('product_unit_id', $productUnit->id))
                    ->currentlyEffective($today)
                    ->orderByRaw('CASE WHEN product_unit_id IS NOT NULL THEN 0 ELSE 1 END')
                    ->first();

                if ($dynamicPrice !== null) {
                    if ($dynamicPrice->product_unit_id !== null) {
                        $resolvedUomPrice = (string) $dynamicPrice->price;
                    } else {
                        $resolvedUomPrice = bcmul((string) $dynamicPrice->price, $conversionFactor, 2);
                    }
                    $priceSource = 'tier_' . strtolower($tierRecord->code);
                } elseif ($productUnit !== null) {
                    $tierCodeUpper = strtoupper($tierRecord->code);
                    if (in_array($tierCodeUpper, ['TIER_1', 'RETAIL', 'UMUM', 'TIER_A', 'A'], true) && $productUnit->selling_price !== null && (float) $productUnit->selling_price > 0) {
                        $resolvedUomPrice = (string) $productUnit->selling_price;
                        $priceSource = 'unit_explicit_' . strtolower($tierRecord->code);
                    } elseif (in_array($tierCodeUpper, ['TIER_2', 'MEMBER', 'TIER_B', 'B'], true) && $productUnit->member_price !== null && (float) $productUnit->member_price > 0) {
                        $resolvedUomPrice = (string) $productUnit->member_price;
                        $priceSource = 'unit_explicit_' . strtolower($tierRecord->code);
                    } elseif (in_array($tierCodeUpper, ['TIER_3', 'RESELLER', 'WARUNG', 'TIER_C', 'C'], true) && $productUnit->reseller_price !== null && (float) $productUnit->reseller_price > 0) {
                        $resolvedUomPrice = (string) $productUnit->reseller_price;
                        $priceSource = 'unit_explicit_' . strtolower($tierRecord->code);
                    } elseif (in_array($tierCodeUpper, ['TIER_4', 'WHOLESALE', 'GROSIR', 'VIP', 'TIER_D', 'D'], true) && $productUnit->wholesale_price !== null && (float) $productUnit->wholesale_price > 0) {
                        $resolvedUomPrice = (string) $productUnit->wholesale_price;
                        $priceSource = 'unit_explicit_' . strtolower($tierRecord->code);
                    }
                }
            }
        }

        // 3. Check Customer Tier / Group Price List (via Customer model)
        if ($resolvedUomPrice === null && $customer !== null) {
            $tier = strtolower((string) $customer->pricing_tier);
            $group = strtolower((string) $customer->customer_group);

            $tierPriceListIds = PriceList::query()
                ->where('organization_id', $variant->product->organization_id)
                ->where('is_active', true)
                ->whereNull('customer_id')
                ->where(static function ($q) use ($tier, $group): void {
                    $q->whereIn('type', array_filter([$tier, $group]))
                        ->orWhereIn('customer_group', array_filter([$tier, $group]));
                })
                ->where(static function ($q) use ($today): void {
                    $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
                })
                ->where(static function ($q) use ($today): void {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
                })
                ->orderByDesc('priority')
                ->pluck('id');

            if ($tierPriceListIds->isNotEmpty()) {
                $plItem = $this->findMatchingPriceListItem(
                    $tierPriceListIds->all(),
                    $variant->id,
                    $productUnit?->id,
                    $normalizedUomQty,
                );

                if ($plItem !== null) {
                    $resolvedUomPrice = $this->scalePriceListItem($plItem, $productUnit, $conversionFactor);
                    $priceSource = 'tier_price_list';
                }
            }
        }

        // 3. Check Customer Tier on ProductVariant (wholesale_price / reseller_price via Customer model)
        if ($resolvedUomPrice === null && $customer !== null && in_array($customer->pricing_tier, ['wholesale', 'vip', 'reseller'], true)) {
            $tierBasePrice = match ($customer->pricing_tier) {
                'wholesale', 'vip' => $variant->wholesale_price !== null && (float) $variant->wholesale_price > 0
                    ? (string) $variant->wholesale_price
                    : null,
                'reseller' => $variant->reseller_price !== null && (float) $variant->reseller_price > 0
                    ? (string) $variant->reseller_price
                    : null,
                default => null,
            };

            if ($tierBasePrice !== null) {
                $resolvedUomPrice = bcmul($tierBasePrice, $conversionFactor, 2);
                $priceSource = 'variant_'.$customer->pricing_tier;
            }
        }

        // 5. Check 4 Explicit Master Customer Tiers (Customer A, B, C, D fallback)
        if ($resolvedUomPrice === null && $customerTier !== null && trim($customerTier) !== '') {
            $normalizedTier = match (strtoupper(trim($customerTier))) {
                'B', 'TIER_B', 'MEMBER', 'LANGGANAN', 'SILVER' => 'B',
                'C', 'TIER_C', 'RESELLER', 'WARUNG', 'MITRA', 'GOLD' => 'C',
                'D', 'TIER_D', 'WHOLESALE', 'GROSIR', 'VIP', 'PLATINUM' => 'D',
                default => 'A',
            };

            $effectiveRetail = (string) $variant->price;
            if ($outlet !== null) {
                $outletSetting = ProductOutletSetting::query()->withoutGlobalScopes()
                    ->where('outlet_id', $outlet->id)
                    ->where('product_variant_id', $variant->id)
                    ->first();
                if ($outletSetting !== null && $outletSetting->price_override !== null && (float) $outletSetting->price_override > 0) {
                    $effectiveRetail = (string) $outletSetting->price_override;
                }
            }

            if ($normalizedTier === 'A') {
                if ($productUnit !== null && $productUnit->selling_price !== null && (float) $productUnit->selling_price > 0) {
                    $resolvedUomPrice = (string) $productUnit->selling_price;
                    $priceSource = 'tier_a_unit_override';
                } else {
                    $resolvedUomPrice = bcmul($effectiveRetail, $conversionFactor, 2);
                    $priceSource = 'tier_a_retail';
                }
            } elseif ($normalizedTier === 'B') {
                // Tier B: Member (Explicit unit price, explicit variant member_price, PriceList match, or fallback)
                if ($productUnit !== null && $productUnit->member_price !== null && (float) $productUnit->member_price > 0) {
                    $resolvedUomPrice = (string) $productUnit->member_price;
                    $priceSource = 'tier_b_unit_explicit';
                } elseif ($variant->member_price !== null && (float) $variant->member_price > 0) {
                    $resolvedUomPrice = bcmul((string) $variant->member_price, $conversionFactor, 2);
                    $priceSource = 'tier_b_variant_explicit';
                } else {
                    $tierPriceListIds = PriceList::query()
                        ->where('organization_id', $variant->product->organization_id)
                        ->where('is_active', true)
                        ->whereNull('customer_id')
                        ->whereIn('type', ['tier_b', 'member', 'silver'])
                        ->where(static fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $today))
                        ->where(static fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
                        ->orderByDesc('priority')
                        ->pluck('id');

                    $plItem = $tierPriceListIds->isNotEmpty()
                        ? $this->findMatchingPriceListItem($tierPriceListIds->all(), $variant->id, $productUnit?->id, $normalizedUomQty)
                        : null;

                    if ($plItem !== null) {
                        $resolvedUomPrice = $this->scalePriceListItem($plItem, $productUnit, $conversionFactor);
                        $priceSource = 'tier_b_price_list';
                    } else {
                        $discount = bcmul($effectiveRetail, '0.05', 2);
                        $tierUnitBase = bcsub($effectiveRetail, $discount, 2);
                        $resolvedUomPrice = bcmul($tierUnitBase, $conversionFactor, 2);
                        $priceSource = 'tier_b_member';
                    }
                }
            } elseif ($normalizedTier === 'C') {
                // Tier C: Reseller (Explicit unit price, explicit variant reseller_price, PriceList match, or fallback)
                if ($productUnit !== null && $productUnit->reseller_price !== null && (float) $productUnit->reseller_price > 0) {
                    $resolvedUomPrice = (string) $productUnit->reseller_price;
                    $priceSource = 'tier_c_unit_explicit';
                } elseif ($variant->reseller_price !== null && (float) $variant->reseller_price > 0) {
                    $resolvedUomPrice = bcmul((string) $variant->reseller_price, $conversionFactor, 2);
                    $priceSource = 'tier_c_reseller_variant';
                } else {
                    $tierPriceListIds = PriceList::query()
                        ->where('organization_id', $variant->product->organization_id)
                        ->where('is_active', true)
                        ->whereNull('customer_id')
                        ->whereIn('type', ['tier_c', 'reseller', 'gold'])
                        ->where(static fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $today))
                        ->where(static fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
                        ->orderByDesc('priority')
                        ->pluck('id');

                    $plItem = $tierPriceListIds->isNotEmpty()
                        ? $this->findMatchingPriceListItem($tierPriceListIds->all(), $variant->id, $productUnit?->id, $normalizedUomQty)
                        : null;

                    if ($plItem !== null) {
                        $resolvedUomPrice = $this->scalePriceListItem($plItem, $productUnit, $conversionFactor);
                        $priceSource = 'tier_c_price_list';
                    } else {
                        $discount = bcmul($effectiveRetail, '0.10', 2);
                        $tierUnitBase = bcsub($effectiveRetail, $discount, 2);
                        $resolvedUomPrice = bcmul($tierUnitBase, $conversionFactor, 2);
                        $priceSource = 'tier_c_reseller';
                    }
                }
            } elseif ($normalizedTier === 'D') {
                // Tier D: Wholesale / Grosir VIP (Explicit unit price, explicit variant wholesale_price, PriceList match, or fallback)
                if ($productUnit !== null && $productUnit->wholesale_price !== null && (float) $productUnit->wholesale_price > 0) {
                    $resolvedUomPrice = (string) $productUnit->wholesale_price;
                    $priceSource = 'tier_d_unit_explicit';
                } elseif ($variant->wholesale_price !== null && (float) $variant->wholesale_price > 0) {
                    $resolvedUomPrice = bcmul((string) $variant->wholesale_price, $conversionFactor, 2);
                    $priceSource = 'tier_d_wholesale_variant';
                } else {
                    $tierPriceListIds = PriceList::query()
                        ->where('organization_id', $variant->product->organization_id)
                        ->where('is_active', true)
                        ->whereNull('customer_id')
                        ->whereIn('type', ['tier_d', 'wholesale', 'vip', 'platinum'])
                        ->where(static fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $today))
                        ->where(static fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
                        ->orderByDesc('priority')
                        ->pluck('id');

                    $plItem = $tierPriceListIds->isNotEmpty()
                        ? $this->findMatchingPriceListItem($tierPriceListIds->all(), $variant->id, $productUnit?->id, $normalizedUomQty)
                        : null;

                    if ($plItem !== null) {
                        $resolvedUomPrice = $this->scalePriceListItem($plItem, $productUnit, $conversionFactor);
                        $priceSource = 'tier_d_price_list';
                    } else {
                        $discount = bcmul($effectiveRetail, '0.15', 2);
                        $tierUnitBase = bcsub($effectiveRetail, $discount, 2);
                        $resolvedUomPrice = bcmul($tierUnitBase, $conversionFactor, 2);
                        $priceSource = 'tier_d_wholesale';
                    }
                }
            }
        }

        // 5. Check Outlet PriceOverride or Outlet PriceList
        if ($resolvedUomPrice === null && $outlet !== null) {
            $outletSetting = ProductOutletSetting::query()->withoutGlobalScopes()
                ->where('outlet_id', $outlet->id)
                ->where('product_variant_id', $variant->id)
                ->first();

            if ($outletSetting !== null && $outletSetting->price_override !== null && (float) $outletSetting->price_override > 0) {
                // If buying in a multi-unit packaging that has an explicit unit selling price and no outlet override applies, compare
                if ($productUnit !== null && ! $productUnit->is_base_unit && $productUnit->selling_price !== null && (float) $productUnit->selling_price > 0) {
                    // Scale unit price proportionally by outlet override ratio or use unit selling price
                    $resolvedUomPrice = (string) $productUnit->selling_price;
                    $priceSource = 'product_unit_price';
                } else {
                    $resolvedUomPrice = bcmul((string) $outletSetting->price_override, $conversionFactor, 2);
                    $priceSource = 'outlet_override';
                }
            }
        }

        // 6. Fallback to ProductUnit selling_price or Variant base price * conversion_factor
        if ($resolvedUomPrice === null) {
            if ($productUnit !== null && $productUnit->selling_price !== null && (float) $productUnit->selling_price > 0) {
                $resolvedUomPrice = bcadd((string) $productUnit->selling_price, '0', 2);
                $priceSource = 'product_unit_price';
            } else {
                $resolvedUomPrice = bcmul((string) $variant->price, $conversionFactor, 2);
                $priceSource = 'base_retail';
            }
        }

        // Base unit cost (Moving Average Cost if > 0, else cost_price)
        $baseUnitCost = bccomp((string) ($variant->average_cost ?? '0'), '0', 2) > 0
            ? bcadd((string) $variant->average_cost, '0', 2)
            : bcadd((string) ($variant->cost_price ?? '0'), '0', 2);

        $uomCost = bcmul($baseUnitCost, $conversionFactor, 2);
        $baseUnitPrice = bcdiv($resolvedUomPrice, $conversionFactor, 2);

        $minSellingPrice = '0.00';
        if ($productUnit !== null && $productUnit->min_selling_price !== null && (float) $productUnit->min_selling_price > 0) {
            $minSellingPrice = bcadd((string) $productUnit->min_selling_price, '0', 2);
        } elseif ($variant->min_selling_price !== null && (float) $variant->min_selling_price > 0) {
            $minSellingPrice = bcmul((string) $variant->min_selling_price, $conversionFactor, 2);
        }

        $marginAmount = bcsub($resolvedUomPrice, $uomCost, 2);
        $marginPercent = bccomp($resolvedUomPrice, '0', 2) > 0
            ? bcdiv(bcmul($marginAmount, '100', 4), $resolvedUomPrice, 2)
            : '0.00';

        return [
            'unit_price' => $resolvedUomPrice,
            'base_unit_price' => $baseUnitPrice,
            'unit_cost' => $uomCost,
            'base_unit_cost' => $baseUnitCost,
            'min_selling_price' => $minSellingPrice,
            'margin_amount' => $marginAmount,
            'margin_percent' => $marginPercent,
            'price_source' => $priceSource,
        ];
    }

    /**
     * Resolve all 4 Master Customer Tier prices for a variant.
     *
     * @return array{
     *   A: string,
     *   B: string,
     *   C: string,
     *   D: string
     * }
     */
    public function resolveAllTierPrices(
        ProductVariant $variant,
        ?Outlet $outlet = null,
        ?ProductUnit $productUnit = null,
    ): array {
        $prices = [
            'A' => $this->resolveSellingPrice($variant, $outlet, null, $productUnit, 1, 'A')['unit_price'],
            'B' => $this->resolveSellingPrice($variant, $outlet, null, $productUnit, 1, 'B')['unit_price'],
            'C' => $this->resolveSellingPrice($variant, $outlet, null, $productUnit, 1, 'C')['unit_price'],
            'D' => $this->resolveSellingPrice($variant, $outlet, null, $productUnit, 1, 'D')['unit_price'],
        ];

        $orgId = (int) $variant->product->organization_id;
        $dbTiers = Tier::query()
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->get(['id', 'code']);

        foreach ($dbTiers as $t) {
            $resolved = $this->resolveSellingPrice($variant, $outlet, null, $productUnit, 1, $t->code)['unit_price'];
            $prices[$t->code] = $resolved;
            $prices[(string) $t->id] = $resolved;
        }

        return $prices;
    }

    /**
     * Update Moving Weighted Average Cost (HPP) when receiving goods into a warehouse.
     */
    public function applyMovingAverageCost(
        ProductVariant $variant,
        string $existingBaseQty,
        string $receivedBaseQty,
        string $landedBaseUnitCost,
        ?int $warehouseId = null,
        ?int $outletId = null,
        ?int $userId = null,
        ?string $reason = null,
    ): ProductVariant {
        $variant->loadMissing('product');

        $oldAvg = bccomp((string) ($variant->average_cost ?? '0'), '0', 2) > 0
            ? bcadd((string) $variant->average_cost, '0', 2)
            : bcadd((string) ($variant->cost_price ?? '0'), '0', 2);

        $safeExistingQty = Quantity::maxZero($existingBaseQty);
        $safeReceivedQty = Quantity::maxZero($receivedBaseQty);
        $totalNewQty = Quantity::add($safeExistingQty, $safeReceivedQty);

        if (Quantity::compare($safeReceivedQty, '0.000') > 0 && Quantity::compare($totalNewQty, '0.000') > 0) {
            $oldTotalValue = bcmul($safeExistingQty, $oldAvg, 6);
            $addedValue = bcmul($safeReceivedQty, $landedBaseUnitCost, 6);
            $newAvg = bcdiv(bcadd($oldTotalValue, $addedValue, 6), $totalNewQty, 2);
        } else {
            $newAvg = bcadd($landedBaseUnitCost, '0', 2);
        }

        $normalizedLandedCost = bcadd($landedBaseUnitCost, '0', 2);

        $variant->update([
            'cost_price' => $newAvg,
            'average_cost' => $newAvg,
            'last_purchase_cost' => $normalizedLandedCost,
        ]);

        if (bccomp($oldAvg, $newAvg, 2) !== 0) {
            ProductPriceHistory::query()->create([
                'organization_id' => $variant->product->organization_id,
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'outlet_id' => $outletId,
                'price_type' => 'average_cost',
                'old_price' => $oldAvg,
                'new_price' => $newAvg,
                'old_cost' => $oldAvg,
                'new_cost' => $normalizedLandedCost,
                'reason' => $reason ?? 'Goods Receipt Moving Average Cost Update',
                'changed_by' => $userId,
            ]);
        }

        return $variant->fresh();
    }

    /**
     * @param  list<int>  $priceListIds
     */
    private function findMatchingPriceListItem(
        array $priceListIds,
        int $variantId,
        ?int $productUnitId,
        string $uomQty,
    ): ?PriceListItem {
        return PriceListItem::query()
            ->whereIn('price_list_id', $priceListIds)
            ->where('product_variant_id', $variantId)
            ->where('min_qty', '<=', $uomQty)
            ->where(static function ($q) use ($productUnitId): void {
                if ($productUnitId !== null) {
                    $q->where('product_unit_id', $productUnitId)
                        ->orWhereNull('product_unit_id');
                } else {
                    $q->whereNull('product_unit_id');
                }
            })
            ->orderByRaw('CASE WHEN product_unit_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByDesc('min_qty')
            ->first();
    }

    private function scalePriceListItem(
        PriceListItem $item,
        ?ProductUnit $requestedUnit,
        string $conversionFactor,
    ): string {
        $basePrice = (string) $item->price;
        if ((float) $item->discount_percent > 0) {
            $discMinor = Money::percentOf(Money::toMinor($basePrice), (string) $item->discount_percent);
            $basePrice = Money::toDecimal(max(0, Money::toMinor($basePrice) - $discMinor));
        } elseif ((float) $item->discount_amount > 0) {
            $basePrice = Money::toDecimal(max(0, Money::toMinor($basePrice) - Money::toMinor($item->discount_amount)));
        }

        // If the price list item was defined specifically for the requested product_unit_id, return directly
        if ($requestedUnit !== null && $item->product_unit_id !== null && (int) $item->product_unit_id === (int) $requestedUnit->id) {
            return bcadd($basePrice, '0', 2);
        }

        // Otherwise the price list item is in base unit -> multiply by conversion factor
        return bcmul($basePrice, $conversionFactor, 2);
    }
}

