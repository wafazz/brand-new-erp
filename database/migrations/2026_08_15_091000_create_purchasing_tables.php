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
        Schema::create('purchase_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('requested_by')->nullable();
            $table->string('reference');
            $table->string('status')->default('draft');
            $table->timestampTz('needed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'id'], 'purchase_requests_tenant_reference');
            $table->index(['company_id', 'status']);
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('purchase_request_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('purchase_request_id');
            $table->ulid('product_variant_id');
            $table->decimal('quantity', 15, 4);
            $table->string('note')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'purchase_request_id']);
            $table->foreign(['company_id', 'purchase_request_id'])->references(['company_id', 'id'])->on('purchase_requests')->cascadeOnDelete();
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('warehouse_id')->nullable();
            $table->ulid('supplier_id');
            $table->ulid('purchase_request_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->string('reference');
            $table->string('status')->default('draft');
            $table->string('currency', 3)->default('MYR');
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->timestampTz('expected_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'id'], 'purchase_orders_tenant_reference');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'supplier_id']);
            $table->foreign(['company_id', 'supplier_id'])->references(['company_id', 'id'])->on('suppliers')->cascadeOnDelete();
            $table->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->nullOnDelete();
            $table->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->nullOnDelete();
            $table->foreign(['company_id', 'purchase_request_id'])->references(['company_id', 'id'])->on('purchase_requests')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('purchase_order_id');
            $table->ulid('product_variant_id');
            $table->string('sku');
            $table->string('product_name');
            $table->decimal('quantity', 15, 4);
            $table->decimal('quantity_received', 15, 4)->default(0);
            $table->decimal('quantity_billed', 15, 4)->default(0);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4);
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'purchase_order_items_tenant_reference');
            $table->index(['company_id', 'purchase_order_id']);
            $table->foreign(['company_id', 'purchase_order_id'])->references(['company_id', 'id'])->on('purchase_orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('purchase_order_id');
            $table->ulid('warehouse_id');
            $table->ulid('received_by')->nullable();
            $table->string('reference');
            $table->string('supplier_do_number')->nullable();
            $table->timestampTz('received_at');
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'id'], 'goods_receipts_tenant_reference');
            $table->index(['company_id', 'purchase_order_id']);
            $table->foreign(['company_id', 'purchase_order_id'])->references(['company_id', 'id'])->on('purchase_orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->cascadeOnDelete();
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('goods_receipt_id');
            $table->ulid('purchase_order_item_id');
            $table->ulid('product_variant_id');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4);
            $table->timestampsTz();

            $table->index(['company_id', 'goods_receipt_id']);
            $table->foreign(['company_id', 'goods_receipt_id'])->references(['company_id', 'id'])->on('goods_receipts')->cascadeOnDelete();
            $table->foreign(['company_id', 'purchase_order_item_id'])->references(['company_id', 'id'])->on('purchase_order_items')->cascadeOnDelete();
            $table->foreign(['company_id', 'product_variant_id'])->references(['company_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        Schema::create('supplier_bills', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('purchase_order_id');
            $table->ulid('supplier_id');
            $table->ulid('recorded_by')->nullable();
            $table->string('reference');
            $table->string('supplier_invoice_number');
            $table->string('status')->default('draft');
            $table->string('currency', 3)->default('MYR');
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('paid_amount', 15, 4)->default(0);
            $table->timestampTz('billed_at');
            $table->timestampTz('due_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'supplier_id', 'supplier_invoice_number'], 'supplier_bills_unique_invoice');
            $table->unique(['company_id', 'id'], 'supplier_bills_tenant_reference');
            $table->foreign(['company_id', 'purchase_order_id'])->references(['company_id', 'id'])->on('purchase_orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'supplier_id'])->references(['company_id', 'id'])->on('suppliers')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('supplier_bill_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('supplier_bill_id');
            $table->ulid('purchase_order_item_id');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('line_total', 15, 4);
            $table->timestampsTz();

            $table->index(['company_id', 'supplier_bill_id']);
            $table->foreign(['company_id', 'supplier_bill_id'])->references(['company_id', 'id'])->on('supplier_bills')->cascadeOnDelete();
            $table->foreign(['company_id', 'purchase_order_item_id'])->references(['company_id', 'id'])->on('purchase_order_items')->cascadeOnDelete();
        });

        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->ulid('supplier_bill_id');
            $table->ulid('paid_by')->nullable();
            $table->string('method')->default('bank_transfer');
            $table->string('reference')->nullable();
            $table->decimal('amount', 15, 4);
            $table->timestampTz('paid_at');
            $table->timestampsTz();

            $table->index(['company_id', 'supplier_bill_id']);
            $table->foreign(['company_id', 'supplier_bill_id'])->references(['company_id', 'id'])->on('supplier_bills')->cascadeOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_requests_status_check CHECK (status IN ('draft','pending','approved','rejected','ordered','cancelled'))");
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft','pending','approved','partially_received','received','billed','closed','cancelled'))");
        DB::statement("ALTER TABLE supplier_bills ADD CONSTRAINT supplier_bills_status_check CHECK (status IN ('draft','matched','disputed','approved','paid','cancelled'))");
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_received_check CHECK (quantity_received >= 0)');
        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_amount_check CHECK (amount <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_bill_items');
        Schema::dropIfExists('supplier_bills');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
    }
};
