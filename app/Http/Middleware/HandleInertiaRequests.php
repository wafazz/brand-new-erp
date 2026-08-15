<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\Access\NavigationBuilder;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();
        $company = app()->bound(Company::class) ? app(Company::class) : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'can' => $user === null ? [] : $user->getAllPermissions()->pluck('name')->values()->all(),
            ],
            'company' => $company === null ? null : [
                'id' => $company->getKey(),
                'name' => $company->name,
                'currency' => $company->currency,
            ],
            'navigation' => $user === null || ! app()->bound(Company::class)
                ? []
                : app(NavigationBuilder::class)->for($user),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
