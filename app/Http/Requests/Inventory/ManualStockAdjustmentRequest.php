<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\StockAdjustmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ManualStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Verified via Supervisor PIN in InventoryService
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(StockAdjustmentType::manualValues())],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'supervisor_pin' => ['required', 'string', 'min:4', 'max:16'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'not_in:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}

