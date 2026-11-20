<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sellable / stock-keeping unit.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property string $name
 * @property string $sku
 * @property string|null $barcode
 * @property string $price
 * @property string|null $member_price
 * @property string $cost_price
 * @property string $average_cost
 * @property string $last_purchase_cost
 * @property string|null $reseller_price
 * @property string|null $wholesale_price
 * @property string $min_selling_price
 * @property string $min_wholesale_qty
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 * @property-read Product $product
 */
class ProductVariant extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'product_id', 'name', 'sku', 'barcode', 'price',
        'member_price', 'cost_price', 'average_cost', 'last_purchase_cost',
        'reseller_price', 'wholesale_price', 'min_selling_price', 'min_wholesale_qty',
        'is_default', 'is_active', 'sort_order',
    ];

    protected static function booted(): void
    {
        $flush = static function (self $model): void {
            if ($model->organization_id) {
                \App\Services\PosCatalogCacheService::bumpMetadata((int) $model->organization_id);
                \App\Services\PosCatalogCacheService::bumpPricing((int) $model->organization_id);
            }
        };
        static::saved($flush);
        static::deleted($flush);
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'member_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'average_cost' => 'decimal:2',
            'last_purchase_cost' => 'decimal:2',
            'reseller_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'min_selling_price' => 'decimal:2',
            'min_wholesale_qty' => 'decimal:3',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<ProductUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class, 'product_variant_id')->orderBy('conversion_factor');
    }

    /** @return HasMany<ProductBarcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'product_variant_id');
    }

    /** @return HasMany<ProductOutletSetting, $this> */
    public function outletSettings(): HasMany
    {
        return $this->hasMany(ProductOutletSetting::class);
    }

    /** @return HasOne<ProductOutletSetting, $this> */
    public function outletSetting(): HasOne
    {
        return $this->hasOne(ProductOutletSetting::class);
    }

    /** @return HasOne<OutletInventory, $this> */
    public function inventory(): HasOne
    {
        return $this->hasOne(OutletInventory::class);
    }

    /** @return HasMany<WarehouseInventory, $this> */
    public function warehouseInventories(): HasMany
    {
        return $this->hasMany(WarehouseInventory::class, 'product_variant_id');
    }

    /** @return HasMany<InventoryBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class, 'product_variant_id')->orderBy('expiry_date');
    }

    /** @return HasMany<SupplierProduct, $this> */
    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class, 'product_variant_id');
    }
}
