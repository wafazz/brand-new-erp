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
        Schema::create('sales_rollups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->date('rollup_date');
            $table->ulid('branch_id')->nullable();
            $table->ulid('salesperson_user_id')->nullable();
            $table->ulid('sales_team_id')->nullable();
            $table->ulid('marketer_id')->nullable();
            $table->ulid('campaign_id')->nullable();
            $table->ulid('channel_id')->nullable();

            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('revenue', 15, 4)->default(0);
            $table->decimal('cost', 15, 4)->default(0);
            $table->decimal('margin', 15, 4)->default(0);
            $table->decimal('tax', 15, 4)->default(0);
            $table->timestampsTz();

            $table->index(['company_id', 'rollup_date']);
            $table->index(['company_id', 'salesperson_user_id', 'rollup_date']);
            $table->index(['company_id', 'marketer_id', 'rollup_date']);
            $table->index(['company_id', 'campaign_id', 'rollup_date']);
            $table->index(['company_id', 'branch_id', 'rollup_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sales_rollups ADD CONSTRAINT sales_rollups_unique_slice
            UNIQUE NULLS NOT DISTINCT (
                company_id, rollup_date, branch_id, salesperson_user_id,
                sales_team_id, marketer_id, campaign_id, channel_id
            )
        SQL);

        Schema::create('commission_rollups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('period');
            $table->foreignUlid('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('recipient_role');

            $table->decimal('pending', 15, 4)->default(0);
            $table->decimal('approved', 15, 4)->default(0);
            $table->decimal('payable', 15, 4)->default(0);
            $table->decimal('paid', 15, 4)->default(0);
            $table->decimal('reversed', 15, 4)->default(0);
            $table->decimal('net', 15, 4)->default(0);
            $table->timestampsTz();

            $table->unique(['company_id', 'period', 'recipient_user_id', 'recipient_role'], 'commission_rollups_unique_slice');
            $table->index(['company_id', 'period']);
        });

        Schema::create('rollup_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('kind');
            $table->string('scope_key');
            $table->unsignedInteger('rows_written')->default(0);
            $table->timestampTz('ran_at');
            $table->timestampTz('created_at');

            $table->index(['company_id', 'kind', 'ran_at']);
        });

        DB::statement("ALTER TABLE rollup_runs ADD CONSTRAINT rollup_runs_kind_check CHECK (kind IN ('sales','commission'))");
        DB::statement('ALTER TABLE sales_rollups ADD CONSTRAINT sales_rollups_orders_check CHECK (orders_count >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rollup_runs');
        Schema::dropIfExists('commission_rollups');
        Schema::dropIfExists('sales_rollups');
    }
};
