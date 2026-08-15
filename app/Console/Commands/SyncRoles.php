<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Access\RoleProvisioner;
use Illuminate\Console\Command;

class SyncRoles extends Command
{
    protected $signature = 'erp:sync-roles {--company= : Limit to one company id}';

    protected $description = 'Give every company the roles and permissions this release ships, leaving any tuned data scope alone.';

    public function handle(RoleProvisioner $provisioner): int
    {
        $companies = Company::query()
            ->when($this->option('company') !== null, fn ($query) => $query->whereKey($this->option('company')))
            ->orderBy('name')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('No company matched.');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $provisioner->provision($company);

            $this->line("Synced {$company->name}");
        }

        $this->info("{$companies->count()} company/companies synced.");

        return self::SUCCESS;
    }
}
