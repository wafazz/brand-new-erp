<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

function screenInvoice(Company $company, User $owner, string $number, string $total = '300', ?string $branchId = null): Invoice
{
    return test()->withCompany($company, function () use ($owner, $number, $total, $branchId): Invoice {
        $order = Order::create([
            'order_number' => 'SO-'.$number,
            'owner_user_id' => $owner->getKey(),
            'branch_id' => $branchId,
            'customer_name' => 'Buyer',
            'placed_at' => now(),
        ]);

        $order->forceFill(['subtotal' => $total, 'total' => $total])->save();

        $invoice = Invoice::create([
            'order_id' => $order->getKey(),
            'branch_id' => $branchId,
            'issued_by' => $owner->getKey(),
            'invoice_number' => $number,
            'status' => 'issued',
            'customer_name' => 'Buyer',
            'issued_at' => now(),
            'due_at' => now()->addDays(30),
        ]);

        $invoice->forceFill(['subtotal' => $total, 'total' => $total])->save();

        return $invoice->refresh();
    });
}

it('refuses the invoice list to a role without invoices.view', function (): void {
    $f = routeFixture();

    $storekeeper = person($f['company'], CompanyRole::Storekeeper, 'store2@acme.test', $f['branch']);

    $this->actingAs($storekeeper)->get('/dashboard')->assertOk();
    $this->actingAs($storekeeper)->get('/invoices')->assertForbidden();
});

it('shows an accountant every invoice and a branch manager only their branch', function (): void {
    $f = routeFixture();

    $accountant = person($f['company'], CompanyRole::Accountant, 'books2@acme.test', $f['branch']);

    $northBranch = $this->withCompany($f['company'], fn (): Branch => Branch::create(['code' => 'NTH', 'name' => 'North Branch']));

    $northManager = person($f['company'], CompanyRole::BranchManager, 'north@acme.test', $northBranch);

    grant($f['company'], CompanyRole::Accountant, 'invoices.view', DataScope::Company);
    grant($f['company'], CompanyRole::BranchManager, 'invoices.view', DataScope::Branch);

    screenInvoice($f['company'], $f['alice'], 'INV-HQ', '300', $f['branch']->getKey());
    screenInvoice($f['company'], $f['bob'], 'INV-NORTH', '300', $northBranch->getKey());

    $this->actingAs($accountant)
        ->get('/invoices')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Finance/Invoices/Index')->has('invoices.data', 2));

    $response = $this->actingAs($northManager)->get('/invoices');

    $response->assertInertia(fn (AssertableInertia $page) => $page->has('invoices.data', 1)
        ->where('invoices.data.0.invoice_number', 'INV-NORTH'));

    expect($response->getContent())->not->toContain('INV-HQ');
});

it('shows no invoice under an own scope, because an invoice carries no owner column', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'invoices.view', DataScope::Own);

    screenInvoice($f['company'], $f['alice'], 'INV-OWN', '300', $f['branch']->getKey());

    $this->actingAs($f['alice'])
        ->get('/invoices')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('invoices.data', 0));
});

it('refuses a payment on an invoice from a role without payments.create', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'invoices.view', DataScope::Own);

    $invoice = screenInvoice($f['company'], $f['alice'], 'INV-NOPAY');

    $this->actingAs($f['alice'])
        ->post("/invoices/{$invoice->getKey()}/payments", ['amount' => '50'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($invoice): void {
        expect((string) $invoice->fresh()?->paid_amount)->toBe('0.0000');
    });
});

it('refuses a payment larger than the outstanding balance', function (): void {
    $f = routeFixture();

    $accountant = person($f['company'], CompanyRole::Accountant, 'books3@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::Accountant, 'invoices.view', DataScope::Company);
    grant($f['company'], CompanyRole::Accountant, 'payments.create', DataScope::Company);

    $invoice = screenInvoice($f['company'], $f['alice'], 'INV-OVER', '100');

    $this->actingAs($accountant)
        ->post("/invoices/{$invoice->getKey()}/payments", ['amount' => '250'])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->withCompany($f['company'], function () use ($invoice): void {
        expect((string) $invoice->fresh()?->paid_amount)->toBe('0.0000');
    });
});

it('refuses to void an invoice the actor can plainly see but may not void', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::BranchManager, 'hq@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::BranchManager, 'invoices.view', DataScope::Branch);

    $invoice = screenInvoice($f['company'], $f['alice'], 'INV-VOID', '300', $f['branch']->getKey());

    $this->withCompany($f['company'], function () use ($f, $manager): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($manager->can('invoices.view'))->toBeTrue('the manager must be able to see it')
            ->and($manager->can('invoices.void'))->toBeFalse('a branch manager must not hold invoices.void');
    });

    $this->actingAs($manager)
        ->get("/invoices/{$invoice->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('permissions.void', false));

    $this->actingAs($manager)
        ->post("/invoices/{$invoice->getKey()}/void", ['reason' => 'Wrong customer entirely'])
        ->assertForbidden();

    $this->withCompany($f['company'], function () use ($invoice): void {
        expect($invoice->fresh()?->status)->toBe('issued');
    });
});

it('refuses to void without a reason even for a role that may void', function (): void {
    $f = routeFixture();

    $accountant = person($f['company'], CompanyRole::Accountant, 'books4@acme.test', $f['branch']);

    grant($f['company'], CompanyRole::Accountant, 'invoices.view', DataScope::Company);
    grant($f['company'], CompanyRole::Accountant, 'invoices.void', DataScope::Company);

    $invoice = screenInvoice($f['company'], $f['alice'], 'INV-NOREASON');

    $this->actingAs($accountant)
        ->post("/invoices/{$invoice->getKey()}/void", ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->withCompany($f['company'], function () use ($invoice): void {
        expect($invoice->fresh()?->status)->toBe('issued');
    });
});
