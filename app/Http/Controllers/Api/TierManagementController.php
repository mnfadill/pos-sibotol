<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductTierAssignment;
use App\Models\ProductTierPrice;
use App\Models\ProductVariant;
use App\Models\Tier;
use App\Services\Audit\AuditLogger;
use App\Services\PricingAndCostService;
use App\Support\PosContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TierManagementController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditLogger $audit,
        private readonly PricingAndCostService $pricing,
    ) {}

    /**
     * List all tiers with product and customer stats.
     */
    public function index(): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $tiers = Tier::query()
            ->where('organization_id', $orgId)
            ->withCount([
                'assignments as total_products_count',
                'tierPrices as active_pricing_count' => static fn ($q) => $q->where('status', 'active'),
                'customers as customers_count',
            ])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'tiers' => $tiers,
        ]);
    }

    /**
     * Store new Tier with atomic product pricing configuration (4-Step Wizard).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('tiers', 'code')->where('organization_id', $orgId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'display_order' => ['nullable', 'integer', 'min:1'],
            'color' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'in:active,inactive'],

            // Step 2: Product Pricing Configuration
            'products' => ['nullable', 'array'],
            'products.*.product_id' => ['required_with:products', 'integer', 'exists:products,id'],
            'products.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'products.*.product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'products.*.price' => ['required_with:products', 'numeric', 'min:0'],
            'products.*.effective_from' => ['required_with:products', 'date'],
            'products.*.effective_until' => ['nullable', 'date', 'after_or_equal:products.*.effective_from'],
            'products.*.status' => ['nullable', 'string', 'in:active,inactive'],
            'products.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($user, $orgId, $validated): JsonResponse {
            // 1. Create Tier
            $tier = Tier::query()->create([
                'organization_id' => $orgId,
                'name' => trim((string) $validated['name']),
                'code' => strtoupper(trim((string) $validated['code'])),
                'description' => $validated['description'] ?? null,
                'display_order' => (int) ($validated['display_order'] ?? 1),
                'color' => $validated['color'] ?? '#2563EB',
                'status' => $validated['status'] ?? 'active',
            ]);

            $productsInput = $validated['products'] ?? [];
            $assignedCount = 0;

            foreach ($productsInput as $item) {
                $productId = (int) $item['product_id'];
                $product = Product::query()->where('organization_id', $orgId)->find($productId);
                if ($product === null) {
                    continue;
                }

                $variantId = ! empty($item['product_variant_id'])
                    ? (int) $item['product_variant_id']
                    : (ProductVariant::query()->where('product_id', $productId)->value('id'));

                // 2. Create Assignment
                ProductTierAssignment::query()->updateOrCreate(
                    [
                        'organization_id' => $orgId,
                        'tier_id' => $tier->id,
                        'product_id' => $productId,
                        'product_variant_id' => $variantId,
                    ],
                    [
                        'status' => $item['status'] ?? 'active',
                    ]
                );

                // 3. Create Tier Price Record
                ProductTierPrice::query()->create([
                    'organization_id' => $orgId,
                    'tier_id' => $tier->id,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'product_unit_id' => ! empty($item['product_unit_id']) ? (int) $item['product_unit_id'] : null,
                    'price' => number_format((float) $item['price'], 2, '.', ''),
                    'effective_from' => $item['effective_from'],
                    'effective_until' => $item['effective_until'] ?? null,
                    'status' => $item['status'] ?? 'active',
                    'created_by' => $user->id,
                    'notes' => $item['notes'] ?? 'Initial tier creation price',
                ]);

                $assignedCount++;
            }

            // 4. Audit Log
            $this->audit->record(
                AuditAction::TIER_CREATED,
                $tier,
                newValues: [
                    'name' => $tier->name,
                    'code' => $tier->code,
                    'products_configured' => $assignedCount,
                ],
                actor: $user,
            );

            return response()->json([
                'message' => sprintf('Tier "%s" berhasil dibuat bersama %d konfigurasi harga produk.', $tier->name, $assignedCount),
                'tier' => $tier->loadCount(['assignments', 'tierPrices', 'customers']),
            ], 201);
        });
    }

    /**
     * Show Tier Detail with 4 Tabs data:
     * - General Info
     * - Product & Pricing
     * - Assigned Customers
     * - Price History
     */
    public function show(int $id): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;
        $today = CarbonImmutable::today()->toDateString();

        $tier = Tier::query()
            ->where('organization_id', $orgId)
            ->withCount(['assignments', 'tierPrices', 'customers'])
            ->findOrFail($id);

        // Assigned Products with active prices
        $assignments = ProductTierAssignment::query()
            ->where('tier_id', $tier->id)
            ->whereHas('product')
            ->with([
                'product:id,name,sku,unit,category_id,brand_id',
                'product.category:id,name',
                'product.brand:id,name',
                'variant:id,sku,cost_price,price',
            ])
            ->get();

        $productIds = $assignments->pluck('product_id')->unique()->all();

        $activePrices = ProductTierPrice::query()
            ->where('tier_id', $tier->id)
            ->whereIn('product_id', $productIds)
            ->where('status', 'active')
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('product_id');

        $enrichedAssignments = $assignments->map(static function (ProductTierAssignment $assign) use ($activePrices): array {
            $prodPrices = $activePrices->get($assign->product_id, collect());
            $currentPrice = $prodPrices->first();

            return [
                'id' => $assign->id,
                'tier_id' => $assign->tier_id,
                'product_id' => $assign->product_id,
                'product_variant_id' => $assign->product_variant_id,
                'status' => $assign->status,
                'product' => $assign->product,
                'variant' => $assign->variant,
                'current_price' => $currentPrice ? (string) $currentPrice->price : null,
                'effective_from' => $currentPrice?->effective_from?->toDateString(),
                'effective_until' => $currentPrice?->effective_until?->toDateString(),
                'price_status' => $currentPrice?->status ?? 'none',
            ];
        });

        // Assigned Customers
        $customers = Customer::query()
            ->where('organization_id', $orgId)
            ->where('tier_id', $tier->id)
            ->get(['id', 'code', 'name', 'phone', 'email', 'customer_group', 'city', 'is_active']);

        // Price History
        $priceHistories = ProductTierPrice::query()
            ->where('tier_id', $tier->id)
            ->with([
                'product' => static fn ($q) => $q->withTrashed()->select('id', 'name', 'sku'),
                'creator:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'tier' => $tier,
            'products' => $enrichedAssignments,
            'customers' => $customers,
            'price_history' => $priceHistories,
        ]);
    }

    /**
     * Update Tier General Information.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $tier = Tier::query()->where('organization_id', $orgId)->findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('tiers', 'code')->where('organization_id', $orgId)->ignore($tier->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'display_order' => ['nullable', 'integer', 'min:1'],
            'color' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $old = $tier->toArray();
        $tier->update([
            'name' => trim((string) $validated['name']),
            'code' => strtoupper(trim((string) $validated['code'])),
            'description' => $validated['description'] ?? null,
            'display_order' => (int) ($validated['display_order'] ?? $tier->display_order),
            'color' => $validated['color'] ?? $tier->color,
            'status' => $validated['status'] ?? $tier->status,
        ]);

        $this->audit->record(
            AuditAction::TIER_UPDATED,
            $tier,
            oldValues: $old,
            newValues: $tier->toArray(),
            actor: $user,
        );

        return response()->json([
            'message' => 'Informasi Tier berhasil diperbarui.',
            'tier' => $tier->fresh(),
        ]);
    }

    /**
     * Assign or update a product and its price in this Tier.
     */
    public function assignProduct(Request $request, int $id): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $tier = Tier::query()->where('organization_id', $orgId)->findOrFail($id);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $productId = (int) $validated['product_id'];
        $variantId = ! empty($validated['product_variant_id'])
            ? (int) $validated['product_variant_id']
            : (ProductVariant::query()->where('product_id', $productId)->value('id'));

        return DB::transaction(function () use ($user, $orgId, $tier, $productId, $variantId, $validated): JsonResponse {
            // Assignment
            ProductTierAssignment::query()->updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'tier_id' => $tier->id,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                ],
                [
                    'status' => $validated['status'] ?? 'active',
                ]
            );

            // Add Price Record
            $priceRecord = ProductTierPrice::query()->create([
                'organization_id' => $orgId,
                'tier_id' => $tier->id,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'price' => number_format((float) $validated['price'], 2, '.', ''),
                'effective_from' => $validated['effective_from'],
                'effective_until' => $validated['effective_until'] ?? null,
                'status' => $validated['status'] ?? 'active',
                'created_by' => $user->id,
                'notes' => $validated['notes'] ?? 'Updated via Tier Detail',
            ]);

            $this->audit->record(
                AuditAction::TIER_PRICE_UPDATED,
                $tier,
                newValues: [
                    'product_id' => $productId,
                    'new_price' => $priceRecord->price,
                    'effective_from' => $priceRecord->effective_from,
                ],
                actor: $user,
            );

            return response()->json([
                'message' => 'Produk dan harga berhasil ditambahkan ke Tier.',
                'price' => $priceRecord,
            ]);
        });
    }

    /**
     * Remove a product from a Tier.
     */
    public function removeProduct(int $id, int $productId): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $tier = Tier::query()->where('organization_id', $orgId)->findOrFail($id);

        ProductTierAssignment::query()
            ->where('tier_id', $tier->id)
            ->where('product_id', $productId)
            ->delete();

        ProductTierPrice::query()
            ->where('tier_id', $tier->id)
            ->where('product_id', $productId)
            ->update(['status' => 'inactive']);

        return response()->json([
            'message' => 'Produk berhasil dihapus dari Tier.',
        ]);
    }

    /**
     * Bulk update prices for multiple products in a Tier.
     */
    public function bulkUpdatePrices(Request $request, int $id): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $tier = Tier::query()->where('organization_id', $orgId)->findOrFail($id);

        $validated = $request->validate([
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'updates' => ['required', 'array', 'min:1'],
            'updates.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'updates.*.price' => ['required', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($user, $orgId, $tier, $validated): JsonResponse {
            $count = 0;
            foreach ($validated['updates'] as $up) {
                $productId = (int) $up['product_id'];
                $variantId = ProductVariant::query()->where('product_id', $productId)->value('id');

                ProductTierPrice::query()->create([
                    'organization_id' => $orgId,
                    'tier_id' => $tier->id,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'price' => number_format((float) $up['price'], 2, '.', ''),
                    'effective_from' => $validated['effective_from'],
                    'effective_until' => $validated['effective_until'] ?? null,
                    'status' => 'active',
                    'created_by' => $user->id,
                    'notes' => $validated['notes'] ?? 'Bulk price update',
                ]);
                $count++;
            }

            return response()->json([
                'message' => sprintf('Berhasil memperbarui harga %d produk pada Tier "%s".', $count, $tier->name),
            ]);
        });
    }

    /**
     * Delete / Archive a Tier.
     */
    public function destroy(int $id): JsonResponse
    {
        $user = $this->context->requireUser();
        $orgId = (int) $user->organization_id;

        $tier = Tier::query()->where('organization_id', $orgId)->findOrFail($id);

        $customerCount = Customer::query()->where('tier_id', $tier->id)->count();
        if ($customerCount > 0) {
            throw new BusinessRuleException(
                'TIER_IN_USE',
                sprintf('Tier "%s" tidak dapat dihapus karena masih digunakan oleh %d pelanggan aktif.', $tier->name, $customerCount),
                422,
            );
        }

        $tier->delete();

        $this->audit->record(
            AuditAction::TIER_DELETED,
            $tier,
            actor: $user,
        );

        return response()->json([
            'message' => sprintf('Tier "%s" berhasil dihapus.', $tier->name),
        ]);
    }
}
