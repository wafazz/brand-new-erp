<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('registration_no')->nullable();
            $table->string('tax_no')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('state')->nullable();
            $table->string('country', 2)->default('MY');
            $table->string('currency', 3)->default('MYR');
            $table->string('timezone')->default('Asia/Kuala_Lumpur');
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('branches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('state')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'branches_tenant_reference');
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('departments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'departments_tenant_reference');
            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->nullOnDelete();
        });

        Schema::create('company_users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('department_id')->nullable();
            $table->ulid('manager_id')->nullable();
            $table->string('role');
            $table->string('employee_no')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'user_id']);
            $table->unique(['company_id', 'id'], 'company_users_tenant_reference');
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'manager_id']);

            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->nullOnDelete();
            $table->foreign(['company_id', 'department_id'])
                ->references(['company_id', 'id'])
                ->on('departments')
                ->nullOnDelete();
            $table->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('branch_user', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id');
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'branch_id', 'user_id']);
            $table->index(['company_id', 'user_id']);

            $table->foreign(['company_id', 'branch_id'])
                ->references(['company_id', 'id'])
                ->on('branches')
                ->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('active_company_id')->references('id')->on('companies')->nullOnDelete();
        });

        $roles = implode("','", CompanyRole::values());
        DB::statement("ALTER TABLE company_users ADD CONSTRAINT company_users_role_check CHECK (role IN ('{$roles}'))");
        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_country_check CHECK (char_length(country) = 2)');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['active_company_id']);
        });

        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('company_users');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
    }
};
