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
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'warehouses_tenant_reference');
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
        });

        Schema::create('stock', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('warehouse_id');
            $table->ulid('branch_id')->nullable();
            $table->ulid('product_variant_id');
            $table->decimal('on_hand', 15, 4)->default(0);
            $table->decimal('reserved', 15, 4)->default(0);
            $table->decimal('incoming', 15, 4)->default(0);
            $table->decimal('low_stock_threshold', 15, 4)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'warehouse_id', 'product_variant_id'], 'stock_unique_line');
            $table->unique(['company_id', 'id'], 'stock_tenant_reference');
            $table->index(['company_id', 'product_variant_id']);
            $table->index(['company_id', 'branch_id']);

            $table->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->cascadeOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('stock_id');
            $table->ulid('actor_user_id')->nullable();
            $table->decimal('quantity_delta', 15, 4);
            $table->decimal('balance_after', 15, 4);
            $table->string('reason');
            $table->string('note')->nullable();
            $table->string('reference_type')->nullable();
            $table->ulid('reference_id')->nullable();
            $table->ulid('correlation_id')->nullable();
            $table->timestampTz('created_at');

            $table->index(['company_id', 'stock_id', 'created_at']);
            $table->index(['company_id', 'reference_type', 'reference_id']);

            $table->foreign(['company_id', 'stock_id'])->references(['company_id', 'id'])->on('stock')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('stock_id');
            $table->ulid('order_id')->nullable();
            $table->ulid('order_item_id')->nullable();
            $table->decimal('quantity', 15, 4);
            $table->string('status')->default('held');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('committed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'stock_id', 'status']);
            $table->index(['company_id', 'status', 'expires_at']);
            $table->index(['company_id', 'order_id']);

            $table->foreign(['company_id', 'stock_id'])->references(['company_id', 'id'])->on('stock')->cascadeOnDelete();
            $table->foreign(['company_id', 'order_id'])->references(['company_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'order_item_id'])->references(['company_id', 'id'])->on('order_items')->cascadeOnDelete();
        });

        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('warehouse_id');
            $table->ulid('requested_by')->nullable();
            $table->string('reference');
            $table->string('reason');
            $table->string('status')->default('draft');
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'id'], 'stock_adjustments_tenant_reference');
            $table->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('stock_adjustment_id');
            $table->ulid('product_variant_id');
            $table->decimal('quantity_delta', 15, 4);
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'stock_adjustment_id']);
            $table->foreign(['company_id', 'stock_adjustment_id'])->references(['company_id', 'id'])->on('stock_adjustments')->cascadeOnDelete();
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('from_warehouse_id');
            $table->ulid('to_warehouse_id');
            $table->ulid('requested_by')->nullable();
            $table->string('reference');
            $table->string('status')->default('draft');
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'id'], 'stock_transfers_tenant_reference');
            $table->foreign(['company_id', 'from_warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->cascadeOnDelete();
            $table->foreign(['company_id', 'to_warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('stock_transfer_id');
            $table->ulid('product_variant_id');
            $table->decimal('quantity', 15, 4);
            $table->timestampsTz();

            $table->index(['company_id', 'stock_transfer_id']);
            $table->foreign(['company_id', 'stock_transfer_id'])->references(['company_id', 'id'])->on('stock_transfers')->cascadeOnDelete();
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reason_check CHECK (reason IN ('received','sold','returned','adjustment','stock_take','damaged','transfer_in','transfer_out','opening'))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_delta_check CHECK (quantity_delta <> 0)');
        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_status_check CHECK (status IN ('held','committed','released'))");
        DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE stock ADD CONSTRAINT stock_reserved_check CHECK (reserved >= 0)');
        DB::statement('ALTER TABLE stock ADD CONSTRAINT stock_incoming_check CHECK (incoming >= 0)');
        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_status_check CHECK (status IN ('draft','pending','approved','rejected','applied'))");
        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_status_check CHECK (status IN ('draft','pending','approved','in_transit','received','cancelled'))");
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_distinct_check CHECK (from_warehouse_id <> to_warehouse_id)');
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_quantity_check CHECK (quantity > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_reject_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'stock_movements is append-only; % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER stock_movements_no_update
                BEFORE UPDATE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update ON stock_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS stock_movements_reject_mutation()');

        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock');
        Schema::dropIfExists('warehouses');
    }
};
