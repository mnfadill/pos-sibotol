<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PurchaseRequest extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'uuid',
        'organization_id',
        'warehouse_id',
        'outlet_id',
        'supplier_id',
        'pr_number',
        'status',
        'request_date',
        'needed_by_date',
        'estimated_total',
        'notes',
        'requested_by',
        'approved_by',
        'approved_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $pr): void {
            if (empty($pr->uuid)) {
                $pr->uuid = (string) Str::uuid();
            }
            if (empty($pr->request_date)) {
                $pr->request_date = now()->toDateString();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'request_date' => 'date:Y-m-d',
            'needed_by_date' => 'date:Y-m-d',
            'estimated_total' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<PurchaseRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }
}

