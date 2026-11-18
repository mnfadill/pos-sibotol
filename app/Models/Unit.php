<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'unit_type',
        'description',
        'is_base',
        'is_purchase_allowed',
        'is_sales_allowed',
        'is_transfer_allowed',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_base' => 'boolean',
            'is_purchase_allowed' => 'boolean',
            'is_sales_allowed' => 'boolean',
            'is_transfer_allowed' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}

