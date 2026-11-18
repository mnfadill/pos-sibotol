<?php

declare(strict_types=1);

namespace App\Services\Outbox;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Support\PosContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Transactional Outbox writer.
 *
 * Events are written to `integration_outbox` inside the SAME database transaction
 * as the POS business state change. POS never makes synchronous HTTP calls to CRM,
 * guaranteeing 100% POS availability even if CRM is down.
 */
final class OutboxService
{
    public function __construct(
        private readonly PosContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $eventType,
        Model|string $aggregate,
        string|int $aggregateId,
        array $payload,
        ?int $outletId = null,
        ?int $organizationId = null,
    ): OutboxMessage {
        $aggregateType = $aggregate instanceof Model ? class_basename($aggregate) : $aggregate;

        return OutboxMessage::query()->create([
            'organization_id' => $organizationId ?? $this->context->organizationId(),
            'outlet_id' => $outletId ?? $this->context->outletId(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'event_type' => $eventType,
            'event_version' => 1,
            'payload' => $payload,
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
            'available_at' => CarbonImmutable::now(),
        ]);
    }
}

