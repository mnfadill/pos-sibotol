<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy backbone: organization -> outlets -> POS terminals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_number', 32)->nullable()->comment('NPWP');
            $table->char('currency', 3)->default('IDR');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->jsonb('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('outlets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->decimal('tax_rate', 5, 2)->default(0)->comment('Percentage, e.g. 11.00 for PPN 11%');
            $table->decimal('service_charge_rate', 5, 2)->default(0);
            $table->decimal('max_cashier_discount_percent', 5, 2)->default(0)
                ->comment('Discount above this threshold requires supervisor override');
            $table->boolean('allow_negative_stock')->default(false);
            $table->text('qris_static_payload')->nullable()->comment('Merchant static QRIS (EMVCo MPM) string');
            $table->string('qris_merchant_name')->nullable();
            $table->jsonb('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'code'], 'uq_outlets_org_code');
            $table->index(['organization_id', 'is_active'], 'idx_outlets_org_active');
        });

        Schema::create('pos_terminals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('device_identifier')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['outlet_id', 'code'], 'uq_pos_terminals_outlet_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_terminals');
        Schema::dropIfExists('outlets');
        Schema::dropIfExists('organizations');
    }
};
