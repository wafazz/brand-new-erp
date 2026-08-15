<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Numbering\DocumentNumberService;
use App\Support\CompanyContext;
use Illuminate\Console\Command;

class AllocateDocumentNumbers extends Command
{
    protected $signature = 'erp:allocate-numbers {company} {key} {count=1} {--prefix=}';

    protected $description = 'Allocate document numbers, one per line. Used by the concurrency suite.';

    public function handle(CompanyContext $context, DocumentNumberService $numbers): int
    {
        $companyId = (string) $this->argument('company');
        $key = (string) $this->argument('key');
        $count = (int) $this->argument('count');
        $prefix = (string) ($this->option('prefix') ?? '');

        $context->runAs($companyId, function () use ($numbers, $key, $count, $prefix): void {
            for ($i = 0; $i < $count; $i++) {
                $this->line($numbers->next($key, $prefix));
            }
        });

        return self::SUCCESS;
    }
}
