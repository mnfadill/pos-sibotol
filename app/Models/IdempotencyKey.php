<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string $method
 * @property string $path
 * @property string $request_hash
 * @property string $status
 * @property int|null $response_code
 * @property array<string, mixed>|null $response_body
 * @property \Illuminate\Support\Carbon $expires_at
 */
class IdempotencyKey extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = ['user_id', 'idempotency_key', 'method', 'path', 'request_hash', 'status', 'response_code', 'response_body', 'expires_at'];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_code' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}

