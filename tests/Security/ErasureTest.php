<?php

declare(strict_types=1);

use App\Domain\Finance\InvoiceService;
use App\Domain\Orders\OrderService;
use App\Domain\Privacy\ErasureService;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\CompanyContext;

function erasureWorld(): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company): array {
        $product = Product::create(['sku' => 'W', 'name' => 'Widget']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'W-STD',
            'selling_price' => '500.0000',
            'cost_price' => '300.0000',
        ]);

        $customer = Customer::create([
            'code' => 'C1',
            'name' => 'Aminah binti Yusof',
            'email' => 'aminah@example.test',
            'phone' => '0123456789',
            'tax_no' => 'TX-9999',
        ]);

        CustomerContact::create(['customer_id' => $customer->getKey(), 'name' => 'Aminah', 'email' => 'aminah@example.test']);
        CustomerAddress::create(['customer_id' => $customer->getKey(), 'line1' => '12 Jalan Bunga', 'postcode' => '50000']);

        Lead::create([
            'reference' => 'LD-1',
            'name' => 'Aminah binti Yusof',
            'phone' => '0123456789',
            'converted_customer_id' => $customer->getKey(),
            'captured_at' => now(),
        ]);

        $order = app(OrderService::class)->create([
            'customer_id' => $customer->getKey(),
            'customer_name' => 'Aminah binti Yusof',
            'customer_phone' => '0123456789',
            'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '1']],
        ]);

        app(InvoiceService::class)->issueFromOrder($order);

        return compact('company', 'customer', 'order');
    });
}

function inErasure(Company $company, callable $callback)
{
    return app(CompanyContext::class)->runAs($company->getKey(), $callback);
}

it('erases personal data across every operational record', function (): void {
    $w = erasureWorld();

    $residual = inErasure($w['company'], function () use ($w): array {
        app(ErasureService::class)->eraseCustomer($w['customer'], 'Customer requested erasure under PDPA.');

        return app(ErasureService::class)->residualPersonalData($w['customer']->refresh());
    });

    expect($residual['orders_with_name'])->toBe(0)
        ->and($residual['leads_with_name'])->toBe(0)
        ->and($residual['contacts_with_name'])->toBe(0);
});

it('leaves no trace of the identity anywhere it was written', function (): void {
    $w = erasureWorld();

    inErasure($w['company'], function () use ($w): void {
        app(ErasureService::class)->eraseCustomer($w['customer'], 'Customer requested erasure.');

        expect(Customer::query()->where('name', 'like', '%Aminah%')->count())->toBe(0)
            ->and(Order::query()->where('customer_name', 'like', '%Aminah%')->count())->toBe(0)
            ->and(Lead::query()->where('name', 'like', '%Aminah%')->count())->toBe(0)
            ->and(CustomerContact::query()->where('name', 'like', '%Aminah%')->count())->toBe(0)
            ->and(CustomerAddress::query()->where('line1', 'like', '%Jalan Bunga%')->count())->toBe(0)
            ->and(Customer::query()->where('phone', '0123456789')->count())->toBe(0);
    });
});

it('retains the invoice because accounting obligation outranks erasure', function (): void {
    $w = erasureWorld();

    $report = inErasure($w['company'], fn () => app(ErasureService::class)
        ->eraseCustomer($w['customer'], 'Customer requested erasure.'));

    $invoices = inErasure($w['company'], fn (): int => Invoice::query()->count());

    expect($invoices)->toBe(1)
        ->and($report->retained['invoices'])->toBe(1)
        ->and($report->explain())->toContain('Retained under accounting obligation: 1 invoices');
});

it('records the erasure itself in the audit trail', function (): void {
    $w = erasureWorld();

    inErasure($w['company'], function () use ($w): void {
        app(ErasureService::class)->eraseCustomer($w['customer'], 'Customer requested erasure under PDPA.');

        $entry = AuditLog::query()->where('action', 'personal_data_erased')->firstOrFail();

        expect($entry->module)->toBe('privacy')
            ->and($entry->reason)->toContain('Customer requested erasure under PDPA.')
            ->and($entry->reason)->toContain('Anonymised:');
    });
});

it('refuses an erasure with no stated reason', function (): void {
    $w = erasureWorld();

    inErasure($w['company'], function () use ($w): void {
        expect(fn () => app(ErasureService::class)->eraseCustomer($w['customer'], '  '))
            ->toThrow(RuntimeException::class, 'Erasure without a record is not erasure');
    });
});

it('keeps the order total intact so the ledger still reconciles', function (): void {
    $w = erasureWorld();

    inErasure($w['company'], function () use ($w): void {
        app(ErasureService::class)->eraseCustomer($w['customer'], 'Customer requested erasure.');

        $order = Order::query()->findOrFail($w['order']->getKey());
        $invoice = Invoice::query()->firstOrFail();

        expect((string) $order->total)->toBe('500.0000')
            ->and((string) $invoice->total)->toBe('500.0000');
    });
});
