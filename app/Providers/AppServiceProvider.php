<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Payments\BillplzClient;
use App\Domain\Payments\BillplzSignature;
use App\Models\User;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BillplzClient::class, fn (): BillplzClient => new BillplzClient(config('billplz')));
        $this->app->singleton(BillplzSignature::class, fn (): BillplzSignature => new BillplzSignature(config('billplz.x_signature_key')));

        $this->app->singleton(CompanyContext::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);
        Date::use(CarbonImmutable::class);

        Gate::define('viewHorizon', fn (?User $user): bool => $user !== null && $user->can('modules.manage'));
    }
}
