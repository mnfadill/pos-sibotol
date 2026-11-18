<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPayment extends Model
{
    use BelongsToOutlet;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'outlet_id', 'customer_id', 'payment_number',
        'method', 'amount', 'allocated_amount', 'unallocated_amount',
        'reference_number', 'payment_date', 'notes', 'received_by', 'idempotency_key',
        'shift_id', 'employee_shift_checkin_id', 'work_date',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'allocated_amount' => 'decimal:2',
            'unallocated_amount' => 'decimal:2',
            'payment_date' => 'date',
            'work_date' => 'date',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /** @return BelongsTo<EmployeeShiftCheckin, $this> */
    public function employeeShiftCheckin(): BelongsTo
    {
        return $this->belongsTo(EmployeeShiftCheckin::class, 'employee_shift_checkin_id');
    }
}

