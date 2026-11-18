<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_terminal_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('shift_number', 48);
            $table->string('status', 16)->default('open');
            $table->decimal('opening_cash', 15, 2);
            $table->text('opening_notes')->nullable();
            $table->timestampTz('opened_at', 6);

            // Closing figures (populated atomically on close).
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('actual_cash', 15, 2)->nullable();
            $table->decimal('cash_difference', 15, 2)->nullable()->comment('actual - expected; negative = shortage');
            $table->decimal('total_sales', 15, 2)->nullable();
            $table->decimal('total_cash_sales', 15, 2)->nullable();
            $table->decimal('total_non_cash_sales', 15, 2)->nullable();
            $table->decimal('total_paid_in', 15, 2)->nullable();
            $table->decimal('total_paid_out', 15, 2)->nullable();
            $table->decimal('total_cash_refunds', 15, 2)->nullable();
            $table->unsignedInteger('transaction_count')->nullable();
            $table->jsonb('closing_summary')->nullable()->comment('Breakdown per payment method, denominations, etc.');
            $table->text('closing_notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at', 6)->nullable();
            $table->timestampsTz();

            $table->unique(['outlet_id', 'shift_number'], 'uq_cashier_shifts_number');
            $table->index(['outlet_id', 'opened_at'], 'idx_cashier_shifts_outlet_opened');
            $table->index(['user_id', 'status'], 'idx_cashier_shifts_user_status');
        });

        // A terminal and a cashier may each have at most ONE open shift at a time.
        DB::statement("CREATE UNIQUE INDEX uq_cashier_shifts_open_terminal ON cashier_shifts (pos_terminal_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX uq_cashier_shifts_open_user ON cashier_shifts (user_id) WHERE status = 'open'");

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_terminal_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 16)->comment('paid_in | paid_out');
            $table->decimal('amount', 15, 2);
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['cashier_shift_id', 'type'], 'idx_cash_movements_shift_type');
            $table->index(['outlet_id', 'created_at'], 'idx_cash_movements_outlet_created');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cashier_shifts ADD CONSTRAINT chk_cashier_shifts_status CHECK (status IN ('open','closed'))");
            DB::statement('ALTER TABLE cashier_shifts ADD CONSTRAINT chk_cashier_shifts_opening_cash CHECK (opening_cash >= 0)');
            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT chk_cash_movements_type CHECK (type IN ('paid_in','paid_out'))");
            DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT chk_cash_movements_amount CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cashier_shifts');
    }
};
