<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\EmployeeShiftCheckin;
use App\Models\Outlet;
use App\Models\User;
use App\Support\PosContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Request;

final class ActivityTrackingService
{
    public function __construct(
        private readonly PosContext $context,
    ) {}

    /**
     * Records a business activity in the global activity logs table with actor and shift snapshots.
     *
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function recordActivity(
        User $user,
        ?Outlet $outlet,
        string $moduleName,
        string $actionType,
        string $entityType,
        string|int $entityId,
        string $description,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?EmployeeShiftCheckin $shiftCheckin = null,
    ): ActivityLog {
        $activeOutlet = $outlet ?? $this->context->outlet();
        $outletId = $activeOutlet?->id ?? (int) ($user->accessibleOutletIds()[0] ?? 1);
        $organizationId = $user->organization_id;

        $checkin = $shiftCheckin ?? $this->context->employeeShiftCheckin();
        $shift = $checkin?->shift;
        $workDate = $checkin?->work_date ?? Carbon::today();

        return ActivityLog::query()->create([
            'organization_id' => $organizationId,
            'outlet_id' => $outletId,
            'user_id' => $user->id,
            'username_snapshot' => $user->username,
            'employee_name_snapshot' => $user->name,
            'shift_checkin_id' => $checkin?->id,
            'shift_id' => $shift?->id,
            'shift_name_snapshot' => $shift?->name,
            'work_date' => $workDate,
            'module_name' => $moduleName,
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Automatically stamps transactional model with actor user, active shift, checkin ID, and work date.
     */
    public function stampModel(
        Model $model,
        User $user,
        ?EmployeeShiftCheckin $shiftCheckin = null,
        bool $isUpdate = false,
    ): void {
        $checkin = $shiftCheckin ?? $this->context->employeeShiftCheckin();

        if (! $isUpdate) {
            if ($model->isFillable('created_by') && empty($model->getAttribute('created_by'))) {
                $model->setAttribute('created_by', $user->id);
            }
        } else {
            if ($model->isFillable('updated_by')) {
                $model->setAttribute('updated_by', $user->id);
            }
        }

        if ($checkin !== null) {
            if ($model->isFillable('shift_id') && empty($model->getAttribute('shift_id'))) {
                $model->setAttribute('shift_id', $checkin->shift_id);
            }
            if ($model->isFillable('employee_shift_checkin_id') && empty($model->getAttribute('employee_shift_checkin_id'))) {
                $model->setAttribute('employee_shift_checkin_id', $checkin->id);
            }
            if ($model->isFillable('work_date') && empty($model->getAttribute('work_date'))) {
                $model->setAttribute('work_date', $checkin->work_date);
            }
        }
    }
}

