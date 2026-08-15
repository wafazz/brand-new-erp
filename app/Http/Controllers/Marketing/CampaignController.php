<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignCost;
use App\Models\Channel;
use App\Models\Marketer;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    private const STATUSES = ['draft', 'active', 'paused', 'ended'];

    public function __construct(private readonly AuditRecorder $recorder) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('marketing.view'), 403);

        $status = (string) $request->query('status', '');

        $campaigns = Campaign::query()
            ->when(in_array($status, self::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->with(['channel:id,name', 'marketer:id,code,user_id', 'marketer.user:id,name'])
            ->orderByDesc('starts_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Campaign $campaign): array => [
                'id' => $campaign->getKey(),
                'code' => $campaign->code,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'channel' => $campaign->channel->name ?? null,
                'marketer' => $campaign->marketer?->user->name ?? null,
                'budget' => (string) $campaign->budget,
                'spend' => $this->spendFor($campaign),
                'starts_at' => $campaign->starts_at?->toDateString(),
                'ends_at' => $campaign->ends_at?->toDateString(),
            ]);

        return Inertia::render('Marketing/Campaigns/Index', [
            'campaigns' => $campaigns,
            'filters' => ['status' => $status],
            ...$this->references(),
            'can' => ['manage' => $request->user()->can('marketing.manage')],
        ]);
    }

    public function show(Request $request, Campaign $campaign): Response
    {
        abort_unless($request->user()->can('marketing.view'), 403);

        $campaign->loadMissing(['channel:id,name', 'marketer:id,code,user_id', 'marketer.user:id,name']);

        $spend = $this->spendFor($campaign);

        return Inertia::render('Marketing/Campaigns/Show', [
            'campaign' => [
                'id' => $campaign->getKey(),
                'code' => $campaign->code,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'channel_id' => $campaign->channel_id,
                'channel' => $campaign->channel->name ?? null,
                'marketer_id' => $campaign->marketer_id,
                'marketer' => $campaign->marketer?->user->name ?? null,
                'budget' => (string) $campaign->budget,
                'spend' => $spend,
                'remaining' => bcsub((string) $campaign->budget, $spend, 4),
                'starts_at' => $campaign->starts_at?->toDateString(),
                'ends_at' => $campaign->ends_at?->toDateString(),
            ],
            'costs' => CampaignCost::query()
                ->where('campaign_id', $campaign->getKey())
                ->with('recorder:id,name')
                ->orderByDesc('spent_on')
                ->get()
                ->map(fn (CampaignCost $cost): array => [
                    'id' => $cost->getKey(),
                    'period' => $cost->period,
                    'platform' => $cost->platform,
                    'amount' => (string) $cost->amount,
                    'spent_on' => $cost->spent_on?->toDateString(),
                    'note' => $cost->note,
                    'recorded_by' => $cost->recorder->name ?? 'System',
                ])->all(),
            ...$this->references(),
            'can' => ['manage' => $request->user()->can('marketing.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.manage'), 403);

        $campaign = Campaign::create($this->validated($request, null));

        $this->recorder->record('created', 'marketing', $campaign, $request->user());

        return redirect("/campaigns/{$campaign->getKey()}")->with('success', "Campaign {$campaign->name} created.");
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.manage'), 403);

        $campaign->update($this->validated($request, $campaign));

        $this->recorder->record('updated', 'marketing', $campaign->refresh(), $request->user());

        return back()->with('success', 'Campaign updated.');
    }

    public function storeCost(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.manage'), 403);

        $data = $request->validate([
            'platform' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'spent_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $cost = DB::transaction(fn (): CampaignCost => CampaignCost::create([
            'campaign_id' => $campaign->getKey(),
            'recorded_by' => $request->user()->getKey(),
            'period' => now()->parse($data['spent_on'])->format('Y-m'),
            'platform' => $data['platform'],
            'amount' => (string) $data['amount'],
            'spent_on' => $data['spent_on'],
            'note' => $data['note'] ?? null,
        ]));

        $this->recorder->record('spend_recorded', 'marketing', $cost, $request->user());

        return back()->with('success', 'Ad spend recorded. Margin-based commission now nets it off.');
    }

    private function spendFor(Campaign $campaign): string
    {
        $total = CampaignCost::query()->where('campaign_id', $campaign->getKey())->sum('amount');

        return Money::of((string) ($total ?: '0'))->toDecimal();
    }

    /** @return array<string, mixed> */
    private function references(): array
    {
        return [
            'channels' => Channel::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Channel $c): array => ['value' => $c->getKey(), 'label' => $c->name])->all(),
            'marketers' => Marketer::query()->where('status', 'active')->with('user:id,name')->orderBy('code')->get()
                ->map(fn (Marketer $m): array => ['value' => $m->getKey(), 'label' => $m->code.' — '.($m->user->name ?? 'Unlinked')])->all(),
            'statuses' => array_map(static fn (string $s): array => ['value' => $s, 'label' => ucfirst($s)], self::STATUSES),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Campaign $campaign): array
    {
        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        return $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('campaigns', 'code')
                ->where('company_id', $companyId)->ignore($campaign?->getKey())],
            'name' => ['required', 'string', 'max:160'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'channel_id' => ['nullable', 'string', Rule::exists('channels', 'id')->where('company_id', $companyId)],
            'marketer_id' => ['nullable', 'string', Rule::exists('marketers', 'id')->where('company_id', $companyId)],
            'budget' => ['required', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }
}
