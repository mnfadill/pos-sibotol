<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Individual Product Price per Tier with Effective Date.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $tier_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $product_unit_id
 * @property string $price
 * @property string $effective_from
 * @property string|null $effective_until
 * @property string $status
 * @property int|null $created_by
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ProductTierPrice extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'tier_id',
        'product_id',
        'product_variant_id',
        'product_unit_id',
        'price',
        'effective_from',
        'effective_until',
        'status',
        'created_by',
        'notes',
    ];

    protected static function booted(): void
    {
        $flush = static function (self $model): void {
            if ($model->organization_id) {
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
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeCurrentlyEffective(Builder $query, ?string $date = null): Builder
    {
        $targetDate = $date ?? CarbonImmutable::today()->toDateString();

        return $query->where('status', 'active')
            ->whereDate('effective_from', '<=', $targetDate)
            ->where(static function ($q) use ($targetDate): void {
                $q->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $targetDate);
            });
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

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
