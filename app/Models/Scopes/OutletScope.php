<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\PosContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Outlet data isolation: non-privileged users only ever see rows of their
 * active outlet (or their assigned outlets when no session is active).
 */
final class OutletScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $outletIds = app(PosContext::class)->scopedOutletIds();

        if ($outletIds === null) {
            return;
        }

        $builder->whereIn($model->qualifyColumn('outlet_id'), $outletIds === [] ? [0] : $outletIds);
    }
}
