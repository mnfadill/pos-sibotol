<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PurchaseInvoice extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'uuid',
        'organization_id',
        'purchase_order_id',
        'supplier_id',
        'invoice_number',
        'supplier_invoice_ref',
        'status',
        'issue_date',
        'due_date',
        'subtotal',
        'tax_total',
        'additional_cost',
        'grand_total',
        'paid_total',
        'balance_due',
        'notes',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $pi): void {
            if (empty($pi->uuid)) {
                $pi->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'additional_cost' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'balance_due' => 'decimal:2',
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
}

