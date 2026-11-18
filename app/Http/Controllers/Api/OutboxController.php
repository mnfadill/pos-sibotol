<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchOutboxMessagesJob;
use App\Models\OutboxMessage;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OutboxController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outletId = $this->context->outletId();

        $baseQuery = OutboxMessage::query()
            ->where('organization_id', $user->organization_id)
            ->when($outletId !== null && ! $user->isSuperAdmin(), static fn ($q) => $q->where('outlet_id', $outletId));

        $counts = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $paginator = (clone $baseQuery)
            ->orderByDesc('created_at')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return response()->json([
            'stats' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'processing' => (int) ($counts['processing'] ?? 0),
                'published' => (int) ($counts['published'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                'dead' => (int) ($counts['dead'] ?? 0),
            ],
            'messages' => $paginator,
        ]);
    }

    public function dispatchNow(): JsonResponse
    {
        $job = new DispatchOutboxMessagesJob(batchSize: 50);
        $publishedCount = $job->handle();

        return response()->json([
            'published_count' => $publishedCount,
        ]);
    }
}

