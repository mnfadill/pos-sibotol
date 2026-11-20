<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int $outlet_id
 * @property int $pos_terminal_id
 * @property int|null $cashier_shift_id
 * @property int $user_id
 * @property string $order_number
 * @property OrderStatus $status
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $crm_customer_ref
 * @property string|null $table_label
 * @property string $subtotal
 * @property string|null $discount_type
 * @property string $discount_value
 * @property string $discount_total
 * @property string|null $discount_reason
 * @property int|null $discount_authorized_by
 * @property string $service_charge_rate
 * @property string $service_charge_total
 * @property string $tax_rate
 * @property string $tax_total
 * @property string $grand_total
 * @property string $paid_total
 * @property string $change_total
 * @property string $refunded_total
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $held_at
 * @property int|null $locked_by_terminal_id
 * @property \Illuminate\Support\Carbon|null $locked_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $voided_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrderItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Payment> $payments
 */
class Order extends Model
{
    use BelongsToOutlet;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'outlet_id', 'pos_terminal_id', 'cashier_shift_id', 'user_id', 'customer_id',
        'order_number', 'status', 'order_type', 'payment_term', 'due_date',
        'customer_name', 'customer_phone', 'crm_customer_ref', 'table_label',
        'subtotal', 'discount_type', 'discount_value', 'discount_total', 'discount_reason', 'discount_authorized_by',
        'service_charge_rate', 'service_charge_total', 'tax_rate', 'tax_total', 'grand_total', 'paid_total',
        'change_total', 'refunded_total', 'notes', 'held_at', 'locked_by_terminal_id', 'locked_at',
        'completed_at', 'cancelled_at', 'cancelled_by', 'cancel_reason',
        'voided_at', 'voided_by', 'void_authorized_by', 'void_reason',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'service_charge_rate' => 'decimal:2',
            'service_charge_total' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'change_total' => 'decimal:2',
            'refunded_total' => 'decimal:2',
            'held_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
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

    /** @return BelongsTo<CashierShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id')->withoutGlobalScopes();
    }

    /** @return HasOne<Invoice, $this> */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'order_id')->withoutGlobalScopes();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStatus(Builder $query, OrderStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, $statuses));
    }
}
