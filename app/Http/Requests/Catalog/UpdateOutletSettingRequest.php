<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateOutletSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.outlet_settings') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_available' => ['required', 'boolean'],
            'price_override' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }
}

