<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\InventoryService;
use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Throwable;

class SweepStockReservations extends Command
{
    protected $signature = 'erp:sweep-reservations';

    protected $description = 'Release expired speculative stock holds for every active company.';

    public function handle(CompanyContext $context, InventoryService $inventory): int
    {
        $failed = 0;

        foreach (Company::query()->where('is_active', true)->get() as $company) {
            try {
                $context->runAs($company->getKey(), function () use ($inventory, $company): void {
                    $released = $inventory->sweepExpired();

                    if ($released > 0) {
                        $this->line("{$company->name}: released {$released} expired hold(s)");
                    }
                });
            } catch (Throwable $exception) {
                $failed++;
                $this->error("{$company->name}: {$exception->getMessage()}");
                report($exception);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
