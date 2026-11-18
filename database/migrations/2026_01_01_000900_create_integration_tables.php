<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Transactional Outbox: rows are written in the SAME DB transaction as the
         * business change, then relayed asynchronously to the CRM. The POS never
         * calls the CRM synchronously, so CRM downtime cannot block a sale.
         */
        Schema::create('integration_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Event ID, used by consumers for de-duplication');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 64);
            $table->string('event_type', 96)->comment('e.g. order.completed, stock.changed');
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->jsonb('payload');
            $table->string('status', 16)->default('pending')->comment('pending | processing | published | failed | dead');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at', 6)->useCurrent();
            $table->timestampTz('locked_at', 6)->nullable();
            $table->string('locked_by', 128)->nullable();
            $table->timestampTz('published_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->nullable();

            $table->index(['status', 'available_at'], 'idx_integration_outbox_dispatch');
            $table->index(['aggregate_type', 'aggregate_id'], 'idx_integration_outbox_aggregate');
            $table->index(['outlet_id', 'created_at'], 'idx_integration_outbox_outlet');
        });

        /*
         * Idempotency store for payment-processing endpoints (X-Idempotency-Key).
         */
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('idempotency_key', 128);
            $table->string('method', 8);
            $table->string('path');
            $table->char('request_hash', 64);
            $table->string('status', 16)->default('processing')->comment('processing | completed');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['user_id', 'idempotency_key'], 'uq_idempotency_keys');
            $table->index('expires_at', 'idx_idempotency_keys_expires');
        });

        /*
         * Gap-free, per-outlet document numbering (orders, refunds, shifts, adjustments).
         */
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->string('document_type', 16);
            $table->string('period', 8)->comment('YYYYMMDD');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestampsTz();

            $table->unique(['outlet_id', 'document_type', 'period'], 'uq_document_sequences');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('integration_outbox');
    }
};
