<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reporting\DashboardService;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboards) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $period = (string) $request->query('period', now()->format('Y-m'));
        $variant = $this->variantFor($request);

        return Inertia::render('Dashboard', [
            'companyName' => app(Company::class)->name,
            'figures' => $this->dashboards->forRole($user, $variant, $period),
            'availableVariants' => $this->variantsFor($request),
        ]);
    }

    private function variantFor(Request $request): string
    {
        $requested = $request->query('view');
        $available = $this->variantsFor($request);

        if (is_string($requested) && in_array($requested, $available, true)) {
            return $requested;
        }

        return $available[0] ?? 'salesperson';
    }

    /** @return array<int, string> */
    private function variantsFor(Request $request): array
    {
        $membership = CompanyUser::query()
            ->where('user_id', $request->user()?->getKey())
            ->where('is_active', true)
            ->first();

        return match ($membership?->role) {
            CompanyRole::Owner, CompanyRole::Admin, CompanyRole::Accountant => ['management', 'sales', 'marketing'],
            CompanyRole::BranchManager => ['management', 'sales'],
            CompanyRole::SalesManager => ['sales', 'salesperson'],
            CompanyRole::MarketingManager => ['marketing', 'marketer'],
            CompanyRole::Marketer => ['marketer'],
            default => ['salesperson'],
        };
    }
}
