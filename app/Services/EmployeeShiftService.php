<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\EmployeeShiftCheckin;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\ShiftCheckinHistory;
use App\Models\User;
use App\Support\PosContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EmployeeShiftService
{
    public function __construct(
        private readonly PosContext $context,
        private readonly ActivityTrackingService $activityTracker,
    ) {}

    /**
     * Retrieve all operational shift templates available for the outlet/organization.
     *
     * @return Collection<int, Shift>
     */
    public function getOperationalShifts(?int $outletId = null, ?int $organizationId = null): Collection
    {
        $orgId = $organizationId ?? $this->context->organizationId() ?? 1;

        return Shift::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where(static function ($q) use ($outletId): void {
                $q->whereNull('outlet_id');
                if ($outletId !== null) {
                    $q->orWhere('outlet_id', $outletId);
                }
            })
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Get active shift check-in for an employee and outlet.
     */
    public function getActiveCheckin(int $userId, int $outletId, ?string $workDate = null): ?EmployeeShiftCheckin
    {
        $query = EmployeeShiftCheckin::query()
            ->with(['shift', 'user:id,name,username', 'outlet:id,code,name'])
            ->where('user_id', $userId)
            ->where('outlet_id', $outletId)
            ->where('status', 'active');

        if ($workDate !== null && $workDate !== '') {
            $query->whereDate('work_date', $workDate);
        }

        return $query->latest('checked_in_at')->first();
    }

    /**
     * Check in an employee to an operational shift for a specific work date.
     */
    public function checkIn(
        User $user,
        Outlet $outlet,
        int $shiftId,
        ?string $workDateInput = null,
        bool $isBackdatedInput = false,
        ?string $backdateReason = null,
        ?string $notes = null,
    ): EmployeeShiftCheckin {
        $today = Carbon::today('Asia/Jakarta');
        $workDate = $workDateInput !== null && $workDateInput !== ''
            ? Carbon::parse($workDateInput, 'Asia/Jakarta')->startOfDay()
            : $today;

        // Validation Rule 1: Future dates are strictly prohibited
        if ($workDate->isAfter($today)) {
            throw new BusinessRuleException(
                'FUTURE_DATE_NOT_ALLOWED',
                'Tanggal kerja tidak boleh di masa mendatang. Harap pilih tanggal hari ini atau sebelumnya.',
                422,
            );
        }

        $isBackdated = $isBackdatedInput || $workDate->isBefore($today);

        // Validation Rule 2: Backdated check-in requires a reason
        if ($isBackdated && (empty($backdateReason) || strlen(trim($backdateReason)) < 5)) {
            throw new BusinessRuleException(
                'BACKDATE_REASON_REQUIRED',
                'Alasan backdate wajib diisi minimal 5 karakter untuk pencatatan shift tanggal sebelumnya.',
                422,
            );
        }

        // Validate shift existence and active status
        $shift = Shift::query()
            ->where('organization_id', $user->organization_id)
            ->where('is_active', true)
            ->findOrFail($shiftId);

        return DB::transaction(function () use (
            $user,
            $outlet,
            $shift,
            $workDate,
            $isBackdated,
            $backdateReason,
            $notes,
        ): EmployeeShiftCheckin {
            // Check if user already has an active checkin for this exact work_date and shift
            $existing = EmployeeShiftCheckin::query()
                ->where('user_id', $user->id)
                ->where('outlet_id', $outlet->id)
                ->whereDate('work_date', $workDate->toDateString())
                ->where('shift_id', $shift->id)
                ->first();

            if ($existing !== null) {
                if ($existing->status === 'active') {
                    return $existing->loadMissing(['shift', 'user', 'outlet']);
                }

                // If existing was switched/completed, reactivate or raise validation
                $existing->update([
                    'status' => 'active',
                    'notes' => $notes ?? $existing->notes,
                ]);

                return $existing->loadMissing(['shift', 'user', 'outlet']);
            }

            // Mark any previous active checkins for other shifts on the same day as completed
            EmployeeShiftCheckin::query()
                ->where('user_id', $user->id)
                ->where('outlet_id', $outlet->id)
                ->whereDate('work_date', $workDate->toDateString())
                ->where('status', 'active')
                ->update(['status' => 'completed']);

            // Create new operational shift check-in
            $checkin = EmployeeShiftCheckin::query()->create([
                'organization_id' => $user->organization_id,
                'outlet_id' => $outlet->id,
                'user_id' => $user->id,
                'shift_id' => $shift->id,
                'work_date' => $workDate->toDateString(),
                'checked_in_at' => now(),
                'is_backdated' => $isBackdated,
                'backdate_reason' => $isBackdated ? trim((string) $backdateReason) : null,
                'approval_status' => 'approved', // Auto-approved or pending based on policy
                'status' => 'active',
                'notes' => $notes,
            ]);

            // Audit Trail in shift_checkin_histories
            ShiftCheckinHistory::query()->create([
                'employee_shift_checkin_id' => $checkin->id,
                'user_id' => $user->id,
                'action' => 'checkin',
                'old_values' => null,
                'new_values' => [
                    'shift_code' => $shift->code,
                    'shift_name' => $shift->name,
                    'work_date' => $checkin->work_date->toDateString(),
                    'is_backdated' => $isBackdated,
                    'backdate_reason' => $backdateReason,
                ],
                'reason' => $backdateReason,
                'created_at' => now(),
            ]);

            // Global Activity Log
            $this->activityTracker->recordActivity(
                user: $user,
                outlet: $outlet,
                moduleName: 'auth',
                actionType: 'checkin',
                entityType: 'EmployeeShiftCheckin',
                entityId: $checkin->id,
                description: sprintf(
                    'Karyawan %s melakukan check-in operasional %s untuk tanggal kerja %s%s',
                    $user->username,
                    $shift->name,
                    $checkin->work_date->toDateString(),
                    $isBackdated ? ' (Backdate: ' . $backdateReason . ')' : '',
                ),
                oldValues: null,
                newValues: [
                    'shift_id' => $shift->id,
                    'shift_name' => $shift->name,
                    'work_date' => $checkin->work_date->toDateString(),
                    'is_backdated' => $isBackdated,
                ],
                shiftCheckin: $checkin,
            );

            return $checkin->load(['shift', 'user', 'outlet']);
        });
    }

    /**
     * Switch current employee shift to another shift with an audit reason.
     */
    public function switchShift(
        User $user,
        Outlet $outlet,
        int $newShiftId,
        string $reason,
    ): EmployeeShiftCheckin {
        if (strlen(trim($reason)) < 5) {
            throw new BusinessRuleException(
                'SWITCH_REASON_REQUIRED',
                'Alasan pergantian shift wajib diisi minimal 5 karakter.',
                422,
            );
        }

        $newShift = Shift::query()
            ->where('organization_id', $user->organization_id)
            ->where('is_active', true)
            ->findOrFail($newShiftId);

        $currentCheckin = $this->getActiveCheckin($user->id, $outlet->id);

        if ($currentCheckin === null) {
            // If no active check-in, perform regular check-in
            return $this->checkIn($user, $outlet, $newShiftId, null, false, null, $reason);
        }

        if ($currentCheckin->shift_id === $newShiftId) {
            throw new BusinessRuleException(
                'SAME_SHIFT',
                'Anda sudah aktif berada di ' . $newShift->name . '.',
                422,
            );
        }

        return DB::transaction(function () use (
            $user,
            $outlet,
            $currentCheckin,
            $newShift,
            $reason,
        ): EmployeeShiftCheckin {
            // Create new check-in
            $newCheckin = EmployeeShiftCheckin::query()->create([
                'organization_id' => $user->organization_id,
                'outlet_id' => $outlet->id,
                'user_id' => $user->id,
                'shift_id' => $newShift->id,
                'work_date' => $currentCheckin->work_date->toDateString(),
                'checked_in_at' => now(),
                'is_backdated' => $currentCheckin->is_backdated,
                'backdate_reason' => $currentCheckin->backdate_reason,
                'approval_status' => 'approved',
                'status' => 'active',
                'notes' => 'Beralih dari ' . $currentCheckin->shift?->name . '. Alasan: ' . $reason,
            ]);

            // Update old check-in
            $currentCheckin->update([
                'status' => 'switched',
                'switched_to_id' => $newCheckin->id,
            ]);

            // Audit history
            ShiftCheckinHistory::query()->create([
                'employee_shift_checkin_id' => $newCheckin->id,
                'user_id' => $user->id,
                'action' => 'switch_shift',
                'old_values' => [
                    'previous_shift_id' => $currentCheckin->shift_id,
                    'previous_shift_name' => $currentCheckin->shift?->name,
                ],
                'new_values' => [
                    'new_shift_id' => $newShift->id,
                    'new_shift_name' => $newShift->name,
                ],
                'reason' => $reason,
                'created_at' => now(),
            ]);

            // Global Activity Log
            $this->activityTracker->recordActivity(
                user: $user,
                outlet: $outlet,
                moduleName: 'auth',
                actionType: 'switch_shift',
                entityType: 'EmployeeShiftCheckin',
                entityId: $newCheckin->id,
                description: sprintf(
                    'Karyawan %s beralih dari %s ke %s. Alasan: %s',
                    $user->username,
                    $currentCheckin->shift?->name,
                    $newShift->name,
                    $reason,
                ),
                oldValues: ['shift_id' => $currentCheckin->shift_id],
                newValues: ['shift_id' => $newShift->id],
                shiftCheckin: $newCheckin,
            );

            return $newCheckin->load(['shift', 'user', 'outlet']);
        });
    }

    /**
     * Supervisor approval for a backdated shift check-in.
     */
    public function approveBackdate(User $approver, int $checkinId): EmployeeShiftCheckin
    {
        $checkin = EmployeeShiftCheckin::query()->findOrFail($checkinId);

        $checkin->update([
            'approval_status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        ShiftCheckinHistory::query()->create([
            'employee_shift_checkin_id' => $checkin->id,
            'user_id' => $approver->id,
            'action' => 'approve_backdate',
            'old_values' => ['approval_status' => 'pending_approval'],
            'new_values' => ['approval_status' => 'approved', 'approved_by' => $approver->id],
            'reason' => 'Disetujui oleh supervisor ' . $approver->username,
            'created_at' => now(),
        ]);

        return $checkin->load(['shift', 'user', 'approver']);
    }
}

