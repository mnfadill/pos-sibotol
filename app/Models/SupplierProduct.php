<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProduct extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'supplier_id',
        'product_id',
        'product_variant_id',
        'supplier_sku',
        'purchase_price',
        'last_purchase_price',
        'lead_time_days',
        'min_order_qty',
        'is_default_supplier',
        'is_primary',
    ];

    public function setIsPrimaryAttribute($value): void
    {
        $this->attributes['is_default_supplier'] = (bool) $value;
    }

    public function getIsPrimaryAttribute(): bool
    {
        return (bool) ($this->attributes['is_default_supplier'] ?? false);
    }

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'last_purchase_price' => 'decimal:2',
            'lead_time_days' => 'integer',
            'min_order_qty' => 'decimal:3',
            'is_default_supplier' => 'boolean',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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

