<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reporting\RollupService;
use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Throwable;

class RebuildRollups extends Command
{
    protected $signature = 'erp:rebuild-rollups {--date=} {--period=} {--company=}';

    protected $description = 'Rebuild sales and commission rollups for every active company.';

    public function handle(CompanyContext $context, RollupService $rollups): int
    {
        $date = $this->option('date') === null ? now() : now()->parse((string) $this->option('date'));
        $period = (string) ($this->option('period') ?? $date->format('Y-m'));

        $companies = Company::query()
            ->where('is_active', true)
            ->when($this->option('company') !== null, fn ($q) => $q->whereKey($this->option('company')))
            ->get();

        $failed = 0;

        foreach ($companies as $company) {
            try {
                $context->runAs($company->getKey(), function () use ($rollups, $date, $period, $company): void {
                    $sales = $rollups->rebuildSales($date);
                    $commission = $rollups->rebuildCommission($period);

                    $this->line("{$company->name}: {$sales} sales slice(s), {$commission} commission slice(s)");
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
