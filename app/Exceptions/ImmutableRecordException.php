<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

final class ImmutableRecordException extends LogicException
{
    public static function for(string $model, string $operation): self
    {
        return new self(sprintf('%s records are append-only; %s is not permitted.', $model, $operation));
    }
}
