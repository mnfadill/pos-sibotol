<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'principal_id', 'code', 'name', 'contact_person', 'phone',
        'email', 'city', 'address', 'payment_term_days', 'lead_time_days',
        'bank_name', 'bank_account_number', 'bank_account_name', 'tax_number', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'payment_term_days' => 'integer',
            'lead_time_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Principal, $this> */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    /** @return BelongsToMany<Principal, $this> */
    public function principals(): BelongsToMany
    {
        return $this->belongsToMany(Principal::class, 'principal_supplier')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /** @return HasMany<SupplierProduct, $this> */
    public function productMappings(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    /** @return HasMany<SupplierProduct, $this> */
    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
