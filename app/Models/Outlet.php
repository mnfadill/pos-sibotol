<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $code
 * @property string $type
 * @property string $name
 * @property string|null $address
 * @property string|null $city
 * @property string|null $province
 * @property string|null $region
 * @property string|null $phone
 * @property string $timezone
 * @property string $tax_rate
 * @property string $service_charge_rate
 * @property string $max_cashier_discount_percent
 * @property bool $allow_negative_stock
 * @property string|null $qris_static_payload
 * @property string|null $qris_merchant_name
 * @property bool $is_active
 */
class Outlet extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'code', 'type', 'name', 'address', 'city', 'province', 'region', 'phone', 'timezone',
        'tax_rate', 'service_charge_rate', 'max_cashier_discount_percent', 'allow_negative_stock',
        'qris_static_payload', 'qris_merchant_name', 'settings', 'is_active',
    ];

    protected $hidden = ['qris_static_payload'];

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2',
            'service_charge_rate' => 'decimal:2',
            'max_cashier_discount_percent' => 'decimal:2',
            'allow_negative_stock' => 'boolean',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<PosTerminal, $this> */
    public function terminals(): HasMany
    {
        return $this->hasMany(PosTerminal::class);
    }

    /** @return HasMany<Warehouse, $this> */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /** @return HasOne<Warehouse, $this> */
    public function defaultWarehouse(): HasOne
    {
        return $this->hasOne(Warehouse::class)->where('is_active', true)->orderByDesc('is_default');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_outlets')
            ->withPivot(['is_default', 'role_override', 'can_switch_outlet', 'is_favorite', 'last_accessed_at'])
            ->withTimestamps();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
