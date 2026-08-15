<?php

declare(strict_types=1);

use App\Enums\ExceptionStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('customer_id')->nullable();
            $table->ulid('owner_user_id')->nullable();
            $table->string('order_number');

            $table->string('payment_status')->default(PaymentStatus::Unpaid->value);
            $table->string('fulfilment_status')->default(FulfilmentStatus::Draft->value);
            $table->string('exception_status')->default(ExceptionStatus::None->value);
            $table->boolean('is_cod')->default(false);

            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('ship_line1')->nullable();
            $table->string('ship_line2')->nullable();
            $table->string('ship_city')->nullable();
            $table->string('ship_postcode', 20)->nullable();
            $table->string('ship_state')->nullable();
            $table->string('ship_country', 2)->default('MY');

            $table->string('currency', 3)->default('MYR');
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('shipping_amount', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('paid_amount', 15, 4)->default(0);

            $table->timestampTz('placed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['company_id', 'order_number']);
            $table->unique(['company_id', 'id'], 'orders_tenant_reference');
            $table->index(['company_id', 'payment_status']);
            $table->index(['company_id', 'fulfilment_status']);
            $table->index(['company_id', 'exception_status']);
            $table->index(['company_id', 'owner_user_id']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'placed_at']);

            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->nullOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('order_id');
            $table->ulid('product_variant_id')->nullable();

            $table->string('sku');
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->jsonb('options')->nullable();

            $table->decimal('quantity', 15, 4);
            $table->decimal('quantity_allocated', 15, 4)->default(0);
            $table->decimal('quantity_picked', 15, 4)->default(0);
            $table->decimal('quantity_shipped', 15, 4)->default(0);
            $table->decimal('quantity_returned', 15, 4)->default(0);

            $table->decimal('unit_price', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4);

            $table->jsonb('price_basis')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'order_items_tenant_reference');
            $table->index(['company_id', 'order_id']);
            $table->index(['company_id', 'product_variant_id']);

            $table->foreign(['company_id', 'order_id'])->references(['company_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->nullOnDelete();
        });

        Schema::create('order_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('order_id');
            $table->ulid('actor_user_id')->nullable();
            $table->string('actor_type')->default('user');
            $table->string('event');
            $table->string('summary');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->ulid('correlation_id')->nullable();
            $table->timestampTz('created_at');

            $table->index(['company_id', 'order_id', 'created_at']);

            $table->foreign(['company_id', 'order_id'])->references(['company_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('order_id');
            $table->ulid('recorded_by')->nullable();
            $table->string('method')->default('cash');
            $table->string('reference')->nullable();
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3)->default('MYR');
            $table->timestampTz('received_at');
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'order_id']);
            $table->unique(['company_id', 'order_id', 'reference'], 'payments_unique_reference');

            $table->foreign(['company_id', 'order_id'])->references(['company_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });

        $payment = implode("','", array_column(PaymentStatus::cases(), 'value'));
        $fulfilment = implode("','", array_column(FulfilmentStatus::cases(), 'value'));
        $exception = implode("','", array_column(ExceptionStatus::cases(), 'value'));

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('{$payment}'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_fulfilment_status_check CHECK (fulfilment_status IN ('{$fulfilment}'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_exception_status_check CHECK (exception_status IN ('{$exception}'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_paid_amount_check CHECK (paid_amount >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_check CHECK (total >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_shipped_check CHECK (quantity_shipped <= quantity)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_returned_check CHECK (quantity_returned <= quantity)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount <> 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_events_reject_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'order_events is append-only; % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_events_no_update
                BEFORE UPDATE ON order_events
                FOR EACH ROW EXECUTE FUNCTION order_events_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS order_events_no_update ON order_events');
        DB::unprepared('DROP FUNCTION IF EXISTS order_events_reject_mutation()');

        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
