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
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->decimal('days_per_year', 6, 2)->default(0);
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_document')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'leave_types_tenant_reference');
        });

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('leave_type_id');
            $table->ulid('user_id');
            $table->ulid('decided_by')->nullable();
            $table->string('reference');
            $table->string('status')->default('pending');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('days', 6, 2);
            $table->string('reason');
            $table->string('decision_note')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->index(['company_id', 'user_id', 'status']);
            $table->foreign(['company_id', 'leave_type_id'])->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check CHECK (status IN ('pending','approved','rejected','cancelled'))");
        DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_window_check CHECK (ends_on >= starts_on)');
        DB::statement('ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_days_check CHECK (days > 0)');
        DB::statement('ALTER TABLE leave_types ADD CONSTRAINT leave_types_days_check CHECK (days_per_year >= 0)');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX leave_requests_one_live_per_day
            ON leave_requests (company_id, user_id, starts_on, ends_on)
            WHERE status IN ('pending', 'approved')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
    }
};
