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
        $user = $this->user();
        if ($user === null || ! $user->is_active) {
            return false;
        }

        return $user->hasAnyPermission('cash_movements.create', 'shifts.operate', 'shifts.manage', 'pos.checkout')
            || $user->hasRole(
                \App\Enums\RoleName::Cashier,
                \App\Enums\RoleName::Supervisor,
                \App\Enums\RoleName::OutletManager,
                \App\Enums\RoleName::AdminOutlet,
                \App\Enums\RoleName::Owner,
                \App\Enums\RoleName::SuperAdmin,
            );
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

