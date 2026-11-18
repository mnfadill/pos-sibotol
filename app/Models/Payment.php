<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property int $outlet_id
 * @property int $cashier_shift_id
 * @property int $user_id
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 * @property string $amount
 * @property string|null $tendered_amount
 * @property string $change_amount
 * @property string|null $provider
 * @property string|null $reference_number
 * @property string|null $card_last4
 * @property string|null $qris_payload
 * @property string|null $idempotency_key
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $paid_at
 */
class Payment extends Model
{
    use BelongsToOutlet;
    use HasUuids;

    protected $fillable = [
        'order_id', 'outlet_id', 'cashier_shift_id', 'user_id', 'method', 'status', 'amount', 'tendered_amount',
        'change_amount', 'provider', 'reference_number', 'card_last4', 'qris_payload', 'idempotency_key',
        'metadata', 'paid_at',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'tendered_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'metadata' => 'array',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<CashierShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }
}
