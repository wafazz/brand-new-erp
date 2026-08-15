<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\Attribution;
use App\Models\Campaign;
use App\Models\CampaignCost;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Marketer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Access\RoleProvisioner;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

it('refuses every marketing screen to a role without marketing.view', function (): void {
    $f = routeFixture();

    $this->actingAs($f['alice'])->get('/dashboard')->assertOk();

    foreach (['/channels', '/campaigns', '/marketers'] as $path) {
        $this->actingAs($f['alice'])->get($path)->assertForbidden();
    }
});

it('lets a marketer look but not change', function (): void {
    $f = routeFixture();

    $marketerUser = person($f['company'], CompanyRole::Marketer, 'mkt@acme.test', $f['branch']);

    $this->withCompany($f['company'], function () use ($f, $marketerUser): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($marketerUser->can('marketing.view'))->toBeTrue('a marketer must see the campaigns they run')
            ->and($marketerUser->can('marketing.manage'))->toBeFalse('but must not create or edit them');
    });

    $this->actingAs($marketerUser)
        ->get('/channels')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Marketing/Channels/Index')->where('can.manage', false));

    $this->actingAs($marketerUser)
        ->post('/channels', ['code' => 'X', 'name' => 'Sneaky', 'kind' => 'marketing', 'is_active' => true])
        ->assertForbidden();

    expect($this->withCompany($f['company'], fn (): int => Channel::query()->count()))->toBe(0);
});

it('lets a marketing manager create a channel', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::MarketingManager, 'mm@acme.test', $f['branch']);

    $this->actingAs($manager)
        ->post('/channels', ['code' => 'FB', 'name' => 'Facebook', 'kind' => 'marketing', 'is_active' => true])
        ->assertRedirect()->assertSessionMissing('error');

    $this->withCompany($f['company'], function (): void {
        expect(Channel::query()->where('code', 'FB')->count())->toBe(1);
    });
});

it('refuses two channels sharing a code', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::MarketingManager, 'mm2@acme.test', $f['branch']);

    $this->actingAs($manager)->post('/channels', ['code' => 'FB', 'name' => 'Facebook', 'kind' => 'marketing', 'is_active' => true]);
    $this->actingAs($manager)->post('/channels', ['code' => 'FB', 'name' => 'Facebook again', 'kind' => 'marketing', 'is_active' => true])
        ->assertSessionHasErrors('code');

    $this->withCompany($f['company'], function (): void {
        expect(Channel::query()->count())->toBe(1);
    });
});

it('creates a campaign and records ad spend against it', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::MarketingManager, 'mm3@acme.test', $f['branch']);

    $channel = $this->withCompany($f['company'], fn (): Channel => Channel::create(['code' => 'FB', 'name' => 'Facebook']));

    $this->actingAs($manager)->post('/campaigns', [
        'code' => 'RAYA', 'name' => 'Raya push', 'status' => 'active',
        'channel_id' => $channel->getKey(), 'budget' => '5000',
    ])->assertRedirect()->assertSessionMissing('error');

    $campaign = $this->withCompany($f['company'], fn (): Campaign => Campaign::query()->firstOrFail());

    $this->actingAs($manager)->post("/campaigns/{$campaign->getKey()}/costs", [
        'platform' => 'Meta', 'amount' => '1200', 'spent_on' => now()->toDateString(), 'note' => 'Week one',
    ])->assertRedirect()->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($campaign): void {
        $cost = CampaignCost::query()->firstOrFail();

        expect((string) $cost->amount)->toBe('1200.0000')
            ->and($cost->period)->toBe(now()->format('Y-m'))
            ->and($cost->campaign_id)->toBe($campaign->getKey());
    });

    $this->actingAs($manager)
        ->get("/campaigns/{$campaign->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Marketing/Campaigns/Show')
            ->where('campaign.spend', '1200.0000')
            ->where('campaign.remaining', '3800.0000')
            ->has('costs', 1));
});

it('refuses ad spend from a role that may only look', function (): void {
    $f = routeFixture();

    $marketerUser = person($f['company'], CompanyRole::Marketer, 'mkt2@acme.test', $f['branch']);
    $campaign = $this->withCompany($f['company'], fn (): Campaign => Campaign::create(['code' => 'C1', 'name' => 'Campaign']));

    $this->actingAs($marketerUser)->get("/campaigns/{$campaign->getKey()}")->assertOk();

    $this->actingAs($marketerUser)
        ->post("/campaigns/{$campaign->getKey()}/costs", ['platform' => 'Meta', 'amount' => '100', 'spent_on' => now()->toDateString()])
        ->assertForbidden();

    $this->withCompany($f['company'], function (): void {
        expect(CampaignCost::query()->count())->toBe(0);
    });
});

it('refuses a campaign that ends before it starts', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::MarketingManager, 'mm4@acme.test', $f['branch']);

    $this->actingAs($manager)->post('/campaigns', [
        'code' => 'BAD', 'name' => 'Backwards', 'status' => 'active', 'budget' => '0',
        'starts_at' => now()->toDateString(), 'ends_at' => now()->subWeek()->toDateString(),
    ])->assertSessionHasErrors('ends_at');

    $this->withCompany($f['company'], function (): void {
        expect(Campaign::query()->count())->toBe(0);
    });
});

it('links a company member as a marketer and refuses linking them twice', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::MarketingManager, 'mm5@acme.test', $f['branch']);

    $this->actingAs($manager)
        ->post('/marketers', ['user_id' => $f['alice']->getKey(), 'code' => 'M-01'])
        ->assertRedirect()->assertSessionMissing('error');

    $this->withCompany($f['company'], function () use ($f): void {
        expect(Marketer::query()->where('user_id', $f['alice']->getKey())->count())->toBe(1);
    });

    $this->actingAs($manager)
        ->post('/marketers', ['user_id' => $f['alice']->getKey(), 'code' => 'M-02'])
        ->assertSessionHasErrors('user_id');

    $this->withCompany($f['company'], function (): void {
        expect(Marketer::query()->count())->toBe(1);
    });
});

it('refuses to make somebody outside the company a marketer', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::MarketingManager, 'mm6@acme.test', $f['branch']);

    $other = Company::create(['name' => 'Rival', 'slug' => 'rival-'.str()->random(6)]);
    app(RoleProvisioner::class)->provision($other);
    $outsider = person($other, CompanyRole::Marketer, 'outsider@rival.test');

    $this->actingAs($manager)
        ->post('/marketers', ['user_id' => $outsider->getKey(), 'code' => 'M-99'])
        ->assertSessionHasErrors('user_id');

    $this->withCompany($f['company'], function (): void {
        expect(Marketer::query()->count())->toBe(0);
    });
});

it('never shows another company campaign', function (): void {
    $f = routeFixture();

    $manager = person($f['company'], CompanyRole::MarketingManager, 'mm7@acme.test', $f['branch']);

    $other = Company::create(['name' => 'Rival', 'slug' => 'rival-'.str()->random(6)]);
    app(RoleProvisioner::class)->provision($other);

    $this->withCompany($other, fn () => Campaign::create(['code' => 'RIVAL', 'name' => 'Rival campaign']));
    $this->withCompany($f['company'], fn () => Campaign::create(['code' => 'OURS', 'name' => 'Our campaign']));

    $response = $this->actingAs($manager)->get('/campaigns');

    $response->assertInertia(fn (AssertableInertia $page) => $page->has('campaigns.data', 1));

    expect($response->getContent())->not->toContain('Rival campaign');
});

it('refuses the attribution report to a role without reports.view', function (): void {
    $f = routeFixture();

    $storekeeper = person($f['company'], CompanyRole::Storekeeper, 'store@acme.test', $f['branch']);

    $this->withCompany($f['company'], function () use ($f, $storekeeper): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($storekeeper->can('reports.view'))->toBeFalse('a storekeeper must not read revenue reports');
    });

    $this->actingAs($storekeeper)->get('/dashboard')->assertOk();
    $this->actingAs($storekeeper)->get('/attribution')->assertForbidden();
});

it('reports campaign spend against the revenue it earned', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Owner, 'reports.view', DataScope::Company);
    grant($f['company'], CompanyRole::Salesperson, 'orders.create', DataScope::Own);

    $campaign = $this->withCompany($f['company'], function (): Campaign {
        $channel = Channel::create(['code' => 'FB', 'name' => 'Facebook']);
        $campaign = Campaign::create(['code' => 'RAYA', 'name' => 'Raya push', 'channel_id' => $channel->getKey()]);

        CampaignCost::create([
            'campaign_id' => $campaign->getKey(),
            'period' => now()->format('Y-m'),
            'platform' => 'Meta',
            'amount' => '400',
            'spent_on' => now()->toDateString(),
        ]);

        return $campaign;
    });

    $variant = $this->withCompany($f['company'], function (): ProductVariant {
        $product = Product::create(['sku' => 'P1', 'name' => 'Product']);

        return ProductVariant::create([
            'product_id' => $product->getKey(), 'sku' => 'P1-A', 'name' => 'Default',
            'selling_price' => '500', 'is_default' => true,
        ]);
    });

    $this->actingAs($f['alice'])->post('/orders', [
        'branch_id' => $f['branch']->getKey(),
        'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '2']],
    ])->assertRedirect();

    $this->withCompany($f['company'], function () use ($campaign): void {
        Attribution::query()->firstOrFail()->forceFill(['campaign_id' => $campaign->getKey()])->save();
    });

    $this->actingAs($f['owner'])
        ->get('/attribution?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Marketing/Attribution/Index')
            ->where('spendVersusReturn.0.spend', '400.0000')
            ->where('spendVersusReturn.0.revenue', '1000.0000')
            ->where('spendVersusReturn.0.net', '600.0000')
            ->where('campaigns.0.revenue', '1000.0000'));
});

it('shows nothing outside the reporting window', function (): void {
    $f = routeFixture();

    grant($f['company'], CompanyRole::Owner, 'reports.view', DataScope::Company);
    grant($f['company'], CompanyRole::Salesperson, 'orders.create', DataScope::Own);

    $variant = $this->withCompany($f['company'], function (): ProductVariant {
        $product = Product::create(['sku' => 'P2', 'name' => 'Product']);

        return ProductVariant::create([
            'product_id' => $product->getKey(), 'sku' => 'P2-A', 'name' => 'Default',
            'selling_price' => '500', 'is_default' => true,
        ]);
    });

    $this->actingAs($f['alice'])->post('/orders', [
        'branch_id' => $f['branch']->getKey(),
        'lines' => [['variant_id' => $variant->getKey(), 'quantity' => '2']],
    ])->assertRedirect();

    $this->actingAs($f['owner'])
        ->get('/attribution?from='.now()->subYear()->toDateString().'&to='.now()->subYear()->addDay()->toDateString())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('salespeople', []));
});
