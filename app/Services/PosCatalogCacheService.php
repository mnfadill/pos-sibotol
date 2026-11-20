<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Smart POS Cache Service with versioned, context-isolated cache keys.
 *
 * Isolation dimensions:
 *  - Organization ID (metadata: products, categories, brands, units, tiers)
 *  - Outlet ID + Warehouse ID (stock snapshot with short TTL)
 *  - Pricing version (invalidated immediately on tier / price list / outlet price override changes)
 *
 * IMPORTANT: Never used as source of truth for checkout, payment validation, or stock deduction.
 */
final class PosCatalogCacheService
{
    public const METADATA_TTL_SECONDS = 300; // 5 minutes for rarely changing product/category/brand/unit metadata

    public const PRICING_TTL_SECONDS = 180; // 3 minutes for tier & outlet pricing (instant invalidation on edit)

    public const STOCK_TTL_SECONDS = 15; // Short TTL (15s) for outlet stock snapshot (instant invalidation on stock change)

    public static function metadataVersion(int $orgId): int
    {
        return (int) Cache::rememberForever("pos:v:meta:org:{$orgId}", static fn (): int => 1);
    }

    public static function pricingVersion(int $orgId, ?int $outletId = null): int
    {
        $orgVer = (int) Cache::rememberForever("pos:v:price:org:{$orgId}", static fn (): int => 1);
        if ($outletId === null) {
            return $orgVer;
        }
        $outletVer = (int) Cache::rememberForever("pos:v:price:outlet:{$outletId}", static fn (): int => 1);

        return $orgVer * 10000 + $outletVer;
    }

    public static function stockVersion(int $outletId, ?int $warehouseId = null): int
    {
        $whPart = $warehouseId ? ":wh:{$warehouseId}" : '';

        return (int) Cache::rememberForever("pos:v:stock:outlet:{$outletId}{$whPart}", static fn (): int => 1);
    }

    public static function bumpMetadata(int $orgId): void
    {
        $key = "pos:v:meta:org:{$orgId}";
        $current = (int) Cache::get($key, 1);
        Cache::forever($key, $current + 1);
        Cache::forget("pos_org_{$orgId}_catalog_categories_v1");
    }

    public static function bumpPricing(int $orgId, ?int $outletId = null): void
    {
        $orgKey = "pos:v:price:org:{$orgId}";
        Cache::forever($orgKey, ((int) Cache::get($orgKey, 1)) + 1);
        Cache::forget("pos_org_{$orgId}_active_tiers");
        Cache::forget("pos_org_{$orgId}_tier_price_lists_" . now()->toDateString());

        if ($outletId !== null) {
            $outletKey = "pos:v:price:outlet:{$outletId}";
            Cache::forever($outletKey, ((int) Cache::get($outletKey, 1)) + 1);
        }
    }

    public static function bumpStock(int $outletId, ?int $warehouseId = null): void
    {
        $baseKey = "pos:v:stock:outlet:{$outletId}";
        Cache::forever($baseKey, ((int) Cache::get($baseKey, 1)) + 1);

        if ($warehouseId !== null) {
            $whKey = "pos:v:stock:outlet:{$outletId}:wh:{$warehouseId}";
            Cache::forever($whKey, ((int) Cache::get($whKey, 1)) + 1);
        }
    }
}
