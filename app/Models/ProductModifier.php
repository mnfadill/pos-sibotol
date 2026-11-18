<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Add-on / modifier, grouped by group_name (e.g. "Sugar Level").
 *
 * @property int $id
 * @property int $product_id
 * @property string $group_name
 * @property string $name
 * @property string $price
 * @property bool $is_required
 * @property bool $is_multiple
 * @property bool $is_active
 * @property int $sort_order
 */
class ProductModifier extends Model
{
    protected $fillable = ['product_id', 'group_name', 'name', 'price', 'is_required', 'is_multiple', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_required' => 'boolean',
            'is_multiple' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
