<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CashMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shift\CashMovementRequest;
use App\Http\Requests\Shift\CloseShiftRequest;
use App\Http\Requests\Shift\OpenShiftRequest;
use App\Models\CashierShift;
use App\Services\ShiftService;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShiftController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly ShiftService $shifts,
    ) {}

    public function current(): JsonResponse
    {
        $shift = $this->context->shift();

        if ($shift === null) {
            return response()->json([
                'shift' => null,
                'summary' => null,
            ]);
        }

        $shift->loadMissing(['cashier:id,name,username', 'terminal:id,code,name', 'cashMovements.user:id,name']);

        return response()->json([
            'shift' => $shift,
            'summary' => $this->shifts->calculateLiveSummary($shift),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $outlet = $this->context->requireOutlet();

        $paginator = CashierShift::query()
            ->with(['cashier:id,name,username', 'terminal:id,code,name'])
            ->where('outlet_id', $outlet->id)
            ->orderByDesc('opened_at')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 20))));

        return response()->json($paginator);
    }

    public function show(CashierShift $shift): JsonResponse
    {
        $shift->load(['cashier:id,name,username', 'terminal:id,code,name', 'cashMovements.user:id,name']);

        return response()->json([
            'shift' => $shift,
            'summary' => $this->shifts->calculateLiveSummary($shift),
        ]);
    }

    public function open(OpenShiftRequest $request): JsonResponse
    {
        $shift = $this->shifts->openShift(
            openingCash: $request->validated('opening_cash'),
            notes: $request->validated('opening_notes'),
            openedAt: $request->validated('opened_at') ?? $request->validated('shift_date'),
        );

        return response()->json([
            'shift' => $shift,
            'summary' => $this->shifts->calculateLiveSummary($shift),
        ], 201);
    }

    public function cashMovement(CashMovementRequest $request): JsonResponse
    {
        $movement = $this->shifts->recordCashMovement(
            type: CashMovementType::from((string) $request->validated('type')),
            amount: $request->validated('amount'),
            reason: (string) $request->validated('reason'),
            notes: $request->validated('notes'),
            supervisorPin: $request->validated('supervisor_pin'),
        );

        $shift = $this->context->requireShift()->fresh(['cashier:id,name,username', 'terminal:id,code,name', 'cashMovements.user:id,name']);

        return response()->json([
            'movement' => $movement,
            'shift' => $shift,
            'summary' => $shift ? $this->shifts->calculateLiveSummary($shift) : null,
        ], 201);
    }

    public function close(CloseShiftRequest $request): JsonResponse
    {
        $shift = $this->context->requireShift();

        $closedShift = $this->shifts->closeShift(
            shift: $shift,
            actualCash: $request->validated('actual_cash'),
            closingNotes: $request->validated('closing_notes'),
        );

        return response()->json([
            'shift' => $closedShift,
            'discrepancy_report' => [
                'shift_number' => $closedShift->shift_number,
                'opened_at' => $closedShift->opened_at->toIso8601String(),
                'closed_at' => $closedShift->closed_at?->toIso8601String(),
                'opening_cash' => $closedShift->opening_cash,
                'total_cash_sales' => $closedShift->total_cash_sales,
                'total_non_cash_sales' => $closedShift->total_non_cash_sales,
                'total_sales' => $closedShift->total_sales,
                'total_paid_in' => $closedShift->total_paid_in,
                'total_paid_out' => $closedShift->total_paid_out,
                'total_cash_refunds' => $closedShift->total_cash_refunds,
                'expected_cash' => $closedShift->expected_cash,
                'actual_cash' => $closedShift->actual_cash,
                'cash_difference' => $closedShift->cash_difference,
                'transaction_count' => $closedShift->transaction_count,
                'closing_summary' => $closedShift->closing_summary,
                'closing_notes' => $closedShift->closing_notes,
            ],
        ]);
    }
}

