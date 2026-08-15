<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompany
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->forget();

        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $companyId = $user->active_company_id;

        if ($companyId === null) {
            abort(403, 'No active company is selected for this account.');
        }

        $membership = $user->membershipFor($companyId);

        if ($membership === null) {
            Log::critical('User reached a company without an active membership.', [
                'user_id' => $user->getKey(),
                'company_id' => $companyId,
            ]);

            abort(403);
        }

        $company = Company::query()->whereKey($companyId)->first();

        if ($company === null || ! $company->is_active) {
            abort(403, 'This company is not active.');
        }

        $this->context->set($companyId);
        $this->permissions->setPermissionsTeamId($companyId);
        app()->instance(Company::class, $company);

        Log::withContext(['company_id' => $companyId, 'user_id' => $user->getKey()]);

        return $next($request);
    }
}
