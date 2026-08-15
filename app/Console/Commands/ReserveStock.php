<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Models\Stock;
use App\Support\CompanyContext;
use Illuminate\Console\Command;

class ReserveStock extends Command
{
    protected $signature = 'erp:reserve-stock {company} {stock} {quantity}';

    protected $description = 'Attempt one stock reservation. Used by the concurrency suite.';

    public function handle(CompanyContext $context, InventoryService $inventory): int
    {
        $companyId = (string) $this->argument('company');
        $stockId = (string) $this->argument('stock');
        $quantity = (string) $this->argument('quantity');

        return $context->runAs($companyId, function () use ($inventory, $stockId, $quantity): int {
            $stock = Stock::query()->find($stockId);

            if ($stock === null) {
                $this->line('NOT_FOUND');

                return self::FAILURE;
            }

            try {
                $inventory->reserve($stock, $quantity);
                $this->line('RESERVED');
            } catch (InsufficientStock $exception) {
                $this->line('REFUSED');
            }

            return self::SUCCESS;
        });
    }
}
