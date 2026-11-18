<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Global User Activity Log.
 *
 * Captures username, employee name, shift snapshot, work date, outlet, module name, and action.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $outlet_id
 * @property int $user_id
 * @property string $username_snapshot
 * @property string $employee_name_snapshot
 * @property int|null $shift_checkin_id
 * @property int|null $shift_id
 * @property string|null $shift_name_snapshot
 * @property \Illuminate\Support\Carbon|null $work_date
 * @property string $module_name
 * @property string $action_type
 * @property string $entity_type
 * @property string $entity_id
 * @property string $description
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $created_at
 */
class ActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'activity_logs';

    protected $fillable = [
        'organization_id',
        'outlet_id',
        'user_id',
        'username_snapshot',
        'employee_name_snapshot',
        'shift_checkin_id',
        'shift_id',
        'shift_name_snapshot',
        'work_date',
        'module_name',
        'action_type',
        'entity_type',
        'entity_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /** @return BelongsTo<EmployeeShiftCheckin, $this> */
    public function shiftCheckin(): BelongsTo
    {
        return $this->belongsTo(EmployeeShiftCheckin::class, 'shift_checkin_id');
    }
}

