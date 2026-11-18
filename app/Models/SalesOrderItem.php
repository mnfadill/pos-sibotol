<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    protected $fillable = [
        'sales_order_id', 'product_id', 'product_variant_id', 'product_unit_id',
        'sku', 'product_name', 'variant_name', 'uom_code', 'uom_quantity', 'conversion_factor',
        'base_quantity', 'ordered_qty', 'fulfilled_qty', 'unit_price', 'unit_cost', 'batch_id',
        'discount_amount', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'uom_quantity' => 'decimal:3',
            'conversion_factor' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'ordered_qty' => 'decimal:3',
            'fulfilled_qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

