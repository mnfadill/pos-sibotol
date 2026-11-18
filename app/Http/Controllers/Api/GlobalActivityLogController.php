<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class GlobalActivityLogController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->outlet();

        $query = ActivityLog::query()
            ->with(['outlet:id,code,name', 'user:id,name,username', 'shift:id,name,code'])
            ->where('organization_id', $user->organization_id);

        if ($request->has('outlet_id') && $request->input('outlet_id') !== '') {
            $query->where('outlet_id', (int) $request->input('outlet_id'));
        } elseif ($outlet !== null && ! $request->boolean('company_scope', false)) {
            $query->where('outlet_id', $outlet->id);
        }

        if ($request->has('user_id') && $request->input('user_id') !== '') {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->has('username') && $request->input('username') !== '') {
            $query->where('username_snapshot', 'like', '%' . $request->input('username') . '%');
        }

        if ($request->has('shift_id') && $request->input('shift_id') !== '') {
            $query->where('shift_id', (int) $request->input('shift_id'));
        }

        if ($request->has('work_date') && $request->input('work_date') !== '') {
            $query->whereDate('work_date', $request->input('work_date'));
        }

        if ($request->has('date_from') && $request->input('date_from') !== '') {
            $query->whereDate('work_date', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to') && $request->input('date_to') !== '') {
            $query->whereDate('work_date', '<=', $request->input('date_to'));
        }

        if ($request->has('module_name') && $request->input('module_name') !== '') {
            $query->where('module_name', $request->input('module_name'));
        }

        if ($request->has('action_type') && $request->input('action_type') !== '') {
            $query->where('action_type', $request->input('action_type'));
        }

        if ($request->has('search') && $request->input('search') !== '') {
            $search = '%' . $request->input('search') . '%';
            $query->where(static function ($q) use ($search): void {
                $q->where('description', 'like', $search)
                    ->orWhere('username_snapshot', 'like', $search)
                    ->orWhere('employee_name_snapshot', 'like', $search)
                    ->orWhere('entity_id', 'like', $search);
            });
        }

        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(25);

        // Stats summary for the day
        $today = Carbon::today();
        $todayTotal = ActivityLog::query()
            ->where('organization_id', $user->organization_id)
            ->whereDate('work_date', $today)
            ->count();

        $shiftSummary = ActivityLog::query()
            ->where('organization_id', $user->organization_id)
            ->whereDate('work_date', $today)
            ->selectRaw('shift_name_snapshot, count(*) as count')
            ->groupBy('shift_name_snapshot')
            ->pluck('count', 'shift_name_snapshot')
            ->all();

        return response()->json([
            'data' => $logs->items(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
            'summary' => [
                'today_total_activities' => $todayTotal,
                'breakdown_by_shift' => $shiftSummary,
            ],
        ]);
    }
}

