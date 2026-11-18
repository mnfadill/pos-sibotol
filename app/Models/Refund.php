<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property int $outlet_id
 * @property int $pos_terminal_id
 * @property int $cashier_shift_id
 * @property int $user_id
 * @property int $authorized_by
 * @property string $refund_number
 * @property PaymentMethod $method
 * @property string $amount
 * @property bool $restock
 * @property string $reason
 */
class Refund extends Model
{
    use BelongsToOutlet;
    use HasUuids;

    protected $fillable = [
        'order_id', 'outlet_id', 'pos_terminal_id', 'cashier_shift_id', 'user_id', 'authorized_by',
        'refund_number', 'method', 'amount', 'restock', 'reason',
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
            'amount' => 'decimal:2',
            'restock' => 'boolean',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<RefundItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
