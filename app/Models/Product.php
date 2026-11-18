<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Enterprise Master Product Model.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $category_id
 * @property int|null $brand_id
 * @property int|null $principal_id
 * @property int|null $base_unit_id
 * @property string $name
 * @property string $sku
 * @property string|null $short_description
 * @property string|null $description
 * @property string|null $image_url
 * @property string $product_type
 * @property string $unit
 * @property string|null $default_barcode
 * @property string $barcode_type
 * @property string $base_price
 * @property bool $track_stock
 * @property bool $track_batch
 * @property bool $track_expiry
 * @property int $shelf_life_days
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Product extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'category_id', 'brand_id', 'principal_id', 'base_unit_id',
        'name', 'sku', 'short_description', 'description', 'image_url', 'product_type',
        'unit', 'default_barcode', 'barcode_type', 'base_price', 'track_stock',
        'track_batch', 'track_expiry', 'shelf_life_days', 'is_active', 'manufacturer',
        'net_content', 'net_content_unit', 'abv', 'packaging_type', 'country_of_origin',
        'is_returnable_packaging',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'net_content' => 'decimal:2',
            'abv' => 'decimal:2',
            'is_returnable_packaging' => 'boolean',
            'track_stock' => 'boolean',
            'track_batch' => 'boolean',
            'track_expiry' => 'boolean',
            'shelf_life_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getStatusAttribute(): string
    {
        return (bool) ($this->attributes['is_active'] ?? true) ? 'active' : 'inactive';
    }

    public function setStatusAttribute(string|bool|int $value): void
    {
        $this->attributes['is_active'] = $value === 'active' || $value === true || $value === 1 || $value === '1';
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Principal, $this> */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ProductUnit, $this> */
    public function productUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('conversion_factor');
    }

    /** @return HasMany<ProductBarcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /** @return HasMany<ProductModifier, $this> */
    public function modifiers(): HasMany
    {
        return $this->hasMany(ProductModifier::class)->orderBy('group_name')->orderBy('sort_order');
    }

    /** @return HasMany<ProductOutletSetting, $this> */
    public function outletSettings(): HasMany
    {
        return $this->hasMany(ProductOutletSetting::class);
    }

    /** @return HasMany<ProductInventorySetting, $this> */
    public function inventorySettings(): HasMany
    {
        return $this->hasMany(ProductInventorySetting::class);
    }

    /** @return HasMany<InventoryBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }

    /** @return HasMany<SupplierProduct, $this> */
    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    /** @return HasMany<ProductPriceHistory, $this> */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class)->orderByDesc('created_at');
    }

    /** @return HasMany<ProductTierAssignment, $this> */
    public function tierAssignments(): HasMany
    {
        return $this->hasMany(ProductTierAssignment::class);
    }

    /** @return HasMany<ProductTierPrice, $this> */
    public function tierPrices(): HasMany
    {
        return $this->hasMany(ProductTierPrice::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('products.is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.mb_strtolower($term).'%';

        return $query->where(static function (Builder $q) use ($like, $term): void {
            $q->whereRaw('LOWER(products.name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(products.sku) LIKE ?', [$like])
                ->orWhereHas('variants', static function (Builder $v) use ($like, $term): void {
                    $v->whereRaw('LOWER(sku) LIKE ?', [$like])->orWhere('barcode', $term);
                })
                ->orWhereHas('barcodes', static function (Builder $b) use ($term): void {
                    $b->where('barcode', $term);
                });
        });
    }
}
