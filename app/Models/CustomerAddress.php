<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    protected $fillable = [
        'customer_id',
        'label',
        'recipient_name',
        'phone',
        'address',
        'city',
        'postal_code',
        'is_default_shipping',
        'is_default_billing',
    ];

    protected function casts(): array
    {
        return [
            'is_default_shipping' => 'boolean',
            'is_default_billing' => 'boolean',
        ];
    }

    public function setIsDefaultAttribute($value): void
    {
        $this->attributes['is_default_shipping'] = (bool) $value;
        $this->attributes['is_default_billing'] = (bool) $value;
    }

    public function getIsDefaultAttribute(): bool
    {
        return (bool) ($this->attributes['is_default_shipping'] ?? false);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

