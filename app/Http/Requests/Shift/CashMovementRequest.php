<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use App\Enums\CashMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cash_movements.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(CashMovementType::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'supervisor_pin' => ['nullable', 'string', 'max:16'],
        ];
    }
}

