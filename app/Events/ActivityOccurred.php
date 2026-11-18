<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\AuditAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain event carrying a fully-resolved audit record.
 *
 * All snapshots (actor name/role, outlet, terminal, request metadata) are captured at
 * the moment the event is raised, so the listener only persists and never re-derives
 * state that might have changed in between.
 */
final class ActivityOccurred
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly AuditAction $action,
        public readonly ?int $organizationId,
        public readonly ?int $actorId,
        public readonly ?string $actorNameSnapshot,
        public readonly ?string $actorRoleSnapshot,
        public readonly ?int $outletId,
        public readonly ?int $posTerminalId,
        public readonly ?string $entityType,
        public readonly ?string $entityId,
        public readonly ?array $oldValues,
        public readonly ?array $newValues,
        public readonly array $metadata,
        public readonly ?string $ipAddress,
        public readonly ?string $requestId,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}

