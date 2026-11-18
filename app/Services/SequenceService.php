<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generates sequential, per-outlet daily document numbers inside a DB transaction.
 *
 * Example: INV-JKT01-20261110-0001
 */
final class SequenceService
{
    public function next(Outlet $outlet, string $prefix): string
    {
        $period = CarbonImmutable::now($outlet->timezone ?: 'Asia/Jakarta')->format('Ymd');
        $type = strtoupper($prefix);

        $row = DB::table('document_sequences')
            ->where('outlet_id', $outlet->id)
            ->where('document_type', $type)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            $nextNumber = 1;
            DB::table('document_sequences')->insert([
                'outlet_id' => $outlet->id,
                'document_type' => $type,
                'period' => $period,
                'last_number' => $nextNumber,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $nextNumber = ((int) $row->last_number) + 1;
            DB::table('document_sequences')
                ->where('id', $row->id)
                ->update([
                    'last_number' => $nextNumber,
                    'updated_at' => now(),
                ]);
        }

        return sprintf('%s-%s-%s-%04d', $type, strtoupper($outlet->code), $period, $nextNumber);
    }
}

