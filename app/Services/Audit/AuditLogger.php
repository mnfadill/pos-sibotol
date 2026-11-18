<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Events\ActivityOccurred;
use App\Models\User;
use App\Support\PosContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Facade-like entry point used by domain services to raise audit events.
 */
final class AuditLogger
{
    public function __construct(
        private readonly PosContext $context,
        private readonly AuditContext $auditContext,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $metadata  Extra domain metadata merged with request metadata
     * @param  array{outlet_id?: int|null, pos_terminal_id?: int|null}  $overrides
     */
    public function record(
        AuditAction $action,
        Model|string|null $entity = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $metadata = [],
        ?User $actor = null,
        array $overrides = [],
    ): void {
        $actor ??= $this->context->user() ?? $this->resolveAuthenticatedUser();
        $actor?->loadMissing('roles');

        [$entityType, $entityId] = $this->describeEntity($entity);

        $this->events->dispatch(new ActivityOccurred(
            action: $action,
            organizationId: $actor?->organization_id ?? $this->context->organizationId(),
            actorId: $actor?->id,
            actorNameSnapshot: $actor?->name,
            actorRoleSnapshot: $actor?->roleSnapshot() ?: null,
            outletId: array_key_exists('outlet_id', $overrides) ? $overrides['outlet_id'] : $this->context->outletId(),
            posTerminalId: array_key_exists('pos_terminal_id', $overrides) ? $overrides['pos_terminal_id'] : $this->context->terminalId(),
            entityType: $entityType,
            entityId: $entityId,
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: array_merge($this->auditContext->metadata(), $metadata),
            ipAddress: $this->auditContext->ipAddress(),
            requestId: $this->auditContext->requestId(),
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function describeEntity(Model|string|null $entity): array
    {
        if ($entity instanceof Model) {
            return [class_basename($entity), (string) $entity->getKey()];
        }

        return [$entity, null];
    }

    private function resolveAuthenticatedUser(): ?User
    {
        $user = Auth::guard('sanctum')->user();

        return $user instanceof User ? $user : null;
    }
}

