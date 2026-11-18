<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;

/**
 * Application-level guard for append-only tables (the DB trigger is the final authority).
 *
 * @mixin Model
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(static function (Model $model): never {
            throw ImmutableRecordException::for(class_basename($model), 'UPDATE');
        });

        static::deleting(static function (Model $model): never {
            throw ImmutableRecordException::for(class_basename($model), 'DELETE');
        });
    }
}
