<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail.
 *
 * Immutability is enforced at THREE layers:
 *   1. Database trigger rejecting UPDATE / DELETE / TRUNCATE (this migration).
 *   2. Eloquent model guard (App\Models\Concerns\AppendOnly).
 *   3. No API route exposes mutation of audit logs.
 *
 * In production also REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM the app role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id')->nullable();
            // Plain indexed columns (no cascading FKs): the log must survive and never be rewritten
            // when referenced rows change. Actor identity is preserved via snapshots.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name_snapshot')->nullable();
            $table->string('actor_role_snapshot', 128)->nullable();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->unsignedBigInteger('pos_terminal_id')->nullable();
            $table->string('action_event', 64);
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('metadata')->nullable()->comment('ip_address, user_agent, request_id, route, ...');
            $table->string('ip_address', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->index(['outlet_id', 'created_at'], 'idx_audit_logs_outlet_created');
            $table->index(['actor_id', 'created_at'], 'idx_audit_logs_actor_created');
            $table->index(['action_event', 'created_at'], 'idx_audit_logs_action_created');
            $table->index(['entity_type', 'entity_id'], 'idx_audit_logs_entity');
            $table->index(['organization_id', 'created_at'], 'idx_audit_logs_org_created');
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION fn_audit_logs_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs is append-only: % is not permitted', TG_OP
                        USING ERRCODE = 'insufficient_privilege';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_audit_logs_no_update
                    BEFORE UPDATE OR DELETE ON audit_logs
                    FOR EACH ROW EXECUTE FUNCTION fn_audit_logs_append_only();

                CREATE TRIGGER trg_audit_logs_no_truncate
                    BEFORE TRUNCATE ON audit_logs
                    FOR EACH STATEMENT EXECUTE FUNCTION fn_audit_logs_append_only();
            SQL);

            DB::statement('CREATE INDEX idx_audit_logs_new_values_gin ON audit_logs USING GIN (new_values jsonb_path_ops)');
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_audit_logs_no_update BEFORE UPDATE ON audit_logs
                BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only: UPDATE is not permitted'); END;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs
                BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only: DELETE is not permitted'); END;
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_no_truncate ON audit_logs');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_no_update ON audit_logs');
            DB::unprepared('DROP FUNCTION IF EXISTS fn_audit_logs_append_only()');
        }

        Schema::dropIfExists('audit_logs');
    }
};
