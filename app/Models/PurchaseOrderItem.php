<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'product_variant_id', 'product_unit_id',
        'sku', 'product_name', 'variant_name', 'uom_code', 'uom_quantity', 'conversion_factor',
        'ordered_qty', 'received_qty', 'damaged_qty', 'unit_cost', 'landed_unit_cost', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'uom_quantity' => 'decimal:3',
            'conversion_factor' => 'decimal:3',
            'ordered_qty' => 'decimal:3',
            'received_qty' => 'decimal:3',
            'damaged_qty' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'landed_unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

