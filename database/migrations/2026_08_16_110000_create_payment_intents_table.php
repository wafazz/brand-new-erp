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
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('invoice_id');
            $table->ulid('requested_by')->nullable();
            $table->string('provider')->default('billplz');
            $table->string('provider_ref')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3)->default('MYR');
            $table->text('pay_url')->nullable();
            $table->jsonb('last_callback')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'invoice_id']);
            $table->foreign(['company_id', 'invoice_id'])->references(['company_id', 'id'])->on('invoices')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_status_check CHECK (status IN ('pending','paid','failed','cancelled'))");
        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_amount_check CHECK (amount > 0)');

        DB::statement('CREATE UNIQUE INDEX payment_intents_provider_ref ON payment_intents (provider, provider_ref) WHERE provider_ref IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
