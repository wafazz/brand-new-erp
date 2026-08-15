<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Attribution;
use App\Models\Campaign;
use App\Models\Channel;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChannelController extends Controller
{
    private const KINDS = ['marketing', 'sales', 'referral', 'organic', 'partner'];

    public function __construct(private readonly AuditRecorder $recorder) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('marketing.view'), 403);

        $channels = Channel::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Channel $channel): array => [
                'id' => $channel->getKey(),
                'code' => $channel->code,
                'name' => $channel->name,
                'kind' => $channel->kind,
                'is_active' => $channel->is_active,
                'campaigns' => Campaign::query()->where('channel_id', $channel->getKey())->count(),
                'attributions' => Attribution::query()->where('channel_id', $channel->getKey())->count(),
            ])
            ->all();

        return Inertia::render('Marketing/Channels/Index', [
            'channels' => $channels,
            'kinds' => array_map(static fn (string $k): array => ['value' => $k, 'label' => ucfirst($k)], self::KINDS),
            'can' => ['manage' => $request->user()->can('marketing.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.manage'), 403);

        $channel = Channel::create($this->validated($request, null));

        $this->recorder->record('created', 'marketing', $channel, $request->user());

        return back()->with('success', "Channel {$channel->name} added.");
    }

    public function update(Request $request, Channel $channel): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.manage'), 403);

        $channel->update($this->validated($request, $channel));

        $this->recorder->record('updated', 'marketing', $channel->refresh(), $request->user());

        return back()->with('success', 'Channel updated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Channel $channel): array
    {
        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        return $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('channels', 'code')
                ->where('company_id', $companyId)->ignore($channel?->getKey())],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(self::KINDS)],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
