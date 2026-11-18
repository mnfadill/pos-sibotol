<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptItem extends Model
{
    protected $fillable = [
        'goods_receipt_id', 'purchase_order_item_id', 'product_variant_id', 'batch_id',
        'batch_number', 'lot_number', 'manufacturing_date', 'expiry_date',
        'uom_code', 'conversion_factor',
        'received_qty', 'accepted_qty', 'damaged_qty', 'discrepancy_qty',
        'unit_cost', 'landed_unit_cost', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'manufacturing_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'conversion_factor' => 'decimal:3',
            'received_qty' => 'decimal:3',
            'accepted_qty' => 'decimal:3',
            'damaged_qty' => 'decimal:3',
            'discrepancy_qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'landed_unit_cost' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

