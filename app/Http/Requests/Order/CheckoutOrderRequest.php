<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;

final class CheckoutOrderRequest extends CalculateCartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'payment_scheme' => ['nullable', 'string', Rule::in(['full', 'partial', 'tempo'])],
            'due_date' => ['nullable', 'date'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'string', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'payments.*.tendered_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'payments.*.provider' => ['nullable', 'string', 'max:64'],
            'payments.*.reference_number' => ['nullable', 'string', 'max:128'],
            'payments.*.card_last4' => ['nullable', 'string', 'max:4'],
            'payments.*.qris_payload' => ['nullable', 'string', 'max:1024'],
        ]);
    }
}

