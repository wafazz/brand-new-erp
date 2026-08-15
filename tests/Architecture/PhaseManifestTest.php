<?php

declare(strict_types=1);

/**
 * @return array<string, array<int, string>>
 */
function phaseManifest(): array
{
    return [
        'P0 Foundation' => [
            'class:App\Support\Money',
            'class:App\Support\CompanyContext',
            'class:App\Models\Module',
            'file:resources/js/Components/DataTable.tsx',
        ],
        'P1 Access' => [
            'class:App\Services\Access\ScopeResolver',
            'class:App\Contracts\Scopeable',
            'class:App\Enums\DataScope',
            'table:audit_logs',
            'route:branches.index',
            'route:users.index',
            'route:roles.index',
        ],
        'P2 Master data' => [
            'class:App\Domain\Pricing\PriceResolver',
            'class:App\Domain\Numbering\DocumentNumberService',
            'class:App\Models\ProductBundle',
            'class:App\Models\TaxRate',
        ],
        'P3 Orders' => [
            'class:App\Domain\Orders\OrderStateMachine',
            'class:App\Domain\Orders\OrderMutabilityPolicy',
            'table:order_events',
            'route:invoices.index',
        ],
        'P4 Inventory and Purchasing' => [
            'class:App\Domain\Inventory\InventoryService',
            'class:App\Models\StockTransfer',
            'class:App\Domain\Purchasing\LandedCostAllocator',
            'route:approvals.index',
        ],
        'P5 Sales force and Marketing' => [
            'class:App\Domain\Attribution\AttributionReport',
            'class:App\Models\SalesTeam',
            'class:App\Models\Territory',
            'class:App\Models\SalesTarget',
            'class:App\Models\ReferralCode',
        ],
        'P6 Commission' => [
            'class:App\Domain\Commission\CommissionEngine',
            'class:App\Domain\Commission\AdSpendAllocator',
            'class:App\Domain\Commission\CommissionStateMachine',
            'table:commission_rule_versions',
        ],
        'P7 Finance' => [
            'class:App\Domain\Finance\Ledger',
            'class:App\Domain\Finance\AgeingReport',
            'class:App\Models\Expense',
            'class:App\Models\CashFlow',
        ],
        'P8 Reporting' => [
            'class:App\Domain\Reporting\DashboardService',
        ],
        'P10 shipped modules' => [
            'class:App\Domain\Pos\PosService',
            'class:App\Domain\Crm\PipelineService',
            'class:App\Domain\Hr\LeaveService',
            'class:App\Domain\Subscriptions\SubscriptionService',
            'class:App\Domain\Payments\PaymentLinkService',
        ],
    ];
}

/**
 * Whether a migration creates this table.
 *
 * Read from source rather than from a live schema: this suite never migrates a
 * database, so asking one would pass only where an earlier run left it behind.
 */
function migrationCreates(string $table): bool
{
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
        if (str_contains((string) file_get_contents($migration), "::create('{$table}'")) {
            return true;
        }
    }

    return false;
}

it('finds every artifact its closed phases claim to have shipped', function (): void {
    $missing = [];

    foreach (phaseManifest() as $phase => $artifacts) {
        foreach ($artifacts as $artifact) {
            [$kind, $name] = explode(':', $artifact, 2);

            $found = match ($kind) {
                'class' => class_exists($name) || interface_exists($name),
                'table' => migrationCreates($name),
                'route' => app('router')->getRoutes()->getByName($name) !== null,
                'file' => file_exists(dirname(__DIR__, 2).'/'.$name),
                default => false,
            };

            if (! $found) {
                $missing[] = "{$phase}: {$artifact}";
            }
        }
    }

    expect($missing)->toBeEmpty();
})->group('phase-manifest');

it('checks enough artifacts to be worth running', function (): void {
    $count = array_sum(array_map('count', phaseManifest()));

    // A manifest that shrinks silently would pass while proving nothing.
    expect($count)->toBeGreaterThanOrEqual(35, 'the phase manifest has been hollowed out');
});

it('names nothing in the phase table that was never built', function (): void {
    $planning = (string) file_get_contents(dirname(__DIR__, 2).'/Planning.md');

    $start = strpos($planning, '| Phase    | Name');
    $end = strpos($planning, '**Hard scope gate:**');

    expect($start)->not->toBeFalse('the phase table has moved or been removed')
        ->and($end)->not->toBeFalse('the marker after the phase table has moved');

    $table = substr($planning, (int) $start, (int) $end - (int) $start);

    // Each of these was listed as shipped and did not exist. The corrected rows say so
    // in prose; the original claims must not come back except by being built, which the
    // manifest above would then confirm.
    $neverBuilt = [
        'quotation and delivery order' => 'Quotation→SO→DO',
        'stock counts' => 'transfers, counts;',
        'promo codes' => 'referral/promo codes',
        'credit notes' => 'refunds, credit notes',
        'exports' => 'rollups, exports',
        'queued commission calculation' => 'strategies, queued calculation',
        'company admin' => 'Company/Branch/User admin',
    ];

    $restored = [];

    foreach ($neverBuilt as $label => $needle) {
        if (str_contains($table, $needle)) {
            $restored[] = $label;
        }
    }

    expect($restored)->toBeEmpty();
});
