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
        Schema::create('categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('parent_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'categories_tenant_reference');
            $table->foreign(['company_id', 'parent_id'])->references(['company_id', 'id'])->on('categories')->nullOnDelete();
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'brands_tenant_reference');
        });

        Schema::create('units_of_measure', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->unsignedSmallInteger('decimals')->default(0);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'units_of_measure_tenant_reference');
        });

        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->decimal('rate_percent', 8, 4)->default(0);
            $table->boolean('is_inclusive')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'tax_rates_tenant_reference');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('category_id')->nullable();
            $table->ulid('brand_id')->nullable();
            $table->ulid('unit_of_measure_id')->nullable();
            $table->ulid('tax_rate_id')->nullable();
            $table->string('sku');
            $table->string('name');
            $table->string('type')->default('product');
            $table->text('description')->nullable();
            $table->boolean('has_variants')->default(false);
            $table->boolean('is_stock_tracked')->default(true);
            $table->string('status')->default('active');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'id'], 'products_tenant_reference');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'name']);

            $table->foreign(['company_id', 'category_id'])->references(['company_id', 'id'])->on('categories')->nullOnDelete();
            $table->foreign(['company_id', 'brand_id'])->references(['company_id', 'id'])->on('brands')->nullOnDelete();
            $table->foreign(['company_id', 'unit_of_measure_id'])->references(['company_id', 'id'])->on('units_of_measure')->nullOnDelete();
            $table->foreign(['company_id', 'tax_rate_id'])->references(['company_id', 'id'])->on('tax_rates')->nullOnDelete();
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('product_id');
            $table->string('sku');
            $table->string('name')->nullable();
            $table->string('barcode')->nullable();
            $table->jsonb('options')->nullable();
            $table->decimal('cost_price', 15, 4)->default(0);
            $table->decimal('selling_price', 15, 4)->default(0);
            $table->decimal('wholesale_price', 15, 4)->nullable();
            $table->decimal('member_price', 15, 4)->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'id'], 'product_variants_tenant_reference');
            $table->index(['company_id', 'product_id']);
            $table->index(['company_id', 'barcode']);

            $table->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::create('product_images', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('product_id');
            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index(['company_id', 'product_id']);
            $table->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::create('product_bundles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('product_id');
            $table->string('pricing_mode')->default('fixed');
            $table->decimal('fixed_price', 15, 4)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'product_id']);
            $table->unique(['company_id', 'id'], 'product_bundles_tenant_reference');
            $table->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::create('bundle_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('product_bundle_id');
            $table->ulid('product_variant_id');
            $table->decimal('quantity', 15, 4)->default(1);
            $table->timestampsTz();

            $table->unique(['company_id', 'product_bundle_id', 'product_variant_id'], 'bundle_items_unique_component');
            $table->foreign(['company_id', 'product_bundle_id'])->references(['company_id', 'id'])->on('product_bundles')->cascadeOnDelete();
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type IN ('product','service','bundle'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft','active','discontinued'))");
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_cost_check CHECK (cost_price >= 0)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_selling_check CHECK (selling_price >= 0)');
        DB::statement("ALTER TABLE product_bundles ADD CONSTRAINT product_bundles_pricing_mode_check CHECK (pricing_mode IN ('fixed','sum_of_components'))");
        DB::statement('ALTER TABLE bundle_items ADD CONSTRAINT bundle_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_rate_check CHECK (rate_percent >= 0 AND rate_percent <= 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('product_bundles');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
