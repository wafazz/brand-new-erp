<?php

declare(strict_types=1);

use App\Domain\Commission\CommissionEngine;
use App\Domain\Commission\CommissionStateMachine;
use App\Domain\Finance\AccountCode;
use App\Domain\Finance\AgeingReport;
use App\Domain\Finance\CommissionPayoutService;
use App\Domain\Finance\InvoiceService;
use App\Domain\Finance\Ledger;
use App\Domain\Finance\UnbalancedJournalEntry;
use App\Domain\Orders\OrderService;
use App\Models\CashFlow;
use App\Models\Commission;
use App\Models\CommissionPlan;
use App\Models\CommissionRule;
use App\Models\CommissionRuleVersion;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;

function financeWorld(): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company): array {
        $tax = TaxRate::create(['code' => 'SST', 'name' => 'Sales tax', 'rate_percent' => '6']);
        $product = Product::create(['sku' => 'WIDGET', 'name' => 'Widget', 'tax_rate_id' => $tax->getKey()]);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'WIDGET-STD',
            'selling_price' => '500.0000',
            'cost_price' => '300.0000',
        ]);
        $customer = Customer::create(['code' => 'C1', 'name' => 'Aminah']);

        return compact('company', 'variant', 'customer');
    });
}

function inFinance(Company $company, callable $callback)
{
    return app(CompanyContext::class)->runAs($company->getKey(), $callback);
}

function orderFor(array $w, string $quantity = '2'): Order
{
    return inFinance($w['company'], fn (): Order => app(OrderService::class)->create([
        'customer_id' => $w['customer']->getKey(),
        'customer_name' => 'Aminah',
        'lines' => [['variant_id' => $w['variant']->getKey(), 'quantity' => $quantity]],
    ]));
}

function payableCommission(array $w): Commission
{
    return inFinance($w['company'], function () use ($w): Commission {
        $plan = CommissionPlan::create([
            'code' => 'FLAT',
            'name' => 'Flat salesperson plan',
            'strategy' => 'percentage_of_value',
            'recipient_role' => 'salesperson',
        ]);

        $rule = CommissionRule::create([
            'commission_plan_id' => $plan->getKey(),
            'code' => 'FLAT6',
            'name' => 'Six percent',
        ]);

        CommissionRuleVersion::create([
            'commission_rule_id' => $rule->getKey(),
            'version' => 1,
            'rate_type' => 'percent',
            'rate_value' => '6',
            'valid_from' => now()->subYear(),
        ]);

        $seller = User::create([
            'name' => 'Siti',
            'email' => 'siti'.str()->random(4).'@a.test',
            'password' => 'secret-password',
        ]);

        $order = app(OrderService::class)->create([
            'customer_id' => $w['customer']->getKey(),
            'customer_name' => 'Aminah',
            'lines' => [['variant_id' => $w['variant']->getKey(), 'quantity' => '2']],
        ], $seller);

        $order->forceFill(['costs_reconciled' => true])->save();

        $commission = app(CommissionEngine::class)->accrueForOrder($order->refresh())->firstOrFail();
        $commission = app(CommissionEngine::class)->finalise($commission);
        $commission = app(CommissionStateMachine::class)->transition($commission, 'approved');

        return app(CommissionPayoutService::class)->markPayable($commission);
    });
}

it('issues an invoice that snapshots the order money', function (): void {
    $w = financeWorld();
    $order = orderFor($w);

    inFinance($w['company'], function () use ($order): void {
        $invoice = app(InvoiceService::class)->issueFromOrder($order);

        expect((string) $invoice->subtotal)->toBe('1000.0000')
            ->and((string) $invoice->tax_amount)->toBe('60.0000')
            ->and((string) $invoice->total)->toBe('1060.0000')
            ->and($invoice->status)->toBe('issued')
            ->and($invoice->items()->count())->toBe(1);
    });
});

it('posts a balanced entry when an invoice is issued', function (): void {
    $w = financeWorld();
    $order = orderFor($w);

    inFinance($w['company'], function () use ($order): void {
        app(InvoiceService::class)->issueFromOrder($order);

        $ledger = app(Ledger::class);

        expect($ledger->trialBalance()->isZero())->toBeTrue()
            ->and($ledger->balanceOf(AccountCode::AccountsReceivable)->toDecimal())->toBe('1060.0000')
            ->and($ledger->balanceOf(AccountCode::Sales)->toDecimal())->toBe('1000.0000')
            ->and($ledger->balanceOf(AccountCode::TaxPayable)->toDecimal())->toBe('60.0000');
    });
});

it('reconciles invoice, payment and outstanding to the cent', function (): void {
    $w = financeWorld();

    $report = inFinance($w['company'], function () use ($w): array {
        $service = app(InvoiceService::class);

        $first = $service->issueFromOrder(orderFor($w, '2'));
        $second = $service->issueFromOrder(orderFor($w, '1'));
        $third = $service->issueFromOrder(orderFor($w, '3'));

        $service->recordPayment($first, '1060.0000');
        $service->recordPayment($second, '200.0000');

        return app(AgeingReport::class)->reconcile();
    });

    expect($report['invoiced'])->toBe('3180.0000')
        ->and($report['paid'])->toBe('1260.0000')
        ->and($report['outstanding'])->toBe('1920.0000')
        ->and($report['reconciles'])->toBe('yes')
        ->and(bcsub($report['invoiced'], bcadd($report['paid'], $report['outstanding'], 4), 4))->toBe('0.0000');
});

it('settles an invoice exactly and marks it paid', function (): void {
    $w = financeWorld();
    $order = orderFor($w);

    $invoice = inFinance($w['company'], function () use ($order) {
        $service = app(InvoiceService::class);
        $invoice = $service->issueFromOrder($order);
        $service->recordPayment($invoice, '500.0000');
        $invoice = $service->recordPayment($invoice->refresh(), '560.0000');

        return $invoice;
    });

    expect($invoice->status)->toBe('paid')
        ->and((string) $invoice->paid_amount)->toBe('1060.0000')
        ->and(inFinance($w['company'], fn () => app(InvoiceService::class)->outstanding($invoice))->isZero())->toBeTrue();
});

it('refuses to overpay an invoice', function (): void {
    $w = financeWorld();
    $order = orderFor($w);

    inFinance($w['company'], function () use ($order): void {
        $service = app(InvoiceService::class);
        $invoice = $service->issueFromOrder($order);

        expect(fn () => $service->recordPayment($invoice, '2000.0000'))
            ->toThrow(RuntimeException::class, 'outstanding and MYR 2,000.00 was offered');
    });
});

it('keeps the ledger balanced after payments', function (): void {
    $w = financeWorld();
    $order = orderFor($w);

    inFinance($w['company'], function () use ($order): void {
        $service = app(InvoiceService::class);
        $invoice = $service->issueFromOrder($order);
        $service->recordPayment($invoice, '600.0000');

        $ledger = app(Ledger::class);

        expect($ledger->trialBalance()->isZero())->toBeTrue()
            ->and($ledger->balanceOf(AccountCode::AccountsReceivable)->toDecimal())->toBe('460.0000')
            ->and($ledger->balanceOf(AccountCode::Bank)->toDecimal())->toBe('600.0000');
    });
});

it('places every outstanding invoice in the right ageing bucket', function (): void {
    $w = financeWorld();

    $buckets = inFinance($w['company'], function () use ($w): array {
        $service = app(InvoiceService::class);

        $ages = ['0-30' => 10, '31-60' => 45, '61-90' => 75, '90+' => 120];

        foreach ($ages as $days) {
            $invoice = $service->issueFromOrder(orderFor($w, '1'));
            $invoice->forceFill(['due_at' => now()->subDays($days)])->save();
        }

        return app(AgeingReport::class)->buckets();
    });

    expect($buckets['0-30'])->toBe('530.0000')
        ->and($buckets['31-60'])->toBe('530.0000')
        ->and($buckets['61-90'])->toBe('530.0000')
        ->and($buckets['90+'])->toBe('530.0000');
});

it('drops a settled invoice out of the ageing report', function (): void {
    $w = financeWorld();

    $buckets = inFinance($w['company'], function () use ($w): array {
        $service = app(InvoiceService::class);
        $invoice = $service->issueFromOrder(orderFor($w, '1'));
        $invoice->forceFill(['due_at' => now()->subDays(45)])->save();
        $service->recordPayment($invoice->refresh(), '530.0000');

        return app(AgeingReport::class)->buckets();
    });

    expect(array_sum(array_map('floatval', $buckets)))->toBe(0.0);
});

it('refuses to post an entry that does not balance', function (): void {
    $w = financeWorld();

    inFinance($w['company'], function (): void {
        expect(fn () => app(Ledger::class)->post('Broken', [
            ['account' => AccountCode::Bank, 'debit' => '100'],
            ['account' => AccountCode::Sales, 'credit' => '90'],
        ]))->toThrow(UnbalancedJournalEntry::class, 'debits MYR 100.00 against credits MYR 90.00');
    });
});

it('refuses an unbalanced entry at the database level too', function (): void {
    $w = financeWorld();

    inFinance($w['company'], function (): void {
        expect(fn () => JournalEntry::create([
            'reference' => 'JE-BAD',
            'description' => 'Unbalanced',
            'posted_at' => now(),
        ])->forceFill(['total_debit' => '100', 'total_credit' => '90'])->save())
            ->toThrow(QueryException::class);
    });
});

it('keeps journal lines append-only', function (): void {
    $w = financeWorld();
    $order = orderFor($w);

    inFinance($w['company'], function () use ($order): void {
        app(InvoiceService::class)->issueFromOrder($order);
        $line = JournalLine::query()->firstOrFail();

        expect(fn () => $line->update(['debit' => '1']))->toThrow(RuntimeException::class);
    });

    expect(fn () => DB::statement('delete from journal_lines'))->toThrow(QueryException::class, 'append-only');
});

it('refuses to void an invoice that has been paid', function (): void {
    $w = financeWorld();
    $order = orderFor($w);

    inFinance($w['company'], function () use ($order): void {
        $service = app(InvoiceService::class);
        $invoice = $service->issueFromOrder($order);
        $service->recordPayment($invoice, '100.0000');

        expect(fn () => $service->void($invoice->refresh(), 'Mistake'))
            ->toThrow(RuntimeException::class, 'Issue a credit note rather than voiding it');
    });
});

it('reverses the ledger when an unpaid invoice is voided', function (): void {
    $w = financeWorld();
    $order = orderFor($w);

    inFinance($w['company'], function () use ($order): void {
        $service = app(InvoiceService::class);
        $invoice = $service->issueFromOrder($order);
        $service->void($invoice, 'Duplicate order');

        $ledger = app(Ledger::class);

        expect($ledger->balanceOf(AccountCode::AccountsReceivable)->isZero())->toBeTrue()
            ->and($ledger->balanceOf(AccountCode::Sales)->isZero())->toBeTrue()
            ->and($ledger->trialBalance()->isZero())->toBeTrue();
    });
});

it('posts commission to the ledger when it becomes payable', function (): void {
    $w = financeWorld();
    $commission = payableCommission($w);

    inFinance($w['company'], function () use ($commission): void {
        $ledger = app(Ledger::class);

        expect($commission->status)->toBe('payable')
            ->and($ledger->balanceOf(AccountCode::CommissionExpense)->toDecimal())->toBe('60.0000')
            ->and($ledger->balanceOf(AccountCode::CommissionPayable)->toDecimal())->toBe('60.0000')
            ->and($ledger->trialBalance()->isZero())->toBeTrue();
    });
});

it('sweeps payable commission into a payout and flips every row to paid', function (): void {
    $w = financeWorld();
    $commission = payableCommission($w);

    [$payout, $reloaded] = inFinance($w['company'], function () use ($commission): array {
        $service = app(CommissionPayoutService::class);
        $payout = $service->createPayout($commission->period);
        $payout = $service->pay($payout);

        return [$payout, Commission::query()->findOrFail($commission->getKey())];
    });

    expect($payout->status)->toBe('paid')
        ->and((string) $payout->total_amount)->toBe('60.0000')
        ->and($reloaded->status)->toBe('paid')
        ->and($reloaded->paid_at)->not->toBeNull();
});

it('clears the commission payable account once the payout is made', function (): void {
    $w = financeWorld();
    $commission = payableCommission($w);

    inFinance($w['company'], function () use ($commission): void {
        $service = app(CommissionPayoutService::class);
        $service->pay($service->createPayout($commission->period));

        $ledger = app(Ledger::class);

        expect($ledger->balanceOf(AccountCode::CommissionPayable)->isZero())->toBeTrue()
            ->and($ledger->balanceOf(AccountCode::Bank)->toDecimal())->toBe('-60.0000')
            ->and($ledger->trialBalance()->isZero())->toBeTrue();
    });
});

it('writes a cash flow row for the payout', function (): void {
    $w = financeWorld();
    $commission = payableCommission($w);

    $flow = inFinance($w['company'], function () use ($commission) {
        $service = app(CommissionPayoutService::class);
        $service->pay($service->createPayout($commission->period));

        return CashFlow::query()->where('category', 'commission')->firstOrFail();
    });

    expect($flow->direction)->toBe('out')
        ->and((string) $flow->amount)->toBe('60.0000')
        ->and($flow->journal_entry_id)->not->toBeNull();
});

it('refuses to pay the same payout twice', function (): void {
    $w = financeWorld();
    $commission = payableCommission($w);

    inFinance($w['company'], function () use ($commission): void {
        $service = app(CommissionPayoutService::class);
        $payout = $service->pay($service->createPayout($commission->period));

        expect(fn () => $service->pay($payout))->toThrow(RuntimeException::class, 'already been paid');
    });
});

it('refuses to create a payout when nothing is payable', function (): void {
    $w = financeWorld();

    inFinance($w['company'], function (): void {
        expect(fn () => app(CommissionPayoutService::class)->createPayout('2026-01'))
            ->toThrow(RuntimeException::class, 'No payable commission is waiting for 2026-01.');
    });
});
