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
        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('order_id')->nullable();
            $table->ulid('customer_id')->nullable();
            $table->ulid('issued_by')->nullable();
            $table->string('invoice_number');
            $table->string('status')->default('draft');

            $table->string('customer_name');
            $table->string('customer_tax_no')->nullable();
            $table->string('bill_line1')->nullable();
            $table->string('bill_city')->nullable();
            $table->string('bill_postcode', 20)->nullable();
            $table->string('bill_state')->nullable();

            $table->string('currency', 3)->default('MYR');
            $table->string('tax_label')->nullable();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('paid_amount', 15, 4)->default(0);

            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'invoice_number']);
            $table->unique(['company_id', 'id'], 'invoices_tenant_reference');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'due_at']);

            $table->foreign(['company_id', 'order_id'])->references(['company_id', 'id'])->on('orders')->nullOnDelete();
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->nullOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign('issued_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('invoice_id');
            $table->ulid('order_item_id')->nullable();
            $table->string('sku');
            $table->string('description');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4);
            $table->timestampsTz();

            $table->index(['company_id', 'invoice_id']);
            $table->foreign(['company_id', 'invoice_id'])->references(['company_id', 'id'])->on('invoices')->cascadeOnDelete();
        });

        Schema::create('accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'accounts_tenant_reference');
            $table->index(['company_id', 'type']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('posted_by')->nullable();
            $table->string('reference');
            $table->string('description');
            $table->string('source_type')->nullable();
            $table->ulid('source_id')->nullable();
            $table->string('currency', 3)->default('MYR');
            $table->decimal('total_debit', 15, 4)->default(0);
            $table->decimal('total_credit', 15, 4)->default(0);
            $table->timestampTz('posted_at');
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'id'], 'journal_entries_tenant_reference');
            $table->index(['company_id', 'posted_at']);
            $table->index(['company_id', 'source_type', 'source_id']);
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('journal_entry_id');
            $table->ulid('account_id');
            $table->decimal('debit', 15, 4)->default(0);
            $table->decimal('credit', 15, 4)->default(0);
            $table->string('memo')->nullable();
            $table->timestampTz('created_at');

            $table->index(['company_id', 'journal_entry_id']);
            $table->index(['company_id', 'account_id']);
            $table->foreign(['company_id', 'journal_entry_id'])->references(['company_id', 'id'])->on('journal_entries')->cascadeOnDelete();
            $table->foreign(['company_id', 'account_id'])->references(['company_id', 'id'])->on('accounts')->cascadeOnDelete();
        });

        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('account_id')->nullable();
            $table->string('name');
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('currency', 3)->default('MYR');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'account_number']);
            $table->unique(['company_id', 'id'], 'bank_accounts_tenant_reference');
            $table->foreign(['company_id', 'account_id'])->references(['company_id', 'id'])->on('accounts')->nullOnDelete();
        });

        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('account_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'expense_categories_tenant_reference');
            $table->foreign(['company_id', 'account_id'])->references(['company_id', 'id'])->on('accounts')->nullOnDelete();
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('expense_category_id')->nullable();
            $table->ulid('bank_account_id')->nullable();
            $table->ulid('requested_by')->nullable();
            $table->string('reference');
            $table->string('description');
            $table->string('status')->default('draft');
            $table->string('currency', 3)->default('MYR');
            $table->decimal('amount', 15, 4);
            $table->boolean('is_recurring')->default(false);
            $table->date('spent_on');
            $table->string('attachment_path')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'id'], 'expenses_tenant_reference');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'spent_on']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign(['company_id', 'expense_category_id'])->references(['company_id', 'id'])->on('expense_categories')->nullOnDelete();
            $table->foreign(['company_id', 'bank_account_id'])->references(['company_id', 'id'])->on('bank_accounts')->nullOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('cash_flows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('bank_account_id')->nullable();
            $table->ulid('journal_entry_id')->nullable();
            $table->ulid('recorded_by')->nullable();
            $table->string('direction');
            $table->string('category');
            $table->string('description');
            $table->string('currency', 3)->default('MYR');
            $table->decimal('amount', 15, 4);
            $table->string('source_type')->nullable();
            $table->ulid('source_id')->nullable();
            $table->date('occurred_on');
            $table->timestampsTz();

            $table->index(['company_id', 'occurred_on']);
            $table->index(['company_id', 'category']);
            $table->index(['company_id', 'source_type', 'source_id']);
            $table->foreign(['company_id', 'bank_account_id'])->references(['company_id', 'id'])->on('bank_accounts')->nullOnDelete();
            $table->foreign(['company_id', 'journal_entry_id'])->references(['company_id', 'id'])->on('journal_entries')->nullOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('draft','issued','partially_paid','paid','void'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ('asset','liability','equity','income','expense'))");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('draft','pending','approved','rejected','paid'))");
        DB::statement("ALTER TABLE cash_flows ADD CONSTRAINT cash_flows_direction_check CHECK (direction IN ('in','out'))");
        DB::statement("ALTER TABLE cash_flows ADD CONSTRAINT cash_flows_category_check CHECK (category IN ('sales','purchase','expense','commission','ads','transfer','other'))");
        DB::statement('ALTER TABLE cash_flows ADD CONSTRAINT cash_flows_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_side_check CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');
        DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_balanced_check CHECK (total_debit = total_credit)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_paid_amount_check CHECK (paid_amount >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_reject_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'the journal is append-only; % is not permitted. Post a reversing entry instead', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_lines_no_update
                BEFORE UPDATE OR DELETE ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION journal_reject_mutation();

            CREATE TRIGGER journal_entries_no_delete
                BEFORE DELETE ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION journal_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_no_delete ON journal_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_no_update ON journal_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS journal_reject_mutation()');

        Schema::dropIfExists('cash_flows');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
