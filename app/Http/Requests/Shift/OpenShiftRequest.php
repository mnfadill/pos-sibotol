<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;

final class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('shifts.operate') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'opening_cash' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'opening_notes' => ['nullable', 'string', 'max:500'],
            'opened_at' => ['nullable', 'date'],
            'shift_date' => ['nullable', 'date'],
        ];
    }
}

