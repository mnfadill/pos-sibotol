<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete()->comment('Null = Berlaku di seluruh outlet perusahaan');
            $table->string('code', 48);
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->string('type', 16)->default('percent')->comment('percent | fixed');
            $table->decimal('value', 15, 2)->default(0);
            $table->decimal('min_purchase', 15, 2)->default(0);
            $table->decimal('max_discount', 15, 2)->nullable()->comment('Plafon maksimal diskon Rp jika tipe percent');
            $table->string('applies_to', 16)->default('both')->comment('both | pos | b2b');
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'code'], 'uq_discounts_org_code');
            $table->index(['organization_id', 'outlet_id', 'is_active'], 'idx_discounts_org_outlet_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};

