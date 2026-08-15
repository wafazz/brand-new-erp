<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyModuleSetting;
use App\Models\CompanyUser;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'companyName' => app(Company::class)->name,
            'branchCount' => Branch::query()->count(),
            'userCount' => CompanyUser::query()->where('is_active', true)->count(),
            'enabledModules' => CompanyModuleSetting::query()->where('enabled', true)->count(),
        ]);
    }
}
