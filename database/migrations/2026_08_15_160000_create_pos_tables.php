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
        Schema::create('pos_registers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('warehouse_id');
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'pos_registers_tenant_reference');
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
        });

        Schema::create('pos_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('pos_register_id');
            $table->ulid('opened_by');
            $table->ulid('closed_by')->nullable();
            $table->string('reference');
            $table->string('status')->default('open');
            $table->decimal('opening_float', 15, 4)->default(0);
            $table->decimal('counted_cash', 15, 4)->nullable();
            $table->decimal('expected_cash', 15, 4)->nullable();
            $table->decimal('variance', 15, 4)->nullable();
            $table->string('closing_note')->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'id'], 'pos_sessions_tenant_reference');
            $table->index(['company_id', 'pos_register_id', 'status']);
            $table->foreign(['company_id', 'pos_register_id'])->references(['company_id', 'id'])->on('pos_registers')->cascadeOnDelete();
            $table->foreign('opened_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('pos_cash_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('pos_session_id');
            $table->ulid('recorded_by')->nullable();
            $table->string('kind');
            $table->decimal('amount', 15, 4);
            $table->string('reason');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'pos_session_id']);
            $table->foreign(['company_id', 'pos_session_id'])->references(['company_id', 'id'])->on('pos_sessions')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->ulid('pos_session_id')->nullable()->after('branch_id');

            $table->index(['company_id', 'pos_session_id']);
        });

        DB::statement("ALTER TABLE pos_sessions ADD CONSTRAINT pos_sessions_status_check CHECK (status IN ('open','closed'))");
        DB::statement('ALTER TABLE pos_sessions ADD CONSTRAINT pos_sessions_float_check CHECK (opening_float >= 0)');
        DB::statement("ALTER TABLE pos_cash_movements ADD CONSTRAINT pos_cash_movements_kind_check CHECK (kind IN ('cash_in','cash_out','drop'))");
        DB::statement('ALTER TABLE pos_cash_movements ADD CONSTRAINT pos_cash_movements_amount_check CHECK (amount > 0)');

        DB::statement('CREATE UNIQUE INDEX pos_sessions_one_open_per_register ON pos_sessions (company_id, pos_register_id) WHERE status = \'open\'');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pos_cash_movements_reject_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a till movement is a record of what happened; it is never edited';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pos_cash_movements_no_update
                BEFORE UPDATE ON pos_cash_movements
                FOR EACH ROW EXECUTE FUNCTION pos_cash_movements_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pos_cash_movements_no_update ON pos_cash_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS pos_cash_movements_reject_mutation()');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'pos_session_id']);
            $table->dropColumn('pos_session_id');
        });

        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_sessions');
        Schema::dropIfExists('pos_registers');
    }
};
