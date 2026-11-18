<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dynamic Customer & Pricing Tier.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int $display_order
 * @property string $color
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Tier extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'description',
        'display_order',
        'color',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProductTierAssignment::class, 'tier_id');
    }

    public function tierPrices(): HasMany
    {
        return $this->hasMany(ProductTierPrice::class, 'tier_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'tier_id');
    }
}

