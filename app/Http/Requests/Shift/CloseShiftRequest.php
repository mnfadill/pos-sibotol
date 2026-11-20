<?php

declare(strict_types=1);

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;

final class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->is_active) {
            return false;
        }

        return $user->hasAnyPermission('shifts.operate', 'shifts.manage', 'pos.checkout')
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
            'actual_cash' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'closing_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

