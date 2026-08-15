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
        Schema::create('channels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('kind')->default('marketing');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'channels_tenant_reference');
            $table->index(['company_id', 'kind', 'is_active']);
        });

        Schema::create('marketing_teams', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('manager_user_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'marketing_teams_tenant_reference');
            $table->foreign('manager_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('marketers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->ulid('marketing_team_id')->nullable();
            $table->string('code');
            $table->string('status')->default('active');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'user_id']);
            $table->unique(['company_id', 'id'], 'marketers_tenant_reference');
            $table->foreign(['company_id', 'marketing_team_id'])->references(['company_id', 'id'])->on('marketing_teams')->nullOnDelete();
        });

        Schema::create('campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('channel_id')->nullable();
            $table->ulid('marketer_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('active');
            $table->decimal('budget', 15, 4)->default(0);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'campaigns_tenant_reference');
            $table->index(['company_id', 'status']);
            $table->foreign(['company_id', 'channel_id'])->references(['company_id', 'id'])->on('channels')->nullOnDelete();
            $table->foreign(['company_id', 'marketer_id'])->references(['company_id', 'id'])->on('marketers')->nullOnDelete();
        });

        Schema::create('campaign_costs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('campaign_id');
            $table->ulid('recorded_by')->nullable();
            $table->string('period');
            $table->string('platform')->nullable();
            $table->decimal('amount', 15, 4);
            $table->date('spent_on');
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'campaign_id', 'period']);
            $table->foreign(['company_id', 'campaign_id'])->references(['company_id', 'id'])->on('campaigns')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('referral_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('marketer_id')->nullable();
            $table->ulid('campaign_id')->nullable();
            $table->ulid('owner_user_id')->nullable();
            $table->string('code');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'referral_codes_tenant_reference');
            $table->foreign(['company_id', 'marketer_id'])->references(['company_id', 'id'])->on('marketers')->nullOnDelete();
            $table->foreign(['company_id', 'campaign_id'])->references(['company_id', 'id'])->on('campaigns')->nullOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('leads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('assigned_to')->nullable();
            $table->ulid('pipeline_stage_id')->nullable();
            $table->ulid('converted_customer_id')->nullable();
            $table->ulid('converted_order_id')->nullable();
            $table->string('reference');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('new');
            $table->decimal('estimated_value', 15, 4)->default(0);
            $table->timestampTz('captured_at');
            $table->timestampTz('converted_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'id'], 'leads_tenant_reference');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'assigned_to']);
            $table->index(['company_id', 'captured_at']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign(['company_id', 'converted_customer_id'])->references(['company_id', 'id'])->on('customers')->nullOnDelete();
            $table->foreign(['company_id', 'converted_order_id'])->references(['company_id', 'id'])->on('orders')->nullOnDelete();
            $table->foreign(['company_id', 'pipeline_stage_id'])->references(['company_id', 'id'])->on('pipeline_stages')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('lead_activities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('lead_id');
            $table->ulid('user_id')->nullable();
            $table->string('type');
            $table->string('summary');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['company_id', 'lead_id', 'occurred_at']);
            $table->foreign(['company_id', 'lead_id'])->references(['company_id', 'id'])->on('leads')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('sales_activities', function (Blueprint $table): void {
            $table->foreign(['company_id', 'lead_id'])->references(['company_id', 'id'])->on('leads')->nullOnDelete();
        });

        DB::statement("ALTER TABLE channels ADD CONSTRAINT channels_kind_check CHECK (kind IN ('marketing','sales','marketplace','direct'))");
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_status_check CHECK (status IN ('draft','active','paused','ended'))");
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_budget_check CHECK (budget >= 0)');
        DB::statement('ALTER TABLE campaign_costs ADD CONSTRAINT campaign_costs_amount_check CHECK (amount >= 0)');
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_status_check CHECK (status IN ('new','contacted','qualified','proposal','won','lost'))");
        DB::statement("ALTER TABLE marketers ADD CONSTRAINT marketers_status_check CHECK (status IN ('active','inactive','suspended'))");
        DB::statement("ALTER TABLE lead_activities ADD CONSTRAINT lead_activities_type_check CHECK (type IN ('call','whatsapp','email','meeting','note','status_changed'))");
    }

    public function down(): void
    {
        Schema::table('sales_activities', function (Blueprint $table): void {
            $table->dropForeign(['company_id', 'lead_id']);
        });

        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('referral_codes');
        Schema::dropIfExists('campaign_costs');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('marketers');
        Schema::dropIfExists('marketing_teams');
        Schema::dropIfExists('channels');
    }
};
