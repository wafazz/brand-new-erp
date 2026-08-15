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
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('type')->default('customer');
            $table->string('currency', 3)->default('MYR');
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'price_lists_tenant_reference');
            $table->index(['company_id', 'type', 'is_active']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('price_list_id');
            $table->ulid('product_variant_id');
            $table->decimal('price', 15, 4);
            $table->decimal('min_quantity', 15, 4)->default(1);
            $table->timestampsTz();

            $table->unique(['company_id', 'price_list_id', 'product_variant_id', 'min_quantity'], 'price_list_items_unique_break');
            $table->index(['company_id', 'product_variant_id']);
            $table->foreign(['company_id', 'price_list_id'])->references(['company_id', 'id'])->on('price_lists')->cascadeOnDelete();
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        Schema::create('tier_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('product_variant_id');
            $table->ulid('customer_group_id')->nullable();
            $table->decimal('min_quantity', 15, 4)->default(1);
            $table->decimal('price', 15, 4);
            $table->timestampsTz();

            $table->unique(['company_id', 'product_variant_id', 'customer_group_id', 'min_quantity'], 'tier_prices_unique_break');
            $table->index(['company_id', 'product_variant_id']);
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
            $table->foreign(['company_id', 'customer_group_id'])->references(['company_id', 'id'])->on('customer_groups')->cascadeOnDelete();
        });

        Schema::create('promotion_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('applies_to')->default('variant');
            $table->ulid('target_id')->nullable();
            $table->string('discount_type')->default('percent');
            $table->decimal('discount_value', 15, 4);
            $table->decimal('min_quantity', 15, 4)->default(1);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active', 'priority']);
            $table->index(['company_id', 'applies_to', 'target_id']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->ulid('price_list_id')->nullable()->after('customer_group_id');
            $table->foreign(['company_id', 'price_list_id'])->references(['company_id', 'id'])->on('price_lists')->nullOnDelete();
        });

        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->ulid('price_list_id')->nullable()->after('name');
            $table->foreign(['company_id', 'price_list_id'])->references(['company_id', 'id'])->on('price_lists')->nullOnDelete();
        });

        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('key');
            $table->string('period')->default('');
            $table->string('prefix')->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedSmallInteger('padding')->default(5);
            $table->timestampsTz();

            $table->unique(['company_id', 'key', 'period']);
        });

        DB::statement("ALTER TABLE price_lists ADD CONSTRAINT price_lists_type_check CHECK (type IN ('customer','group','branch','channel','wholesale'))");
        DB::statement('ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_price_check CHECK (price >= 0)');
        DB::statement('ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_min_quantity_check CHECK (min_quantity > 0)');
        DB::statement('ALTER TABLE tier_prices ADD CONSTRAINT tier_prices_price_check CHECK (price >= 0)');
        DB::statement('ALTER TABLE tier_prices ADD CONSTRAINT tier_prices_min_quantity_check CHECK (min_quantity > 0)');
        DB::statement("ALTER TABLE promotion_rules ADD CONSTRAINT promotion_rules_applies_to_check CHECK (applies_to IN ('variant','product','category','all'))");
        DB::statement("ALTER TABLE promotion_rules ADD CONSTRAINT promotion_rules_discount_type_check CHECK (discount_type IN ('percent','fixed'))");
        DB::statement('ALTER TABLE promotion_rules ADD CONSTRAINT promotion_rules_discount_value_check CHECK (discount_value >= 0)');
        DB::statement('ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_next_number_check CHECK (next_number >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');

        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->dropForeign(['company_id', 'price_list_id']);
            $table->dropColumn('price_list_id');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['company_id', 'price_list_id']);
            $table->dropColumn('price_list_id');
        });

        Schema::dropIfExists('promotion_rules');
        Schema::dropIfExists('tier_prices');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
