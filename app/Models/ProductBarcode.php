<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcode extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'product_id',
        'product_variant_id',
        'product_unit_id',
        'barcode',
        'barcode_type',
        'label',
        'notes',
        'is_primary',
        'is_active',
    ];

    public function setNotesAttribute($value): void
    {
        $this->attributes['label'] = $value;
    }

    public function getNotesAttribute(): ?string
    {
        return $this->attributes['label'] ?? null;
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
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

    /** @return BelongsTo<ProductUnit, $this> */
    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}

