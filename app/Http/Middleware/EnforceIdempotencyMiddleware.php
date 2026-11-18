<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\BusinessRuleException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces idempotency on payment-processing endpoints via X-Idempotency-Key.
 *
 * - Missing header -> 422 IDEMPOTENCY_KEY_REQUIRED
 * - Same key + same payload (completed) -> replays stored response with X-Idempotent-Replay: true
 * - Same key + different payload -> 409 IDEMPOTENCY_KEY_PAYLOAD_MISMATCH
 * - Same key currently in-flight -> 409 IDEMPOTENCY_KEY_IN_FLIGHT
 */
final class EnforceIdempotencyMiddleware
{
    private const HEADER = 'X-Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER, ''));

        if ($key === '') {
            throw new BusinessRuleException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'The X-Idempotency-Key header is required for payment processing.',
                422,
            );
        }

        if (strlen($key) > 128 || preg_match('/^[A-Za-z0-9\-_.:]+$/', $key) !== 1) {
            throw new BusinessRuleException(
                'IDEMPOTENCY_KEY_INVALID',
                'The X-Idempotency-Key header must be 1-128 alphanumeric/UUID characters.',
                422,
            );
        }

        $user = $request->user('sanctum');
        $userId = $user ? (int) $user->getAuthIdentifier() : 0;
        $requestHash = $this->hashRequest($request);

        /** @var IdempotencyKey|null $existing */
        $existing = IdempotencyKey::query()
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            if ($existing->expires_at->isPast()) {
                $existing->delete();
                $existing = null;
            } else {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new BusinessRuleException(
                        'IDEMPOTENCY_KEY_PAYLOAD_MISMATCH',
                        'This X-Idempotency-Key was already used with a different request payload.',
                        409,
                    );
                }

                if ($existing->status === IdempotencyKey::STATUS_PROCESSING) {
                    throw new BusinessRuleException(
                        'IDEMPOTENCY_KEY_IN_FLIGHT',
                        'A request with this X-Idempotency-Key is currently being processed.',
                        409,
                    );
                }

                return response()
                    ->json($existing->response_body ?? [], $existing->response_code ?? 200)
                    ->header('X-Idempotency-Key', $key)
                    ->header('X-Idempotent-Replay', 'true');
            }
        }

        try {
            $record = IdempotencyKey::query()->create([
                'user_id' => $userId,
                'idempotency_key' => $key,
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'request_hash' => $requestHash,
                'status' => IdempotencyKey::STATUS_PROCESSING,
                'expires_at' => now()->addHours(24),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException(
                'IDEMPOTENCY_KEY_IN_FLIGHT',
                'A concurrent request with this X-Idempotency-Key is already in progress.',
                409,
            );
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $record->delete();
            throw $e;
        }

        // Only cache 2xx responses; allow client to retry validation/domain errors with a new fix or same key if 5xx/4xx
        if ($response instanceof JsonResponse && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $response->getContent(), true) ?? [];
            $record->update([
                'status' => IdempotencyKey::STATUS_COMPLETED,
                'response_code' => $response->getStatusCode(),
                'response_body' => $decoded,
            ]);
        } else {
            $record->delete();
        }

        $response->headers->set('X-Idempotency-Key', $key);
        $response->headers->set('X-Idempotent-Replay', 'false');

        return $response;
    }

    private function hashRequest(Request $request): string
    {
        $payload = $request->all();
        $this->ksortRecursive($payload);

        return hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $array
     */
    private function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
        ksort($array);
    }
}

