<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'suspended_at')) {
                $table->timestampTz('suspended_at')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('users', 'suspension_reason')) {
                $table->string('suspension_reason')->nullable()->after('suspended_at');
            }
        });

        Schema::table('roles', function (Blueprint $table): void {
            if (! Schema::hasColumn('roles', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            }
            if (! Schema::hasColumn('roles', 'is_system')) {
                $table->boolean('is_system')->default(false)->after('level');
            }
            if (! Schema::hasColumn('roles', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_system');
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            if (Schema::hasColumn('roles', 'is_active')) {
                $table->dropColumn('is_active');
            }
            if (Schema::hasColumn('roles', 'is_system')) {
                $table->dropColumn('is_system');
            }
            if (Schema::hasColumn('roles', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'suspension_reason')) {
                $table->dropColumn('suspension_reason');
            }
            if (Schema::hasColumn('users', 'suspended_at')) {
                $table->dropColumn('suspended_at');
            }
        });
    }
};
