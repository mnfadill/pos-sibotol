<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'outlet_id', 'code', 'name', 'company_name', 'npwp', 'sales_pic',
        'customer_type', 'contact_person', 'customer_group', 'customer_category',
        'pricing_tier', 'tier_id', 'price_list_id', 'assigned_sales_id',
        'phone', 'email', 'city', 'address', 'shipping_address', 'tax_number',
        'credit_limit', 'payment_term_days', 'outstanding_balance', 'is_active',
    ];

    public function setCustomerStatusAttribute($value): void
    {
        $this->attributes['is_active'] = $value === 'active' || $value === true || $value === 1 || $value === '1';
    }

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'payment_term_days' => 'integer',
            'outstanding_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedSales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_sales_id');
    }

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /** @return HasMany<SalesOrder, $this> */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    /** @return BelongsTo<Tier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class, 'tier_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
