<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Supervisor PIN is verified inside OrderCheckoutService::refundOrder
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::enum(PaymentMethod::class)],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'restock' => ['required', 'boolean'],
            'supervisor_pin' => ['required', 'string', 'min:4', 'max:16'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}

