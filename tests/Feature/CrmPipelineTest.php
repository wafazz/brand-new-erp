<?php

declare(strict_types=1);

use App\Domain\Crm\PipelineRefused;
use App\Domain\Crm\PipelineService;
use App\Enums\CompanyRole;
use App\Enums\DataScope;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\PipelineStage;
use App\Models\SalesActivity;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

/** @return array<string, mixed> */
function pipelineFixture(): array
{
    $f = routeFixture();

    grant($f['company'], CompanyRole::Salesperson, 'leads.view', DataScope::Own);
    grant($f['company'], CompanyRole::Salesperson, 'leads.update', DataScope::Own);
    grant($f['company'], CompanyRole::Owner, 'leads.view', DataScope::Company);

    $extra = test()->withCompany($f['company'], function () use ($f): array {
        $new = PipelineStage::create(['code' => 'NEW', 'name' => 'New', 'probability' => 10, 'sort' => 0]);
        $quoted = PipelineStage::create(['code' => 'QUOTE', 'name' => 'Quoted', 'probability' => 50, 'sort' => 10]);
        $won = PipelineStage::create(['code' => 'WON', 'name' => 'Won', 'probability' => 100, 'sort' => 20, 'is_won' => true]);

        $lead = Lead::create([
            'reference' => 'LD-1',
            'name' => 'Hopeful Buyer',
            'assigned_to' => $f['alice']->getKey(),
            'pipeline_stage_id' => $new->getKey(),
            'estimated_value' => '1000',
            'captured_at' => now(),
        ]);

        return compact('new', 'quoted', 'won', 'lead');
    });

    return [...$f, ...$extra];
}

function crm(): PipelineService
{
    return app(PipelineService::class);
}

it('writes both a timeline entry and a follow-up when contact is logged', function (): void {
    $f = pipelineFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        crm()->logContact($f['lead'], [
            'type' => 'call',
            'summary' => 'Talked through the quote',
            'follow_up_at' => now()->addDays(3),
        ], $f['alice']);

        $activity = SalesActivity::query()->firstOrFail();
        $timeline = LeadActivity::query()->firstOrFail();

        expect($activity->lead_id)->toBe($f['lead']->getKey())
            ->and($activity->follow_up_at)->not->toBeNull()
            ->and($timeline->type)->toBe('call')
            ->and($timeline->summary)->toBe('Talked through the quote', 'the panel that was dead now has something in it');
    });
});

it('refuses a contact with no summary', function (): void {
    $f = pipelineFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        expect(fn () => crm()->logContact($f['lead'], ['type' => 'call', 'summary' => '   '], $f['alice']))
            ->toThrow(PipelineRefused::class, 'Say what happened');

        expect(SalesActivity::query()->count())->toBe(0)
            ->and(LeadActivity::query()->count())->toBe(0);
    });
});

it('refuses a follow-up in the past', function (): void {
    $f = pipelineFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        expect(fn () => crm()->logContact($f['lead'], [
            'type' => 'call', 'summary' => 'Called', 'follow_up_at' => now()->subWeek(),
        ], $f['alice']))->toThrow(PipelineRefused::class, 'has to be in the future');

        expect(SalesActivity::query()->count())->toBe(0);
    });
});

it('moves a lead between stages and records why', function (): void {
    $f = pipelineFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $moved = crm()->moveToStage($f['lead'], $f['quoted'], $f['alice'], 'Sent the numbers');

        expect($moved->pipeline_stage_id)->toBe($f['quoted']->getKey())
            ->and($moved->status)->toBe('contacted', 'a new lead that has moved has clearly been contacted');

        $entry = LeadActivity::query()->where('type', 'status_changed')->firstOrFail();

        expect($entry->summary)->toContain('New')->toContain('Quoted')->toContain('Sent the numbers');
    });
});

it('sets the lead won when it reaches a winning stage', function (): void {
    $f = pipelineFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $moved = crm()->moveToStage($f['lead'], $f['won'], $f['alice']);

        expect($moved->status)->toBe('won', 'the board and the lead list must not disagree');
    });
});

it('refuses to move a lead that has already been converted', function (): void {
    $f = pipelineFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        $order = Order::create(['order_number' => 'SO-1', 'customer_name' => 'Buyer', 'placed_at' => now()]);

        $f['lead']->forceFill(['converted_order_id' => $order->getKey()])->save();

        expect(fn () => crm()->moveToStage($f['lead']->refresh(), $f['quoted'], $f['alice']))
            ->toThrow(PipelineRefused::class, 'already been converted');
    });
});

it('weights the board by each stage probability', function (): void {
    $f = pipelineFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        Lead::create([
            'reference' => 'LD-2', 'name' => 'Second', 'assigned_to' => $f['alice']->getKey(),
            'pipeline_stage_id' => $f['quoted']->getKey(), 'estimated_value' => '2000', 'captured_at' => now(),
        ]);

        $board = collect(crm()->board($f['owner']));

        $new = $board->firstWhere('name', 'New');
        $quoted = $board->firstWhere('name', 'Quoted');

        expect($new['value'])->toBe('1000.0000')
            ->and($new['weighted'])->toBe('100.0000', '10% of 1000')
            ->and($quoted['value'])->toBe('2000.0000')
            ->and($quoted['weighted'])->toBe('1000.0000', '50% of 2000');
    });
});

it('shows a salesperson only their own leads on the board', function (): void {
    $f = pipelineFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        Lead::create([
            'reference' => 'LD-BOB', 'name' => 'Bob Lead', 'assigned_to' => $f['bob']->getKey(),
            'pipeline_stage_id' => $f['new']->getKey(), 'estimated_value' => '5000', 'captured_at' => now(),
        ]);

        $mine = collect(crm()->board($f['alice']))->firstWhere('name', 'New');
        $all = collect(crm()->board($f['owner']))->firstWhere('name', 'New');

        expect($mine['leads'])->toHaveCount(1)
            ->and($mine['value'])->toBe('1000.0000')
            ->and($all['leads'])->toHaveCount(2);
    });
});

it('keeps a won lead off the follow-up list', function (): void {
    $f = pipelineFixture();

    $this->withCompany($f['company'], function () use ($f): void {
        crm()->logContact($f['lead'], [
            'type' => 'call', 'summary' => 'Chasing', 'follow_up_at' => now()->addDay(),
        ], $f['alice']);

        expect(crm()->followUpsDue($f['owner'], now()->addWeek()))->toHaveCount(1);

        crm()->moveToStage($f['lead']->refresh(), $f['won'], $f['alice']);

        expect(crm()->followUpsDue($f['owner'], now()->addWeek()))
            ->toHaveCount(0, 'nobody should be chased about a deal already won');
    });
});

it('refuses the stages screen to a salesperson and allows it to a manager', function (): void {
    $f = pipelineFixture();

    $manager = person($f['company'], CompanyRole::SalesManager, 'boss@acme.test', $f['branch']);

    $this->withCompany($f['company'], function () use ($f, $manager): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($f['company']->getKey());

        expect($f['alice']->can('leads.view'))->toBeTrue('a salesperson works the pipeline')
            ->and($f['alice']->can('leads.configure'))->toBeFalse('but does not shape it')
            ->and($manager->can('leads.configure'))->toBeTrue();
    });

    $this->actingAs($f['alice'])->get('/pipeline')->assertOk();
    $this->actingAs($f['alice'])->get('/pipeline/stages')->assertForbidden();

    $this->actingAs($f['alice'])
        ->post('/pipeline/stages', ['code' => 'X', 'name' => 'Sneaky', 'probability' => 100, 'sort' => 0, 'is_won' => true, 'is_lost' => false])
        ->assertForbidden();

    $this->actingAs($manager)
        ->get('/pipeline/stages')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Crm/Stages')->has('stages', 3));
});

it('refuses a stage that is both won and lost', function (): void {
    $f = pipelineFixture();

    $this->actingAs($f['owner'])
        ->post('/pipeline/stages', ['code' => 'ODD', 'name' => 'Both', 'probability' => 50, 'sort' => 5, 'is_won' => true, 'is_lost' => true])
        ->assertStatus(422);

    $this->withCompany($f['company'], function (): void {
        expect(PipelineStage::query()->count())->toBe(3);
    });
});

it('logs contact over HTTP and shows it on the lead', function (): void {
    $f = pipelineFixture();

    $this->actingAs($f['alice'])->post("/leads/{$f['lead']->getKey()}/contacts", [
        'type' => 'whatsapp',
        'summary' => 'Sent the brochure',
        'follow_up_at' => now()->addDays(2)->toDateString(),
    ])->assertRedirect()->assertSessionMissing('error');

    $this->actingAs($f['alice'])
        ->get("/leads/{$f['lead']->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('activities', 1)
            ->where('activities.0.summary', 'Sent the brochure')
            ->has('followUps', 1));
});

it('refuses to log contact on a lead outside the data scope', function (): void {
    $f = pipelineFixture();

    $bobs = $this->withCompany($f['company'], fn (): Lead => Lead::create([
        'reference' => 'LD-BOB', 'name' => 'Bob Lead', 'assigned_to' => $f['bob']->getKey(), 'captured_at' => now(),
    ]));

    $this->actingAs($f['alice'])
        ->post("/leads/{$bobs->getKey()}/contacts", ['type' => 'call', 'summary' => 'Poaching'])
        ->assertForbidden();

    $this->withCompany($f['company'], function (): void {
        expect(SalesActivity::query()->count())->toBe(0);
    });
});
