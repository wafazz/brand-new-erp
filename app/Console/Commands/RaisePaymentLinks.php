<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payments\PaymentLinkSweeper;
use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Throwable;

class RaisePaymentLinks extends Command
{
    protected $signature = 'erp:raise-payment-links {--company=}';

    protected $description = 'Raise a Billplz payment link for every unpaid invoice from a subscription set to collect online.';

    public function handle(CompanyContext $context, PaymentLinkSweeper $sweeper): int
    {
        $companies = Company::query()
            ->when($this->option('company') !== null, fn ($query) => $query->whereKey($this->option('company')))
            ->orderBy('name')
            ->get();

        $failed = 0;

        foreach ($companies as $company) {
            try {
                $result = $context->runAs($company->getKey(), fn () => $sweeper->sweep());

                $this->line("{$company->name}: {$result->summary()}");

                foreach ($result->failed as $reason) {
                    $this->warn("  {$reason}");
                }

                if ($result->failed !== []) {
                    $failed++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->error("{$company->name}: {$exception->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
