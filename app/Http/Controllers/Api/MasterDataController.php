<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\OutletInventory;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Principal;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductInventorySetting;
use App\Models\ProductModifier;
use App\Models\ProductOutletSetting;
use App\Models\ProductPriceHistory;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\SupplierProduct;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\Audit\AuditLogger;
use App\Services\InventoryEngineService;
use App\Services\PricingAndCostService;
use App\Services\UnitConversionService;
use App\Support\PosContext;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MasterDataController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditLogger $audit,
        private readonly PricingAndCostService $pricing,
        private readonly InventoryEngineService $inventoryEngine,
        private readonly UnitConversionService $unitConversion,
    ) {}

    /**
     * Overview bootstrap endpoint for Master Data Hub UI.
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $categories = Category::query()->withoutGlobalScopes()
            ->with(['parent:id,code,name', 'children:id,parent_id,code,name,slug,color,is_active'])
            ->where('organization_id', $orgId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $brands = Brand::query()
            ->with('principal:id,code,name')
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get();

        $principals = Principal::query()
            ->with('suppliers:id,code,name')
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get();

        $units = Unit::query()
            ->where('organization_id', $orgId)
            ->orderBy('code')
            ->get();

        $priceLists = PriceList::query()
            ->with(['outlet:id,code,name', 'customer:id,code,name', 'items.variant.product:id,name', 'items.productUnit:id,unit_code,unit_name'])
            ->where('organization_id', $orgId)
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        $warehouses = Warehouse::query()->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN type = 'central' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        $outlets = Outlet::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'city']);

        return response()->json([
            'stats' => [
                'total_products' => Product::query()->withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->count(),
                'total_units' => Unit::query()->where('organization_id', $orgId)->count(),
                'total_brands' => Brand::query()->where('organization_id', $orgId)->count(),
                'total_principals' => Principal::query()->where('organization_id', $orgId)->count(),
                'total_price_lists' => PriceList::query()->where('organization_id', $orgId)->count(),
            ],
            'categories' => $categories,
            'brands' => $brands,
            'principals' => $principals,
            'units' => $units,
            'price_lists' => $priceLists,
            'warehouses' => $warehouses,
            'outlets' => $outlets,
        ]);
    }

    // =========================================================================
    // 1. MASTER PRODUCTS (9-TAB ENTERPRISE PRODUCT MANAGEMENT)
    // =========================================================================

    public function productsIndex(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $search = $request->string('search')->toString();
        $categoryId = $request->integer('category_id');
        $brandId = $request->integer('brand_id');
        $principalId = $request->integer('principal_id');
        $status = $request->string('status')->toString();
        $productType = $request->string('product_type')->toString();

        $products = Product::query()->withoutGlobalScopes()
            ->with([
                'category:id,code,name,slug,color,parent_id',
                'brand:id,code,name',
                'principal:id,code,name',
                'baseUnit:id,code,name',
                'variants' => static fn ($q) => $q->orderBy('sort_order'),
                'productUnits' => static fn ($q) => $q->orderBy('conversion_factor'),
                'barcodes',
            ])
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->when($categoryId > 0, static function ($q) use ($categoryId): void {
                $q->where('category_id', $categoryId);
            })
            ->when($brandId > 0, static fn ($q) => $q->where('brand_id', $brandId))
            ->when($principalId > 0, static fn ($q) => $q->where('principal_id', $principalId))
            ->when($status !== '', static function ($q) use ($status): void {
                if ($status === 'active') {
                    $q->where('is_active', true);
                } elseif (in_array($status, ['inactive', 'archived', 'discontinued'], true)) {
                    $q->where('is_active', false);
                }
            })
            ->when($productType !== '', static fn ($q) => $q->where('product_type', $productType))
            ->when($search !== '', static function ($q) use ($search): void {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(static function ($w) use ($like): void {
                    $w->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(sku) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(default_barcode, \'\')) LIKE ?', [$like])
                        ->orWhereHas('variants', static function ($vq) use ($like): void {
                            $vq->whereRaw('LOWER(sku) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(COALESCE(barcode, \'\')) LIKE ?', [$like]);
                        })
                        ->orWhereHas('barcodes', static function ($bq) use ($like): void {
                            $bq->whereRaw('LOWER(barcode) LIKE ?', [$like]);
                        });
                });
            })
            ->orderBy('name')
            ->get();

        // Aggregate total stock across warehouses per product
        $warehouseStocks = WarehouseInventory::query()->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->get()
            ->groupBy('product_id');

        $unitConversion = $this->unitConversion;

        $enriched = $products->map(static function (Product $p) use ($warehouseStocks, $unitConversion): array {
            $stocks = $warehouseStocks->get($p->id, collect());
            $totalOnHand = '0.000';
            $totalReserved = '0.000';
            $totalAvailable = '0.000';

            foreach ($stocks as $whInv) {
                $totalOnHand = Quantity::add($totalOnHand, $whInv->quantity);
                $totalReserved = Quantity::add($totalReserved, $whInv->reserved_quantity);
                $totalAvailable = Quantity::add($totalAvailable, $whInv->available_quantity);
            }

            $reorderLevel = $stocks->first()?->reorder_level ?? '15.000';
            $formattedStock = $unitConversion->formatStockDisplay($totalOnHand, $p);

            return array_merge($p->toArray(), [
                'reorder_level' => $reorderLevel,
                'stock_summary' => [
                    'on_hand' => $totalOnHand,
                    'reserved' => $totalReserved,
                    'available' => $totalAvailable,
                    'reorder_level' => $reorderLevel,
                    'display' => $formattedStock['full_display'],
                    'breakdown' => $formattedStock['breakdown'],
                ],
            ]);
        });

        return response()->json([
            'products' => $enriched,
            'data' => $enriched,
            'meta' => [
                'total' => $enriched->count(),
            ],
        ]);
    }

    public function showProduct(int $id): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $product = Product::query()->withoutGlobalScopes()
            ->with([
                'category:id,code,name,slug,color,parent_id',
                'brand:id,code,name',
                'principal:id,code,name,contact_person,phone',
                'baseUnit:id,code,name',
                'variants' => static fn ($q) => $q->orderBy('sort_order'),
                'productUnits.unit',
                'barcodes.productUnit',
                'inventorySettings.warehouse:id,code,name,type',
                'inventorySettings.outlet:id,code,name',
                'batches' => static fn ($q) => $q->with('warehouse:id,code,name,type')->orderBy('expiry_date'),
                'supplierProducts.supplier:id,code,name,phone,lead_time_days',
                'priceHistories' => static fn ($q) => $q->with(['changer:id,name', 'outlet:id,code,name'])->orderByDesc('created_at')->limit(30),
                'modifiers',
            ])
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $warehouseInventories = WarehouseInventory::query()->withoutGlobalScopes()
            ->with(['warehouse:id,code,name,type', 'outlet:id,code,name', 'variant:id,sku,name'])
            ->where('organization_id', $orgId)
            ->where('product_id', $product->id)
            ->get();

        $outletSettings = ProductOutletSetting::query()->withoutGlobalScopes()
            ->with(['outlet:id,code,name,city'])
            ->where('product_id', $product->id)
            ->get();

        $recentMovements = StockMovement::query()->withoutGlobalScopes()
            ->with(['warehouse:id,code,name,type', 'outlet:id,code,name', 'batch:id,batch_number,expiry_date', 'user:id,name'])
            ->where('product_id', $product->id)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        return response()->json([
            'product' => $product,
            'warehouse_inventories' => $warehouseInventories,
            'outlet_settings' => $outletSettings,
            'recent_movements' => $recentMovements,
        ]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $validated = $request->validate([
            'sku' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'principal_id' => ['nullable', 'integer', 'exists:principals,id'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'product_type' => ['nullable', 'string', 'in:regular,bundle,package,non_inventory'],
            'base_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'unit' => ['nullable', 'string', 'max:24'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'default_barcode' => ['nullable', 'string', 'max:64'],
            'barcode_type' => ['nullable', 'string', 'in:EAN13,CODE128,INTERNAL,QR'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'tier_a_price' => ['nullable', 'numeric', 'min:0'],
            'tier_b_price' => ['nullable', 'numeric', 'min:0'],
            'tier_c_price' => ['nullable', 'numeric', 'min:0'],
            'tier_d_price' => ['nullable', 'numeric', 'min:0'],
            'member_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'reseller_price' => ['nullable', 'numeric', 'min:0'],
            'min_selling_price' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['nullable', 'boolean'],
            'track_batch' => ['nullable', 'boolean'],
            'track_expiry' => ['nullable', 'boolean'],
            'min_expiry_days_alert' => ['nullable', 'integer', 'min:0', 'max:365'],
            'shelf_life_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'net_content' => ['nullable', 'numeric', 'min:0'],
            'net_content_unit' => ['nullable', 'string', 'max:16'],
            'abv' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'packaging_type' => ['nullable', 'string', 'max:64'],
            'country_of_origin' => ['nullable', 'string', 'max:64'],
            'is_returnable_packaging' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:active,inactive,discontinued,archived'],
            'is_active' => ['nullable', 'boolean'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'units' => ['nullable', 'array'],
            'units.*.unit_code' => ['required_with:units', 'string', 'max:24'],
            'units.*.unit_name' => ['required_with:units', 'string', 'max:64'],
            'units.*.conversion_factor' => ['required_with:units', 'numeric', 'gt:0'],
            'units.*.barcode' => ['nullable', 'string', 'max:64'],
            'units.*.is_purchase_unit' => ['nullable', 'boolean'],
            'units.*.is_sales_unit' => ['nullable', 'boolean'],
            'units.*.is_active' => ['nullable', 'boolean'],
            'units.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.tier_a_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.tier_b_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.tier_c_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.tier_d_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.member_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.reseller_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.purchase_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $rawSku = trim((string) ($validated['sku'] ?? ''));
        if ($rawSku === '') {
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $validated['name']) ?: 'PRD', 0, 5));
            $rawSku = 'SBT-'.$prefix.'-'.random_int(100, 999);
        }
        $cleanSku = strtoupper($rawSku);
        $resolvedBarcode = ! empty($validated['default_barcode'])
            ? trim((string) $validated['default_barcode'])
            : (! empty($validated['barcode']) ? trim((string) $validated['barcode']) : null);
        $validated['default_barcode'] = $resolvedBarcode;

        $skuExists = Product::query()->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->whereRaw('UPPER(sku) = ?', [$cleanSku])
            ->whereNull('deleted_at')
            ->exists()
            || ProductVariant::query()->withoutGlobalScopes()
                ->whereRaw('UPPER(sku) = ?', [$cleanSku])
                ->whereNull('deleted_at')
                ->exists();

        if ($skuExists) {
            throw new BusinessRuleException(
                'DUPLICATE_SKU',
                sprintf('SKU "%s" sudah digunakan oleh produk lain.', $cleanSku),
                422,
            );
        }

        if (! empty($validated['default_barcode'])) {
            $cleanBarcode = trim((string) $validated['default_barcode']);
            $this->assertBarcodeUnique($orgId, $cleanBarcode);
        }

        return DB::transaction(function () use ($user, $orgId, $validated, $cleanSku): JsonResponse {
            $baseUnitCode = strtoupper(trim((string) ($validated['unit'] ?? 'BTL')));
            $baseUnitModel = null;
            if (! empty($validated['base_unit_id'])) {
                $baseUnitModel = Unit::query()->find((int) $validated['base_unit_id']);
                if ($baseUnitModel !== null) {
                    $baseUnitCode = $baseUnitModel->code;
                }
            }
            if ($baseUnitModel === null) {
                $baseUnitModel = Unit::query()->firstOrCreate(
                    ['organization_id' => $orgId, 'code' => $baseUnitCode],
                    ['name' => $baseUnitCode, 'is_active' => true],
                );
            }

            $productType = $validated['product_type'] ?? 'regular';
            $trackStock = $productType === 'non_inventory' ? false : (bool) ($validated['track_stock'] ?? true);
            $status = isset($validated['is_active'])
                ? ($validated['is_active'] ? 'active' : 'inactive')
                : ($validated['status'] ?? 'active');
            $reorderLevel = Quantity::normalize($validated['reorder_level'] ?? 15);
            $tierAPrice = isset($validated['tier_a_price']) && $validated['tier_a_price'] !== ''
                ? (float) $validated['tier_a_price']
                : (float) ($validated['base_price'] ?? 0);
            $tierBPrice = isset($validated['tier_b_price']) && $validated['tier_b_price'] !== ''
                ? (float) $validated['tier_b_price']
                : (isset($validated['member_price']) && $validated['member_price'] !== '' ? (float) $validated['member_price'] : round($tierAPrice * 0.95, 2));
            $tierCPrice = isset($validated['tier_c_price']) && $validated['tier_c_price'] !== ''
                ? (float) $validated['tier_c_price']
                : (isset($validated['reseller_price']) && $validated['reseller_price'] !== '' ? (float) $validated['reseller_price'] : round($tierAPrice * 0.90, 2));
            $tierDPrice = isset($validated['tier_d_price']) && $validated['tier_d_price'] !== ''
                ? (float) $validated['tier_d_price']
                : (isset($validated['wholesale_price']) && $validated['wholesale_price'] !== '' ? (float) $validated['wholesale_price'] : round($tierAPrice * 0.85, 2));

            $basePrice = number_format($tierAPrice, 2, '.', '');
            $memberPrice = number_format($tierBPrice, 2, '.', '');
            $resellerPrice = number_format($tierCPrice, 2, '.', '');
            $wholesalePrice = number_format($tierDPrice, 2, '.', '');
            $costPrice = number_format((float) ($validated['cost_price'] ?? ($tierAPrice * 0.65)), 2, '.', '');
            $minSellingPrice = number_format((float) ($validated['min_selling_price'] ?? $costPrice), 2, '.', '');

            $product = Product::query()->withoutGlobalScopes()->create([
                'organization_id' => $orgId,
                'category_id' => (int) $validated['category_id'],
                'brand_id' => $validated['brand_id'] ?? null,
                'principal_id' => $validated['principal_id'] ?? null,
                'base_unit_id' => $baseUnitModel?->id,
                'name' => $validated['name'],
                'sku' => $cleanSku,
                'short_description' => $validated['short_description'] ?? null,
                'description' => $validated['description'] ?? null,
                'manufacturer' => $validated['manufacturer'] ?? null,
                'product_type' => $productType,
                'base_price' => $basePrice,
                'unit' => strtolower($baseUnitCode),
                'net_content' => isset($validated['net_content']) && $validated['net_content'] !== '' ? $validated['net_content'] : null,
                'net_content_unit' => $validated['net_content_unit'] ?? 'ml',
                'abv' => isset($validated['abv']) && $validated['abv'] !== '' ? $validated['abv'] : null,
                'packaging_type' => $validated['packaging_type'] ?? null,
                'country_of_origin' => $validated['country_of_origin'] ?? null,
                'is_returnable_packaging' => (bool) ($validated['is_returnable_packaging'] ?? false),
                'default_barcode' => $validated['default_barcode'] ?? null,
                'track_stock' => $trackStock,
                'track_batch' => (bool) ($validated['track_batch'] ?? false),
                'track_expiry' => (bool) ($validated['track_expiry'] ?? false),
                'shelf_life_days' => (int) ($validated['shelf_life_days'] ?? $validated['min_expiry_days_alert'] ?? 30),
                'is_active' => $status === 'active',
                'image_url' => $validated['image_url'] ?? null,
            ]);

            $variant = ProductVariant::query()->withoutGlobalScopes()->create([
                'organization_id' => $orgId,
                'product_id' => $product->id,
                'name' => 'Standard',
                'sku' => $cleanSku,
                'barcode' => $validated['default_barcode'] ?? null,
                'cost_price' => $costPrice,
                'average_cost' => $costPrice,
                'last_purchase_cost' => $costPrice,
                'price' => $basePrice,
                'member_price' => $memberPrice,
                'reseller_price' => $resellerPrice,
                'wholesale_price' => $wholesalePrice,
                'min_selling_price' => $minSellingPrice,
                'is_default' => true,
                'is_active' => $status === 'active',
                'sort_order' => 1,
            ]);

            // Create Base Unit record in product_units
            $baseProductUnit = ProductUnit::query()->create([
                'organization_id' => $orgId,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'unit_id' => $baseUnitModel?->id,
                'unit_code' => $baseUnitCode,
                'unit_name' => $baseUnitModel?->name ?? $baseUnitCode,
                'conversion_factor' => '1.000',
                'is_base' => true,
                'is_purchase_unit' => empty($validated['units']),
                'is_sales_unit' => empty($validated['units']),
                'barcode' => $validated['default_barcode'] ?? null,
                'selling_price' => $basePrice,
                'member_price' => $memberPrice,
                'reseller_price' => $resellerPrice,
                'wholesale_price' => $wholesalePrice,
                'purchase_cost' => $costPrice,
                'min_purchase_qty' => '1.000',
                'is_active' => true,
            ]);

            if (! empty($validated['default_barcode'])) {
                ProductBarcode::query()->create([
                    'organization_id' => $orgId,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'product_unit_id' => $baseProductUnit->id,
                    'barcode' => trim((string) $validated['default_barcode']),
                    'barcode_type' => $validated['barcode_type'] ?? 'EAN13',
                    'is_primary' => true,
                    'is_active' => true,
                ]);
            }

            // Create additional packaging units if provided (e.g. PACK, DUS)
            if (! empty($validated['units'])) {
                foreach ($validated['units'] as $uRow) {
                    $uCode = strtoupper(trim((string) $uRow['unit_code']));
                    if ($uCode === $baseUnitCode) {
                        continue;
                    }
                    $factor = Quantity::normalize($uRow['conversion_factor']);
                    $uSell = isset($uRow['tier_a_price']) && $uRow['tier_a_price'] !== ''
                        ? number_format((float) $uRow['tier_a_price'], 2, '.', '')
                        : (isset($uRow['selling_price']) && $uRow['selling_price'] !== '' ? number_format((float) $uRow['selling_price'], 2, '.', '') : bcmul($basePrice, $factor, 2));

                    $uMember = isset($uRow['tier_b_price']) && $uRow['tier_b_price'] !== ''
                        ? number_format((float) $uRow['tier_b_price'], 2, '.', '')
                        : (isset($uRow['member_price']) && $uRow['member_price'] !== '' ? number_format((float) $uRow['member_price'], 2, '.', '') : bcmul($memberPrice, $factor, 2));

                    $uReseller = isset($uRow['tier_c_price']) && $uRow['tier_c_price'] !== ''
                        ? number_format((float) $uRow['tier_c_price'], 2, '.', '')
                        : (isset($uRow['reseller_price']) && $uRow['reseller_price'] !== '' ? number_format((float) $uRow['reseller_price'], 2, '.', '') : bcmul($resellerPrice, $factor, 2));

                    $uWholesale = isset($uRow['tier_d_price']) && $uRow['tier_d_price'] !== ''
                        ? number_format((float) $uRow['tier_d_price'], 2, '.', '')
                        : (isset($uRow['wholesale_price']) && $uRow['wholesale_price'] !== '' ? number_format((float) $uRow['wholesale_price'], 2, '.', '') : bcmul($wholesalePrice, $factor, 2));

                    $uCost = isset($uRow['purchase_cost']) && $uRow['purchase_cost'] !== ''
                        ? number_format((float) $uRow['purchase_cost'], 2, '.', '')
                        : bcmul($costPrice, $factor, 2);

                    if (! empty($uRow['barcode'])) {
                        $this->assertBarcodeUnique($orgId, trim((string) $uRow['barcode']));
                    }

                    $unitMaster = Unit::query()->firstOrCreate(
                        ['organization_id' => $orgId, 'code' => $uCode],
                        ['name' => $uRow['unit_name'] ?: $uCode, 'is_active' => true],
                    );

                    $pu = ProductUnit::query()->create([
                        'organization_id' => $orgId,
                        'product_id' => $product->id,
                        'product_variant_id' => $variant->id,
                        'unit_id' => $unitMaster->id,
                        'unit_code' => $uCode,
                        'unit_name' => $uRow['unit_name'],
                        'conversion_factor' => $factor,
                        'is_base' => false,
                        'is_purchase_unit' => isset($uRow['is_purchase_unit']) ? (bool) $uRow['is_purchase_unit'] : true,
                        'is_sales_unit' => isset($uRow['is_sales_unit']) ? (bool) $uRow['is_sales_unit'] : true,
                        'barcode' => $uRow['barcode'] ?? null,
                        'selling_price' => $uSell,
                        'member_price' => $uMember,
                        'reseller_price' => $uReseller,
                        'wholesale_price' => $uWholesale,
                        'purchase_cost' => $uCost,
                        'min_purchase_qty' => '1.000',
                        'is_active' => isset($uRow['is_active']) ? (bool) $uRow['is_active'] : true,
                    ]);

                    if (! empty($uRow['barcode'])) {
                        ProductBarcode::query()->create([
                            'organization_id' => $orgId,
                            'product_id' => $product->id,
                            'product_variant_id' => $variant->id,
                            'product_unit_id' => $pu->id,
                            'barcode' => trim((string) $uRow['barcode']),
                            'barcode_type' => 'EAN13',
                            'is_primary' => false,
                            'is_active' => true,
                        ]);
                    }
                }
            }

            // Initialize zero inventory across active warehouses & outlets
            $warehouses = Warehouse::query()->withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->get();

            foreach ($warehouses as $wh) {
                WarehouseInventory::query()->withoutGlobalScopes()->firstOrCreate(
                    [
                        'warehouse_id' => $wh->id,
                        'product_variant_id' => $variant->id,
                    ],
                    [
                        'organization_id' => $orgId,
                        'outlet_id' => $wh->outlet_id,
                        'product_id' => $product->id,
                        'quantity' => '0.000',
                        'reserved_quantity' => '0.000',
                        'in_transit_quantity' => '0.000',
                        'damaged_quantity' => '0.000',
                        'quarantine_quantity' => '0.000',
                        'reorder_level' => $reorderLevel,
                    ],
                );

                if ($wh->outlet_id !== null) {
                    OutletInventory::query()->withoutGlobalScopes()->firstOrCreate(
                        [
                            'outlet_id' => $wh->outlet_id,
                            'product_variant_id' => $variant->id,
                        ],
                        [
                            'warehouse_id' => $wh->id,
                            'product_id' => $product->id,
                            'quantity' => '0.000',
                            'reorder_level' => $reorderLevel,
                            'version' => 1,
                        ],
                    );
                }
            }

            $this->audit->record(
                AuditAction::MASTER_DATA_UPDATED,
                $product,
                newValues: [
                    'action' => 'product_created',
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'base_price' => $product->base_price,
                ],
                actor: $user,
            );

            return response()->json([
                'product' => $product->load(['category', 'brand', 'principal', 'variants', 'productUnits', 'barcodes']),
            ], 201);
        });
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $product = Product::query()->withoutGlobalScopes()
            ->with('variants')
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $validated = $request->validate([
            'sku' => ['nullable', 'string', 'max:64'],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'principal_id' => ['nullable', 'integer', 'exists:principals,id'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'product_type' => ['sometimes', 'string', 'in:regular,bundle,package,non_inventory'],
            'base_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'unit' => ['nullable', 'string', 'max:24'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'default_barcode' => ['nullable', 'string', 'max:64'],
            'base_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'tier_a_price' => ['nullable', 'numeric', 'min:0'],
            'tier_b_price' => ['nullable', 'numeric', 'min:0'],
            'tier_c_price' => ['nullable', 'numeric', 'min:0'],
            'tier_d_price' => ['nullable', 'numeric', 'min:0'],
            'member_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'reseller_price' => ['nullable', 'numeric', 'min:0'],
            'min_selling_price' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['nullable', 'boolean'],
            'track_batch' => ['nullable', 'boolean'],
            'track_expiry' => ['nullable', 'boolean'],
            'min_expiry_days_alert' => ['nullable', 'integer', 'min:0', 'max:365'],
            'shelf_life_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'net_content' => ['nullable', 'numeric', 'min:0'],
            'net_content_unit' => ['nullable', 'string', 'max:16'],
            'abv' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'packaging_type' => ['nullable', 'string', 'max:64'],
            'country_of_origin' => ['nullable', 'string', 'max:64'],
            'is_returnable_packaging' => ['nullable', 'boolean'],
            'status' => ['sometimes', 'string', 'in:active,inactive,discontinued,archived'],
            'is_active' => ['nullable', 'boolean'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'units' => ['nullable', 'array'],
            'units.*.id' => ['nullable', 'integer'],
            'units.*.unit_code' => ['required_with:units', 'string', 'max:24'],
            'units.*.unit_name' => ['required_with:units', 'string', 'max:64'],
            'units.*.conversion_factor' => ['required_with:units', 'numeric', 'gt:0'],
            'units.*.barcode' => ['nullable', 'string', 'max:64'],
            'units.*.is_purchase_unit' => ['nullable', 'boolean'],
            'units.*.is_sales_unit' => ['nullable', 'boolean'],
            'units.*.is_active' => ['nullable', 'boolean'],
            'units.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.tier_a_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.tier_b_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.tier_c_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.tier_d_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.member_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.reseller_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.purchase_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($user, $orgId, $product, $validated): JsonResponse {
            $oldBasePrice = (string) $product->base_price;
            $status = isset($validated['is_active'])
                ? ($validated['is_active'] ? 'active' : 'inactive')
                : ($validated['status'] ?? $product->status ?? 'active');

            $productFields = array_intersect_key($validated, array_flip([
                'name', 'short_description', 'description', 'category_id', 'brand_id',
                'principal_id', 'manufacturer', 'product_type', 'base_price',
                'net_content', 'net_content_unit', 'abv', 'packaging_type', 'country_of_origin',
                'is_returnable_packaging',
                'track_stock', 'track_batch', 'track_expiry', 'image_url',
            ]));

            if (! empty($validated['sku'])) {
                $cleanSku = strtoupper(trim((string) $validated['sku']));
                $productFields['sku'] = $cleanSku;
            }

            if (array_key_exists('default_barcode', $validated) || array_key_exists('barcode', $validated)) {
                $newBc = $validated['default_barcode'] ?? $validated['barcode'] ?? null;
                $productFields['default_barcode'] = $newBc !== null && trim((string) $newBc) !== '' ? trim((string) $newBc) : null;
            }

            $baseUnitCode = strtoupper(trim((string) ($validated['unit'] ?? $product->unit ?? 'BTL')));
            $baseUnitModel = null;
            if (! empty($validated['base_unit_id'])) {
                $baseUnitModel = Unit::query()->find((int) $validated['base_unit_id']);
                if ($baseUnitModel !== null) {
                    $baseUnitCode = $baseUnitModel->code;
                }
            }
            if ($baseUnitModel === null && $baseUnitCode !== '') {
                $baseUnitModel = Unit::query()->firstOrCreate(
                    ['organization_id' => $orgId, 'code' => $baseUnitCode],
                    ['name' => $baseUnitCode, 'is_active' => true],
                );
            }
            if ($baseUnitModel !== null) {
                $productFields['base_unit_id'] = $baseUnitModel->id;
                $productFields['unit'] = strtolower($baseUnitCode);
            }

            if (isset($validated['tier_a_price'])) {
                $productFields['base_price'] = number_format((float) $validated['tier_a_price'], 2, '.', '');
            }

            if (isset($validated['shelf_life_days'])) {
                $productFields['shelf_life_days'] = (int) $validated['shelf_life_days'];
            } elseif (isset($validated['min_expiry_days_alert'])) {
                $productFields['shelf_life_days'] = (int) $validated['min_expiry_days_alert'];
            }
            if (isset($validated['status'])) {
                $productFields['is_active'] = $validated['status'] === 'active';
            } elseif (isset($validated['is_active'])) {
                $productFields['is_active'] = (bool) $validated['is_active'];
            }

            $product->update($productFields);

            /** @var ProductVariant|null $defaultVariant */
            $defaultVariant = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();
            if ($defaultVariant !== null) {
                $variantUpdate = ['is_active' => $product->is_active];
                if (! empty($productFields['sku'])) {
                    $variantUpdate['sku'] = $productFields['sku'];
                }
                if (array_key_exists('default_barcode', $productFields)) {
                    $variantUpdate['barcode'] = $productFields['default_barcode'];
                }
                if (isset($validated['tier_a_price'])) {
                    $variantUpdate['price'] = number_format((float) $validated['tier_a_price'], 2, '.', '');
                } elseif (isset($validated['base_price'])) {
                    $variantUpdate['price'] = number_format((float) $validated['base_price'], 2, '.', '');
                }
                if (isset($validated['tier_b_price'])) {
                    $variantUpdate['member_price'] = number_format((float) $validated['tier_b_price'], 2, '.', '');
                } elseif (isset($validated['member_price'])) {
                    $variantUpdate['member_price'] = number_format((float) $validated['member_price'], 2, '.', '');
                }
                if (isset($validated['tier_c_price'])) {
                    $variantUpdate['reseller_price'] = number_format((float) $validated['tier_c_price'], 2, '.', '');
                } elseif (isset($validated['reseller_price'])) {
                    $variantUpdate['reseller_price'] = number_format((float) $validated['reseller_price'], 2, '.', '');
                }
                if (isset($validated['tier_d_price'])) {
                    $variantUpdate['wholesale_price'] = number_format((float) $validated['tier_d_price'], 2, '.', '');
                } elseif (isset($validated['wholesale_price'])) {
                    $variantUpdate['wholesale_price'] = number_format((float) $validated['wholesale_price'], 2, '.', '');
                }
                if (isset($validated['cost_price'])) {
                    $variantUpdate['cost_price'] = number_format((float) $validated['cost_price'], 2, '.', '');
                    if ((float) $defaultVariant->average_cost <= 0) {
                        $variantUpdate['average_cost'] = $variantUpdate['cost_price'];
                    }
                }
                if (isset($validated['min_selling_price'])) {
                    $variantUpdate['min_selling_price'] = number_format((float) $validated['min_selling_price'], 2, '.', '');
                }

                $defaultVariant->update($variantUpdate);

                // Sync base ProductUnit
                $basePu = ProductUnit::query()
                    ->where('product_id', $product->id)
                    ->where('is_base', true)
                    ->first();

                if ($basePu !== null) {
                    $basePu->update([
                        'unit_id' => $baseUnitModel?->id ?? $basePu->unit_id,
                        'unit_code' => $baseUnitCode,
                        'unit_name' => $baseUnitModel?->name ?? $baseUnitCode,
                        'barcode' => $defaultVariant->barcode,
                        'selling_price' => (string) $defaultVariant->price,
                        'member_price' => (string) $defaultVariant->member_price,
                        'reseller_price' => (string) $defaultVariant->reseller_price,
                        'wholesale_price' => (string) $defaultVariant->wholesale_price,
                        'purchase_cost' => (string) $defaultVariant->cost_price,
                    ]);
                } else {
                    $basePu = ProductUnit::query()->create([
                        'organization_id' => $orgId,
                        'product_id' => $product->id,
                        'product_variant_id' => $defaultVariant->id,
                        'unit_id' => $baseUnitModel?->id,
                        'unit_code' => $baseUnitCode,
                        'unit_name' => $baseUnitModel?->name ?? $baseUnitCode,
                        'conversion_factor' => '1.000',
                        'is_base' => true,
                        'is_purchase_unit' => true,
                        'is_sales_unit' => true,
                        'barcode' => $defaultVariant->barcode,
                        'selling_price' => (string) $defaultVariant->price,
                        'member_price' => (string) $defaultVariant->member_price,
                        'reseller_price' => (string) $defaultVariant->reseller_price,
                        'wholesale_price' => (string) $defaultVariant->wholesale_price,
                        'purchase_cost' => (string) $defaultVariant->cost_price,
                        'min_purchase_qty' => '1.000',
                        'is_active' => true,
                    ]);
                }

                // Sync packaging units if 'units' array was sent
                if (array_key_exists('units', $validated) && is_array($validated['units'])) {
                    $keptUnitIds = [$basePu->id];
                    $basePriceStr = (string) $defaultVariant->price;
                    $memberPriceStr = (string) ($defaultVariant->member_price ?? $basePriceStr);
                    $resellerPriceStr = (string) ($defaultVariant->reseller_price ?? $basePriceStr);
                    $wholesalePriceStr = (string) ($defaultVariant->wholesale_price ?? $basePriceStr);
                    $costPriceStr = (string) $defaultVariant->cost_price;

                    foreach ($validated['units'] as $uRow) {
                        $uCode = strtoupper(trim((string) $uRow['unit_code']));
                        if ($uCode === '' || $uCode === $baseUnitCode) {
                            continue;
                        }

                        $factor = Quantity::normalize($uRow['conversion_factor']);
                        $uSell = isset($uRow['tier_a_price']) && $uRow['tier_a_price'] !== ''
                            ? number_format((float) $uRow['tier_a_price'], 2, '.', '')
                            : (isset($uRow['selling_price']) && $uRow['selling_price'] !== '' ? number_format((float) $uRow['selling_price'], 2, '.', '') : bcmul($basePriceStr, $factor, 2));

                        $uMember = isset($uRow['tier_b_price']) && $uRow['tier_b_price'] !== ''
                            ? number_format((float) $uRow['tier_b_price'], 2, '.', '')
                            : (isset($uRow['member_price']) && $uRow['member_price'] !== '' ? number_format((float) $uRow['member_price'], 2, '.', '') : bcmul($memberPriceStr, $factor, 2));

                        $uReseller = isset($uRow['tier_c_price']) && $uRow['tier_c_price'] !== ''
                            ? number_format((float) $uRow['tier_c_price'], 2, '.', '')
                            : (isset($uRow['reseller_price']) && $uRow['reseller_price'] !== '' ? number_format((float) $uRow['reseller_price'], 2, '.', '') : bcmul($resellerPriceStr, $factor, 2));

                        $uWholesale = isset($uRow['tier_d_price']) && $uRow['tier_d_price'] !== ''
                            ? number_format((float) $uRow['tier_d_price'], 2, '.', '')
                            : (isset($uRow['wholesale_price']) && $uRow['wholesale_price'] !== '' ? number_format((float) $uRow['wholesale_price'], 2, '.', '') : bcmul($wholesalePriceStr, $factor, 2));

                        $uCost = isset($uRow['purchase_cost']) && $uRow['purchase_cost'] !== ''
                            ? number_format((float) $uRow['purchase_cost'], 2, '.', '')
                            : bcmul($costPriceStr, $factor, 2);

                        $unitMaster = Unit::query()->firstOrCreate(
                            ['organization_id' => $orgId, 'code' => $uCode],
                            ['name' => $uRow['unit_name'] ?: $uCode, 'is_active' => true],
                        );

                        $existingPu = null;
                        if (! empty($uRow['id'])) {
                            $existingPu = ProductUnit::query()->where('product_id', $product->id)->find((int) $uRow['id']);
                        }
                        if ($existingPu === null) {
                            $existingPu = ProductUnit::query()
                                ->where('product_id', $product->id)
                                ->where('unit_code', $uCode)
                                ->where('is_base', false)
                                ->first();
                        }

                        $cleanBc = ! empty($uRow['barcode']) ? trim((string) $uRow['barcode']) : null;

                        $puPayload = [
                            'organization_id' => $orgId,
                            'product_id' => $product->id,
                            'product_variant_id' => $defaultVariant->id,
                            'unit_id' => $unitMaster->id,
                            'unit_code' => $uCode,
                            'unit_name' => $uRow['unit_name'] ?: $uCode,
                            'conversion_factor' => $factor,
                            'is_base' => false,
                            'is_purchase_unit' => isset($uRow['is_purchase_unit']) ? (bool) $uRow['is_purchase_unit'] : true,
                            'is_sales_unit' => isset($uRow['is_sales_unit']) ? (bool) $uRow['is_sales_unit'] : true,
                            'barcode' => $cleanBc,
                            'selling_price' => $uSell,
                            'member_price' => $uMember,
                            'reseller_price' => $uReseller,
                            'wholesale_price' => $uWholesale,
                            'purchase_cost' => $uCost,
                            'min_purchase_qty' => '1.000',
                            'is_active' => isset($uRow['is_active']) ? (bool) $uRow['is_active'] : true,
                        ];

                        if ($existingPu !== null) {
                            $existingPu->update($puPayload);
                            $pu = $existingPu;
                        } else {
                            $pu = ProductUnit::query()->create($puPayload);
                        }

                        $keptUnitIds[] = $pu->id;

                        if ($cleanBc !== null) {
                            ProductBarcode::query()->updateOrCreate(
                                [
                                    'organization_id' => $orgId,
                                    'product_id' => $product->id,
                                    'product_unit_id' => $pu->id,
                                ],
                                [
                                    'product_variant_id' => $defaultVariant->id,
                                    'barcode' => $cleanBc,
                                    'barcode_type' => 'EAN13',
                                    'is_primary' => false,
                                    'is_active' => true,
                                ],
                            );
                        } else {
                            ProductBarcode::query()
                                ->where('product_id', $product->id)
                                ->where('product_unit_id', $pu->id)
                                ->delete();
                        }
                    }

                    // Remove or deactivate non-base units not in the updated list
                    $removedUnits = ProductUnit::query()
                        ->where('product_id', $product->id)
                        ->where('is_base', false)
                        ->whereNotIn('id', $keptUnitIds)
                        ->get();

                    foreach ($removedUnits as $remPu) {
                        ProductBarcode::query()->where('product_unit_id', $remPu->id)->delete();
                        $remPu->delete();
                    }
                }

                // Sync reorder_level (minimum stock) across warehouses and outlets if provided
                if (isset($validated['reorder_level']) && $validated['reorder_level'] !== '') {
                    $reorderLevel = Quantity::normalize($validated['reorder_level']);
                    WarehouseInventory::query()->withoutGlobalScopes()
                        ->where('product_id', $product->id)
                        ->update(['reorder_level' => $reorderLevel]);
                    OutletInventory::query()->withoutGlobalScopes()
                        ->where('product_id', $product->id)
                        ->update(['reorder_level' => $reorderLevel]);
                }

                if (isset($variantUpdate['price']) && bccomp($oldBasePrice, (string) $defaultVariant->price, 2) !== 0) {
                    ProductPriceHistory::query()->create([
                        'organization_id' => $orgId,
                        'product_id' => $product->id,
                        'product_variant_id' => $defaultVariant->id,
                        'price_type' => 'base_retail',
                        'old_price' => $oldBasePrice,
                        'new_price' => (string) $defaultVariant->price,
                        'old_cost' => (string) $defaultVariant->cost_price,
                        'new_cost' => (string) $defaultVariant->cost_price,
                        'reason' => 'Master Product price updated',
                        'changed_by' => $user->id,
                    ]);
                }
            }

            return response()->json([
                'product' => $product->fresh(['category', 'brand', 'principal', 'variants', 'productUnits', 'barcodes']),
            ]);
        });
    }

    public function bulkUpdateProducts(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:products,id'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'items.*.brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'items.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.tier_a_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.tier_b_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.tier_c_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.tier_d_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.reorder_level' => ['nullable', 'numeric', 'min:0'],
            'items.*.is_active' => ['nullable', 'boolean'],
        ]);

        $updatedCount = DB::transaction(function () use ($user, $orgId, $validated): int {
            $count = 0;
            foreach ($validated['items'] as $item) {
                $product = Product::query()->withoutGlobalScopes()
                    ->with('variants')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at')
                    ->find((int) $item['id']);

                if ($product === null) {
                    continue;
                }

                $prodUpdate = [];
                if (isset($item['category_id'])) {
                    $prodUpdate['category_id'] = (int) $item['category_id'];
                }
                if (array_key_exists('brand_id', $item)) {
                    $prodUpdate['brand_id'] = $item['brand_id'] ? (int) $item['brand_id'] : null;
                }
                if (isset($item['tier_a_price'])) {
                    $prodUpdate['base_price'] = number_format((float) $item['tier_a_price'], 2, '.', '');
                }
                if (isset($item['is_active'])) {
                    $prodUpdate['is_active'] = (bool) $item['is_active'];
                }
                if ($prodUpdate !== []) {
                    $product->update($prodUpdate);
                }

                $variant = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();
                if ($variant !== null) {
                    $varUpdate = [];
                    if (isset($item['cost_price'])) {
                        $varUpdate['cost_price'] = number_format((float) $item['cost_price'], 2, '.', '');
                    }
                    if (isset($item['tier_a_price'])) {
                        $varUpdate['price'] = number_format((float) $item['tier_a_price'], 2, '.', '');
                    }
                    if (isset($item['tier_b_price'])) {
                        $varUpdate['member_price'] = number_format((float) $item['tier_b_price'], 2, '.', '');
                    }
                    if (isset($item['tier_c_price'])) {
                        $varUpdate['reseller_price'] = number_format((float) $item['tier_c_price'], 2, '.', '');
                    }
                    if (isset($item['tier_d_price'])) {
                        $varUpdate['wholesale_price'] = number_format((float) $item['tier_d_price'], 2, '.', '');
                    }
                    if (isset($item['is_active'])) {
                        $varUpdate['is_active'] = (bool) $item['is_active'];
                    }
                    if ($varUpdate !== []) {
                        $variant->update($varUpdate);
                    }
                }

                if (isset($item['reorder_level'])) {
                    $rl = Quantity::normalize($item['reorder_level']);
                    WarehouseInventory::query()->withoutGlobalScopes()
                        ->where('product_id', $product->id)
                        ->update(['reorder_level' => $rl]);
                    OutletInventory::query()->withoutGlobalScopes()
                        ->where('product_id', $product->id)
                        ->update(['reorder_level' => $rl]);
                }

                $count++;
            }

            $this->audit->record(
                AuditAction::MASTER_DATA_UPDATED,
                null,
                newValues: ['action' => 'bulk_update_products', 'updated_count' => $count],
                actor: $user,
            );

            return $count;
        });

        return response()->json([
            'message' => "{$updatedCount} produk berhasil diperbarui secara massal.",
            'updated_count' => $updatedCount,
        ]);
    }

    public function archiveProduct(int $id): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $product = Product::query()->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $product->update([
            'is_active' => false,
        ]);
        $product->delete();

        $product->variants()->update(['is_active' => false]);
        $product->variants()->delete();

        ProductBarcode::query()->where('product_id', $product->id)->update(['is_active' => false]);
        ProductUnit::query()->withoutGlobalScopes()->where('product_id', $product->id)->update(['is_active' => false]);

        $this->audit->record(
            AuditAction::PRODUCT_ARCHIVED,
            $product,
            newValues: ['sku' => $product->sku, 'status' => 'archived'],
            actor: $user,
        );

        return response()->json([
            'message' => 'Produk berhasil diarsipkan.',
            'product' => $product,
        ]);
    }

    public function destroyProduct(int $id): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $product = Product::query()->withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->findOrFail($id);

        return DB::transaction(function () use ($product, $user, $orgId): JsonResponse {
            $timestamp = time();
            $originalSku = $product->sku;
            $archivedSku = str_contains($originalSku, '__DELETED_')
                ? $originalSku
                : $originalSku . '__DELETED_' . $timestamp;

            // 1. Delete barcodes so the barcode numbers are immediately freed for future products
            ProductBarcode::query()->where('product_id', $product->id)->delete();

            // 2. Mark product units and outlet settings as inactive / unavailable
            ProductUnit::query()->withoutGlobalScopes()->where('product_id', $product->id)->update(['is_active' => false]);
            ProductOutletSetting::query()->withoutGlobalScopes()->where('product_id', $product->id)->update(['is_available' => false]);

            // 3. Clean up non-transactional metadata
            ProductModifier::query()->where('product_id', $product->id)->delete();
            DB::table('product_tier_assignments')->where('product_id', $product->id)->delete();
            DB::table('product_tier_prices')->where('product_id', $product->id)->delete();
            DB::table('price_list_items')->where('product_id', $product->id)->delete();

            // 4. Archive product SKU and deactivate product
            $product->update([
                'sku' => $archivedSku,
                'is_active' => false,
            ]);

            // 5. Archive variant SKUs, deactivate variants, and soft-delete variants
            foreach ($product->variants()->withoutGlobalScopes()->get() as $variant) {
                $variantSku = $variant->sku;
                $archivedVariantSku = str_contains($variantSku, '__DELETED_')
                    ? $variantSku
                    : $variantSku . '__DELETED_' . $timestamp;

                $variant->update([
                    'sku' => $archivedVariantSku,
                    'is_active' => false,
                ]);
                $variant->delete();
            }

            // 6. Soft-delete the product
            $product->delete();

            $this->audit->record(
                AuditAction::PRODUCT_ARCHIVED,
                $product,
                newValues: ['sku' => $originalSku, 'action' => 'deleted'],
                actor: $user,
            );

            return response()->json([
                'deleted' => true,
                'message' => 'Produk berhasil dihapus.',
            ]);
        });
    }

    public function updateTierPrices(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $validated = $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'tier_a_price' => ['sometimes', 'numeric', 'min:0'],
            'member_price' => ['sometimes', 'numeric', 'min:0'],
            'tier_b_price' => ['sometimes', 'numeric', 'min:0'],
            'reseller_price' => ['sometimes', 'numeric', 'min:0'],
            'tier_c_price' => ['sometimes', 'numeric', 'min:0'],
            'wholesale_price' => ['sometimes', 'numeric', 'min:0'],
            'tier_d_price' => ['sometimes', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'numeric', 'min:0'],
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $variant = ProductVariant::query()->withoutGlobalScopes()
            ->with('product')
            ->whereHas('product', fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail((int) $validated['product_variant_id']);

        $update = [];
        if (isset($validated['tier_a_price'])) {
            $update['price'] = number_format((float) $validated['tier_a_price'], 2, '.', '');
        } elseif (isset($validated['price'])) {
            $update['price'] = number_format((float) $validated['price'], 2, '.', '');
        }

        if (isset($validated['tier_b_price'])) {
            $update['member_price'] = number_format((float) $validated['tier_b_price'], 2, '.', '');
        } elseif (isset($validated['member_price'])) {
            $update['member_price'] = number_format((float) $validated['member_price'], 2, '.', '');
        }

        if (isset($validated['tier_c_price'])) {
            $update['reseller_price'] = number_format((float) $validated['tier_c_price'], 2, '.', '');
        } elseif (isset($validated['reseller_price'])) {
            $update['reseller_price'] = number_format((float) $validated['reseller_price'], 2, '.', '');
        }

        if (isset($validated['tier_d_price'])) {
            $update['wholesale_price'] = number_format((float) $validated['tier_d_price'], 2, '.', '');
        } elseif (isset($validated['wholesale_price'])) {
            $update['wholesale_price'] = number_format((float) $validated['wholesale_price'], 2, '.', '');
        }

        if (isset($validated['cost_price'])) {
            $update['cost_price'] = number_format((float) $validated['cost_price'], 2, '.', '');
        }

        $oldPrice = (string) $variant->price;
        $variant->update($update);

        if (isset($update['price'])) {
            $variant->product->update(['base_price' => $update['price']]);

            if (bccomp($oldPrice, $update['price'], 2) !== 0) {
                ProductPriceHistory::query()->create([
                    'organization_id' => $orgId,
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'outlet_id' => $validated['outlet_id'] ?? null,
                    'price_type' => 'tier_a_retail',
                    'old_price' => $oldPrice,
                    'new_price' => $update['price'],
                    'old_cost' => (string) $variant->cost_price,
                    'new_cost' => (string) ($update['cost_price'] ?? $variant->cost_price),
                    'reason' => $validated['reason'] ?? '4 Tier Pricing updated',
                    'changed_by' => $user->id,
                ]);
            }
        }

        if (! empty($validated['outlet_id']) && isset($update['price'])) {
            ProductOutletSetting::query()->updateOrCreate(
                [
                    'outlet_id' => (int) $validated['outlet_id'],
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                ],
                [
                    'price_override' => $update['price'],
                    'is_available' => true,
                    'updated_by' => $user->id,
                ]
            );
        }

        return response()->json([
            'message' => '4 Tier Harga Customer berhasil diperbarui.',
            'variant' => $variant->fresh(),
        ]);
    }

    public function importProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'products' => ['required', 'array', 'min:1'],
            'products.*.sku' => ['required', 'string', 'max:64'],
            'products.*.name' => ['required', 'string', 'max:160'],
            'products.*.category_id' => ['required', 'integer', 'exists:categories,id'],
            'products.*.base_price' => ['required', 'numeric', 'min:0'],
            'products.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'products.*.unit' => ['nullable', 'string', 'max:24'],
            'products.*.default_barcode' => ['nullable', 'string', 'max:64'],
        ]);

        $imported = [];
        foreach ($validated['products'] as $row) {
            $subReq = Request::create('/api/v1/products', 'POST', $row);
            $res = $this->storeProduct($subReq);
            $data = $res->getData(true);
            $imported[] = $data['product'];
        }

        return response()->json([
            'imported_count' => count($imported),
            'products' => $imported,
        ], 201);
    }

    // =========================================================================
    // 2. MASTER CATEGORIES, BRANDS & PRINCIPALS
    // =========================================================================

    public function categoriesIndex(): JsonResponse
    {
        $user = $this->context->requireUser();

        $categories = Category::query()->withoutGlobalScopes()
            ->with(['parent:id,code,name', 'children'])
            ->where('organization_id', $user->organization_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:24'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $category = Category::query()->withoutGlobalScopes()->create([
            'organization_id' => $user->organization_id,
            'parent_id' => $validated['parent_id'] ?? null,
            'code' => strtoupper(trim((string) $validated['code'])),
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.strtolower(trim((string) $validated['code'])),
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? '#2563EB',
            'sort_order' => (int) ($validated['sort_order'] ?? 10),
            'is_active' => true,
        ]);

        return response()->json(['category' => $category->load('parent')], 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $user = $this->context->requireUser();

        $category = Category::query()->withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:24'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category->update($validated);

        return response()->json(['category' => $category->fresh('parent')]);
    }

    public function brandsIndex(): JsonResponse
    {
        $user = $this->context->requireUser();

        $brands = Brand::query()
            ->with('principal:id,code,name')
            ->where('organization_id', $user->organization_id)
            ->orderBy('name')
            ->get();

        return response()->json(['brands' => $brands]);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'principal_id' => ['nullable', 'integer', 'exists:principals,id'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $brand = Brand::query()->create([
            'organization_id' => $user->organization_id,
            'principal_id' => $validated['principal_id'] ?? null,
            'code' => strtoupper(trim((string) $validated['code'])),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return response()->json(['brand' => $brand->load('principal')], 201);
    }

    public function updateBrand(Request $request, int $id): JsonResponse
    {
        $user = $this->context->requireUser();

        $brand = Brand::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'principal_id' => ['nullable', 'integer', 'exists:principals,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $brand->update($validated);

        return response()->json(['brand' => $brand->fresh('principal')]);
    }

    public function principalsIndex(): JsonResponse
    {
        $user = $this->context->requireUser();

        $principals = Principal::query()
            ->with(['brands:id,principal_id,code,name', 'suppliers:id,code,name'])
            ->where('organization_id', $user->organization_id)
            ->orderBy('name')
            ->get();

        return response()->json(['principals' => $principals]);
    }

    public function storePrincipal(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'supplier_ids' => ['nullable', 'array'],
            'supplier_ids.*' => ['integer', 'exists:suppliers,id'],
        ]);

        $principal = Principal::query()->create([
            'organization_id' => $user->organization_id,
            'code' => strtoupper(trim((string) $validated['code'])),
            'name' => $validated['name'],
            'contact_person' => $validated['contact_person'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'is_active' => true,
        ]);

        if (! empty($validated['supplier_ids'])) {
            $principal->suppliers()->sync($validated['supplier_ids']);
        }

        return response()->json(['principal' => $principal->load(['brands', 'suppliers'])], 201);
    }

    public function updatePrincipal(Request $request, int $id): JsonResponse
    {
        $user = $this->context->requireUser();

        $principal = Principal::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'supplier_ids' => ['nullable', 'array'],
            'supplier_ids.*' => ['integer', 'exists:suppliers,id'],
        ]);

        $principal->update($validated);
        if (array_key_exists('supplier_ids', $validated)) {
            $principal->suppliers()->sync($validated['supplier_ids'] ?? []);
        }

        return response()->json(['principal' => $principal->fresh(['brands', 'suppliers'])]);
    }

    // =========================================================================
    // 3. MASTER UNITS & PRODUCT MULTI-UOM CONVERSIONS
    // =========================================================================

    public function unitsIndex(): JsonResponse
    {
        $user = $this->context->requireUser();

        $units = Unit::query()
            ->where('organization_id', $user->organization_id)
            ->orderBy('code')
            ->get();

        return response()->json(['units' => $units]);
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:24'],
            'name' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $unit = Unit::query()->create([
            'organization_id' => $user->organization_id,
            'code' => strtoupper(trim((string) $validated['code'])),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return response()->json(['unit' => $unit], 201);
    }

    public function updateUnit(Request $request, int $id): JsonResponse
    {
        $user = $this->context->requireUser();

        $unit = Unit::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $unit->update($validated);

        return response()->json(['unit' => $unit]);
    }

    public function destroyUnit(int $id): JsonResponse
    {
        $user = $this->context->requireUser();

        $unit = Unit::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $unit->delete();

        return response()->json(['deleted' => true, 'message' => 'Satuan master berhasil dihapus.']);
    }

    public function productUnitsIndex(int $productId): JsonResponse
    {
        $user = $this->context->requireUser();

        $product = Product::query()->withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->findOrFail($productId);

        $units = ProductUnit::query()
            ->with('unit')
            ->where('product_id', $product->id)
            ->orderBy('conversion_factor')
            ->get();

        return response()->json(['product_units' => $units]);
    }

    public function storeProductUnit(Request $request, int $productId): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $product = Product::query()->withoutGlobalScopes()
            ->with('variants')
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->findOrFail($productId);

        $validated = $request->validate([
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'unit_code' => ['required', 'string', 'max:24'],
            'unit_name' => ['required', 'string', 'max:64'],
            'conversion_factor' => ['required', 'numeric', 'gt:0'],
            'is_base_unit' => ['nullable', 'boolean'],
            'is_default_pos_unit' => ['nullable', 'boolean'],
            'is_default_purchase_unit' => ['nullable', 'boolean'],
            'is_default_sales_order_unit' => ['nullable', 'boolean'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'tier_a_price' => ['nullable', 'numeric', 'min:0'],
            'member_price' => ['nullable', 'numeric', 'min:0'],
            'tier_b_price' => ['nullable', 'numeric', 'min:0'],
            'reseller_price' => ['nullable', 'numeric', 'min:0'],
            'tier_c_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'tier_d_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'min_selling_price' => ['nullable', 'numeric', 'min:0'],
            'weight_grams' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! empty($validated['barcode'])) {
            $this->assertBarcodeUnique($orgId, trim((string) $validated['barcode']));
        }

        $variant = isset($validated['product_variant_id'])
            ? $product->variants->firstWhere('id', (int) $validated['product_variant_id'])
            : ($product->variants->firstWhere('is_default', true) ?? $product->variants->first());

        $factor = Quantity::normalize($validated['conversion_factor']);
        $sellingPrice = isset($validated['tier_a_price']) && $validated['tier_a_price'] !== ''
            ? number_format((float) $validated['tier_a_price'], 2, '.', '')
            : (isset($validated['selling_price']) && $validated['selling_price'] !== ''
                ? number_format((float) $validated['selling_price'], 2, '.', '')
                : ($variant ? bcmul((string) $variant->price, $factor, 2) : null));

        $memberPrice = isset($validated['tier_b_price']) && $validated['tier_b_price'] !== ''
            ? number_format((float) $validated['tier_b_price'], 2, '.', '')
            : (isset($validated['member_price']) && $validated['member_price'] !== ''
                ? number_format((float) $validated['member_price'], 2, '.', '')
                : ($variant && $variant->member_price ? bcmul((string) $variant->member_price, $factor, 2) : ($sellingPrice ? bcmul($sellingPrice, '0.95', 2) : null)));

        $resellerPrice = isset($validated['tier_c_price']) && $validated['tier_c_price'] !== ''
            ? number_format((float) $validated['tier_c_price'], 2, '.', '')
            : (isset($validated['reseller_price']) && $validated['reseller_price'] !== ''
                ? number_format((float) $validated['reseller_price'], 2, '.', '')
                : ($variant && $variant->reseller_price ? bcmul((string) $variant->reseller_price, $factor, 2) : ($sellingPrice ? bcmul($sellingPrice, '0.90', 2) : null)));

        $wholesalePrice = isset($validated['tier_d_price']) && $validated['tier_d_price'] !== ''
            ? number_format((float) $validated['tier_d_price'], 2, '.', '')
            : (isset($validated['wholesale_price']) && $validated['wholesale_price'] !== ''
                ? number_format((float) $validated['wholesale_price'], 2, '.', '')
                : ($variant && $variant->wholesale_price ? bcmul((string) $variant->wholesale_price, $factor, 2) : ($sellingPrice ? bcmul($sellingPrice, '0.85', 2) : null)));

        $purchaseCost = isset($validated['purchase_cost']) && $validated['purchase_cost'] !== ''
            ? number_format((float) $validated['purchase_cost'], 2, '.', '')
            : ($variant ? bcmul((string) $variant->cost_price, $factor, 2) : null);

        $pu = ProductUnit::query()->create([
            'organization_id' => $orgId,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'unit_id' => $validated['unit_id'] ?? null,
            'unit_code' => strtoupper(trim((string) $validated['unit_code'])),
            'unit_name' => $validated['unit_name'],
            'conversion_factor' => $factor,
            'is_base' => (bool) ($validated['is_base_unit'] ?? $validated['is_base'] ?? false),
            'is_purchase_unit' => (bool) ($validated['is_default_purchase_unit'] ?? $validated['is_purchase_unit'] ?? false),
            'is_sales_unit' => (bool) ($validated['is_default_sales_order_unit'] ?? $validated['is_sales_unit'] ?? false),
            'barcode' => $validated['barcode'] ?? null,
            'selling_price' => $sellingPrice,
            'member_price' => $memberPrice,
            'reseller_price' => $resellerPrice,
            'wholesale_price' => $wholesalePrice,
            'purchase_cost' => $purchaseCost,
            'min_purchase_qty' => '1.000',
            'is_active' => true,
        ]);

        if (! empty($validated['barcode'])) {
            ProductBarcode::query()->create([
                'organization_id' => $orgId,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_unit_id' => $pu->id,
                'barcode' => trim((string) $validated['barcode']),
                'barcode_type' => 'EAN13',
                'is_primary' => false,
                'is_active' => true,
            ]);
        }

        return response()->json(['product_unit' => $pu], 201);
    }

    public function updateProductUnit(Request $request, int $productId, int $unitId): JsonResponse
    {
        $user = $this->context->requireUser();

        $product = Product::query()->withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->findOrFail($productId);

        $pu = ProductUnit::query()
            ->where('product_id', $product->id)
            ->findOrFail($unitId);

        $validated = $request->validate([
            'unit_name' => ['sometimes', 'required', 'string', 'max:64'],
            'conversion_factor' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'tier_a_price' => ['nullable', 'numeric', 'min:0'],
            'member_price' => ['nullable', 'numeric', 'min:0'],
            'tier_b_price' => ['nullable', 'numeric', 'min:0'],
            'reseller_price' => ['nullable', 'numeric', 'min:0'],
            'tier_c_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'tier_d_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'min_selling_price' => ['nullable', 'numeric', 'min:0'],
            'is_default_pos_unit' => ['sometimes', 'boolean'],
            'is_default_purchase_unit' => ['sometimes', 'boolean'],
            'is_default_sales_order_unit' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['tier_a_price'])) {
            $validated['selling_price'] = $validated['tier_a_price'];
        }
        if (isset($validated['tier_b_price'])) {
            $validated['member_price'] = $validated['tier_b_price'];
        }
        if (isset($validated['tier_c_price'])) {
            $validated['reseller_price'] = $validated['tier_c_price'];
        }
        if (isset($validated['tier_d_price'])) {
            $validated['wholesale_price'] = $validated['tier_d_price'];
        }

        if (isset($validated['conversion_factor'])) {
            $newFactor = Quantity::normalize($validated['conversion_factor']);
            if ($pu->is_locked && Quantity::compare($newFactor, $pu->conversion_factor) !== 0) {
                throw new BusinessRuleException(
                    'UNIT_CONVERSION_LOCKED',
                    sprintf('Nilai konversi satuan "%s" sudah digunakan dalam transaksi dan tidak dapat diubah.', $pu->unit_code),
                    422,
                );
            }
            $validated['conversion_factor'] = $newFactor;
        }

        $pu->update($validated);

        return response()->json(['product_unit' => $pu]);
    }

    public function destroyProductUnit(int $productId, int $unitId): JsonResponse
    {
        $user = $this->context->requireUser();

        $product = Product::query()->withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->findOrFail($productId);

        $pu = ProductUnit::query()
            ->where('product_id', $product->id)
            ->findOrFail($unitId);

        if ($pu->is_base) {
            throw new BusinessRuleException(
                'CANNOT_DELETE_BASE_UNIT',
                'Satuan dasar produk tidak boleh dihapus.',
                422,
            );
        }

        $pu->delete();

        return response()->json(['deleted' => true, 'message' => 'Satuan kemasan berhasil dihapus.']);
    }

    // =========================================================================
    // 4. MULTI-BARCODE MANAGEMENT
    // =========================================================================

    public function productBarcodesIndex(int $productId): JsonResponse
    {
        $user = $this->context->requireUser();

        $barcodes = ProductBarcode::query()
            ->with(['productUnit:id,unit_code,unit_name,conversion_factor', 'variant:id,sku,name'])
            ->where('organization_id', $user->organization_id)
            ->where('product_id', $productId)
            ->orderByDesc('is_primary')
            ->get();

        return response()->json(['barcodes' => $barcodes]);
    }

    public function storeProductBarcode(Request $request, int $productId): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $product = Product::query()->withoutGlobalScopes()
            ->with('variants')
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->findOrFail($productId);

        $validated = $request->validate([
            'barcode' => ['required', 'string', 'max:64'],
            'barcode_type' => ['nullable', 'string', 'in:EAN13,CODE128,INTERNAL,QR'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'is_primary' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $cleanBarcode = trim((string) $validated['barcode']);
        $this->assertBarcodeUnique($orgId, $cleanBarcode);

        $variant = isset($validated['product_variant_id'])
            ? $product->variants->firstWhere('id', (int) $validated['product_variant_id'])
            : ($product->variants->firstWhere('is_default', true) ?? $product->variants->first());

        $pb = ProductBarcode::query()->create([
            'organization_id' => $orgId,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'product_unit_id' => $validated['product_unit_id'] ?? null,
            'barcode' => $cleanBarcode,
            'barcode_type' => $validated['barcode_type'] ?? 'EAN13',
            'is_primary' => (bool) ($validated['is_primary'] ?? false),
            'is_active' => true,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'barcode' => $pb->load(['productUnit', 'variant']),
        ], 201);
    }

    public function destroyProductBarcode(int $productId, int $barcodeId): JsonResponse
    {
        $user = $this->context->requireUser();

        $pb = ProductBarcode::query()
            ->where('organization_id', $user->organization_id)
            ->where('product_id', $productId)
            ->findOrFail($barcodeId);

        $pb->delete();

        return response()->json(['deleted' => true]);
    }

    // =========================================================================
    // 5. PRICE LISTS & UNIFIED PRICING RESOLUTION
    // =========================================================================

    public function priceListsIndex(): JsonResponse
    {
        $user = $this->context->requireUser();

        $priceLists = PriceList::query()
            ->with([
                'outlet:id,code,name',
                'customer:id,code,name',
                'items.variant.product:id,name,sku,unit',
                'items.productUnit:id,unit_code,unit_name,conversion_factor',
            ])
            ->where('organization_id', $user->organization_id)
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        return response()->json(['price_lists' => $priceLists]);
    }

    public function storePriceList(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:retail,wholesale,reseller,promo,customer,outlet'],
            'customer_group' => ['nullable', 'string', 'max:32'],
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer'],
            'items' => ['nullable', 'array'],
            'items.*.product_variant_id' => ['required_with:items', 'integer', 'exists:product_variants,id'],
            'items.*.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'items.*.min_qty' => ['nullable', 'numeric', 'min:1'],
            'items.*.price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return DB::transaction(function () use ($user, $orgId, $validated): JsonResponse {
            $priceList = PriceList::query()->create([
                'organization_id' => $orgId,
                'code' => strtoupper(trim((string) $validated['code'])),
                'name' => $validated['name'],
                'type' => $validated['type'],
                'customer_group' => $validated['customer_group'] ?? null,
                'outlet_id' => $validated['outlet_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'priority' => (int) ($validated['priority'] ?? 10),
                'is_active' => true,
            ]);

            if (! empty($validated['items'])) {
                foreach ($validated['items'] as $row) {
                    $variant = ProductVariant::query()->withoutGlobalScopes()->findOrFail((int) $row['product_variant_id']);
                    PriceListItem::query()->create([
                        'price_list_id' => $priceList->id,
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'product_unit_id' => $row['product_unit_id'] ?? null,
                        'min_qty' => Quantity::normalize($row['min_qty'] ?? 1),
                        'price' => number_format((float) $row['price'], 2, '.', ''),
                        'discount_percent' => number_format((float) ($row['discount_percent'] ?? 0), 2, '.', ''),
                        'discount_amount' => '0.00',
                    ]);
                }
            }

            $this->audit->record(
                AuditAction::PRICE_LIST_UPDATED,
                $priceList,
                newValues: ['code' => $priceList->code, 'name' => $priceList->name, 'type' => $priceList->type],
                actor: $user,
            );

            return response()->json([
                'price_list' => $priceList->load(['outlet', 'customer', 'items.variant.product', 'items.productUnit']),
            ], 201);
        });
    }

    public function updatePriceList(Request $request, int $id): JsonResponse
    {
        $user = $this->context->requireUser();

        $priceList = PriceList::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'type' => ['sometimes', 'required', 'string', 'in:retail,wholesale,reseller,promo,customer,outlet'],
            'customer_group' => ['nullable', 'string', 'max:32'],
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'items' => ['nullable', 'array'],
            'items.*.product_variant_id' => ['required_with:items', 'integer', 'exists:product_variants,id'],
            'items.*.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'items.*.min_qty' => ['nullable', 'numeric', 'min:1'],
            'items.*.price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return DB::transaction(function () use ($priceList, $validated): JsonResponse {
            $priceList->update($validated);

            if (array_key_exists('items', $validated) && is_array($validated['items'])) {
                $priceList->items()->delete();
                foreach ($validated['items'] as $row) {
                    $variant = ProductVariant::query()->withoutGlobalScopes()->findOrFail((int) $row['product_variant_id']);
                    PriceListItem::query()->create([
                        'price_list_id' => $priceList->id,
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'product_unit_id' => $row['product_unit_id'] ?? null,
                        'min_qty' => Quantity::normalize($row['min_qty'] ?? 1),
                        'price' => number_format((float) $row['price'], 2, '.', ''),
                        'discount_percent' => number_format((float) ($row['discount_percent'] ?? 0), 2, '.', ''),
                        'discount_amount' => '0.00',
                    ]);
                }
            }

            return response()->json([
                'price_list' => $priceList->fresh(['outlet', 'customer', 'items.variant.product', 'items.productUnit']),
            ]);
        });
    }

    public function productPriceHistory(int $productId): JsonResponse
    {
        $user = $this->context->requireUser();

        $histories = ProductPriceHistory::query()
            ->with(['variant:id,sku,name', 'outlet:id,code,name', 'changer:id,name'])
            ->where('organization_id', $user->organization_id)
            ->where('product_id', $productId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['price_histories' => $histories]);
    }

    public function customerTiers(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $priceLists = PriceList::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereIn('type', ['tier_a', 'retail', 'tier_b', 'member', 'tier_c', 'reseller', 'tier_d', 'wholesale', 'vip'])
            ->get()
            ->groupBy('type');

        $tiers = [
            [
                'tier_code' => 'A',
                'name' => 'Customer A',
                'label' => 'Retail / Umum',
                'subtitle' => 'Walk-in & Retail Standar',
                'description' => 'Pelanggan walk-in / eceran langsung di kasir counter sales dengan harga standar outlet.',
                'pricing_rule' => 'Standard Outlet Base Price (100%)',
                'default_discount_pct' => 0.0,
                'target_market' => 'Walk-in Guest / Konsumen Akhir',
                'badge_color' => 'blue',
                'is_default' => true,
                'has_custom_price_list' => $priceLists->has('tier_a') || $priceLists->has('retail'),
            ],
            [
                'tier_code' => 'B',
                'name' => 'Customer B',
                'label' => 'Member / Langganan',
                'subtitle' => 'Pelanggan Setia / Silver Member',
                'description' => 'Pelanggan tetap dengan potongan harga khusus member 5% dari harga jual retail counter.',
                'pricing_rule' => 'Member Special Price (5% Discount or Dedicated Price List)',
                'default_discount_pct' => 5.0,
                'target_market' => 'Regular Loyal Buyers / Member',
                'badge_color' => 'emerald',
                'is_default' => false,
                'has_custom_price_list' => $priceLists->has('tier_b') || $priceLists->has('member'),
            ],
            [
                'tier_code' => 'C',
                'name' => 'Customer C',
                'label' => 'Reseller / Warung Mitra',
                'subtitle' => 'Toko Mitra / Gold Reseller',
                'description' => 'Mitra warung & toko binaan menggunakan master harga reseller terstruktur atau diskon 10%.',
                'pricing_rule' => 'Master Reseller Price (Variant reseller_price or 10% Discount)',
                'default_discount_pct' => 10.0,
                'target_market' => 'Warung Binaan / Reseller Mitra',
                'badge_color' => 'amber',
                'is_default' => false,
                'has_custom_price_list' => $priceLists->has('tier_c') || $priceLists->has('reseller'),
            ],
            [
                'tier_code' => 'D',
                'name' => 'Customer D',
                'label' => 'Grosir VIP / Wholesale',
                'subtitle' => 'Partai Besar / Platinum VIP',
                'description' => 'Pembeli grosir volume tinggi & B2B counter dengan master harga wholesale atau diskon 15%.',
                'pricing_rule' => 'Master Wholesale Price (Variant wholesale_price or 15% Discount)',
                'default_discount_pct' => 15.0,
                'target_market' => 'Grosir Partai Besar / VIP Counter',
                'badge_color' => 'purple',
                'is_default' => false,
                'has_custom_price_list' => $priceLists->has('tier_d') || $priceLists->has('wholesale') || $priceLists->has('vip'),
            ],
        ];

        return response()->json([
            'tiers' => $tiers,
            'summary' => [
                'total_tiers' => 4,
                'active_crm_integrated' => false,
                'note' => 'Operational counter pricing tiering linked directly to Counter Sales Cashier without requiring external CRM integration.',
            ],
        ]);
    }

    public function resolvePrice(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->outlet();

        $validated = $request->validate([
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_tier' => ['nullable', 'string', 'max:32'],
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'product_unit_id' => ['nullable', 'integer'],
            'uom_code' => ['nullable', 'string', 'max:24'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $variant = ProductVariant::query()->withoutGlobalScopes()
            ->with('product')
            ->findOrFail((int) $validated['product_variant_id']);

        if (isset($validated['outlet_id'])) {
            $outlet = Outlet::query()
                ->where('organization_id', $user->organization_id)
                ->find((int) $validated['outlet_id']);
        }

        $customer = isset($validated['customer_id'])
            ? Customer::query()->withoutGlobalScopes()
                ->where('organization_id', $user->organization_id)
                ->find((int) $validated['customer_id'])
            : null;

        $qty = $validated['quantity'] ?? 1;
        $uom = $this->pricing->resolveUnitConversion(
            variant: $variant,
            productUnitId: isset($validated['product_unit_id']) ? (int) $validated['product_unit_id'] : null,
            uomCode: $validated['uom_code'] ?? null,
            uomQty: $qty,
        );

        $resolved = $this->pricing->resolveSellingPrice(
            variant: $variant,
            outlet: $outlet,
            customer: $customer,
            productUnit: $uom['product_unit'],
            uomQty: $qty,
            customerTier: $validated['customer_tier'] ?? null,
        );

        return response()->json([
            'uom' => [
                'uom_code' => $uom['uom_code'],
                'uom_quantity' => $uom['uom_quantity'],
                'conversion_factor' => $uom['conversion_factor'],
                'base_quantity' => $uom['base_quantity'],
            ],
            'pricing' => $resolved,
        ]);
    }

    // =========================================================================
    // 6. STOCK LEDGER, BATCH & EXPIRY (FEFO) & INVENTORY SETTINGS
    // =========================================================================

    public function stockLedger(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $activeOutlet = $this->context->outlet();

        $warehouseId = $request->integer('warehouse_id');
        $outletId = $request->integer('outlet_id');
        $variantId = $request->integer('product_variant_id');
        $type = $request->string('type')->toString();
        $allWarehouses = $request->boolean('all_warehouses', true);

        $movements = StockMovement::query()->withoutGlobalScopes()
            ->with([
                'product:id,name,sku,unit',
                'variant:id,sku,name',
                'warehouse:id,code,name,type',
                'outlet:id,code,name',
                'batch:id,batch_number,expiry_date',
                'user:id,name',
            ])
            ->where(static function ($q) use ($user): void {
                $q->where('organization_id', $user->organization_id)
                    ->orWhereNull('organization_id');
            })
            ->when($warehouseId > 0, static fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($outletId > 0, static fn ($q) => $q->where('outlet_id', $outletId))
            ->when(! $allWarehouses && $warehouseId === 0 && $outletId === 0 && $activeOutlet !== null, static function ($q) use ($activeOutlet): void {
                $q->where('outlet_id', $activeOutlet->id);
            })
            ->when($variantId > 0, static fn ($q) => $q->where('product_variant_id', $variantId))
            ->when($type !== '', static fn ($q) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $reservations = StockReservation::query()
            ->with(['product:id,name,sku,unit', 'variant:id,sku,name', 'warehouse:id,code,name', 'outlet:id,code,name', 'batch:id,batch_number'])
            ->where('organization_id', $user->organization_id)
            ->where('status', 'active')
            ->when($warehouseId > 0, static fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderByDesc('reserved_at')
            ->get();

        return response()->json([
            'movements' => $movements,
            'data' => $movements,
            'active_reservations' => $reservations,
        ]);
    }

    public function batchesIndex(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $warehouseId = $request->integer('warehouse_id');
        $productId = $request->integer('product_id');
        $status = $request->string('status')->toString();

        $batches = InventoryBatch::query()
            ->with([
                'product:id,name,sku,unit,shelf_life_days',
                'variant:id,sku,name',
                'warehouse:id,code,name,type',
                'outlet:id,code,name',
            ])
            ->where('organization_id', $user->organization_id)
            ->when($warehouseId > 0, static fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($productId > 0, static fn ($q) => $q->where('product_id', $productId))
            ->when($status !== '', static fn ($q) => $q->where('status', $status))
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('expiry_date')
            ->get();

        return response()->json(['batches' => $batches]);
    }

    public function expiringBatches(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $days = max(1, $request->integer('days', 60));
        $thresholdDate = CarbonImmutable::today()->addDays($days)->toDateString();

        $batches = InventoryBatch::query()
            ->with([
                'product:id,name,sku,unit,shelf_life_days',
                'variant:id,sku,name',
                'warehouse:id,code,name,type',
                'outlet:id,code,name',
            ])
            ->where('organization_id', $user->organization_id)
            ->where('on_hand_quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $thresholdDate)
            ->orderBy('expiry_date')
            ->get();

        return response()->json([
            'threshold_days' => $days,
            'threshold_date' => $thresholdDate,
            'expiring_batches' => $batches,
        ]);
    }

    public function storeBatch(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'batch_number' => ['required', 'string', 'max:64'],
            'lot_number' => ['nullable', 'string', 'max:64'],
            'manufacturing_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
        ]);

        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->findOrFail((int) $validated['warehouse_id']);

        $variant = ProductVariant::query()->withoutGlobalScopes()
            ->with('product')
            ->findOrFail((int) $validated['product_variant_id']);

        $batch = $this->inventoryEngine->receiveIntoBatch(
            organizationId: (int) $user->organization_id,
            warehouseId: $warehouse->id,
            outletId: $warehouse->outlet_id,
            variant: $variant,
            batchNumber: $validated['batch_number'],
            acceptedBaseQty: Quantity::normalize($validated['quantity']),
            lotNumber: $validated['lot_number'] ?? null,
            manufacturingDate: $validated['manufacturing_date'] ?? null,
            expiryDate: $validated['expiry_date'] ?? null,
            unitCost: number_format((float) ($validated['unit_cost'] ?? $variant->cost_price ?? 0), 2, '.', ''),
            supplierId: isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
        );

        return response()->json([
            'batch' => $batch->load(['product', 'variant', 'warehouse', 'outlet']),
        ], 201);
    }

    public function updateBatchStatus(Request $request, int $batchId): JsonResponse
    {
        $user = $this->context->requireUser();

        $batch = InventoryBatch::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($batchId);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:available,quarantine,damaged,expired'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($user, $batch, $validated): JsonResponse {
            $oldStatus = $batch->status;
            $newStatus = $validated['status'];

            $whInv = WarehouseInventory::query()->withoutGlobalScopes()
                ->where('warehouse_id', $batch->warehouse_id)
                ->where('product_variant_id', $batch->product_variant_id)
                ->lockForUpdate()
                ->first();

            if ($whInv !== null && $oldStatus !== $newStatus) {
                $qty = Quantity::normalize($batch->quantity);
                // Adjust quarantine / damaged counters on WarehouseInventory
                $quarantine = Quantity::normalize($whInv->quarantine_quantity ?? '0.000');
                $damaged = Quantity::normalize($whInv->damaged_quantity ?? '0.000');

                if ($oldStatus === 'quarantine') {
                    $quarantine = Quantity::maxZero(Quantity::sub($quarantine, $qty));
                } elseif (in_array($oldStatus, ['damaged', 'expired'], true)) {
                    $damaged = Quantity::maxZero(Quantity::sub($damaged, $qty));
                }

                if ($newStatus === 'quarantine') {
                    $quarantine = Quantity::add($quarantine, $qty);
                } elseif (in_array($newStatus, ['damaged', 'expired'], true)) {
                    $damaged = Quantity::add($damaged, $qty);
                }

                $whInv->update([
                    'quarantine_quantity' => $quarantine,
                    'damaged_quantity' => $damaged,
                ]);
            }

            $batch->update([
                'status' => $newStatus,
                'notes' => $validated['notes'] ?? $batch->notes,
            ]);

            $this->audit->record(
                AuditAction::BATCH_UPDATED,
                $batch,
                oldValues: ['status' => $oldStatus],
                newValues: ['batch_number' => $batch->batch_number, 'status' => $newStatus],
                actor: $user,
            );

            return response()->json([
                'batch' => $batch->fresh(['product', 'variant', 'warehouse', 'outlet']),
            ]);
        });
    }

    public function saveProductInventorySetting(Request $request, int $productId): JsonResponse
    {
        $user = $this->context->requireUser();

        $product = Product::query()->withoutGlobalScopes()
            ->with('variants')
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->findOrFail($productId);

        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'min_stock' => ['required', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_point' => ['required', 'numeric', 'min:0'],
            'safety_stock' => ['nullable', 'numeric', 'min:0'],
            'default_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'allow_negative_stock' => ['nullable', 'boolean'],
        ]);

        $variant = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();

        $setting = ProductInventorySetting::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'outlet_id' => $validated['outlet_id'] ?? null,
            ],
            [
                'organization_id' => $user->organization_id,
                'min_stock' => Quantity::normalize($validated['min_stock']),
                'max_stock' => Quantity::normalize($validated['max_stock'] ?? 0),
                'reorder_point' => Quantity::normalize($validated['reorder_point']),
                'safety_stock' => Quantity::normalize($validated['safety_stock'] ?? 0),
                'default_warehouse_id' => $validated['default_warehouse_id'] ?? $validated['warehouse_id'] ?? null,
                'allow_negative_stock' => (bool) ($validated['allow_negative_stock'] ?? false),
            ],
        );

        return response()->json([
            'inventory_setting' => $setting->load(['warehouse', 'outlet']),
        ]);
    }

    public function saveSupplierProduct(Request $request, int $productId): JsonResponse
    {
        $user = $this->context->requireUser();

        $product = Product::query()->withoutGlobalScopes()
            ->with('variants')
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->findOrFail($productId);

        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'supplier_sku' => ['nullable', 'string', 'max:64'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'min_order_qty' => ['nullable', 'numeric', 'gt:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $variant = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();

        $sp = SupplierProduct::query()->updateOrCreate(
            [
                'organization_id' => $user->organization_id,
                'supplier_id' => (int) $validated['supplier_id'],
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
            ],
            [
                'supplier_sku' => $validated['supplier_sku'] ?? null,
                'purchase_price' => number_format((float) $validated['purchase_price'], 2, '.', ''),
                'min_order_qty' => Quantity::normalize($validated['min_order_qty'] ?? 1),
                'lead_time_days' => (int) ($validated['lead_time_days'] ?? 3),
                'is_default_supplier' => (bool) ($validated['is_primary'] ?? true),
            ],
        );

        return response()->json([
            'supplier_product' => $sp->load('supplier'),
        ]);
    }

    private function assertBarcodeUnique(int $organizationId, string $barcode): void
    {
        if ($barcode === '') {
            return;
        }

        $existsInBarcodes = ProductBarcode::query()
            ->where('organization_id', $organizationId)
            ->where('barcode', $barcode)
            ->exists();

        $existsInVariants = ProductVariant::query()->withoutGlobalScopes()
            ->where('barcode', $barcode)
            ->whereNull('deleted_at')
            ->exists();

        if ($existsInBarcodes || $existsInVariants) {
            throw new BusinessRuleException(
                'DUPLICATE_BARCODE',
                sprintf('Barcode "%s" sudah terdaftar pada produk/kemasan lain.', $barcode),
                422,
            );
        }
    }
}
