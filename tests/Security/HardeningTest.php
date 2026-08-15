<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Access\RoleProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

it('never stores a password in plain text', function (): void {
    $user = User::create([
        'name' => 'Test',
        'email' => 'hash'.str()->random(4).'@a.test',
        'password' => 'secret-password',
    ]);

    expect($user->password)->not->toBe('secret-password')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue()
        ->and(str_starts_with($user->password, '$2y$') || str_starts_with($user->password, '$argon'))->toBeTrue();
});

it('hides the password and remember token from serialisation', function (): void {
    $user = User::create([
        'name' => 'Test',
        'email' => 'hide'.str()->random(4).'@a.test',
        'password' => 'secret-password',
    ]);

    $json = $user->toArray();

    expect($json)->not->toHaveKey('password')
        ->and($json)->not->toHaveKey('remember_token');
});

it('rate limits the login endpoint', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->uri() === 'login' && in_array('POST', $r->methods(), true));

    expect($route)->not->toBeNull()
        ->and(collect($route->gatherMiddleware())->contains(fn (string $m): bool => str_starts_with($m, 'throttle:')))
        ->toBeTrue('The login endpoint is not rate limited.');
});

it('protects every state-changing route with authentication and a company', function (): void {
    $unprotected = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $methods = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);

        $exempt = ['login', 'logout'] + [];

        if ($methods === [] || in_array($route->uri(), $exempt, true) || str_starts_with($route->uri(), 'horizon')) {
            continue;
        }

        $middleware = $route->gatherMiddleware();

        if (! in_array('auth', $middleware, true) || ! in_array('company', $middleware, true)) {
            $unprotected[] = implode('|', $methods).' /'.$route->uri();
        }
    }

    expect($unprotected)->toBeEmpty();
});

it('applies CSRF protection to the web group', function (): void {
    $kernel = app(Kernel::class);
    $property = (new ReflectionClass($kernel))->getProperty('middlewareGroups');
    $property->setAccessible(true);

    /** @var array<string, array<int, string>> $groups */
    $groups = $property->getValue($kernel);

    expect($groups['web'] ?? [])->toContain(ValidateCsrfToken::class);
});

it('serves no unauthenticated route from the private disk', function (): void {
    $open = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'storage/') && $route->gatherMiddleware() === []) {
            $open[] = implode('|', $route->methods()).' /'.$route->uri();
        }
    }

    expect($open)->toBeEmpty(
        'The local disk is serving an unauthenticated route. Business documents must go through an authorised controller.'
    );
});

it('gates the queue dashboard behind an explicit permission', function (): void {
    expect(Gate::has('viewHorizon'))->toBeTrue('Horizon has no explicit gate, so access depends on the environment alone.');

    expect(Gate::forUser(null)->allows('viewHorizon'))
        ->toBeFalse('the queue dashboard must never be open to a guest.');
});

it('opens the queue dashboard only to a role holding modules.manage', function (): void {
    $this->seed(PermissionSeeder::class);

    $company = Company::create(['name' => 'Horizon Co', 'slug' => 'horizon-'.str()->random(6)]);
    app(RoleProvisioner::class)->provision($company);

    $owner = person($company, CompanyRole::Owner, 'hz-owner@acme.test');
    $clerk = person($company, CompanyRole::Staff, 'hz-staff@acme.test');

    $this->withCompany($company, function () use ($company, $owner, $clerk): void {
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());

        expect($clerk->can('modules.manage'))->toBeFalse('a staff account must not hold modules.manage')
            ->and(Gate::forUser($clerk)->allows('viewHorizon'))->toBeFalse('staff must not reach the queue dashboard')
            ->and(Gate::forUser($owner)->allows('viewHorizon'))->toBeTrue('an owner must reach the queue dashboard');
    });
});

it('answers the root path only to GET', function (): void {
    $root = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => in_array($r->uri(), ['/', ''], true))
        ->flatMap(fn ($r): array => $r->methods())
        ->unique()
        ->values()
        ->all();

    expect(array_intersect($root, ['POST', 'PUT', 'PATCH', 'DELETE']))->toBeEmpty();
});

it('keeps session cookies http-only and same-site', function (): void {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');
});

it('defaults file storage to a private disk', function (): void {
    expect(config('filesystems.default'))->not->toBe('public');
});

it('never ships a privilege boolean on the users table', function (): void {
    $columns = Schema::getColumnListing('users');

    foreach (['is_admin', 'is_super_admin', 'is_platform_owner', 'is_owner', 'is_staff'] as $forbidden) {
        expect(in_array($forbidden, $columns, true))->toBeFalse("users.{$forbidden} exists.");
    }
});

it('keeps debug off whenever the environment is production', function (): void {
    expect(config('app.env') === 'production' && config('app.debug') === true)->toBeFalse();
});

it('exposes no secret in the example environment file', function (): void {
    $example = (string) file_get_contents(dirname(__DIR__, 2).'/.env.example');

    expect($example)->not->toContain('base64:')
        ->and(preg_match('/^DB_PASSWORD=.+$/m', $example))->toBe(0);
});
