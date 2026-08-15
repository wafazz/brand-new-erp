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
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('shipping_cost', 15, 4)->default(0)->after('shipping_amount');
            $table->decimal('payment_fee', 15, 4)->default(0)->after('shipping_cost');
            $table->boolean('costs_reconciled')->default(false)->after('payment_fee');
        });

        Schema::create('commission_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('strategy');
            $table->string('recipient_role');
            $table->string('ad_spend_allocation')->default('pro_rata_by_order_value');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'commission_plans_tenant_reference');
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('commission_plan_id');
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'commission_rules_tenant_reference');
            $table->foreign(['company_id', 'commission_plan_id'])->references(['company_id', 'id'])->on('commission_plans')->cascadeOnDelete();
        });

        Schema::create('commission_rule_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('commission_rule_id');
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version');
            $table->string('rate_type');
            $table->decimal('rate_value', 15, 4);
            $table->jsonb('tier_config')->nullable();
            $table->jsonb('conditions')->nullable();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['company_id', 'commission_rule_id', 'version'], 'commission_rule_versions_unique');
            $table->unique(['company_id', 'id'], 'commission_rule_versions_tenant_reference');
            $table->index(['company_id', 'commission_rule_id', 'valid_from']);
            $table->foreign(['company_id', 'commission_rule_id'])->references(['company_id', 'id'])->on('commission_rules')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('commission_payouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('created_by')->nullable();
            $table->string('reference');
            $table->string('period');
            $table->string('status')->default('draft');
            $table->decimal('total_amount', 15, 4)->default(0);
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'id'], 'commission_payouts_tenant_reference');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('commissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('order_id')->nullable();
            $table->ulid('order_item_id')->nullable();
            $table->foreignUlid('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('recipient_role');
            $table->ulid('commission_plan_id');
            $table->ulid('commission_rule_id')->nullable();
            $table->ulid('commission_rule_version_id')->nullable();
            $table->ulid('commission_payout_id')->nullable();
            $table->ulid('reverses_commission_id')->nullable();

            $table->string('type');
            $table->string('status')->default('pending');
            $table->boolean('is_provisional')->default(true);
            $table->string('period');

            $table->string('currency', 3)->default('MYR');
            $table->decimal('basis_amount', 15, 4);
            $table->string('rate_type');
            $table->decimal('rate_applied', 15, 4);
            $table->decimal('amount', 15, 4);
            $table->jsonb('calc_inputs');

            $table->ulid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('finalised_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'commissions_tenant_reference');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'period']);
            $table->index(['company_id', 'recipient_user_id', 'period']);
            $table->index(['company_id', 'commission_payout_id']);

            $table->foreign(['company_id', 'order_id'])->references(['company_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'order_item_id'])->references(['company_id', 'id'])->on('order_items')->cascadeOnDelete();
            $table->foreign(['company_id', 'commission_plan_id'])->references(['company_id', 'id'])->on('commission_plans')->cascadeOnDelete();
            $table->foreign(['company_id', 'commission_rule_id'])->references(['company_id', 'id'])->on('commission_rules')->nullOnDelete();
            $table->foreign(['company_id', 'commission_rule_version_id'])->references(['company_id', 'id'])->on('commission_rule_versions')->nullOnDelete();
            $table->foreign(['company_id', 'commission_payout_id'])->references(['company_id', 'id'])->on('commission_payouts')->nullOnDelete();
            $table->foreign(['company_id', 'reverses_commission_id'])->references(['company_id', 'id'])->on('commissions')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('commission_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('commission_id');
            $table->ulid('order_id');
            $table->ulid('order_item_id')->nullable();
            $table->decimal('contribution', 15, 4);
            $table->timestampTz('created_at');

            $table->unique(['company_id', 'commission_id', 'order_id', 'order_item_id'], 'commission_sources_unique');
            $table->index(['company_id', 'order_id']);
            $table->foreign(['company_id', 'commission_id'])->references(['company_id', 'id'])->on('commissions')->cascadeOnDelete();
            $table->foreign(['company_id', 'order_id'])->references(['company_id', 'id'])->on('orders')->cascadeOnDelete();
        });

        Schema::create('commission_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('commission_id');
            $table->ulid('actor_user_id')->nullable();
            $table->string('event');
            $table->string('summary');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestampTz('created_at');

            $table->index(['company_id', 'commission_id', 'created_at']);
            $table->foreign(['company_id', 'commission_id'])->references(['company_id', 'id'])->on('commissions')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('commission_payout_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('commission_payout_id');
            $table->ulid('commission_id');
            $table->decimal('amount', 15, 4);
            $table->timestampsTz();

            $table->unique(['company_id', 'commission_payout_id', 'commission_id'], 'commission_payout_items_unique');
            $table->foreign(['company_id', 'commission_payout_id'])->references(['company_id', 'id'])->on('commission_payouts')->cascadeOnDelete();
            $table->foreign(['company_id', 'commission_id'])->references(['company_id', 'id'])->on('commissions')->cascadeOnDelete();
        });

        Schema::create('commission_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('commission_payout_id')->nullable();
            $table->foreignUlid('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('bank_account');
            $table->string('bank_holder');
            $table->decimal('amount', 15, 4);
            $table->string('status')->default('pending');
            $table->string('voucher_path')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'recipient_user_id']);
            $table->foreign(['company_id', 'commission_payout_id'])->references(['company_id', 'id'])->on('commission_payouts')->nullOnDelete();
        });

        DB::statement("ALTER TABLE commission_plans ADD CONSTRAINT commission_plans_strategy_check CHECK (strategy IN ('percentage_of_value','percentage_of_margin','fixed_per_order','fixed_per_unit'))");
        DB::statement("ALTER TABLE commission_plans ADD CONSTRAINT commission_plans_allocation_check CHECK (ad_spend_allocation IN ('pro_rata_by_order_value','equal_per_order','pro_rata_by_margin','excluded'))");
        DB::statement("ALTER TABLE commission_plans ADD CONSTRAINT commission_plans_recipient_check CHECK (recipient_role IN ('marketer','salesperson','sales_team','upline'))");
        DB::statement("ALTER TABLE commission_rule_versions ADD CONSTRAINT commission_rule_versions_rate_type_check CHECK (rate_type IN ('percent','fixed'))");
        DB::statement('ALTER TABLE commission_rule_versions ADD CONSTRAINT commission_rule_versions_window_check CHECK (valid_to IS NULL OR valid_to > valid_from)');
        DB::statement(<<<'SQL'
            ALTER TABLE commissions ADD CONSTRAINT commissions_unique_accrual
            UNIQUE NULLS NOT DISTINCT (company_id, order_id, order_item_id, recipient_user_id, commission_plan_id, type)
        SQL);

        DB::statement("ALTER TABLE commissions ADD CONSTRAINT commissions_type_check CHECK (type IN ('direct','override','bonus','adjustment','reversal'))");
        DB::statement("ALTER TABLE commissions ADD CONSTRAINT commissions_status_check CHECK (status IN ('pending','approved','payable','paid','cancelled','reversed'))");
        DB::statement("ALTER TABLE commissions ADD CONSTRAINT commissions_provisional_not_payable_check CHECK (NOT (is_provisional AND status IN ('payable','paid')))");
        DB::statement("ALTER TABLE commission_payouts ADD CONSTRAINT commission_payouts_status_check CHECK (status IN ('draft','approved','paid','cancelled'))");
        DB::statement("ALTER TABLE commission_requests ADD CONSTRAINT commission_requests_status_check CHECK (status IN ('pending','approved','paid','rejected'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION commission_events_reject_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'commission_events is append-only; % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER commission_events_no_update
                BEFORE UPDATE ON commission_events
                FOR EACH ROW EXECUTE FUNCTION commission_events_reject_mutation();

            CREATE OR REPLACE FUNCTION commission_rule_versions_reject_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a commission rule version is immutable; edit creates a new version';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER commission_rule_versions_no_update
                BEFORE UPDATE ON commission_rule_versions
                FOR EACH ROW EXECUTE FUNCTION commission_rule_versions_reject_mutation();

            CREATE OR REPLACE FUNCTION commissions_reject_delete()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a commission is never deleted; reverse it with a contra entry';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER commissions_no_delete
                BEFORE DELETE ON commissions
                FOR EACH ROW EXECUTE FUNCTION commissions_reject_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS commissions_no_delete ON commissions');
        DB::unprepared('DROP TRIGGER IF EXISTS commission_rule_versions_no_update ON commission_rule_versions');
        DB::unprepared('DROP TRIGGER IF EXISTS commission_events_no_update ON commission_events');
        DB::unprepared('DROP FUNCTION IF EXISTS commissions_reject_delete()');
        DB::unprepared('DROP FUNCTION IF EXISTS commission_rule_versions_reject_mutation()');
        DB::unprepared('DROP FUNCTION IF EXISTS commission_events_reject_mutation()');

        Schema::dropIfExists('commission_requests');
        Schema::dropIfExists('commission_payout_items');
        Schema::dropIfExists('commission_events');
        Schema::dropIfExists('commission_sources');
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_payouts');
        Schema::dropIfExists('commission_rule_versions');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('commission_plans');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['shipping_cost', 'payment_fee', 'costs_reconciled']);
        });
    }
};
