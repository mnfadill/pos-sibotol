<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\UpdateOutletSettingRequest;
use App\Models\Category;
use App\Models\OutletInventory;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductOutletSetting;
use App\Models\ProductPriceHistory;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Services\Audit\AuditLogger;
use App\Services\Outbox\OutboxService;
use App\Services\PricingAndCostService;
use App\Support\Money;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class CatalogController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
        private readonly PricingAndCostService $pricing,
    ) {}

    public static function flushOrganizationCache(int $organizationId): void
    {
        \App\Services\PosCatalogCacheService::bumpMetadata($organizationId);
        \App\Services\PosCatalogCacheService::bumpPricing($organizationId);
    }

    public function categories(): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $payload = Cache::remember("pos_org_{$orgId}_catalog_categories_v1", 300, static function () use ($orgId): array {
            $counts = Product::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->selectRaw('category_id, COUNT(*) as total')
                ->groupBy('category_id')
                ->pluck('total', 'category_id');

            $totalActiveProducts = (int) $counts->sum();

            $categories = Category::query()
                ->select(['id', 'organization_id', 'parent_id', 'name', 'slug', 'color', 'sort_order', 'is_active'])
                ->with(['children' => static fn ($q) => $q
                    ->select(['id', 'organization_id', 'parent_id', 'name', 'slug', 'color', 'sort_order', 'is_active'])
                    ->where('is_active', true)
                    ->orderBy('sort_order')])
                ->roots()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(static function (Category $cat) use ($counts): array {
                    $ownCount = (int) ($counts->get($cat->id) ?? 0);
                    $childrenCount = 0;
                    foreach ($cat->children as $child) {
                        $childrenCount += (int) ($counts->get($child->id) ?? 0);
                    }
                    $arr = $cat->toArray();
                    $arr['products_count'] = $ownCount + $childrenCount;

                    return $arr;
                })
                ->values()
                ->all();

            return [
                'categories' => $categories,
                'total_products' => $totalActiveProducts,
            ];
        });

        return response()->json($payload);
    }

    public function products(Request $request): JsonResponse
    {
        $outlet = $this->context->requireOutlet();
        $warehouse = $this->context->warehouse();
        $orgId = (int) $outlet->organization_id;
        $outletId = (int) $outlet->id;
        $warehouseId = $warehouse?->id ? (int) $warehouse->id : null;
        $categoryId = $request->integer('category_id');
        $search = trim($request->string('search')->toString());
        $includeUnavailable = $request->boolean('include_unavailable', true);
        $isPaginated = $request->has('page') || $request->has('per_page');
        $page = max(1, $request->integer('page', 1));
        $perPage = min(50, max(10, $request->integer('per_page', 30)));
        $compact = $request->boolean('compact', false);
        $customerTier = strtoupper(trim((string) $request->query('customer_tier', 'A')));

        // Context-isolated cache versions (Organization Metadata + Outlet/Org Pricing + Outlet/Warehouse Stock)
        $metaVer = \App\Services\PosCatalogCacheService::metadataVersion($orgId);
        $priceVer = \App\Services\PosCatalogCacheService::pricingVersion($orgId, $outletId);
        $stockVer = \App\Services\PosCatalogCacheService::stockVersion($outletId, $warehouseId);

        // Full response cache for standard catalog browsing (no free-text search) with short stock-safe TTL (15s)
        // Automatically bypassed/invalidated instantly whenever metadata, price, or outlet stock version bumps!
        $canUseResponseCache = $compact && $isPaginated && $search === '';
        if ($canUseResponseCache) {
            $responseCacheKey = sprintf(
                'pos:catalog:o%d:w%d:c%d:t%s:p%d:pp%d:iu%d:mv%d:pv%d:sv%d',
                $outletId,
                $warehouseId ?? 0,
                $categoryId,
                $customerTier,
                $page,
                $perPage,
                $includeUnavailable ? 1 : 0,
                $metaVer,
                $priceVer,
                $stockVer,
            );

            $cachedPayload = Cache::get($responseCacheKey);
            if (is_array($cachedPayload)) {
                return response()->json($cachedPayload)->header('X-POS-Cache', 'HIT');
            }
        }

        $query = Product::query()
            ->select([
                'id', 'organization_id', 'category_id', 'brand_id', 'principal_id',
                'name', 'sku', 'short_description', 'description', 'product_type',
                'base_price', 'unit', 'net_content', 'net_content_unit', 'abv',
                'packaging_type', 'country_of_origin', 'is_returnable_packaging',
                'default_barcode', 'track_stock', 'track_batch', 'track_expiry',
                'image_url', 'is_active',
            ])
            ->with([
                'category:id,name,slug,color,parent_id',
                'brand:id,code,name',
                'variants' => static fn ($q) => $q
                    ->select([
                        'id', 'product_id', 'name', 'sku', 'barcode',
                        'cost_price', 'average_cost', 'last_purchase_cost',
                        'price', 'member_price', 'reseller_price', 'wholesale_price',
                        'min_selling_price', 'is_default', 'is_active', 'sort_order',
                    ])
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'productUnits' => static fn ($q) => $q
                    ->select([
                        'id', 'organization_id', 'product_id', 'product_variant_id', 'unit_id',
                        'unit_code', 'unit_name', 'conversion_factor', 'is_base',
                        'is_purchase_unit', 'is_sales_unit', 'barcode',
                        'selling_price', 'member_price', 'reseller_price', 'wholesale_price',
                        'tier_a_price', 'tier_b_price', 'tier_c_price', 'tier_d_price',
                        'purchase_cost', 'min_purchase_qty', 'is_locked', 'is_active',
                    ])
                    ->where('is_active', true)
                    ->orderBy('conversion_factor'),
                'modifiers' => static fn ($q) => $q
                    ->select(['id', 'product_id', 'group_name', 'name', 'price', 'is_required', 'is_multiple', 'sort_order', 'is_active'])
                    ->where('is_active', true)
                    ->orderBy('group_name')
                    ->orderBy('sort_order'),
            ])
            ->active()
            ->when($categoryId > 0, static function ($q) use ($categoryId, $orgId, $metaVer): void {
                $childIds = Cache::remember(
                    "pos_org_{$orgId}_mv{$metaVer}_cat_children_{$categoryId}",
                    \App\Services\PosCatalogCacheService::METADATA_TTL_SECONDS,
                    static fn () => Category::query()->where('parent_id', $categoryId)->pluck('id')->all(),
                );
                $q->whereIn('category_id', array_merge([$categoryId], $childIds));
            })
            ->search($search)
            ->orderBy('name')
            ->orderBy('id');

        if (! $compact) {
            $query->with([
                'principal:id,code,name',
                'barcodes' => static fn ($q) => $q->where('is_active', true),
            ]);
        }

        $paginationMeta = null;
        if ($isPaginated) {
            $paginator = $query->paginate($perPage);
            $products = collect($paginator->items());
            $paginationMeta = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ];
        } else {
            $products = $query->get();
        }

        $allVariants = $products->flatMap(static fn (Product $p) => $p->variants);
        $variantIds = $allVariants->pluck('id')->all();
        $variantHash = $variantIds !== [] ? md5(implode(',', $variantIds)) : 'empty';

        // Outlet settings (availability + outlet price override) cached by pricingVersion
        $outletSettings = $variantIds !== []
            ? Cache::remember(
                "pos:os:o{$outletId}:pv{$priceVer}:{$variantHash}",
                \App\Services\PosCatalogCacheService::PRICING_TTL_SECONDS,
                static fn () => ProductOutletSetting::query()->withoutGlobalScopes()
                    ->select(['product_variant_id', 'is_available', 'price_override'])
                    ->where('outlet_id', $outletId)
                    ->whereIn('product_variant_id', $variantIds)
                    ->get()
                    ->keyBy('product_variant_id'),
            )
            : collect();

        // Stock snapshot cached with short TTL (15s) & isolated by outlet + warehouse + stockVersion
        $inventories = $variantIds !== []
            ? Cache::remember(
                "pos:stk:o{$outletId}:w".($warehouseId ?? 0).":sv{$stockVer}:{$variantHash}",
                \App\Services\PosCatalogCacheService::STOCK_TTL_SECONDS,
                static fn () => OutletInventory::query()->withoutGlobalScopes()
                    ->select(['product_variant_id', 'quantity', 'reorder_level'])
                    ->where('outlet_id', $outletId)
                    ->whereIn('product_variant_id', $variantIds)
                    ->get()
                    ->keyBy('product_variant_id'),
            )
            : collect();

        // Tier prices cached by organization + outlet + pricingVersion (instant invalidation on price/tier change)
        $tierPricesByVariantId = $variantIds !== []
            ? Cache::remember(
                "pos:tp:o{$outletId}:pv{$priceVer}:{$variantHash}",
                \App\Services\PosCatalogCacheService::PRICING_TTL_SECONDS,
                fn (): array => $this->pricing->resolveAllTierPricesBatch($allVariants, $outlet, $outletSettings),
            )
            : [];

        $transformed = $products->map(static function (Product $product) use ($outletSettings, $inventories, $includeUnavailable, $tierPricesByVariantId, $customerTier, $compact): ?array {
            $variants = $product->variants->map(static function (ProductVariant $v) use ($product, $outletSettings, $inventories, $tierPricesByVariantId, $customerTier, $compact): array {
                /** @var ProductOutletSetting|null $setting */
                $setting = $outletSettings->get($v->id);
                /** @var OutletInventory|null $inv */
                $inv = $inventories->get($v->id);

                $isAvailable = $setting ? $setting->is_available : true;
                $priceOverride = $setting?->price_override;

                $tierPrices = $tierPricesByVariantId[$v->id] ?? ['A' => (string) $v->price];
                $effectivePrice = $tierPrices[$customerTier] ?? ($priceOverride ?? $v->price);

                $row = [
                    'id' => $v->id,
                    'product_id' => $v->product_id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'base_price' => $v->price,
                    'price_override' => $priceOverride,
                    'effective_price' => $effectivePrice,
                    'tier_prices' => $tierPrices,
                    'selected_tier' => $customerTier,
                    'is_available' => $isAvailable,
                    'is_default' => $v->is_default,
                    'stock_quantity' => $inv ? $inv->quantity : '0.000',
                    'reorder_level' => $inv ? $inv->reorder_level : '0.000',
                ];

                if (! $compact) {
                    $variantUnits = $product->productUnits
                        ->filter(static fn (ProductUnit $pu): bool => $pu->product_variant_id === null || (int) $pu->product_variant_id === (int) $v->id)
                        ->values()
                        ->all();
                    $row['cost_price'] = $v->cost_price;
                    $row['average_cost'] = $v->average_cost ?? $v->cost_price;
                    $row['last_purchase_cost'] = $v->last_purchase_cost ?? $v->cost_price;
                    $row['wholesale_price'] = $v->wholesale_price;
                    $row['reseller_price'] = $v->reseller_price;
                    $row['min_selling_price'] = $v->min_selling_price ?? '0.00';
                    $row['units'] = $variantUnits;
                }

                return $row;
            })->filter(static fn (array $v): bool => $includeUnavailable || $v['is_available'])->values();

            if ($variants->isEmpty()) {
                return null;
            }

            $item = [
                'id' => $product->id,
                'category_id' => $product->category_id,
                'category' => $product->category,
                'brand_id' => $product->brand_id,
                'brand' => $product->brand,
                'name' => $product->name,
                'sku' => $product->sku,
                'base_price' => $product->base_price,
                'unit' => $product->unit,
                'track_stock' => $product->track_stock,
                'image_url' => $product->image_url,
                'variants' => $variants->all(),
                'product_units' => $product->productUnits->values()->all(),
                'modifiers' => $product->modifiers->values()->all(),
            ];

            if (! $compact) {
                $item['principal_id'] = $product->principal_id;
                $item['principal'] = $product->principal;
                $item['short_description'] = $product->short_description;
                $item['description'] = $product->description;
                $item['product_type'] = $product->product_type ?? 'regular';
                $item['status'] = $product->status ?? 'active';
                $item['net_content'] = $product->net_content;
                $item['net_content_unit'] = $product->net_content_unit ?? 'ml';
                $item['abv'] = $product->abv;
                $item['packaging_type'] = $product->packaging_type;
                $item['country_of_origin'] = $product->country_of_origin;
                $item['is_returnable_packaging'] = (bool) ($product->is_returnable_packaging ?? false);
                $item['default_barcode'] = $product->default_barcode;
                $item['track_batch'] = (bool) ($product->track_batch ?? false);
                $item['track_expiry'] = (bool) ($product->track_expiry ?? false);
                $item['barcodes'] = $product->barcodes->values()->all();
            }

            return $item;
        })->filter()->values();

        $response = [
            'outlet_id' => $outletId,
            'warehouse_id' => $warehouseId,
            'cache_versions' => [
                'metadata' => $metaVer,
                'pricing' => $priceVer,
                'stock' => $stockVer,
            ],
            'synced_at' => now()->toIso8601String(),
            'products' => $transformed,
        ];

        if ($paginationMeta !== null) {
            $response['pagination'] = $paginationMeta;
        }

        if ($canUseResponseCache) {
            Cache::put($responseCacheKey, $response, \App\Services\PosCatalogCacheService::STOCK_TTL_SECONDS);
        }

        return response()->json($response)->header('X-POS-Cache', 'MISS');
    }

    /**
     * Multi-Barcode & Packaging Unit Scanner Lookup:
     * Resolves both the ProductVariant AND the specific packaging unit (e.g. PCS, PACK, DUS)
     * when a barcode from `product_barcodes`, `product_units`, or `product_variants` is scanned.
     */
    public function lookupBarcode(string $code): JsonResponse
    {
        $outlet = $this->context->requireOutlet();
        $code = trim($code);

        $matchedUnit = null;
        $variant = null;

        // 1. Check multi-barcode registry (`product_barcodes`)
        $barcodeRecord = ProductBarcode::query()
            ->with(['variant.product.modifiers', 'variant.product.category', 'productUnit', 'product.variants'])
            ->where('organization_id', $outlet->organization_id)
            ->where('is_active', true)
            ->where('barcode', $code)
            ->first();

        if ($barcodeRecord !== null) {
            $matchedUnit = $barcodeRecord->productUnit;
            $variant = $barcodeRecord->variant ?? $barcodeRecord->product?->variants?->firstWhere('is_active', true);
        }

        // 2. Check packaging unit barcode (`product_units`)
        if ($variant === null) {
            $unitRecord = ProductUnit::query()
                ->with(['variant.product.modifiers', 'variant.product.category', 'product.variants'])
                ->where('is_active', true)
                ->where('barcode', $code)
                ->first();

            if ($unitRecord !== null) {
                $matchedUnit = $unitRecord;
                $variant = $unitRecord->variant ?? $unitRecord->product?->variants?->firstWhere('is_active', true);
            }
        }

        // 3. Fallback to `product_variants` barcode or SKU
        if ($variant === null) {
            $variant = ProductVariant::query()
                ->with(['product.modifiers', 'product.category', 'product.productUnits'])
                ->where('is_active', true)
                ->where(static function ($q) use ($code): void {
                    $q->where('barcode', $code)
                        ->orWhereRaw('LOWER(sku) = ?', [mb_strtolower($code)]);
                })
                ->first();
        }

        if ($variant === null || ! $variant->product->is_active) {
            throw new BusinessRuleException(
                'BARCODE_NOT_FOUND',
                sprintf('No active product found for barcode/SKU "%s".', $code),
                404,
            );
        }

        $setting = ProductOutletSetting::query()->withoutGlobalScopes()
            ->where('outlet_id', $outlet->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $inventory = OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $outlet->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $isAvailable = $setting ? $setting->is_available : true;
        $resolvedPrice = $this->pricing->resolveSellingPrice(
            variant: $variant,
            outlet: $outlet,
            customer: null,
            productUnit: $matchedUnit,
            uomQty: 1,
        );

        $tierPricesMap = $this->pricing->resolveAllTierPricesBatch(
            collect([$variant]),
            $outlet,
            $setting ? collect([$variant->id => $setting]) : collect(),
        );
        $tierPrices = $tierPricesMap[$variant->id] ?? ['A' => (string) $variant->price];

        return response()->json([
            'variant' => [
                'id' => $variant->id,
                'product_id' => $variant->product_id,
                'product_name' => $variant->product->name,
                'product_unit_base' => $variant->product->unit ?? 'PCS',
                'name' => $variant->name,
                'sku' => $variant->sku,
                'barcode' => $code,
                'base_price' => $variant->price,
                'price_override' => $setting?->price_override,
                'effective_price' => $resolvedPrice['unit_price'],
                'tier_prices' => $tierPrices,
                'uom_code' => $matchedUnit ? $matchedUnit->unit_code : ($variant->product->unit ?? 'PCS'),
                'product_unit_id' => $matchedUnit?->id,
                'conversion_factor' => $matchedUnit ? $matchedUnit->conversion_factor : '1.000',
                'matched_unit' => $matchedUnit ? [
                    'id' => $matchedUnit->id,
                    'unit_code' => $matchedUnit->unit_code,
                    'unit_name' => $matchedUnit->unit_name,
                    'conversion_factor' => $matchedUnit->conversion_factor,
                    'selling_price' => $matchedUnit->selling_price,
                    'is_base' => $matchedUnit->is_base,
                ] : null,
                'is_available' => $isAvailable,
                'stock_quantity' => $inventory ? $inventory->quantity : '0.000',
                'track_stock' => $variant->product->track_stock,
                'modifiers' => $variant->product->modifiers->where('is_active', true)->values()->all(),
            ],
        ]);
    }

    /**
     * Outlet-Specific Override: toggle product availability (In Stock / Out of Stock)
     * and/or set custom outlet price.
     */
    public function updateOutletSetting(UpdateOutletSettingRequest $request, ProductVariant $variant): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();

        $variant->loadMissing('product');

        $isAvailable = (bool) $request->validated('is_available');
        $rawPriceOverride = $request->validated('price_override');
        $priceOverrideDecimal = ($rawPriceOverride !== null && $rawPriceOverride !== '')
            ? Money::toDecimal(Money::toMinor($rawPriceOverride))
            : null;

        $setting = DB::transaction(function () use ($outlet, $variant, $user, $isAvailable, $priceOverrideDecimal): ProductOutletSetting {
            $existing = ProductOutletSetting::query()->withoutGlobalScopes()
                ->where('outlet_id', $outlet->id)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            $oldValues = $existing ? [
                'is_available' => $existing->is_available,
                'price_override' => $existing->price_override,
                'effective_price' => $existing->price_override ?? $variant->price,
            ] : [
                'is_available' => true,
                'price_override' => null,
                'effective_price' => $variant->price,
            ];

            $setting = ProductOutletSetting::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'outlet_id' => $outlet->id,
                    'product_variant_id' => $variant->id,
                ],
                [
                    'product_id' => $variant->product_id,
                    'is_available' => $isAvailable,
                    'price_override' => $priceOverrideDecimal,
                    'updated_by' => $user->id,
                ],
            );

            if ($oldValues['effective_price'] !== ($setting->price_override ?? $variant->price)) {
                ProductPriceHistory::query()->create([
                    'organization_id' => $outlet->organization_id,
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'outlet_id' => $outlet->id,
                    'price_type' => 'outlet_override',
                    'old_price' => (string) $oldValues['effective_price'],
                    'new_price' => (string) ($setting->price_override ?? $variant->price),
                    'old_cost' => (string) ($variant->average_cost ?? $variant->cost_price ?? '0.00'),
                    'new_cost' => (string) ($variant->average_cost ?? $variant->cost_price ?? '0.00'),
                    'reason' => sprintf('Outlet %s price override update', $outlet->code),
                    'changed_by' => $user->id,
                ]);
            }

            $newValues = [
                'outlet_id' => $outlet->id,
                'outlet_code' => $outlet->code,
                'product_variant_id' => $variant->id,
                'sku' => $variant->sku,
                'product_name' => $variant->product->name.' - '.$variant->name,
                'is_available' => $setting->is_available,
                'price_override' => $setting->price_override,
                'effective_price' => $setting->price_override ?? $variant->price,
            ];

            $this->audit->record(
                AuditAction::PRODUCT_OUTLET_SETTING_UPDATED,
                $setting,
                oldValues: $oldValues,
                newValues: $newValues,
            );

            $this->outbox->record('catalog.outlet_setting_updated', $setting, $setting->id, $newValues, $outlet->id);

            return $setting;
        });

        return response()->json([
            'setting' => [
                'id' => $setting->id,
                'outlet_id' => $setting->outlet_id,
                'product_id' => $setting->product_id,
                'product_variant_id' => $setting->product_variant_id,
                'is_available' => $setting->is_available,
                'price_override' => $setting->price_override,
                'effective_price' => $setting->price_override ?? $variant->price,
            ],
        ]);
    }
}
