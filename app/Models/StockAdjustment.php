<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockAdjustmentType;
use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Header of a manual stock adjustment or stock opname (always supervisor-authorized).
 *
 * @property int $id
 * @property string $uuid
 * @property int $outlet_id
 * @property int|null $pos_terminal_id
 * @property string $adjustment_number
 * @property StockAdjustmentType $type
 * @property string $status
 * @property string|null $reason
 * @property string|null $notes
 * @property int $created_by
 * @property int $authorized_by
 * @property \Carbon\CarbonImmutable $authorized_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 */
class StockAdjustment extends Model
{
    use BelongsToOutlet;
    use HasUuids;

    protected $fillable = [
        'outlet_id', 'pos_terminal_id', 'adjustment_number', 'type', 'status', 'reason', 'notes',
        'created_by', 'authorized_by', 'authorized_at', 'completed_at',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'type' => StockAdjustmentType::class,
            'authorized_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<StockAdjustmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}

