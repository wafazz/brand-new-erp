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
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('returned_amount', 15, 4)->default(0)->after('paid_amount');
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_returned_amount_check CHECK (returned_amount >= 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_returned_not_over_total_check CHECK (returned_amount <= total)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_returned_not_over_total_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_returned_amount_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('returned_amount');
        });
    }
};
