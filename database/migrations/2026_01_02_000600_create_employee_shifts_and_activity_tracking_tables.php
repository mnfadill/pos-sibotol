<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Operational Shift Definitions Template
        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 64);
            $table->string('code', 32);
            $table->string('start_time', 8); // e.g. 07:00
            $table->string('end_time', 8);   // e.g. 15:00
            $table->boolean('is_overnight')->default(false);
            $table->unsignedSmallInteger('display_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['organization_id', 'outlet_id', 'is_active'], 'idx_shifts_org_outlet');
        });

        // 2. Employee Daily Operational Shift Check-ins
        Schema::create('employee_shift_checkins', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->date('work_date');
            $table->timestampTz('checked_in_at');
            $table->boolean('is_backdated')->default(false);
            $table->text('backdate_reason')->nullable();
            $table->string('approval_status', 24)->default('approved'); // approved | pending_approval | rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('status', 24)->default('active'); // active | completed | switched
            $table->unsignedBigInteger('switched_to_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'outlet_id', 'work_date', 'shift_id'], 'uq_emp_shift_checkin');
            $table->index(['user_id', 'status'], 'idx_emp_shift_user_status');
            $table->index(['outlet_id', 'work_date'], 'idx_emp_shift_outlet_date');
            $table->index(['work_date', 'shift_id'], 'idx_emp_shift_date_shift');
        });

        // 3. Shift Check-in Audit History (Switches, approvals, note updates)
        Schema::create('shift_checkin_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_shift_checkin_id')->constrained('employee_shift_checkins')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 32); // checkin | switch_shift | approve_backdate | reject_backdate | update_notes
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['employee_shift_checkin_id', 'created_at'], 'idx_shift_hist_checkin');
        });

        // 4. Global User Activity Tracking Log
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('user_id');
            $table->string('username_snapshot', 64);
            $table->string('employee_name_snapshot', 128);
            $table->unsignedBigInteger('shift_checkin_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('shift_name_snapshot', 64)->nullable();
            $table->date('work_date')->nullable();
            $table->string('module_name', 48); // sales, invoices, receivables, procurement, inventory, master_data, auth
            $table->string('action_type', 32); // create, update, delete, approve, cancel, pay, fulfill
            $table->string('entity_type', 64);
            $table->string('entity_id', 64);
            $table->text('description');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['outlet_id', 'created_at'], 'idx_activity_logs_outlet');
            $table->index(['user_id', 'created_at'], 'idx_activity_logs_user');
            $table->index(['module_name', 'created_at'], 'idx_activity_logs_module');
            $table->index(['work_date', 'shift_id'], 'idx_activity_logs_date_shift');
            $table->index(['entity_type', 'entity_id'], 'idx_activity_logs_entity');
        });

        // 5. Extend Transaction Tables with Actor Stamps & Shift References
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->after('updated_by')->constrained('shifts')->nullOnDelete();
            $table->foreignId('employee_shift_checkin_id')->nullable()->after('shift_id')->constrained('employee_shift_checkins')->nullOnDelete();
            $table->date('work_date')->nullable()->after('employee_shift_checkin_id');

            $table->index(['work_date', 'shift_id'], 'idx_invoices_work_date_shift');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->after('updated_by')->constrained('shifts')->nullOnDelete();
            $table->foreignId('employee_shift_checkin_id')->nullable()->after('shift_id')->constrained('employee_shift_checkins')->nullOnDelete();
            $table->date('work_date')->nullable()->after('employee_shift_checkin_id');

            $table->index(['work_date', 'shift_id'], 'idx_sales_orders_work_date_shift');
        });

        Schema::table('customer_payments', function (Blueprint $table): void {
            $table->foreignId('shift_id')->nullable()->after('received_by')->constrained('shifts')->nullOnDelete();
            $table->foreignId('employee_shift_checkin_id')->nullable()->after('shift_id')->constrained('employee_shift_checkins')->nullOnDelete();
            $table->date('work_date')->nullable()->after('employee_shift_checkin_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('employee_shift_checkin_id');
            $table->dropColumn('work_date');
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropIndex('idx_sales_orders_work_date_shift');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('employee_shift_checkin_id');
            $table->dropColumn('work_date');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('idx_invoices_work_date_shift');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('employee_shift_checkin_id');
            $table->dropColumn('work_date');
        });

        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('shift_checkin_histories');
        Schema::dropIfExists('employee_shift_checkins');
        Schema::dropIfExists('shifts');
    }
};

