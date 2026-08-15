<?php

declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Domain\Commission\CommissionConfigurationRefused;
use App\Domain\Commission\CommissionConfigurator;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\CommissionPlan;
use App\Models\CommissionRule;
use App\Models\CommissionRuleVersion;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CommissionPlanController extends Controller
{
    public function __construct(
        private readonly CommissionConfigurator $configurator,
        private readonly AuditRecorder $recorder,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('commissions.configure'), 403);

        $plans = CommissionPlan::query()
            ->withCount(['rules' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(fn (CommissionPlan $plan): array => [
                'id' => $plan->getKey(),
                'code' => $plan->code,
                'name' => $plan->name,
                'strategy' => $plan->strategy,
                'recipient_role' => $plan->recipient_role,
                'ad_spend_allocation' => $plan->ad_spend_allocation,
                'is_active' => $plan->is_active,
                'rules_count' => (int) $plan->getAttribute('rules_count'),
                'accruals' => Commission::query()->where('commission_plan_id', $plan->getKey())->count(),
            ])
            ->all();

        return Inertia::render('Finance/Plans/Index', [
            'plans' => $plans,
            'options' => $this->options(),
        ]);
    }

    public function show(Request $request, CommissionPlan $plan): Response
    {
        abort_unless($request->user()->can('commissions.configure'), 403);

        $plan->loadMissing(['rules.versions']);

        return Inertia::render('Finance/Plans/Show', [
            'plan' => [
                'id' => $plan->getKey(),
                'code' => $plan->code,
                'name' => $plan->name,
                'strategy' => $plan->strategy,
                'recipient_role' => $plan->recipient_role,
                'ad_spend_allocation' => $plan->ad_spend_allocation,
                'is_active' => $plan->is_active,
                'has_accruals' => $this->configurator->hasAccruals($plan),
                'expected_rate_type' => str_starts_with((string) $plan->strategy, 'percentage_of') ? 'percent' : 'fixed',
            ],
            'rules' => $plan->rules->map(function (CommissionRule $rule): array {
                $inForce = $this->configurator->versionInForce($rule);

                return [
                    'id' => $rule->getKey(),
                    'code' => $rule->code,
                    'name' => $rule->name,
                    'is_active' => $rule->is_active,
                    'versions' => $rule->versions->sortByDesc('version')->values()
                        ->map(fn (CommissionRuleVersion $version): array => [
                            'id' => $version->getKey(),
                            'version' => $version->version,
                            'rate_type' => $version->rate_type,
                            'rate_value' => (string) $version->rate_value,
                            'valid_from' => $version->valid_from?->toDayDateTimeString(),
                            'valid_to' => $version->valid_to?->toDayDateTimeString(),
                            'state' => match (true) {
                                $inForce !== null && $version->getKey() === $inForce->getKey() => 'in force',
                                $version->valid_from !== null && $version->valid_from->isFuture() => 'scheduled',
                                default => 'superseded',
                            },
                        ])->all(),
                ];
            })->all(),
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('commissions.configure'), 403);

        $data = $this->validatedPlan($request, null);

        try {
            $plan = $this->configurator->createPlan($data);
        } catch (CommissionConfigurationRefused $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $this->recorder->record('created', 'commissions', $plan, $request->user());

        return redirect("/commission-plans/{$plan->getKey()}")->with('success', "Plan {$plan->name} created.");
    }

    public function update(Request $request, CommissionPlan $plan): RedirectResponse
    {
        abort_unless($request->user()->can('commissions.configure'), 403);

        $data = $this->validatedPlan($request, $plan);

        try {
            $this->configurator->updatePlan($plan, $data);
        } catch (CommissionConfigurationRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('updated', 'commissions', $plan->refresh(), $request->user());

        return back()->with('success', 'Plan updated.');
    }

    public function storeRule(Request $request, CommissionPlan $plan): RedirectResponse
    {
        abort_unless($request->user()->can('commissions.configure'), 403);

        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('commission_rules', 'code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:160'],
        ]);

        $rule = $this->configurator->createRule($plan, $data);

        $this->recorder->record('rule_created', 'commissions', $rule, $request->user());

        return back()->with('success', "Rule {$rule->name} added. It pays nothing until you publish a rate.");
    }

    public function storeVersion(Request $request, CommissionRule $rule): RedirectResponse
    {
        abort_unless($request->user()->can('commissions.configure'), 403);

        $data = $request->validate([
            'rate_type' => ['required', Rule::in(CommissionConfigurator::RATE_TYPES)],
            'rate_value' => ['required', 'numeric'],
            'valid_from' => ['required', 'date'],
        ]);

        try {
            $version = $this->configurator->publishVersion($rule, [
                'rate_type' => $data['rate_type'],
                'rate_value' => (string) $data['rate_value'],
                'valid_from' => now()->parse($data['valid_from']),
            ], $request->user());
        } catch (CommissionConfigurationRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->recorder->record('rate_published', 'commissions', $version, $request->user());

        return back()->with('success', "Version {$version->version} published. Earlier accruals keep the rate they were calculated on.");
    }

    public function updateRule(Request $request, CommissionRule $rule): RedirectResponse
    {
        abort_unless($request->user()->can('commissions.configure'), 403);

        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $rule->update($data);

        $this->recorder->record('rule_updated', 'commissions', $rule->refresh(), $request->user());

        return back()->with('success', $data['is_active'] ? 'Rule active.' : 'Rule stopped. Nothing new will accrue against it.');
    }

    /** @return array<string, array<int, array{value: string, label: string}>> */
    private function options(): array
    {
        $label = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value));

        return [
            'strategies' => array_map(static fn (string $v): array => ['value' => $v, 'label' => $label($v)], CommissionConfigurator::STRATEGIES),
            'recipients' => array_map(static fn (string $v): array => ['value' => $v, 'label' => $label($v)], CommissionConfigurator::RECIPIENTS),
            'allocations' => array_map(static fn (string $v): array => ['value' => $v, 'label' => $label($v)], CommissionConfigurator::ALLOCATIONS),
            'rateTypes' => array_map(static fn (string $v): array => ['value' => $v, 'label' => $label($v)], CommissionConfigurator::RATE_TYPES),
        ];
    }

    /** @return array<string, mixed> */
    private function validatedPlan(Request $request, ?CommissionPlan $plan): array
    {
        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        return $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('commission_plans', 'code')
                ->where('company_id', $companyId)->ignore($plan?->getKey())],
            'name' => ['required', 'string', 'max:160'],
            'strategy' => ['required', Rule::in(CommissionConfigurator::STRATEGIES)],
            'recipient_role' => ['required', Rule::in(CommissionConfigurator::RECIPIENTS)],
            'ad_spend_allocation' => ['required', Rule::in(CommissionConfigurator::ALLOCATIONS)],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
