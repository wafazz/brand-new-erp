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
        Schema::create('customer_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->decimal('discount_percent', 8, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'customer_groups_tenant_reference');
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('customer_group_id')->nullable();
            $table->ulid('owner_user_id')->nullable();
            $table->string('code');
            $table->string('type')->default('individual');
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('registration_no')->nullable();
            $table->string('tax_no')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('currency', 3)->default('MYR');
            $table->decimal('credit_limit', 15, 4)->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->string('status')->default('active');
            $table->string('acquisition_source')->nullable();
            $table->timestampTz('last_interaction_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'customers_tenant_reference');
            $table->index(['company_id', 'owner_user_id']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'name']);

            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign(['company_id', 'customer_group_id'])->references(['company_id', 'id'])->on('customer_groups')->nullOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('customer_contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('customer_id');
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index(['company_id', 'customer_id']);
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->cascadeOnDelete();
        });

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('customer_id');
            $table->string('label')->nullable();
            $table->string('type')->default('shipping');
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('state')->nullable();
            $table->string('country', 2)->default('MY');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->index(['company_id', 'customer_id']);
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->cascadeOnDelete();
        });

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('registration_no')->nullable();
            $table->string('tax_no')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('currency', 3)->default('MYR');
            $table->decimal('credit_limit', 15, 4)->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'suppliers_tenant_reference');
            $table->index(['company_id', 'status']);
        });

        Schema::create('supplier_contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('supplier_id');
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index(['company_id', 'supplier_id']);
            $table->foreign(['company_id', 'supplier_id'])->references(['company_id', 'id'])->on('suppliers')->cascadeOnDelete();
        });

        Schema::create('supplier_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('supplier_id');
            $table->string('label')->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('state')->nullable();
            $table->string('country', 2)->default('MY');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->index(['company_id', 'supplier_id']);
            $table->foreign(['company_id', 'supplier_id'])->references(['company_id', 'id'])->on('suppliers')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_type_check CHECK (type IN ('individual','business'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('active','inactive','blocked'))");
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_credit_limit_check CHECK (credit_limit >= 0)');
        DB::statement("ALTER TABLE customer_addresses ADD CONSTRAINT customer_addresses_type_check CHECK (type IN ('billing','shipping'))");
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_status_check CHECK (status IN ('active','inactive','blocked'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_addresses');
        Schema::dropIfExists('supplier_contacts');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_groups');
    }
};
