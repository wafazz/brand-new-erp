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
        Schema::create('territories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'territories_tenant_reference');
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
        });

        Schema::create('sales_teams', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('territory_id')->nullable();
            $table->ulid('manager_user_id')->nullable();
            $table->ulid('parent_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'sales_teams_tenant_reference');
            $table->index(['company_id', 'manager_user_id']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign(['company_id', 'territory_id'])->references(['company_id', 'id'])->on('territories')->nullOnDelete();
            $table->foreign(['company_id', 'parent_id'])->references(['company_id', 'id'])->on('sales_teams')->nullOnDelete();
            $table->foreign('manager_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('sales_team_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('sales_team_id');
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role_in_team')->default('member');
            $table->timestampTz('joined_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'sales_team_id', 'user_id'], 'sales_team_members_unique');
            $table->index(['company_id', 'user_id']);
            $table->foreign(['company_id', 'sales_team_id'])->references(['company_id', 'id'])->on('sales_teams')->cascadeOnDelete();
        });

        Schema::create('sales_targets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('sales_team_id')->nullable();
            $table->ulid('user_id')->nullable();
            $table->string('period');
            $table->string('metric')->default('revenue');
            $table->decimal('target_amount', 15, 4);
            $table->timestampsTz();

            $table->unique(['company_id', 'sales_team_id', 'user_id', 'period', 'metric'], 'sales_targets_unique_subject');
            $table->index(['company_id', 'period']);
            $table->foreign(['company_id', 'sales_team_id'])->references(['company_id', 'id'])->on('sales_teams')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('pipeline_stages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->unsignedSmallInteger('probability')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'pipeline_stages_tenant_reference');
        });

        Schema::create('sales_activities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('user_id')->nullable();
            $table->ulid('customer_id')->nullable();
            $table->ulid('lead_id')->nullable();
            $table->string('type');
            $table->string('summary');
            $table->text('note')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('follow_up_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'user_id', 'occurred_at']);
            $table->index(['company_id', 'customer_id']);
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE sales_activities ADD CONSTRAINT sales_activities_type_check CHECK (type IN ('call','whatsapp','email','meeting','visit','note'))");
        DB::statement("ALTER TABLE sales_team_members ADD CONSTRAINT sales_team_members_role_check CHECK (role_in_team IN ('manager','executive','agent','member'))");
        DB::statement('ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_amount_check CHECK (target_amount >= 0)');
        DB::statement('ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_subject_check CHECK (sales_team_id IS NOT NULL OR user_id IS NOT NULL)');
        DB::statement('ALTER TABLE pipeline_stages ADD CONSTRAINT pipeline_stages_probability_check CHECK (probability BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE pipeline_stages ADD CONSTRAINT pipeline_stages_outcome_check CHECK (NOT (is_won AND is_lost))');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_activities');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('sales_targets');
        Schema::dropIfExists('sales_team_members');
        Schema::dropIfExists('sales_teams');
        Schema::dropIfExists('territories');
    }
};
