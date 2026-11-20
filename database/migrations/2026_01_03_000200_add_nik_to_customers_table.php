<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'nik')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->string('nik', 32)->nullable()->after('npwp')->comment('Optional NIK/KTP (protected & masked)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'nik')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropColumn('nik');
            });
        }
    }
};
