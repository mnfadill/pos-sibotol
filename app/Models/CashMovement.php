<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CashMovementType;
use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cashier_shift_id
 * @property int $outlet_id
 * @property int $pos_terminal_id
 * @property int $user_id
 * @property CashMovementType $type
 * @property string $amount
 * @property string $reason
 * @property string|null $notes
 * @property int|null $authorized_by
 */
class CashMovement extends Model
{
    use BelongsToOutlet;

    protected $fillable = ['cashier_shift_id', 'outlet_id', 'pos_terminal_id', 'user_id', 'type', 'amount', 'reason', 'notes', 'authorized_by'];

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<CashierShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
