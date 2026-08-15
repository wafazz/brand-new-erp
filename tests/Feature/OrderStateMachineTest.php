<?php

declare(strict_types=1);

use App\Domain\Orders\IllegalOrderTransition;
use App\Domain\Orders\OrderMutabilityPolicy;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\ExceptionStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\CompanyContext;

function orderFixture(bool $cod = false): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company, $cod): array {
        $product = Product::create(['sku' => 'WIDGET', 'name' => 'Widget']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'WIDGET-STD',
            'selling_price' => '100.0000',
            'cost_price' => '60.0000',
        ]);

        $order = app(OrderService::class)->create([
            'customer_name' => 'Walk-in',
            'is_cod' => $cod,
            'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '2']],
        ]);

        return ['company' => $company, 'variant' => $variant, 'order' => $order];
    });
}

function inCompany(Company $company, callable $callback)
{
    return app(CompanyContext::class)->runAs($company->getKey(), $callback);
}

function advance(Company $company, Order $order, FulfilmentStatus ...$steps): Order
{
    return inCompany($company, function () use ($order, $steps): Order {
        $machine = app(OrderStateMachine::class);

        foreach ($steps as $step) {
            $order = $machine->transition($order, $step);
        }

        return $order;
    });
}

it('creates an order with totals derived from the price resolver', function (): void {
    $f = orderFixture();

    expect((string) $f['order']->total)->toBe('200.0000')
        ->and($f['order']->fulfilment_status)->toBe(FulfilmentStatus::Draft)
        ->and($f['order']->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($f['order']->exception_status)->toBe(ExceptionStatus::None);
});

it('snapshots the cost and the price basis onto every line', function (): void {
    $f = orderFixture();

    $item = inCompany($f['company'], fn () => $f['order']->items()->firstOrFail());

    expect((string) $item->unit_cost)->toBe('60.0000')
        ->and((string) $item->unit_price)->toBe('100.0000')
        ->and($item->price_basis)->toHaveKey('trail')
        ->and($item->marginAtSale()->toDecimal())->toBe('80.0000');
});

it('keeps a COD order packed while it is still unpaid', function (): void {
    $f = orderFixture(cod: true);

    $order = advance($f['company'], $f['order'],
        FulfilmentStatus::Pending, FulfilmentStatus::Approved,
        FulfilmentStatus::Allocated, FulfilmentStatus::Picked, FulfilmentStatus::Packed);

    expect($order->fulfilment_status)->toBe(FulfilmentStatus::Packed)
        ->and($order->payment_status)->toBe(PaymentStatus::Unpaid);
});

it('refuses to ship an unpaid order that is not COD, and says why', function (): void {
    $f = orderFixture(cod: false);

    $order = advance($f['company'], $f['order'],
        FulfilmentStatus::Pending, FulfilmentStatus::Approved,
        FulfilmentStatus::Allocated, FulfilmentStatus::Picked, FulfilmentStatus::Packed);

    $reason = inCompany($f['company'], fn () => app(OrderStateMachine::class)->reasonAgainst($order, FulfilmentStatus::Shipped));

    expect($reason)->toBe('This order is not COD and is not fully paid, so it cannot ship. Mark it COD or record the payment first.');
});

it('expresses a shipped-then-refunded order without inventing a hybrid state', function (): void {
    $f = orderFixture(cod: true);

    $order = advance($f['company'], $f['order'],
        FulfilmentStatus::Pending, FulfilmentStatus::Approved, FulfilmentStatus::Allocated,
        FulfilmentStatus::Picked, FulfilmentStatus::Packed, FulfilmentStatus::Shipped);

    inCompany($f['company'], function () use ($order): void {
        app(OrderService::class)->recordPayment($order, '200.0000');
    });

    $order = $order->refresh();

    $order = inCompany($f['company'], fn () => app(OrderStateMachine::class)->transition($order, PaymentStatus::Refunded));

    expect($order->fulfilment_status)->toBe(FulfilmentStatus::Shipped)
        ->and($order->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($order->exception_status)->toBe(ExceptionStatus::None);
});

it('tells a merchant to record a return rather than cancel a shipped order', function (): void {
    $f = orderFixture(cod: true);

    $order = advance($f['company'], $f['order'],
        FulfilmentStatus::Pending, FulfilmentStatus::Approved, FulfilmentStatus::Allocated,
        FulfilmentStatus::Picked, FulfilmentStatus::Packed, FulfilmentStatus::Shipped);

    $reason = inCompany($f['company'], fn () => app(OrderStateMachine::class)->reasonAgainst($order, ExceptionStatus::Cancelled));

    expect($reason)->toBe('This order has already shipped. Record a return instead of cancelling it.');
});

it('tells a merchant to cancel rather than return an order that never shipped', function (): void {
    $f = orderFixture();

    $reason = inCompany($f['company'], fn () => app(OrderStateMachine::class)->reasonAgainst($f['order'], ExceptionStatus::Returned));

    expect($reason)->toBe('This order has not shipped yet, so it cannot be returned. Cancel it instead.');
});

it('blocks fulfilment progress while an exception is open', function (): void {
    $f = orderFixture();

    $order = inCompany($f['company'], fn () => app(OrderStateMachine::class)->transition($f['order'], ExceptionStatus::OnHold));

    $reason = inCompany($f['company'], fn () => app(OrderStateMachine::class)->reasonAgainst($order, FulfilmentStatus::Pending));

    expect($reason)->toBe('This order is On hold. Clear the exception before moving fulfilment on.');
});

it('refuses to mark an order paid when the money has not arrived', function (): void {
    $f = orderFixture();

    inCompany($f['company'], function () use ($f): void {
        app(OrderService::class)->recordPayment($f['order'], '50.0000');
    });

    $order = $f['order']->refresh();

    $reason = inCompany($f['company'], fn () => app(OrderStateMachine::class)->reasonAgainst($order, PaymentStatus::Paid));

    expect($order->payment_status)->toBe(PaymentStatus::PartiallyPaid)
        ->and($reason)->toBe('Only MYR 50.00 of MYR 200.00 has been received, so this order is not fully paid.');
});

it('settles the payment status once the full amount arrives', function (): void {
    $f = orderFixture();

    inCompany($f['company'], function () use ($f): void {
        $service = app(OrderService::class);
        $service->recordPayment($f['order'], '50.0000');
        $service->recordPayment($f['order']->refresh(), '150.0000');
    });

    expect($f['order']->refresh()->payment_status)->toBe(PaymentStatus::Paid);
});

it('rejects an illegal jump with a readable sentence', function (): void {
    $f = orderFixture();

    inCompany($f['company'], function () use ($f): void {
        expect(fn () => app(OrderStateMachine::class)->transition($f['order'], FulfilmentStatus::Shipped))
            ->toThrow(IllegalOrderTransition::class, 'An order that is draft cannot become shipped.');
    });
});

it('rejects a transition to the state it is already in', function (): void {
    $f = orderFixture();

    $reason = inCompany($f['company'], fn () => app(OrderStateMachine::class)->reasonAgainst($f['order'], FulfilmentStatus::Draft));

    expect($reason)->toBe('This order is already draft.');
});

it('offers only the transitions that are actually legal right now', function (): void {
    $f = orderFixture();

    $available = inCompany($f['company'], fn () => app(OrderStateMachine::class)->availableTransitions($f['order'], 'fulfilment'));

    expect($available)->toBe([FulfilmentStatus::Pending]);
});

it('writes an append-only event for every transition', function (): void {
    $f = orderFixture();

    advance($f['company'], $f['order'], FulfilmentStatus::Pending, FulfilmentStatus::Approved);

    $events = inCompany($f['company'], fn () => OrderEvent::query()->where('order_id', $f['order']->getKey())->orderBy('created_at')->get());

    expect($events)->toHaveCount(3)
        ->and($events[0]->event)->toBe('order.created')
        ->and($events[1]->summary)->toBe('Fulfilment moved from draft to pending.')
        ->and($events[2]->summary)->toBe('Fulfilment moved from pending to approved.');

    inCompany($f['company'], function () use ($events): void {
        expect(fn () => $events[0]->update(['summary' => 'tampered']))->toThrow(RuntimeException::class);
    });
});

it('locks the field groups the mutability policy protects', function (): void {
    $f = orderFixture(cod: true);

    $order = advance($f['company'], $f['order'],
        FulfilmentStatus::Pending, FulfilmentStatus::Approved, FulfilmentStatus::Allocated,
        FulfilmentStatus::Picked);

    $map = inCompany($f['company'], fn () => app(OrderMutabilityPolicy::class)->map($order));

    expect($map['items'])->toBe('This order has been picked. Return it to allocated before changing the lines.')
        ->and($map['customer'])->toBe('The customer can only be changed while the order is a draft.')
        ->and($map['notes'])->toBeNull();
});

it('refuses to approve an order with no lines', function (): void {
    $company = Company::create(['name' => 'Empty Co', 'slug' => 'empty-'.str()->random(6)]);

    $order = inCompany($company, fn () => Order::create([
        'order_number' => 'SO-EMPTY',
        'customer_name' => 'Nobody',
    ]));

    $order = inCompany($company, fn () => app(OrderStateMachine::class)->transition($order, FulfilmentStatus::Pending));

    $reason = inCompany($company, fn () => app(OrderStateMachine::class)->reasonAgainst($order, FulfilmentStatus::Approved));

    expect($reason)->toBe('An order with no lines cannot be approved.');
});
