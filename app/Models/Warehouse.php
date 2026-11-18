<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $outlet_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string|null $city
 * @property string|null $address
 * @property string|null $phone
 * @property bool $is_default
 * @property bool $is_active
 */
class Warehouse extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'outlet_id', 'code', 'name', 'type',
        'city', 'address', 'phone', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withoutGlobalScopes();
    }

    /** @return HasMany<WarehouseInventory, $this> */
    public function inventories(): HasMany
    {
        return $this->hasMany(WarehouseInventory::class);
    }
}

