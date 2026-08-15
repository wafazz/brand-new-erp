<?php

declare(strict_types=1);

use App\Domain\Approvals\ApprovalEngine;
use App\Domain\Approvals\ApprovalNotPermitted;
use App\Models\ApprovalAction;
use App\Models\ApprovalFlow;
use App\Models\ApprovalLevel;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CompanyContext;

function approvalFixture(): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company): array {
        $requester = User::create(['name' => 'Requester', 'email' => 'req'.str()->random(4).'@a.test', 'password' => 'secret-password']);
        $manager = User::create(['name' => 'Manager', 'email' => 'mgr'.str()->random(4).'@a.test', 'password' => 'secret-password']);
        $finance = User::create(['name' => 'Finance', 'email' => 'fin'.str()->random(4).'@a.test', 'password' => 'secret-password']);
        $director = User::create(['name' => 'Director', 'email' => 'dir'.str()->random(4).'@a.test', 'password' => 'secret-password']);

        $flow = ApprovalFlow::create([
            'code' => 'PO',
            'name' => 'Purchase order approval',
            'approvable_type' => PurchaseOrder::class,
        ]);

        ApprovalLevel::create(['approval_flow_id' => $flow->getKey(), 'approver_user_id' => $manager->getKey(), 'sequence' => 1, 'min_amount' => '0']);
        ApprovalLevel::create(['approval_flow_id' => $flow->getKey(), 'approver_user_id' => $finance->getKey(), 'sequence' => 2, 'min_amount' => '1000']);
        ApprovalLevel::create(['approval_flow_id' => $flow->getKey(), 'approver_user_id' => $director->getKey(), 'sequence' => 3, 'min_amount' => '10000']);

        $supplier = Supplier::create(['code' => 'S1', 'name' => 'Supply Co']);
        $order = PurchaseOrder::create(['supplier_id' => $supplier->getKey(), 'reference' => 'PO-'.str()->random(5)]);

        return compact('company', 'requester', 'manager', 'finance', 'director', 'flow', 'order');
    });
}

function inApproval(Company $company, callable $callback)
{
    return app(CompanyContext::class)->runAs($company->getKey(), $callback);
}

it('routes a small amount to the department manager only', function (): void {
    $f = approvalFixture();

    $request = inApproval($f['company'], fn () => app(ApprovalEngine::class)->submit($f['order'], '500', $f['requester']));
    $request = inApproval($f['company'], fn () => app(ApprovalEngine::class)->approve($request, $f['manager']));

    expect($request->status)->toBe('approved');
});

it('routes a mid amount through the manager and finance', function (): void {
    $f = approvalFixture();
    $engine = app(ApprovalEngine::class);

    $request = inApproval($f['company'], fn () => $engine->submit($f['order'], '5000', $f['requester']));
    $request = inApproval($f['company'], fn () => $engine->approve($request, $f['manager']));

    expect($request->status)->toBe('pending')->and($request->current_sequence)->toBe(2);

    $request = inApproval($f['company'], fn () => $engine->approve($request, $f['finance']));

    expect($request->status)->toBe('approved');
});

it('routes a large amount all the way to the director', function (): void {
    $f = approvalFixture();
    $engine = app(ApprovalEngine::class);

    $request = inApproval($f['company'], fn () => $engine->submit($f['order'], '25000', $f['requester']));
    $request = inApproval($f['company'], fn () => $engine->approve($request, $f['manager']));
    $request = inApproval($f['company'], fn () => $engine->approve($request, $f['finance']));

    expect($request->status)->toBe('pending')->and($request->current_sequence)->toBe(3);

    $request = inApproval($f['company'], fn () => $engine->approve($request, $f['director']));

    expect($request->status)->toBe('approved');
});

it('refuses to let someone approve their own request', function (): void {
    $f = approvalFixture();
    $engine = app(ApprovalEngine::class);

    $request = inApproval($f['company'], fn () => $engine->submit($f['order'], '500', $f['manager']));

    inApproval($f['company'], function () use ($engine, $request, $f): void {
        expect($engine->reasonAgainst($request, $f['manager']))->toBe('You cannot approve your own request.');
    });
});

it('refuses an approver who is not at this level', function (): void {
    $f = approvalFixture();
    $engine = app(ApprovalEngine::class);

    $request = inApproval($f['company'], fn () => $engine->submit($f['order'], '5000', $f['requester']));

    inApproval($f['company'], function () use ($engine, $request, $f): void {
        expect($engine->reasonAgainst($request, $f['finance']))->toBe('You are not an approver at this level.')
            ->and(fn () => $engine->approve($request, $f['finance']))->toThrow(ApprovalNotPermitted::class);
    });
});

it('records every action in an append-only trail', function (): void {
    $f = approvalFixture();
    $engine = app(ApprovalEngine::class);

    $request = inApproval($f['company'], fn () => $engine->submit($f['order'], '5000', $f['requester']));
    inApproval($f['company'], fn () => $engine->approve($request, $f['manager'], 'Looks reasonable.'));

    $actions = inApproval($f['company'], fn () => ApprovalAction::query()->where('approval_request_id', $request->getKey())->orderBy('created_at')->get());

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->action)->toBe('submit')
        ->and($actions[1]->action)->toBe('approve')
        ->and($actions[1]->comment)->toBe('Looks reasonable.');

    inApproval($f['company'], function () use ($actions): void {
        expect(fn () => $actions[0]->update(['comment' => 'tampered']))->toThrow(RuntimeException::class);
    });
});

it('stops the flow when a request is rejected', function (): void {
    $f = approvalFixture();
    $engine = app(ApprovalEngine::class);

    $request = inApproval($f['company'], fn () => $engine->submit($f['order'], '5000', $f['requester']));
    $request = inApproval($f['company'], fn () => $engine->reject($request, $f['manager'], 'Budget exceeded.'));

    expect($request->status)->toBe('rejected');

    inApproval($f['company'], function () use ($engine, $request, $f): void {
        expect($engine->reasonAgainst($request, $f['manager']))->toBe('This request has already been rejected.');
    });
});

it('returns a request for revision without approving it', function (): void {
    $f = approvalFixture();
    $engine = app(ApprovalEngine::class);

    $request = inApproval($f['company'], fn () => $engine->submit($f['order'], '500', $f['requester']));
    $request = inApproval($f['company'], fn () => $engine->returnForRevision($request, $f['manager'], 'Add a quote.'));

    expect($request->status)->toBe('returned');
});

it('refuses to submit something with no configured flow', function (): void {
    $f = approvalFixture();

    inApproval($f['company'], function (): void {
        $supplier = Supplier::create(['code' => 'S2', 'name' => 'Other']);

        expect(fn () => app(ApprovalEngine::class)->submit($supplier, '100'))
            ->toThrow(ApprovalNotPermitted::class, 'No approval flow is configured for Supplier.');
    });
});
