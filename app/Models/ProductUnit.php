<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductUnit extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'product_id',
        'product_variant_id',
        'unit_id',
        'unit_code',
        'unit_name',
        'conversion_factor',
        'is_base',
        'is_purchase_unit',
        'is_sales_unit',
        'barcode',
        'selling_price',
        'member_price',
        'reseller_price',
        'wholesale_price',
        'purchase_cost',
        'min_purchase_qty',
        'is_locked',
        'is_active',
        // Aliases for convenience
        'is_base_unit',
        'is_default_purchase_unit',
        'is_default_sales_order_unit',
    ];

    protected static function booted(): void
    {
        static::creating(static function (ProductUnit $unit): void {
            if (empty($unit->organization_id) && $unit->product_id) {
                $unit->organization_id = Product::query()->withoutGlobalScopes()->where('id', $unit->product_id)->value('organization_id');
            }
        });
    }
    public function setIsBaseUnitAttribute($value): void
    {
        $this->attributes['is_base'] = (bool) $value;
    }

    public function getIsBaseUnitAttribute(): bool
    {
        return (bool) ($this->attributes['is_base'] ?? false);
    }

    public function setIsDefaultPurchaseUnitAttribute($value): void
    {
        $this->attributes['is_purchase_unit'] = (bool) $value;
    }

    public function getIsDefaultPurchaseUnitAttribute(): bool
    {
        return (bool) ($this->attributes['is_purchase_unit'] ?? true);
    }

    public function setIsDefaultSalesOrderUnitAttribute($value): void
    {
        $this->attributes['is_sales_unit'] = (bool) $value;
    }

    public function getIsDefaultSalesOrderUnitAttribute(): bool
    {
        return (bool) ($this->attributes['is_sales_unit'] ?? true);
    }

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:3',
            'selling_price' => 'decimal:2',
            'member_price' => 'decimal:2',
            'reseller_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'purchase_cost' => 'decimal:2',
            'min_purchase_qty' => 'decimal:3',
            'is_base' => 'boolean',
            'is_purchase_unit' => 'boolean',
            'is_sales_unit' => 'boolean',
            'is_locked' => 'boolean',
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

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<ProductBarcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }
}

