<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\AuditLogQueryRequest;
use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\PosTerminal;
use App\Models\User;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;

final class AuditLogController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
    ) {}

    public function index(AuditLogQueryRequest $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $validated = $request->validated();

        $actionEvents = $validated['action_events'] ?? [];
        if (! empty($validated['action_event'])) {
            $actionEvents[] = $validated['action_event'];
        }

        $filters = [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'actor_id' => $validated['actor_id'] ?? null,
            'action_events' => $actionEvents !== [] ? array_values(array_unique($actionEvents)) : null,
            'entity_type' => $validated['entity_type'] ?? null,
            'entity_id' => $validated['entity_id'] ?? null,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'pos_terminal_id' => $validated['pos_terminal_id'] ?? null,
            'search' => $validated['search'] ?? null,
        ];

        $query = AuditLog::query()
            ->with(['outlet:id,code,name', 'terminal:id,code,name'])
            ->where('organization_id', $user->organization_id)
            ->filter($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $perPage = min(100, max(1, (int) ($validated['per_page'] ?? 25)));
        $paginator = $query->paginate($perPage);

        // Provide filter options scoped to the user's accessible outlets
        $accessibleOutletIds = $user->accessibleOutletIds();

        $outlets = Outlet::query()->withoutGlobalScopes()
            ->whereIn('id', $accessibleOutletIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $terminals = PosTerminal::query()
            ->whereIn('outlet_id', $accessibleOutletIds)
            ->orderBy('code')
            ->get(['id', 'outlet_id', 'code', 'name']);

        $actors = User::query()
            ->where('organization_id', $user->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'username']);

        $actions = array_map(static fn (AuditAction $a): array => [
            'value' => $a->value,
            'label' => $a->label(),
            'category' => $a->category(),
            'severity' => $a->severity(),
        ], AuditAction::cases());

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filter_options' => [
                'actions' => $actions,
                'actors' => $actors,
                'outlets' => $outlets,
                'terminals' => $terminals,
            ],
        ]);
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        $auditLog->load(['outlet:id,code,name', 'terminal:id,code,name']);

        return response()->json(['audit_log' => $auditLog]);
    }
}

