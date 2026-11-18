<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PurchaseReturn extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'uuid',
        'organization_id',
        'purchase_order_id',
        'supplier_id',
        'warehouse_id',
        'return_number',
        'status',
        'return_date',
        'total_amount',
        'reason',
        'notes',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $pr): void {
            if (empty($pr->uuid)) {
                $pr->uuid = (string) Str::uuid();
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

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<PurchaseReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }
}

