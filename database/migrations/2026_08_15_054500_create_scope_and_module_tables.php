<?php

declare(strict_types=1);

use App\Enums\DataScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission_scopes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('role_id');
            $table->ulid('permission_id');
            $table->string('scope');
            $table->timestampsTz();

            $table->unique(['role_id', 'permission_id']);
            $table->index(['company_id', 'role_id']);

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('modules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->string('route')->nullable();
            $table->string('nav_group')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_core')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['is_active', 'sort']);
        });

        Schema::create('company_module_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('module_key');
            $table->boolean('enabled')->default(true);
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'module_key']);
            $table->foreign('module_key')->references('key')->on('modules')->cascadeOnDelete();
        });

        $scopes = implode("','", array_map(
            static fn (DataScope $case): string => $case->value,
            DataScope::cases()
        ));

        DB::statement("ALTER TABLE role_permission_scopes ADD CONSTRAINT role_permission_scopes_scope_check CHECK (scope IN ('{$scopes}'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('company_module_settings');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('role_permission_scopes');
    }
};
