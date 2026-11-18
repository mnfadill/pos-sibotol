<?php

declare(strict_types=1);

namespace App\Enums;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Published = 'published';
    case Failed = 'failed';
    case Dead = 'dead';
}
