<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Subscriptions\SubscriptionService;
use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Throwable;

class BillSubscriptions extends Command
{
    protected $signature = 'erp:bill-subscriptions {--company=} {--date=}';

    protected $description = 'Raise an invoice for every subscription due, once per period.';

    public function handle(CompanyContext $context, SubscriptionService $subscriptions): int
    {
        $upTo = $this->option('date') === null
            ? now()->toImmutable()
            : now()->parse((string) $this->option('date'))->toImmutable();

        $companies = Company::query()
            ->when($this->option('company') !== null, fn ($query) => $query->whereKey($this->option('company')))
            ->orderBy('name')
            ->get();

        $failed = 0;

        foreach ($companies as $company) {
            try {
                $run = $context->runAs(
                    $company->getKey(),
                    fn () => $subscriptions->billDue($upTo)
                );

                $this->line("{$company->name}: {$run->summary()}");

                foreach ($run->skipped as $reason) {
                    $this->warn("  {$reason}");
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->error("{$company->name}: {$exception->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
