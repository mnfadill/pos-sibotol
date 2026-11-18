<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operational Shift Definition Template (e.g. Shift Pagi, Shift Sore, Shift Malam).
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $outlet_id
 * @property string $name
 * @property string $code
 * @property string $start_time
 * @property string $end_time
 * @property bool $is_overnight
 * @property int $display_order
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Shift extends Model
{
    use HasFactory;

    protected $table = 'shifts';

    protected $fillable = [
        'organization_id',
        'outlet_id',
        'name',
        'code',
        'start_time',
        'end_time',
        'is_overnight',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_overnight' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
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

    /** @return HasMany<EmployeeShiftCheckin, $this> */
    public function checkins(): HasMany
    {
        return $this->hasMany(EmployeeShiftCheckin::class, 'shift_id');
    }
}

