<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_adjustment_id
 * @property int $product_variant_id
 * @property string $system_quantity
 * @property string|null $counted_quantity
 * @property string $quantity_change
 * @property string|null $notes
 */
class StockAdjustmentItem extends Model
{
    protected $fillable = ['stock_adjustment_id', 'product_variant_id', 'system_quantity', 'counted_quantity', 'quantity_change', 'notes'];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
            'quantity_change' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<StockAdjustment, $this> */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}

