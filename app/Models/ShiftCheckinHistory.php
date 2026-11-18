<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit history for employee shift check-in changes, switches, and approvals.
 *
 * @property int $id
 * @property int $employee_shift_checkin_id
 * @property int $user_id
 * @property string $action
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon $created_at
 */
class ShiftCheckinHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'shift_checkin_histories';

    protected $fillable = [
        'employee_shift_checkin_id',
        'user_id',
        'action',
        'old_values',
        'new_values',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmployeeShiftCheckin, $this> */
    public function employeeShiftCheckin(): BelongsTo
    {
        return $this->belongsTo(EmployeeShiftCheckin::class, 'employee_shift_checkin_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

