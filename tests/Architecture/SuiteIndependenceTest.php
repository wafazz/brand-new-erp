<?php

declare(strict_types=1);

it('never calls a helper defined in another suite, so any suite can run alone', function (): void {
    $suites = ['Unit', 'Architecture', 'Isolation', 'Feature', 'Concurrency', 'Security'];
    $defined = [];

    foreach ($suites as $suite) {
        foreach (glob(base_path("tests/{$suite}/*.php")) ?: [] as $path) {
            preg_match_all('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', (string) file_get_contents($path), $matches);

            foreach ($matches[1] as $name) {
                $defined[$name] = $suite;
            }
        }
    }

    $offenders = [];

    foreach ($suites as $suite) {
        foreach (glob(base_path("tests/{$suite}/*.php")) ?: [] as $path) {
            $source = (string) file_get_contents($path);

            foreach ($defined as $name => $home) {
                if ($home === $suite) {
                    continue;
                }

                if (preg_match('/(?<![\$>:\w])'.preg_quote($name, '/').'\s*\(/', $source) === 1) {
                    $offenders[] = basename($path)." calls {$name}(), which lives in tests/{$home}";
                }
            }
        }
    }

    expect($offenders)->toBe([], 'move the shared helper into tests/Helpers.php: '.implode('; ', $offenders));
});
