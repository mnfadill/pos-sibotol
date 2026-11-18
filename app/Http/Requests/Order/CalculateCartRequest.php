<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalculateCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('pos.checkout') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'crm_customer_ref' => ['nullable', 'string', 'max:64'],
            'customer_tier' => ['nullable', 'string', 'max:32'],
            'table_label' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'discount_type' => ['nullable', 'string', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'supervisor_pin' => ['nullable', 'string', 'max:16'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.product_unit_id' => ['nullable', 'integer'],
            'items.*.uom_code' => ['nullable', 'string', 'max:24'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'items.*.unit_price_override' => ['nullable', 'numeric', 'min:0'],
            'items.*.price_override_reason' => ['nullable', 'string', 'max:255'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer', 'exists:product_modifiers,id'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}

