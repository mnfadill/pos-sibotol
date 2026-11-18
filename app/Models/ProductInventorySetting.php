<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductInventorySetting extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'outlet_id',
        'product_id',
        'product_variant_id',
        'min_stock',
        'max_stock',
        'reorder_point',
        'safety_stock',
        'default_warehouse_id',
        'allow_negative_stock',
    ];

    protected function casts(): array
    {
        return [
            'min_stock' => 'decimal:3',
            'max_stock' => 'decimal:3',
            'reorder_point' => 'decimal:3',
            'safety_stock' => 'decimal:3',
            'allow_negative_stock' => 'boolean',
        ];
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
}

