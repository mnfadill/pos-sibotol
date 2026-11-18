<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $warehouse_id
 * @property int|null $outlet_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property string $quantity
 * @property string $reserved_quantity
 * @property string $in_transit_quantity
 * @property string $damaged_quantity
 * @property string $quarantine_quantity
 * @property string $reorder_level
 * @property string $min_stock
 * @property string $max_stock
 * @property string $safety_stock
 */
class WarehouseInventory extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'warehouse_id', 'outlet_id', 'product_id', 'product_variant_id',
        'quantity', 'reserved_quantity', 'in_transit_quantity',
        'damaged_quantity', 'quarantine_quantity',
        'reorder_level', 'min_stock', 'max_stock', 'safety_stock',
    ];

    protected $appends = ['available_quantity'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reserved_quantity' => 'decimal:3',
            'in_transit_quantity' => 'decimal:3',
            'damaged_quantity' => 'decimal:3',
            'quarantine_quantity' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'max_stock' => 'decimal:3',
            'safety_stock' => 'decimal:3',
        ];
    }

    /**
     * Available Stock = On Hand - Reserved - Damaged - Quarantine.
     * In Transit is NOT included in Available Stock.
     */
    public function getAvailableQuantityAttribute(): string
    {
        $onHand = Quantity::normalize($this->quantity ?? '0.000');
        $reserved = Quantity::normalize($this->reserved_quantity ?? '0.000');
        $damaged = Quantity::normalize($this->damaged_quantity ?? '0.000');
        $quarantine = Quantity::normalize($this->quarantine_quantity ?? '0.000');

        $deductions = Quantity::add(Quantity::add($reserved, $damaged), $quarantine);

        return Quantity::maxZero(Quantity::sub($onHand, $deductions));
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withoutGlobalScopes();
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
