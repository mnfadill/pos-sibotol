<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Operational Shift Check-in per Employee per Work Date.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $outlet_id
 * @property int $user_id
 * @property int $shift_id
 * @property \Illuminate\Support\Carbon $work_date
 * @property \Illuminate\Support\Carbon $checked_in_at
 * @property bool $is_backdated
 * @property string|null $backdate_reason
 * @property string $approval_status
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property string $status
 * @property int|null $switched_to_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class EmployeeShiftCheckin extends Model
{
    use HasFactory;

    protected $table = 'employee_shift_checkins';

    protected $fillable = [
        'uuid',
        'organization_id',
        'outlet_id',
        'user_id',
        'shift_id',
        'work_date',
        'checked_in_at',
        'is_backdated',
        'backdate_reason',
        'approval_status',
        'approved_by',
        'approved_at',
        'status',
        'switched_to_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'checked_in_at' => 'datetime',
            'approved_at' => 'datetime',
            'is_backdated' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EmployeeShiftCheckin $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<ShiftCheckinHistory, $this> */
    public function histories(): HasMany
    {
        return $this->hasMany(ShiftCheckinHistory::class, 'employee_shift_checkin_id');
    }

    /** @return HasMany<ActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'shift_checkin_id');
    }

    /**
     * @param Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}

