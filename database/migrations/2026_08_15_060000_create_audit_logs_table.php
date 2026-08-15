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
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('actor_user_id')->nullable();
            $table->string('action');
            $table->string('module');
            $table->string('auditable_type')->nullable();
            $table->ulid('auditable_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('reason')->nullable();
            $table->ulid('correlation_id')->nullable();
            $table->timestampTz('created_at');

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'actor_user_id']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['company_id', 'auditable_type', 'auditable_id']);

            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->nullOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_reject_update()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only; an entry can never be edited';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION audit_logs_reject_delete()
            RETURNS trigger AS $$
            BEGIN
                IF current_setting('app.audit_purge', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'audit_logs is append-only; deletion requires an explicit purge (see AuditPurger)';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_update();

            CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_reject_update()');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_reject_delete()');

        Schema::dropIfExists('audit_logs');
    }
};
