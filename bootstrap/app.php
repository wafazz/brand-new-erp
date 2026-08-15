<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveCompany;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->redirectGuestsTo('/login');

        $middleware->alias([
            'company' => ResolveCompany::class,
        ]);

        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveCompany::class);
        $middleware->prependToPriorityList(ResolveCompany::class, Authenticate::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
