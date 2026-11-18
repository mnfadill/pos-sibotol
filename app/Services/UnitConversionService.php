<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Support\Quantity;

/**
 * Enterprise Centralized Unit of Measure & Multi-Packaging Conversion Service
 * for SiBotol Sub-Distributor POS (Alcoholic Beverage Distribution).
 */
final class UnitConversionService
{
    /**
     * Resolve packaging unit conversion and calculate base stock quantity.
     *
     * @return array{
     *   product_unit: ProductUnit|null,
     *   unit_id: int|null,
     *   uom_code: string,
     *   uom_name: string,
     *   uom_quantity: string,
     *   conversion_factor: string,
     *   base_quantity: string,
     *   base_unit_code: string
     * }
     */
    public function resolveUnitConversion(
        ProductVariant $variant,
        ?int $productUnitId = null,
        ?string $uomCode = null,
        string|int|float $uomQty = 1,
        bool $lockUnit = false,
    ): array {
        $variant->loadMissing('product.baseUnit');
        $normalizedUomQty = Quantity::normalize($uomQty);

        if (Quantity::compare($normalizedUomQty, '0.000') <= 0) {
            throw new BusinessRuleException('INVALID_QUANTITY', 'Quantity must be greater than zero.', 422);
        }

        $baseUnitCode = strtoupper((string) ($variant->product->baseUnit?->code ?? $variant->product->unit ?? 'BTL'));
        $baseUnitName = $variant->product->baseUnit?->name ?? $baseUnitCode;
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
                    sprintf('Satuan #%d tidak terdaftar pada produk "%s".', $productUnitId, $variant->product->name),
                    422,
                );
            }
        } elseif ($uomCode !== null && trim($uomCode) !== '') {
            $cleanCode = strtoupper(trim($uomCode));
            $productUnit = ProductUnit::query()
                ->where('product_id', $variant->product_id)
                ->where(static function ($q) use ($cleanCode): void {
                    $q->where('unit_code', $cleanCode)
                        ->orWhereRaw('UPPER(unit_name) = ?', [$cleanCode]);
                })
                ->where(static function ($q) use ($variant): void {
                    $q->where('product_variant_id', $variant->id)
                        ->orWhereNull('product_variant_id');
                })
                ->orderByRaw('CASE WHEN product_variant_id IS NOT NULL THEN 0 ELSE 1 END')
                ->first();
        }

        if ($productUnit !== null) {
            $conversionFactor = Quantity::normalize($productUnit->conversion_factor);
            if (Quantity::compare($conversionFactor, '0.000') <= 0) {
                $conversionFactor = '1.000';
            }
            $resolvedCode = strtoupper($productUnit->unit_code);
            $resolvedName = $productUnit->unit_name ?: $resolvedCode;

            if ($lockUnit && ! $productUnit->is_locked) {
                $productUnit->update(['is_locked' => true]);
            }
        } else {
            $conversionFactor = '1.000';
            $resolvedCode = $uomCode !== null && trim($uomCode) !== ''
                ? strtoupper(trim($uomCode))
                : $baseUnitCode;
            $resolvedName = $resolvedCode;
        }

        $baseQty = Quantity::mul($normalizedUomQty, $conversionFactor);

        return [
            'product_unit' => $productUnit,
            'unit_id' => $productUnit?->unit_id,
            'uom_code' => $resolvedCode,
            'uom_name' => $resolvedName,
            'uom_quantity' => $normalizedUomQty,
            'conversion_factor' => $conversionFactor,
            'base_quantity' => $baseQty,
            'base_unit_code' => $baseUnitCode,
        ];
    }

    /**
     * Format inventory stock into readable packaging breakdown.
     * Example: 158 Botol (with 1 Dus = 12 Botol) -> "158 Botol (13 Dus + 2 Botol)"
     *
     * @return array{
     *   base_quantity: string,
     *   base_unit: string,
     *   base_display: string,
     *   breakdown: string|null,
     *   full_display: string
     * }
     */
    public function formatStockDisplay(
        string|int|float $baseQuantity,
        Product $product,
        ?ProductUnit $preferredPackagingUnit = null,
    ): array {
        $product->loadMissing(['productUnits', 'baseUnit']);
        $normQty = Quantity::normalize($baseQuantity);
        $baseUnitCode = strtoupper((string) ($product->baseUnit?->code ?? $product->unit ?? 'BTL'));
        $baseDisplay = sprintf('%s %s', rtrim(rtrim($normQty, '0'), '.'), $baseUnitCode);

        // Find largest non-base packaging unit (e.g. Dus or Krat)
        $packagingUnit = $preferredPackagingUnit;
        if ($packagingUnit === null) {
            $packagingUnit = $product->productUnits
                ->filter(static fn (ProductUnit $pu): bool => ! $pu->is_base && (float) $pu->conversion_factor > 1)
                ->sortByDesc('conversion_factor')
                ->first();
        }

        if ($packagingUnit === null || (float) $normQty <= 0) {
            return [
                'base_quantity' => $normQty,
                'base_unit' => $baseUnitCode,
                'base_display' => $baseDisplay,
                'breakdown' => null,
                'full_display' => $baseDisplay,
            ];
        }

        $factor = (float) $packagingUnit->conversion_factor;
        $totalBase = (float) $normQty;
        $wholePack = (int) floor($totalBase / $factor);
        $remainder = (int) round(fmod($totalBase, $factor));

        $packName = $packagingUnit->unit_code ?: 'Dus';

        if ($wholePack > 0 && $remainder > 0) {
            $breakdown = sprintf('%d %s + %d %s', $wholePack, $packName, $remainder, $baseUnitCode);
            $fullDisplay = sprintf('%s (%s)', $baseDisplay, $breakdown);
        } elseif ($wholePack > 0 && $remainder === 0) {
            $breakdown = sprintf('%d %s', $wholePack, $packName);
            $fullDisplay = sprintf('%s (%s)', $baseDisplay, $breakdown);
        } else {
            $breakdown = null;
            $fullDisplay = $baseDisplay;
        }

        return [
            'base_quantity' => $normQty,
            'base_unit' => $baseUnitCode,
            'base_display' => $baseDisplay,
            'breakdown' => $breakdown,
            'full_display' => $fullDisplay,
        ];
    }
}

