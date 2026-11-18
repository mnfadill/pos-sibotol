<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SalesReturn extends Model
{
    use BelongsToOrganization;
    use BelongsToOutlet;

    protected $fillable = [
        'uuid',
        'organization_id',
        'outlet_id',
        'warehouse_id',
        'sales_order_id',
        'customer_id',
        'return_number',
        'status',
        'condition',
        'return_date',
        'total_amount',
        'reason',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $sr): void {
            if (empty($sr->uuid)) {
                $sr->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'return_date' => 'date:Y-m-d',
            'total_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<SalesReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }
}

