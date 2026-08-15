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
        Schema::create('goods_receipt_costs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('goods_receipt_id');
            $table->ulid('recorded_by')->nullable();
            $table->string('kind');
            $table->string('allocation')->default('by_value');
            $table->decimal('amount', 15, 4);
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'goods_receipt_id']);
            $table->foreign(['company_id', 'goods_receipt_id'])->references(['company_id', 'id'])->on('goods_receipts')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            $table->decimal('landed_unit_cost', 15, 4)->nullable()->after('unit_cost');
            $table->jsonb('landed_cost_basis')->nullable()->after('landed_unit_cost');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('average_cost', 15, 4)->nullable()->after('cost_price');
            $table->decimal('cost_quantity', 15, 4)->default(0)->after('average_cost');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('unit_cost_source')->default('standard')->after('unit_cost');
        });

        DB::statement("ALTER TABLE goods_receipt_costs ADD CONSTRAINT goods_receipt_costs_kind_check CHECK (kind IN ('freight','duty','handling','insurance','other'))");
        DB::statement("ALTER TABLE goods_receipt_costs ADD CONSTRAINT goods_receipt_costs_allocation_check CHECK (allocation IN ('by_value','by_quantity','by_weight'))");
        DB::statement('ALTER TABLE goods_receipt_costs ADD CONSTRAINT goods_receipt_costs_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_unit_cost_source_check CHECK (unit_cost_source IN ('standard','average'))");
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_cost_quantity_check CHECK (cost_quantity >= 0)');
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('unit_cost_source');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn(['average_cost', 'cost_quantity']);
        });

        Schema::table('goods_receipt_items', function (Blueprint $table): void {
            $table->dropColumn(['landed_unit_cost', 'landed_cost_basis']);
        });

        Schema::dropIfExists('goods_receipt_costs');
    }
};
