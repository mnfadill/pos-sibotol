<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable stock ledger entry (supports both Central Warehouse & Branch Outlet movements).
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int|null $outlet_id
 * @property int|null $warehouse_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property int|null $batch_id
 * @property StockMovementType $type
 * @property string $uom_code
 * @property string|null $uom_quantity
 * @property string $conversion_factor
 * @property string $quantity_change
 * @property string $quantity_before
 * @property string $quantity_after
 * @property string $unit_cost
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $user_id
 * @property string|null $notes
 * @property \Carbon\CarbonImmutable $created_at
 */
class StockMovement extends Model
{
    use AppendOnly;
    use BelongsToOutlet;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'outlet_id', 'warehouse_id', 'product_id', 'product_variant_id', 'batch_id',
        'type', 'uom_code', 'uom_quantity', 'conversion_factor',
        'quantity_change', 'quantity_before', 'quantity_after', 'unit_cost',
        'reference_type', 'reference_id', 'user_id', 'notes',
    ];

    protected static function booted(): void
    {
        static::creating(static function (StockMovement $movement): void {
            if (empty($movement->uom_code)) {
                $movement->uom_code = 'PCS';
            }
            if (empty($movement->conversion_factor)) {
                $movement->conversion_factor = '1.000';
            }
            if (! isset($movement->unit_cost) || $movement->unit_cost === '') {
                $movement->unit_cost = '0.00';
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'uom_quantity' => 'decimal:3',
            'conversion_factor' => 'decimal:3',
            'quantity_change' => 'decimal:3',
            'quantity_before' => 'decimal:3',
            'quantity_after' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return BelongsTo<InventoryBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
