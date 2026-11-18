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
use Illuminate\Support\Facades\DB;

final class CatalogController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
        private readonly PricingAndCostService $pricing,
    ) {}

    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->with(['children' => static fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->roots()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    public function products(Request $request): JsonResponse
    {
        $outlet = $this->context->requireOutlet();
        $categoryId = $request->integer('category_id');
        $search = $request->string('search')->toString();
        $includeUnavailable = $request->boolean('include_unavailable', true);

        $products = Product::query()
            ->with([
                'category:id,name,slug,color,parent_id',
                'brand:id,code,name',
                'principal:id,code,name',
                'variants' => static fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'productUnits' => static fn ($q) => $q->where('is_active', true)->orderBy('conversion_factor'),
                'barcodes' => static fn ($q) => $q->where('is_active', true),
                'modifiers' => static fn ($q) => $q->where('is_active', true)->orderBy('group_name')->orderBy('sort_order'),
            ])
            ->active()
            ->when($categoryId > 0, static function ($q) use ($categoryId): void {
                $childIds = Category::query()->where('parent_id', $categoryId)->pluck('id')->all();
                $q->whereIn('category_id', array_merge([$categoryId], $childIds));
            })
            ->search($search)
            ->orderBy('name')
            ->get();

        $variantIds = $products->flatMap(static fn (Product $p) => $p->variants->pluck('id'))->all();

        $outletSettings = ProductOutletSetting::query()->withoutGlobalScopes()
            ->where('outlet_id', $outlet->id)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->keyBy('product_variant_id');

        $inventories = OutletInventory::query()->withoutGlobalScopes()
            ->where('outlet_id', $outlet->id)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->keyBy('product_variant_id');

        $customerTier = strtoupper(trim((string) $request->query('customer_tier', 'A')));
        $pricing = $this->pricing;

        $transformed = $products->map(static function (Product $product) use ($outletSettings, $inventories, $includeUnavailable, $outlet, $pricing, $customerTier): ?array {
            $variants = $product->variants->map(static function (ProductVariant $v) use ($product, $outletSettings, $inventories, $outlet, $pricing, $customerTier): array {
                /** @var ProductOutletSetting|null $setting */
                $setting = $outletSettings->get($v->id);
                /** @var OutletInventory|null $inv */
                $inv = $inventories->get($v->id);

                $isAvailable = $setting ? $setting->is_available : true;
                $priceOverride = $setting?->price_override;

                $tierPrices = $pricing->resolveAllTierPrices($v, $outlet);
                $effectivePrice = $tierPrices[$customerTier] ?? ($priceOverride ?? $v->price);

                $variantUnits = $product->productUnits
                    ->filter(static fn (ProductUnit $pu): bool => $pu->product_variant_id === null || (int) $pu->product_variant_id === (int) $v->id)
                    ->values()
                    ->all();

                return [
                    'id' => $v->id,
                    'product_id' => $v->product_id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'cost_price' => $v->cost_price,
                    'average_cost' => $v->average_cost ?? $v->cost_price,
                    'last_purchase_cost' => $v->last_purchase_cost ?? $v->cost_price,
                    'base_price' => $v->price,
                    'wholesale_price' => $v->wholesale_price,
                    'reseller_price' => $v->reseller_price,
                    'min_selling_price' => $v->min_selling_price ?? '0.00',
                    'price_override' => $priceOverride,
                    'effective_price' => $effectivePrice,
                    'tier_prices' => $tierPrices,
                    'selected_tier' => $customerTier,
                    'is_available' => $isAvailable,
                    'is_default' => $v->is_default,
                    'stock_quantity' => $inv ? $inv->quantity : '0.000',
                    'reorder_level' => $inv ? $inv->reorder_level : '0.000',
                    'units' => $variantUnits,
                ];
            })->filter(static fn (array $v): bool => $includeUnavailable || $v['is_available'])->values();

            if ($variants->isEmpty()) {
                return null;
            }

            return [
                'id' => $product->id,
                'category_id' => $product->category_id,
                'category' => $product->category,
                'brand_id' => $product->brand_id,
                'brand' => $product->brand,
                'principal_id' => $product->principal_id,
                'principal' => $product->principal,
                'name' => $product->name,
                'sku' => $product->sku,
                'short_description' => $product->short_description,
                'description' => $product->description,
                'product_type' => $product->product_type ?? 'regular',
                'status' => $product->status ?? 'active',
                'base_price' => $product->base_price,
                'unit' => $product->unit,
                'net_content' => $product->net_content,
                'net_content_unit' => $product->net_content_unit ?? 'ml',
                'abv' => $product->abv,
                'packaging_type' => $product->packaging_type,
                'country_of_origin' => $product->country_of_origin,
                'is_returnable_packaging' => (bool) ($product->is_returnable_packaging ?? false),
                'default_barcode' => $product->default_barcode,
                'track_stock' => $product->track_stock,
                'track_batch' => (bool) ($product->track_batch ?? false),
                'track_expiry' => (bool) ($product->track_expiry ?? false),
                'image_url' => $product->image_url,
                'variants' => $variants->all(),
                'product_units' => $product->productUnits->values()->all(),
                'barcodes' => $product->barcodes->values()->all(),
                'modifiers' => $product->modifiers->values()->all(),
            ];
        })->filter()->values();

        return response()->json([
            'outlet_id' => $outlet->id,
            'products' => $transformed,
        ]);
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

        return response()->json([
            'variant' => [
                'id' => $variant->id,
                'product_id' => $variant->product_id,
                'product_name' => $variant->product->name,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'barcode' => $code,
                'base_price' => $variant->price,
                'price_override' => $setting?->price_override,
                'effective_price' => $resolvedPrice['unit_price'],
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
