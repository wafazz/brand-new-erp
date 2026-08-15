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
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('product_variant_id');
            $table->string('code');
            $table->string('name');
            $table->string('interval')->default('monthly');
            $table->decimal('price', 15, 4);
            $table->string('currency', 3)->default('MYR');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'subscription_plans_tenant_reference');
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('customer_id');
            $table->ulid('subscription_plan_id');
            $table->ulid('owner_user_id')->nullable();
            $table->string('reference');
            $table->string('status')->default('active');
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 15, 4);
            $table->string('currency', 3)->default('MYR');
            $table->date('starts_on');
            $table->date('next_invoice_on');
            $table->date('ends_on')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'id'], 'subscriptions_tenant_reference');
            $table->index(['company_id', 'status', 'next_invoice_on']);
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['company_id', 'subscription_plan_id'])->references(['company_id', 'id'])->on('subscription_plans')->restrictOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->ulid('subscription_id')->nullable()->after('pos_session_id');
            $table->date('billing_period')->nullable()->after('subscription_id');
        });

        DB::statement("ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_interval_check CHECK (interval IN ('weekly','monthly','quarterly','yearly'))");
        DB::statement('ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_price_check CHECK (price >= 0)');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('active','paused','cancelled','ended'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_window_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX orders_one_per_subscription_period
            ON orders (company_id, subscription_id, billing_period)
            WHERE subscription_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_one_per_subscription_period');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['subscription_id', 'billing_period']);
        });

        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
