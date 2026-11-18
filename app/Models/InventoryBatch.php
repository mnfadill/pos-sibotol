<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatch extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'outlet_id',
        'product_id',
        'product_variant_id',
        'batch_number',
        'lot_number',
        'manufacturing_date',
        'expiry_date',
        'initial_quantity',
        'on_hand_quantity',
        'reserved_quantity',
        'damaged_quantity',
        'quarantine_quantity',
        'unit_cost',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'manufacturing_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'initial_quantity' => 'decimal:3',
            'on_hand_quantity' => 'decimal:3',
            'reserved_quantity' => 'decimal:3',
            'damaged_quantity' => 'decimal:3',
            'quarantine_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function setQuantityAttribute($value): void
    {
        $this->attributes['on_hand_quantity'] = (string) $value;
    }

    public function getQuantityAttribute(): string
    {
        return (string) ($this->attributes['on_hand_quantity'] ?? '0.000');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

