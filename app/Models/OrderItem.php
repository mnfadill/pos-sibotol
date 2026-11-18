<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable line snapshot (names / SKU / prices are copied at sale time).
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property string $product_name
 * @property string $variant_name
 * @property string $sku
 * @property string $quantity
 * @property string $original_unit_price
 * @property string $unit_price
 * @property int|null $price_override_by
 * @property string|null $price_override_reason
 * @property list<array{id:int, group_name:string, name:string, price:string}>|null $modifiers
 * @property string $modifiers_total
 * @property string $line_total
 * @property string $refunded_quantity
 * @property string|null $notes
 */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_variant_id', 'product_unit_id',
        'product_name', 'variant_name', 'sku', 'uom_code', 'uom_quantity', 'conversion_factor',
        'base_quantity', 'quantity', 'original_unit_price', 'unit_price', 'unit_cost', 'batch_id',
        'price_override_by', 'price_override_reason', 'modifiers',
        'modifiers_total', 'line_total', 'refunded_quantity', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'uom_quantity' => 'decimal:3',
            'conversion_factor' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'quantity' => 'decimal:3',
            'original_unit_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'modifiers' => 'array',
            'modifiers_total' => 'decimal:2',
            'line_total' => 'decimal:2',
            'refunded_quantity' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
