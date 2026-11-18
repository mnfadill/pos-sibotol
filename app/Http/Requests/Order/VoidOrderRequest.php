<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class VoidOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Supervisor PIN is verified inside OrderCheckoutService::voidOrder
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'supervisor_pin' => ['required', 'string', 'min:4', 'max:16'],
        ];
    }
}

