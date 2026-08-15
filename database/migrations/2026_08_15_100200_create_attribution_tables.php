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
        Schema::create('attribution_touches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('subject_type');
            $table->ulid('subject_id');
            $table->unsignedInteger('sequence');
            $table->ulid('channel_id')->nullable();
            $table->ulid('campaign_id')->nullable();
            $table->ulid('marketer_id')->nullable();
            $table->ulid('referral_code_id')->nullable();
            $table->string('source')->nullable();
            $table->string('medium')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->unique(['company_id', 'subject_type', 'subject_id', 'sequence'], 'attribution_touches_unique_sequence');
            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'campaign_id']);
            $table->index(['company_id', 'marketer_id']);

            $table->foreign(['company_id', 'channel_id'])->references(['company_id', 'id'])->on('channels')->nullOnDelete();
            $table->foreign(['company_id', 'campaign_id'])->references(['company_id', 'id'])->on('campaigns')->nullOnDelete();
            $table->foreign(['company_id', 'marketer_id'])->references(['company_id', 'id'])->on('marketers')->nullOnDelete();
            $table->foreign(['company_id', 'referral_code_id'])->references(['company_id', 'id'])->on('referral_codes')->nullOnDelete();
        });

        Schema::create('attributions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('attributable_type');
            $table->ulid('attributable_id');
            $table->string('touch_type')->default('first');

            $table->ulid('channel_id')->nullable();
            $table->ulid('campaign_id')->nullable();
            $table->ulid('marketer_id')->nullable();
            $table->ulid('referral_code_id')->nullable();
            $table->ulid('promotion_rule_id')->nullable();
            $table->ulid('lead_id')->nullable();
            $table->ulid('salesperson_user_id')->nullable();
            $table->ulid('sales_team_id')->nullable();
            $table->ulid('branch_id')->nullable();
            $table->string('source')->nullable();
            $table->string('medium')->nullable();

            $table->jsonb('raw')->nullable();
            $table->timestampTz('captured_at');
            $table->timestampsTz();

            $table->unique(['company_id', 'attributable_type', 'attributable_id'], 'attributions_unique_subject');
            $table->unique(['company_id', 'id'], 'attributions_tenant_reference');
            $table->index(['company_id', 'campaign_id']);
            $table->index(['company_id', 'marketer_id']);
            $table->index(['company_id', 'salesperson_user_id']);
            $table->index(['company_id', 'sales_team_id']);
            $table->index(['company_id', 'channel_id']);
            $table->index(['company_id', 'branch_id']);

            $table->foreign(['company_id', 'channel_id'])->references(['company_id', 'id'])->on('channels')->nullOnDelete();
            $table->foreign(['company_id', 'campaign_id'])->references(['company_id', 'id'])->on('campaigns')->nullOnDelete();
            $table->foreign(['company_id', 'marketer_id'])->references(['company_id', 'id'])->on('marketers')->nullOnDelete();
            $table->foreign(['company_id', 'referral_code_id'])->references(['company_id', 'id'])->on('referral_codes')->nullOnDelete();
            $table->foreign(['company_id', 'lead_id'])->references(['company_id', 'id'])->on('leads')->nullOnDelete();
            $table->foreign(['company_id', 'sales_team_id'])->references(['company_id', 'id'])->on('sales_teams')->nullOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign('salesperson_user_id')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE attributions ADD CONSTRAINT attributions_touch_type_check CHECK (touch_type IN ('first','last'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attribution_touches_reject_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'attribution_touches is append-only; % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER attribution_touches_no_update
                BEFORE UPDATE ON attribution_touches
                FOR EACH ROW EXECUTE FUNCTION attribution_touches_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS attribution_touches_no_update ON attribution_touches');
        DB::unprepared('DROP FUNCTION IF EXISTS attribution_touches_reject_mutation()');

        Schema::dropIfExists('attributions');
        Schema::dropIfExists('attribution_touches');
    }
};
