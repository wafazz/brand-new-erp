<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CompanyUser;
use App\Models\Marketer;
use App\Models\MarketingTeam;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MarketerController extends Controller
{
    private const STATUSES = ['active', 'inactive'];

    public function __construct(private readonly AuditRecorder $recorder) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('marketing.view'), 403);

        $marketers = Marketer::query()
            ->with(['user:id,name,email', 'team:id,name'])
            ->orderBy('code')
            ->get()
            ->map(fn (Marketer $marketer): array => [
                'id' => $marketer->getKey(),
                'code' => $marketer->code,
                'name' => $marketer->user->name ?? 'Unlinked',
                'email' => $marketer->user->email ?? null,
                'team' => $marketer->team->name ?? null,
                'status' => $marketer->status,
                'campaigns' => Campaign::query()->where('marketer_id', $marketer->getKey())->count(),
            ])
            ->all();

        $linked = Marketer::query()->pluck('user_id')->all();

        return Inertia::render('Marketing/Marketers/Index', [
            'marketers' => $marketers,
            'candidates' => User::query()
                ->whereIn('id', CompanyUser::query()->where('is_active', true)->pluck('user_id'))
                ->whereNotIn('id', $linked)
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => ['value' => $user->getKey(), 'label' => $user->name.' — '.$user->email])
                ->all(),
            'teams' => MarketingTeam::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (MarketingTeam $team): array => ['value' => $team->getKey(), 'label' => $team->name])->all(),
            'statuses' => array_map(static fn (string $s): array => ['value' => $s, 'label' => ucfirst($s)], self::STATUSES),
            'can' => ['manage' => $request->user()->can('marketing.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.manage'), 403);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'user_id' => [
                'required', 'string',
                Rule::exists('company_users', 'user_id')->where('company_id', $companyId),
                Rule::unique('marketers', 'user_id')->where('company_id', $companyId),
            ],
            'code' => ['required', 'string', 'max:40', Rule::unique('marketers', 'code')->where('company_id', $companyId)],
            'marketing_team_id' => ['nullable', 'string', Rule::exists('marketing_teams', 'id')->where('company_id', $companyId)],
        ]);

        $marketer = Marketer::create([...$data, 'status' => 'active', 'joined_at' => now()]);

        $this->recorder->record('created', 'marketing', $marketer, $request->user());

        return back()->with('success', 'Marketer added. Campaigns and referral codes can now be attributed to them.');
    }

    public function update(Request $request, Marketer $marketer): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.manage'), 403);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'marketing_team_id' => ['nullable', 'string', Rule::exists('marketing_teams', 'id')->where('company_id', $companyId)],
        ]);

        $marketer->update($data);

        $this->recorder->record('updated', 'marketing', $marketer->refresh(), $request->user());

        return back()->with('success', 'Marketer updated.');
    }
}
