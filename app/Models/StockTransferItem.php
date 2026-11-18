<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    protected $fillable = [
        'stock_transfer_id', 'product_id', 'product_variant_id', 'batch_id',
        'sku', 'product_name', 'variant_name', 'uom_code', 'conversion_factor',
        'requested_qty', 'approved_qty', 'shipped_qty', 'received_qty',
        'damaged_qty', 'discrepancy_qty', 'unit_cost', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:3',
            'requested_qty' => 'decimal:3',
            'approved_qty' => 'decimal:3',
            'shipped_qty' => 'decimal:3',
            'received_qty' => 'decimal:3',
            'damaged_qty' => 'decimal:3',
            'discrepancy_qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<StockTransfer, $this> */
    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

