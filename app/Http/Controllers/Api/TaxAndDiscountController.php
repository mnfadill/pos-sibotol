<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Outlet;
use App\Services\Audit\AuditLogger;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TaxAndDiscountController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $activeOutlet = $this->context->outlet();

        $accessibleOutlets = Outlet::query()->withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->whereIn('id', $user->accessibleOutletIds())
            ->orderBy('code')
            ->get();

        $discountsQuery = Discount::query()
            ->with('outlet:id,code,name')
            ->where('organization_id', $user->organization_id)
            ->orderByDesc('is_active')
            ->orderBy('code');

        if (! $user->isSuperAdmin() && $activeOutlet !== null) {
            $discountsQuery->where(static function ($q) use ($activeOutlet): void {
                $q->whereNull('outlet_id')
                    ->orWhere('outlet_id', $activeOutlet->id);
            });
        }

        $discounts = $discountsQuery->get();

        return response()->json([
            'active_outlet_config' => $activeOutlet ? [
                'id' => $activeOutlet->id,
                'code' => $activeOutlet->code,
                'name' => $activeOutlet->name,
                'city' => $activeOutlet->city,
                'tax_rate' => $activeOutlet->tax_rate,
                'service_charge_rate' => $activeOutlet->service_charge_rate,
                'max_cashier_discount_percent' => $activeOutlet->max_cashier_discount_percent,
            ] : null,
            'outlets_config' => $accessibleOutlets->map(static fn (Outlet $o): array => [
                'id' => $o->id,
                'code' => $o->code,
                'name' => $o->name,
                'city' => $o->city,
                'tax_rate' => $o->tax_rate,
                'service_charge_rate' => $o->service_charge_rate,
                'max_cashier_discount_percent' => $o->max_cashier_discount_percent,
            ])->values(),
            'discounts' => $discounts,
        ]);
    }

    public function updateOutletTaxConfig(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        $validated = $request->validate([
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'apply_to_all_outlets' => ['nullable', 'boolean'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'service_charge_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_cashier_discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $applyToAll = (bool) ($validated['apply_to_all_outlets'] ?? false);
        $taxRate = number_format((float) $validated['tax_rate'], 2, '.', '');
        $serviceChargeRate = number_format((float) $validated['service_charge_rate'], 2, '.', '');
        $maxCashierDiscount = number_format((float) $validated['max_cashier_discount_percent'], 2, '.', '');

        $updatedOutlets = DB::transaction(function () use (
            $user,
            $validated,
            $applyToAll,
            $taxRate,
            $serviceChargeRate,
            $maxCashierDiscount
        ) {
            if ($applyToAll) {
                $outlets = Outlet::query()->withoutGlobalScopes()
                    ->where('organization_id', $user->organization_id)
                    ->whereIn('id', $user->accessibleOutletIds())
                    ->get();
            } else {
                $targetOutletId = isset($validated['outlet_id'])
                    ? (int) $validated['outlet_id']
                    : $this->context->requireOutlet()->id;

                if (! $user->canAccessOutlet($targetOutletId)) {
                    throw new BusinessRuleException(
                        'OUTLET_ACCESS_DENIED',
                        'Anda tidak memiliki akses untuk mengubah konfigurasi pajak outlet tersebut.',
                        403,
                    );
                }

                $outlets = Outlet::query()->withoutGlobalScopes()
                    ->where('organization_id', $user->organization_id)
                    ->where('id', $targetOutletId)
                    ->get();
            }

            foreach ($outlets as $outlet) {
                $oldValues = [
                    'tax_rate' => $outlet->tax_rate,
                    'service_charge_rate' => $outlet->service_charge_rate,
                    'max_cashier_discount_percent' => $outlet->max_cashier_discount_percent,
                ];

                $outlet->update([
                    'tax_rate' => $taxRate,
                    'service_charge_rate' => $serviceChargeRate,
                    'max_cashier_discount_percent' => $maxCashierDiscount,
                ]);

                $this->audit->record(
                    AuditAction::OUTLET_TAX_CONFIG_UPDATED,
                    $outlet,
                    oldValues: $oldValues,
                    newValues: [
                        'outlet_code' => $outlet->code,
                        'tax_rate' => $outlet->tax_rate,
                        'service_charge_rate' => $outlet->service_charge_rate,
                        'max_cashier_discount_percent' => $outlet->max_cashier_discount_percent,
                    ],
                );
            }

            return $outlets;
        });

        return response()->json([
            'message' => $applyToAll
                ? 'Konfigurasi PPN, Service Charge & Batas Diskon berhasil diterapkan ke seluruh outlet.'
                : 'Konfigurasi PPN, Service Charge & Batas Diskon outlet berhasil disimpan.',
            'updated_outlets_count' => $updatedOutlets->count(),
        ]);
    }

    public function storeDiscount(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();

        $validated = $request->validate([
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'code' => ['required', 'string', 'max:48'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'min:0.01'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['required', 'string', 'in:both,pos,b2b'],
            'is_active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ]);

        if ($validated['type'] === 'percent' && (float) $validated['value'] > 100) {
            throw new BusinessRuleException(
                'INVALID_DISCOUNT_PERCENT',
                'Nilai diskon persentase tidak boleh melebihi 100%.',
                422,
            );
        }

        $code = strtoupper(trim((string) $validated['code']));

        $exists = Discount::query()
            ->where('organization_id', $user->organization_id)
            ->where('code', $code)
            ->exists();

        if ($exists) {
            throw new BusinessRuleException(
                'DUPLICATE_DISCOUNT_CODE',
                sprintf('Kode diskon "%s" sudah terdaftar.', $code),
                422,
            );
        }

        $discount = Discount::query()->create([
            'organization_id' => $user->organization_id,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'code' => $code,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
            'value' => number_format((float) $validated['value'], 2, '.', ''),
            'min_purchase' => number_format((float) ($validated['min_purchase'] ?? 0), 2, '.', ''),
            'max_discount' => isset($validated['max_discount']) && $validated['max_discount'] !== ''
                ? number_format((float) $validated['max_discount'], 2, '.', '')
                : null,
            'applies_to' => $validated['applies_to'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
        ]);

        $this->audit->record(
            AuditAction::DISCOUNT_MASTER_UPDATED,
            $discount,
            newValues: [
                'action' => 'created',
                'code' => $discount->code,
                'name' => $discount->name,
                'type' => $discount->type,
                'value' => $discount->value,
            ],
        );

        return response()->json([
            'discount' => $discount->load('outlet:id,code,name'),
        ], 201);
    }

    public function updateDiscount(Request $request, Discount $discount): JsonResponse
    {
        $user = $this->context->requireUser();

        if ($discount->organization_id !== $user->organization_id) {
            throw new BusinessRuleException('ACCESS_DENIED', 'Diskon tidak ditemukan.', 404);
        }

        $validated = $request->validate([
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
            'code' => ['sometimes', 'required', 'string', 'max:48'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', 'in:percent,fixed'],
            'value' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['sometimes', 'required', 'string', 'in:both,pos,b2b'],
            'is_active' => ['sometimes', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $type = $validated['type'] ?? $discount->type;
        $val = isset($validated['value']) ? (float) $validated['value'] : (float) $discount->value;
        if ($type === 'percent' && $val > 100) {
            throw new BusinessRuleException(
                'INVALID_DISCOUNT_PERCENT',
                'Nilai diskon persentase tidak boleh melebihi 100%.',
                422,
            );
        }

        $oldValues = $discount->only(['code', 'name', 'type', 'value', 'is_active', 'outlet_id']);

        $discount->update([
            'outlet_id' => array_key_exists('outlet_id', $validated) ? $validated['outlet_id'] : $discount->outlet_id,
            'code' => isset($validated['code']) ? strtoupper(trim((string) $validated['code'])) : $discount->code,
            'name' => $validated['name'] ?? $discount->name,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $discount->description,
            'type' => $type,
            'value' => number_format($val, 2, '.', ''),
            'min_purchase' => array_key_exists('min_purchase', $validated)
                ? number_format((float) ($validated['min_purchase'] ?? 0), 2, '.', '')
                : $discount->min_purchase,
            'max_discount' => array_key_exists('max_discount', $validated)
                ? ($validated['max_discount'] !== null && $validated['max_discount'] !== ''
                    ? number_format((float) $validated['max_discount'], 2, '.', '')
                    : null)
                : $discount->max_discount,
            'applies_to' => $validated['applies_to'] ?? $discount->applies_to,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $discount->is_active,
            'valid_from' => array_key_exists('valid_from', $validated) ? $validated['valid_from'] : $discount->valid_from,
            'valid_until' => array_key_exists('valid_until', $validated) ? $validated['valid_until'] : $discount->valid_until,
        ]);

        $this->audit->record(
            AuditAction::DISCOUNT_MASTER_UPDATED,
            $discount,
            oldValues: $oldValues,
            newValues: $discount->only(['code', 'name', 'type', 'value', 'is_active', 'outlet_id']),
        );

        return response()->json([
            'discount' => $discount->fresh('outlet:id,code,name'),
        ]);
    }

    public function destroyDiscount(Discount $discount): JsonResponse
    {
        $user = $this->context->requireUser();

        if ($discount->organization_id !== $user->organization_id) {
            throw new BusinessRuleException('ACCESS_DENIED', 'Diskon tidak ditemukan.', 404);
        }

        $oldValues = $discount->only(['id', 'code', 'name', 'type', 'value']);
        $discount->delete();

        $this->audit->record(
            AuditAction::DISCOUNT_MASTER_UPDATED,
            null,
            oldValues: $oldValues,
            newValues: ['action' => 'deleted'],
        );

        return response()->json([
            'message' => 'Master diskon berhasil dihapus.',
        ]);
    }
}
