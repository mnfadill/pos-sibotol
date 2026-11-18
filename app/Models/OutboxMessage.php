<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Transactional outbox message destined for the CRM.
 *
 * @property string $id
 * @property int|null $organization_id
 * @property int|null $outlet_id
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $event_type
 * @property int $event_version
 * @property array<string, mixed> $payload
 * @property OutboxStatus $status
 * @property int $attempts
 * @property \Carbon\CarbonImmutable $available_at
 * @property \Carbon\CarbonImmutable|null $locked_at
 * @property string|null $locked_by
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property string|null $last_error
 * @property \Carbon\CarbonImmutable $created_at
 */
class OutboxMessage extends Model
{
    use HasUuids;

    protected $table = 'integration_outbox';

    protected $fillable = [
        'organization_id', 'outlet_id', 'aggregate_type', 'aggregate_id', 'event_type', 'event_version', 'payload',
        'status', 'attempts', 'available_at', 'locked_at', 'locked_by', 'published_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'event_version' => 'integer',
            'available_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Envelope delivered to the CRM (CloudEvents-like). */
    public function toEnvelope(): array
    {
        return [
            'id' => $this->id,
            'source' => 'pos',
            'type' => $this->event_type,
            'version' => $this->event_version,
            'organization_id' => $this->organization_id,
            'outlet_id' => $this->outlet_id,
            'aggregate' => ['type' => $this->aggregate_type, 'id' => $this->aggregate_id],
            'occurred_at' => $this->created_at->format('Y-m-d\TH:i:s.uP'),
            'data' => $this->payload,
        ];
    }
}

