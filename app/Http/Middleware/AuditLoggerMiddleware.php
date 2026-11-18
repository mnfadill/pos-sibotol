<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures request metadata (request ID, IP, user agent, route) for every audit
 * record written during the request, propagates X-Request-Id, and records
 * ACCESS_DENIED events for authorization failures on authenticated calls.
 */
final class AuditLoggerMiddleware
{
    public function __construct(
        private readonly AuditContext $auditContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $this->auditContext->capture($requestId, [
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'http_method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'request_id' => $requestId,
            'device_id' => $request->header('X-Device-Id'),
        ]);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        if ($response->getStatusCode() === Response::HTTP_FORBIDDEN && $request->user('sanctum') !== null) {
            $this->auditLogger->record(
                AuditAction::ACCESS_DENIED,
                'Route',
                metadata: ['route' => $request->route()?->getName(), 'status' => Response::HTTP_FORBIDDEN],
            );
        }

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');

        return preg_match('/^[A-Za-z0-9\-_.]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();
    }
}

