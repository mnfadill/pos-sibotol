<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Asynchronous relay worker that publishes pending `integration_outbox` records to CRM.
 *
 * Uses exponential backoff and dead-lettering after MAX_ATTEMPTS so POS is completely
 * decoupled from CRM uptime.
 */
final class DispatchOutboxMessagesJob implements ShouldQueue
{
    use Queueable;

    private const MAX_ATTEMPTS = 8;

    public function __construct(
        public readonly int $batchSize = 50,
    ) {}

    public function handle(): int
    {
        $workerId = gethostname().':'.Str::random(8);
        $now = CarbonImmutable::now();

        $messages = OutboxMessage::query()
            ->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Failed->value])
            ->where('available_at', '<=', $now)
            ->orderBy('created_at')
            ->limit($this->batchSize)
            ->get();

        $publishedCount = 0;
        $webhookUrl = (string) config('services.crm.webhook_url', '');

        foreach ($messages as $message) {
            $message->update([
                'status' => OutboxStatus::Processing,
                'locked_at' => $now,
                'locked_by' => $workerId,
                'attempts' => $message->attempts + 1,
            ]);

            try {
                if ($webhookUrl !== '') {
                    Http::timeout(5)
                        ->withHeaders([
                            'X-Outbox-Event-Id' => $message->id,
                            'X-Outbox-Event-Type' => $message->event_type,
                        ])
                        ->post($webhookUrl, $message->toEnvelope())
                        ->throw();
                }

                $message->update([
                    'status' => OutboxStatus::Published,
                    'published_at' => CarbonImmutable::now(),
                    'locked_at' => null,
                    'locked_by' => null,
                    'last_error' => null,
                ]);

                $publishedCount++;
            } catch (\Throwable $e) {
                $attempts = $message->attempts;
                $isDead = $attempts >= self::MAX_ATTEMPTS;
                $backoffSeconds = min(3600, (int) pow(2, $attempts) * 15);

                $message->update([
                    'status' => $isDead ? OutboxStatus::Dead : OutboxStatus::Failed,
                    'available_at' => CarbonImmutable::now()->addSeconds($backoffSeconds),
                    'locked_at' => null,
                    'locked_by' => null,
                    'last_error' => Str::limit($e->getMessage(), 1000),
                ]);
            }
        }

        return $publishedCount;
    }
}

