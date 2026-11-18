<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Product Membership per Tier.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $tier_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ProductTierAssignment extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'tier_id',
        'product_id',
        'product_variant_id',
        'status',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class, 'tier_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductTierPrice::class, 'tier_id', 'tier_id')
            ->where('product_id', $this->product_id);
    }
}

