<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'outlet_id',
        'customer_id',
        'code',
        'name',
        'type',
        'customer_tier',
        'priority',
        'effective_from',
        'effective_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'effective_until' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function setCustomerGroupAttribute(?string $value): void
    {
        $this->attributes['customer_tier'] = $value;
    }

    public function getCustomerGroupAttribute(): ?string
    {
        return $this->attributes['customer_tier'] ?? null;
    }

    public function setStartDateAttribute(?string $value): void
    {
        $this->attributes['effective_from'] = $value;
    }

    public function getStartDateAttribute(): ?string
    {
        return $this->attributes['effective_from'] ?? null;
    }

    public function setEndDateAttribute(?string $value): void
    {
        $this->attributes['effective_until'] = $value;
    }

    public function getEndDateAttribute(): ?string
    {
        return $this->attributes['effective_until'] ?? null;
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }
}

