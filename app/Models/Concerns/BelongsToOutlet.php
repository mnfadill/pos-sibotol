<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Outlet;
use App\Models\Scopes\OutletScope;
use App\Support\PosContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Model
 */
trait BelongsToOutlet
{
    public static function bootBelongsToOutlet(): void
    {
        static::addGlobalScope(new OutletScope);

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('outlet_id') === null) {
                $model->setAttribute('outlet_id', app(PosContext::class)->outletId());
            }
        });
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * Explicit outlet filter (defense in depth on top of the global scope).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOutlet(Builder $query, int $outletId): Builder
    {
        return $query->where($this->qualifyColumn('outlet_id'), $outletId);
    }
}
