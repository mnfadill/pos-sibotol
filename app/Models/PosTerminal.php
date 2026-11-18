<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $code
 * @property string $name
 * @property string|null $device_identifier
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
class PosTerminal extends Model
{
    use SoftDeletes;

    protected $fillable = ['outlet_id', 'code', 'name', 'device_identifier', 'is_active', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
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
