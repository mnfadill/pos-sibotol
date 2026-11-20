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
        'organization_id', 'outlet_id', 'code', 'name', 'company_name', 'npwp', 'nik', 'sales_pic',
        'customer_type', 'contact_person', 'customer_group', 'customer_category',
        'pricing_tier', 'tier_id', 'price_list_id', 'assigned_sales_id',
        'phone', 'email', 'city', 'address', 'shipping_address', 'tax_number',
        'credit_limit', 'payment_term_days', 'outstanding_balance', 'is_active',
    ];

    protected $hidden = ['nik'];

    protected $appends = ['masked_nik', 'has_nik'];

    public function getHasNikAttribute(): bool
    {
        return ! empty($this->attributes['nik']);
    }

    public function getMaskedNikAttribute(): ?string
    {
        $raw = isset($this->attributes['nik']) ? trim((string) $this->attributes['nik']) : '';
        if ($raw === '') {
            return null;
        }
        $len = mb_strlen($raw);
        if ($len <= 6) {
            return str_repeat('*', max(1, $len - 2)) . mb_substr($raw, -2);
        }

        return mb_substr($raw, 0, 4) . str_repeat('*', max(4, $len - 8)) . mb_substr($raw, -4);
    }

    public function rawNik(): ?string
    {
        $raw = isset($this->attributes['nik']) ? trim((string) $this->attributes['nik']) : '';

        return $raw !== '' ? $raw : null;
    }

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

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<CustomerPayment, $this> */
    public function customerPayments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
