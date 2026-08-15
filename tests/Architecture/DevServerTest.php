<?php

declare(strict_types=1);

it('injects the React refresh preamble before loading any module', function (): void {
    $blade = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/app.blade.php');

    $refresh = strpos($blade, '@viteReactRefresh');
    $vite = strpos($blade, '@vite(');

    expect($refresh)->not->toBeFalse(
        'app.blade.php has no @viteReactRefresh. It is a no-op in a production build, so every '.
        'test and every CI run passes without it — and the dev server renders a blank page with '.
        '"@vitejs/plugin-react can\'t detect preamble".'
    );

    expect($vite)->not->toBeFalse('app.blade.php loads no assets at all.');

    expect($refresh)->toBeLessThan(
        (int) $vite,
        'The refresh preamble must be injected before the module that needs it.'
    );
});

it('serves its assets over a host the browser will also be using', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/vite.config.ts');

    // A dev server bound only to ::1 while the app is browsed on 127.0.0.1 loads
    // nothing, and the failure looks identical to a JavaScript error.
    expect(str_contains($config, 'host:'))->toBeTrue(
        'vite.config.ts pins no dev-server host, so it binds wherever Node defaults to. '.
        'Set server.host so the URL written into the page matches the one the browser uses.'
    );
});
