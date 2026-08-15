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
        Schema::table('pos_registers', function (Blueprint $table): void {
            $table->decimal('refund_limit', 15, 4)->nullable()->after('name');
        });

        DB::statement('ALTER TABLE pos_registers ADD CONSTRAINT pos_registers_refund_limit_check CHECK (refund_limit IS NULL OR refund_limit >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pos_registers DROP CONSTRAINT IF EXISTS pos_registers_refund_limit_check');

        Schema::table('pos_registers', function (Blueprint $table): void {
            $table->dropColumn('refund_limit');
        });
    }
};
