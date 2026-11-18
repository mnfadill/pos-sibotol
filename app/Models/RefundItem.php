<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $refund_id
 * @property int $order_item_id
 * @property int $product_variant_id
 * @property string $quantity
 * @property string $amount
 */
class RefundItem extends Model
{
    protected $fillable = ['refund_id', 'order_item_id', 'product_variant_id', 'quantity', 'amount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
