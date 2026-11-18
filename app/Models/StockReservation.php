<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'outlet_id',
        'product_id',
        'product_variant_id',
        'batch_id',
        'reference_type',
        'reference_id',
        'reserved_quantity',
        'quantity',
        'consumed_quantity',
        'released_quantity',
        'status',
        'reserved_at',
        'consumed_at',
        'released_at',
        'notes',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $res): void {
            if ((!isset($res->attributes['reserved_quantity']) || $res->attributes['reserved_quantity'] === null) && isset($res->attributes['quantity'])) {
                $res->attributes['reserved_quantity'] = (string) $res->attributes['quantity'];
            }
            unset($res->attributes['quantity']);
        });
    }

    protected function casts(): array
    {
        return [
            'reserved_quantity' => 'decimal:3',
            'consumed_quantity' => 'decimal:3',
            'released_quantity' => 'decimal:3',
        ];
    }

    public function setQuantityAttribute($value): void
    {
        $this->attributes['reserved_quantity'] = (string) $value;
    }

    public function getQuantityAttribute(): string
    {
        return (string) ($this->attributes['reserved_quantity'] ?? '0.000');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

