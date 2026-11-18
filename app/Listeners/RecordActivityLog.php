<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ActivityOccurred;
use App\Models\AuditLog;

/**
 * Persists audit events into the append-only audit_logs table.
 *
 * Deliberately SYNCHRONOUS: the audit row is written inside the same database
 * transaction as the business change, so a committed sale can never exist without
 * its audit trail (and a rolled-back sale leaves no phantom log).
 */
final class RecordActivityLog
{
    /** Keys whose values must never reach the audit store. */
    private const REDACTED_KEYS = ['password', 'pin', 'pin_hash', 'token', 'remember_token', 'secret', 'qris_static_payload'];

    public function handle(ActivityOccurred $event): void
    {
        AuditLog::query()->create([
            'organization_id' => $event->organizationId,
            'actor_id' => $event->actorId,
            'actor_name_snapshot' => $event->actorNameSnapshot,
            'actor_role_snapshot' => $event->actorRoleSnapshot,
            'outlet_id' => $event->outletId,
            'pos_terminal_id' => $event->posTerminalId,
            'action_event' => $event->action,
            'entity_type' => $event->entityType,
            'entity_id' => $event->entityId,
            'old_values' => $this->redact($event->oldValues),
            'new_values' => $this->redact($event->newValues),
            'metadata' => $this->redact($event->metadata),
            'ip_address' => $event->ipAddress,
            'request_id' => $event->requestId,
            'created_at' => $event->occurredAt,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}

