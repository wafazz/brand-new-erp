<?php

declare(strict_types=1);

use App\Support\CompanyContext;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(ModuleSeeder::class);
});

it('sends an already-signed-in visitor away from the login page', function (): void {
    $f = routeFixture();

    // A remembered session authenticates the user on /login, which carries no company
    // middleware. Anything on that page that needs a company must cope with not having one.
    app(CompanyContext::class)->forget();

    $this->actingAs($f['owner'])->get('/login')->assertRedirect();
});

it('renders the login page for a visitor who is not signed in', function (): void {
    $this->get('/login')->assertOk();
});

it('serves every guest-reachable page to a signed-in visitor with no company resolved', function (): void {
    $f = routeFixture();

    $broken = [];
    $checked = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $middleware = $route->gatherMiddleware();

        // Only pages a browser can reach without signing in. Those carry no company
        // middleware, so anything they share must cope with not having one.
        if (in_array('auth', $middleware, true) || str_contains($route->uri(), '{')) {
            continue;
        }

        if (str_starts_with($route->uri(), 'horizon') || str_starts_with($route->uri(), '_')) {
            continue;
        }

        app(CompanyContext::class)->forget();

        $response = $this->actingAs($f['owner'])->get('/'.ltrim($route->uri(), '/'));
        $checked[] = $route->uri();

        if ($response->status() >= 500) {
            $broken[] = '/'.$route->uri().' → '.$response->status();
        }
    }

    // Counted inside the loop, not recomputed outside it: a filter that skipped
    // everything would otherwise leave this test green while proving nothing.
    expect($checked)->toHaveCount(
        4,
        'The sweep visited '.count($checked).' guest-reachable pages ('.implode(', ', $checked).'). '.
        'If you added one deliberately, raise this number; a page anybody can reach without '.
        'signing in is worth noticing.'
    );

    expect($broken)->toBe([], 'These pages break for a signed-in visitor who has no company bound: '.implode(', ', $broken));
});
