<?php

declare(strict_types=1);

use App\Domain\Attribution\AttributionReport;
use App\Domain\Attribution\AttributionService;
use App\Domain\Orders\OrderService;
use App\Models\AttributionTouch;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\CampaignCost;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Marketer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesTarget;
use App\Models\SalesTeam;
use App\Models\SalesTeamMember;
use App\Models\User;
use App\Support\CompanyContext;

function attributionWorld(): array
{
    $company = Company::create(['name' => 'Acme Trading', 'slug' => 'acme-'.str()->random(6)]);

    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($company): array {
        $branch = Branch::create(['code' => 'HQ', 'name' => 'Head Office', 'is_default' => true]);

        $facebook = Channel::create(['code' => 'FB', 'name' => 'Facebook', 'kind' => 'marketing']);
        Channel::create(['code' => 'WALKIN', 'name' => 'Walk-in', 'kind' => 'direct']);

        $aliUser = User::create(['name' => 'Ali', 'email' => 'ali'.str()->random(4).'@a.test', 'password' => 'secret-password']);
        $ali = Marketer::create(['user_id' => $aliUser->getKey(), 'code' => 'MK-ALI']);

        $campaign = Campaign::create([
            'channel_id' => $facebook->getKey(),
            'marketer_id' => $ali->getKey(),
            'code' => 'RAYA2026',
            'name' => 'Raya 2026',
            'budget' => '1000',
        ]);

        CampaignCost::create([
            'campaign_id' => $campaign->getKey(),
            'period' => '2026-08',
            'platform' => 'facebook',
            'amount' => '500',
            'spent_on' => now()->toDateString(),
        ]);

        $siti = User::create(['name' => 'Siti', 'email' => 'siti'.str()->random(4).'@a.test', 'password' => 'secret-password']);
        $team = SalesTeam::create(['code' => 'NORTH', 'name' => 'North Team', 'branch_id' => $branch->getKey()]);
        SalesTeamMember::create(['sales_team_id' => $team->getKey(), 'user_id' => $siti->getKey(), 'role_in_team' => 'executive']);
        SalesTarget::create(['sales_team_id' => $team->getKey(), 'period' => '2026-08', 'target_amount' => '800']);

        $product = Product::create(['sku' => 'WIDGET', 'name' => 'Widget']);
        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => 'WIDGET-STD',
            'selling_price' => '500.0000',
            'cost_price' => '300.0000',
        ]);

        $customer = Customer::create(['code' => 'C1', 'name' => 'Aminah']);

        $lead = Lead::create([
            'reference' => 'LD-0001',
            'name' => 'Aminah',
            'branch_id' => $branch->getKey(),
            'assigned_to' => $siti->getKey(),
            'converted_customer_id' => $customer->getKey(),
            'captured_at' => now(),
        ]);

        app(AttributionService::class)->recordTouch($lead, [
            'channel_id' => $facebook->getKey(),
            'campaign_id' => $campaign->getKey(),
            'marketer_id' => $ali->getKey(),
            'source' => 'facebook',
            'medium' => 'paid_social',
        ]);

        app(AttributionService::class)->resolveFor($customer);

        $order = app(OrderService::class)->create([
            'customer_id' => $customer->getKey(),
            'branch_id' => $branch->getKey(),
            'customer_name' => 'Aminah',
            'lead_id' => $lead->getKey(),
            'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '2']],
        ], $siti);

        return compact('company', 'branch', 'facebook', 'ali', 'aliUser', 'campaign', 'siti', 'team', 'variant', 'customer', 'lead', 'order');
    });
}

function ask(Company $company, callable $callback)
{
    return app(CompanyContext::class)->runAs($company->getKey(), $callback);
}

it('Q1 answers where the customer came from', function (): void {
    $w = attributionWorld();

    $answer = ask($w['company'], fn () => app(AttributionReport::class)->whereDidThisCustomerComeFrom($w['customer']));

    expect($answer['channel'])->toBe('Facebook')
        ->and($answer['campaign'])->toBe('Raya 2026')
        ->and($answer['attributed'])->toBeTrue();
});

it('Q2 answers where the order came from', function (): void {
    $w = attributionWorld();

    $answer = ask($w['company'], fn () => app(AttributionReport::class)->whereDidThisOrderComeFrom($w['order']));

    expect($answer['channel'])->toBe('Facebook')
        ->and($answer['campaign'])->toBe('Raya 2026')
        ->and($answer['lead_reference'])->toBe('LD-0001')
        ->and($answer['source'])->toBe('facebook');
});

it('Q3 answers who generated the lead', function (): void {
    $w = attributionWorld();

    $answer = ask($w['company'], fn () => app(AttributionReport::class)->whoGeneratedTheLead($w['order']));

    expect($answer['name'])->toBe('Ali')->and($answer['code'])->toBe('MK-ALI');
});

it('Q4 answers who closed the order', function (): void {
    $w = attributionWorld();

    $answer = ask($w['company'], fn () => app(AttributionReport::class)->whoClosedTheOrder($w['order']));

    expect($answer['name'])->toBe('Siti')->and($answer['sales_team'])->toBe('North Team');
});

it('Q5 answers which campaign generated revenue', function (): void {
    $w = attributionWorld();

    $rows = ask($w['company'], fn () => app(AttributionReport::class)->whichCampaignGeneratedRevenue());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->code)->toBe('RAYA2026')
        ->and((string) $rows[0]->revenue)->toBe('1000.0000')
        ->and((int) $rows[0]->orders)->toBe(1);
});

it('Q6 answers which marketer generated revenue', function (): void {
    $w = attributionWorld();

    $rows = ask($w['company'], fn () => app(AttributionReport::class)->whichMarketerGeneratedRevenue());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->name)->toBe('Ali')
        ->and((string) $rows[0]->revenue)->toBe('1000.0000');
});

it('Q7 answers which salesperson generated revenue', function (): void {
    $w = attributionWorld();

    $rows = ask($w['company'], fn () => app(AttributionReport::class)->whichSalespersonGeneratedRevenue());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->name)->toBe('Siti')
        ->and((string) $rows[0]->revenue)->toBe('1000.0000');
});

it('Q8 answers which channel converts best', function (): void {
    $w = attributionWorld();

    $rows = ask($w['company'], fn () => app(AttributionReport::class)->whichChannelConvertsBest());

    $facebook = $rows->firstWhere('code', 'FB');
    $walkin = $rows->firstWhere('code', 'WALKIN');

    expect((int) $facebook->leads)->toBe(1)
        ->and((int) $facebook->orders)->toBe(1)
        ->and((string) $facebook->revenue)->toBe('1000.0000')
        ->and((int) $walkin->orders)->toBe(0);
});

it('Q9 answers what the campaign cost versus what it returned', function (): void {
    $w = attributionWorld();

    $rows = ask($w['company'], fn () => app(AttributionReport::class)->whatDidThisCampaignCostVersusReturn());

    expect((string) $rows[0]->spend)->toBe('500.0000')
        ->and((string) $rows[0]->revenue)->toBe('1000.0000')
        ->and((string) $rows[0]->net)->toBe('500.0000')
        ->and((float) $rows[0]->roas)->toBe(2.0);
});

it('Q10 answers the cost per lead by campaign', function (): void {
    $w = attributionWorld();

    $rows = ask($w['company'], fn () => app(AttributionReport::class)->whatIsTheCostPerLeadByCampaign());

    expect((int) $rows[0]->leads)->toBe(1)
        ->and((string) $rows[0]->spend)->toBe('500.0000')
        ->and((float) $rows[0]->cost_per_lead)->toBe(500.0);
});

it('Q11 answers which team hit target', function (): void {
    $w = attributionWorld();

    $rows = ask($w['company'], fn () => app(AttributionReport::class)->whichTeamHitTarget('2026-08'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->code)->toBe('NORTH')
        ->and((string) $rows[0]->achieved)->toBe('1000.0000')
        ->and((bool) $rows[0]->hit)->toBeTrue()
        ->and((float) $rows[0]->attainment_percent)->toBe(125.0);
});

it('Q12 answers which branch generated what', function (): void {
    $w = attributionWorld();

    $rows = ask($w['company'], fn () => app(AttributionReport::class)->whichBranchGeneratedWhat());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->code)->toBe('HQ')
        ->and((string) $rows[0]->revenue)->toBe('1000.0000');
});

it('gives the first touch the credit when two marketers touch one lead', function (): void {
    $w = attributionWorld();

    ask($w['company'], function () use ($w): void {
        $second = Marketer::create([
            'user_id' => User::create(['name' => 'Bakar', 'email' => 'bakar'.str()->random(4).'@a.test', 'password' => 'secret-password'])->getKey(),
            'code' => 'MK-BAKAR',
        ]);

        $lead = Lead::create(['reference' => 'LD-0002', 'name' => 'Two touches', 'captured_at' => now()]);
        $service = app(AttributionService::class);

        $service->recordTouch($lead, ['marketer_id' => $w['ali']->getKey(), 'source' => 'facebook'], now()->subDays(3));
        $service->recordTouch($lead, ['marketer_id' => $second->getKey(), 'source' => 'tiktok'], now());

        $attribution = $service->resolveFor($lead);

        expect($attribution->marketer_id)->toBe($w['ali']->getKey())
            ->and($attribution->source)->toBe('facebook')
            ->and($service->lastTouchFor($lead)->marketer_id)->toBe($second->getKey());
    });
});

it('keeps every touch even though only the first is credited', function (): void {
    $w = attributionWorld();

    ask($w['company'], function (): void {
        $lead = Lead::create(['reference' => 'LD-0003', 'name' => 'Many touches', 'captured_at' => now()]);
        $service = app(AttributionService::class);

        $service->recordTouch($lead, ['source' => 'facebook']);
        $service->recordTouch($lead, ['source' => 'tiktok']);
        $service->recordTouch($lead, ['source' => 'whatsapp']);

        expect(AttributionTouch::query()->where('subject_id', $lead->getKey())->count())->toBe(3);
    });
});

it('refuses to re-attribute an order once money can be paid on it', function (): void {
    $w = attributionWorld();

    ask($w['company'], function () use ($w): void {
        expect(fn () => app(AttributionService::class)->freezeOntoOrder($w['order']))
            ->toThrow(RuntimeException::class, 'Attribution is frozen once an order exists');
    });
});

it('treats an unattributed order as a valid first-class state', function (): void {
    $w = attributionWorld();

    $answer = ask($w['company'], function () use ($w): ?array {
        $order = app(OrderService::class)->create([
            'customer_name' => 'Walk-in',
            'branch_id' => $w['branch']->getKey(),
            'lines' => [['variant_id' => $w['variant']->getKey(), 'quantity' => '1']],
        ]);

        return app(AttributionReport::class)->whereDidThisOrderComeFrom($order);
    });

    expect($answer)->not->toBeNull()
        ->and($answer['attributed'])->toBeFalse()
        ->and($answer['campaign'])->toBeNull()
        ->and($answer['marketer'])->toBeNull();
});

it('never counts another company\'s revenue in any attribution report', function (): void {
    $mine = attributionWorld();
    $theirs = attributionWorld();

    $rows = ask($mine['company'], fn () => app(AttributionReport::class)->whichCampaignGeneratedRevenue());
    $roas = ask($mine['company'], fn () => app(AttributionReport::class)->whatDidThisCampaignCostVersusReturn());
    $teams = ask($mine['company'], fn () => app(AttributionReport::class)->whichTeamHitTarget('2026-08'));

    expect($rows)->toHaveCount(1)
        ->and((string) $rows[0]->revenue)->toBe('1000.0000')
        ->and($roas)->toHaveCount(1)
        ->and((string) $roas[0]->spend)->toBe('500.0000')
        ->and($teams)->toHaveCount(1);

    expect($theirs['company']->getKey())->not->toBe($mine['company']->getKey());
});

it('converts the lead when the order is created from it', function (): void {
    $w = attributionWorld();

    $lead = $w['lead']->refresh();

    expect($lead->status)->toBe('won')
        ->and($lead->converted_order_id)->toBe($w['order']->getKey())
        ->and($lead->converted_at)->not->toBeNull();
});
