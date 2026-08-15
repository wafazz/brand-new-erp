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
        Schema::create('approval_flows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('approvable_type');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'approval_flows_tenant_reference');
            $table->index(['company_id', 'approvable_type', 'is_active']);
        });

        Schema::create('approval_levels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('approval_flow_id');
            $table->ulid('approver_role_id')->nullable();
            $table->ulid('approver_user_id')->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->decimal('min_amount', 15, 4)->default(0);
            $table->decimal('max_amount', 15, 4)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'approval_flow_id', 'sequence'], 'approval_levels_unique_sequence');
            $table->unique(['company_id', 'id'], 'approval_levels_tenant_reference');
            $table->foreign(['company_id', 'approval_flow_id'])->references(['company_id', 'id'])->on('approval_flows')->cascadeOnDelete();
            $table->foreign('approver_role_id')->references('id')->on('roles')->nullOnDelete();
            $table->foreign('approver_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('approval_flow_id');
            $table->ulid('requested_by')->nullable();
            $table->string('approvable_type');
            $table->ulid('approvable_id');
            $table->decimal('amount', 15, 4)->default(0);
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('current_sequence')->default(1);
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'approvable_type', 'approvable_id'], 'approval_requests_unique_subject');
            $table->unique(['company_id', 'id'], 'approval_requests_tenant_reference');
            $table->index(['company_id', 'status']);
            $table->foreign(['company_id', 'approval_flow_id'])->references(['company_id', 'id'])->on('approval_flows')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('approval_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('approval_request_id');
            $table->ulid('actor_user_id')->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->string('action');
            $table->string('comment')->nullable();
            $table->timestampTz('created_at');

            $table->index(['company_id', 'approval_request_id', 'created_at']);
            $table->foreign(['company_id', 'approval_request_id'])->references(['company_id', 'id'])->on('approval_requests')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_check CHECK (status IN ('pending','approved','rejected','returned','cancelled'))");
        DB::statement("ALTER TABLE approval_actions ADD CONSTRAINT approval_actions_action_check CHECK (action IN ('approve','reject','return_for_revision','submit'))");
        DB::statement('ALTER TABLE approval_levels ADD CONSTRAINT approval_levels_amount_window_check CHECK (max_amount IS NULL OR max_amount >= min_amount)');
        DB::statement('ALTER TABLE approval_levels ADD CONSTRAINT approval_levels_approver_check CHECK (approver_role_id IS NOT NULL OR approver_user_id IS NOT NULL)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION approval_actions_reject_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'approval_actions is append-only; % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER approval_actions_no_update
                BEFORE UPDATE ON approval_actions
                FOR EACH ROW EXECUTE FUNCTION approval_actions_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS approval_actions_no_update ON approval_actions');
        DB::unprepared('DROP FUNCTION IF EXISTS approval_actions_reject_mutation()');

        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_levels');
        Schema::dropIfExists('approval_flows');
    }
};
