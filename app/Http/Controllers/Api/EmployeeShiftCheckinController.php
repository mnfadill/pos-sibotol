<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\EmployeeShiftCheckin;
use App\Services\EmployeeShiftService;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmployeeShiftCheckinController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
        private readonly EmployeeShiftService $employeeShiftService,
    ) {}

    /**
     * List all operational shift definitions for the active outlet / organization.
     */
    public function catalogue(): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->outlet();

        $shifts = $this->employeeShiftService->getOperationalShifts(
            $outlet?->id,
            $user->organization_id,
        );

        return response()->json([
            'shifts' => $shifts,
        ]);
    }

    /**
     * Get active operational shift check-in for the current user and outlet.
     */
    public function current(): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();

        $checkin = $this->employeeShiftService->getActiveCheckin($user->id, $outlet->id);

        return response()->json([
            'active_checkin' => $checkin,
            'is_checked_in' => $checkin !== null,
        ]);
    }

    /**
     * Perform employee operational shift check-in.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();

        $validated = $request->validate([
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'work_date' => ['nullable', 'date'],
            'is_backdated' => ['nullable', 'boolean'],
            'backdate_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $checkin = $this->employeeShiftService->checkIn(
            user: $user,
            outlet: $outlet,
            shiftId: (int) $validated['shift_id'],
            workDateInput: $validated['work_date'] ?? null,
            isBackdatedInput: (bool) ($validated['is_backdated'] ?? false),
            backdateReason: $validated['backdate_reason'] ?? null,
            notes: $validated['notes'] ?? null,
        );

        $this->context->setEmployeeShiftCheckin($checkin);

        return response()->json([
            'message' => 'Check-in shift berhasil disimpan.',
            'checkin' => $checkin,
        ], 201);
    }

    /**
     * Switch current employee shift to another shift with an audit reason.
     */
    public function switchShift(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->requireOutlet();

        $validated = $request->validate([
            'new_shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $newCheckin = $this->employeeShiftService->switchShift(
            user: $user,
            outlet: $outlet,
            newShiftId: (int) $validated['new_shift_id'],
            reason: $validated['reason'],
        );

        $this->context->setEmployeeShiftCheckin($newCheckin);

        return response()->json([
            'message' => 'Pergantian shift berhasil dilakukan.',
            'checkin' => $newCheckin,
        ]);
    }

    /**
     * List employee shift check-in records and history with filters.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->outlet();

        $query = EmployeeShiftCheckin::query()
            ->with(['shift', 'user:id,name,username', 'outlet:id,code,name', 'approver:id,name,username'])
            ->where('organization_id', $user->organization_id);

        if ($outlet !== null) {
            $query->where('outlet_id', $outlet->id);
        }

        if ($request->has('user_id') && $request->input('user_id') !== '') {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->has('shift_id') && $request->input('shift_id') !== '') {
            $query->where('shift_id', (int) $request->input('shift_id'));
        }

        if ($request->has('work_date') && $request->input('work_date') !== '') {
            $query->whereDate('work_date', $request->input('work_date'));
        }

        if ($request->has('is_backdated') && $request->input('is_backdated') !== '') {
            $query->where('is_backdated', $request->boolean('is_backdated'));
        }

        if ($request->has('status') && $request->input('status') !== '') {
            $query->where('status', $request->input('status'));
        }

        $records = $query->orderByDesc('work_date')->orderByDesc('checked_in_at')->paginate(25);

        return response()->json($records);
    }

    /**
     * Supervisor approval for a backdated shift checkin.
     */
    public function approve(int $id): JsonResponse
    {
        $approver = $this->context->requireUser();

        $checkin = $this->employeeShiftService->approveBackdate($approver, $id);

        return response()->json([
            'message' => 'Shift check-in berhasil disetujui.',
            'checkin' => $checkin,
        ]);
    }
}

