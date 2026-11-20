<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->is_active) {
            return false;
        }

        return $user->hasAnyPermission('orders.cancel', 'orders.manage', 'pos.checkout')
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
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}

