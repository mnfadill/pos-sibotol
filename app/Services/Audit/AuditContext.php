<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Request-scoped technical metadata captured by AuditLoggerMiddleware.
 */
final class AuditContext
{
    private ?string $requestId = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function capture(string $requestId, array $metadata): void
    {
        $this->requestId = $requestId;
        $this->metadata = $metadata;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function ipAddress(): ?string
    {
        $ip = $this->metadata['ip_address'] ?? null;

        return is_string($ip) ? $ip : null;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }
}

