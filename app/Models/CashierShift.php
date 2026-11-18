<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShiftStatus;
use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $outlet_id
 * @property int $pos_terminal_id
 * @property int $user_id
 * @property string $shift_number
 * @property ShiftStatus $status
 * @property string $opening_cash
 * @property string|null $opening_notes
 * @property \Carbon\CarbonImmutable $opened_at
 * @property string|null $expected_cash
 * @property string|null $actual_cash
 * @property string|null $cash_difference
 * @property string|null $total_sales
 * @property string|null $total_cash_sales
 * @property string|null $total_non_cash_sales
 * @property string|null $total_paid_in
 * @property string|null $total_paid_out
 * @property string|null $total_cash_refunds
 * @property int|null $transaction_count
 * @property array<string, mixed>|null $closing_summary
 * @property string|null $closing_notes
 * @property int|null $closed_by
 * @property \Carbon\CarbonImmutable|null $closed_at
 */
class CashierShift extends Model
{
    use BelongsToOutlet;
    use HasUuids;

    protected $fillable = [
        'outlet_id', 'pos_terminal_id', 'user_id', 'shift_number', 'status', 'opening_cash', 'opening_notes',
        'opened_at', 'expected_cash', 'actual_cash', 'cash_difference', 'total_sales', 'total_cash_sales',
        'total_non_cash_sales', 'total_paid_in', 'total_paid_out', 'total_cash_refunds', 'transaction_count',
        'closing_summary', 'closing_notes', 'closed_by', 'closed_at',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'total_sales' => 'decimal:2',
            'total_cash_sales' => 'decimal:2',
            'total_non_cash_sales' => 'decimal:2',
            'total_paid_in' => 'decimal:2',
            'total_paid_out' => 'decimal:2',
            'total_cash_refunds' => 'decimal:2',
            'transaction_count' => 'integer',
            'closing_summary' => 'array',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::Open;
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<PosTerminal, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id');
    }

    /** @return HasMany<CashMovement, $this> */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ShiftStatus::Open->value);
    }
}
