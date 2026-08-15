<?php

declare(strict_types=1);

it('names no developer machine in the test configuration', function (): void {
    $config = (string) file_get_contents(base_path('phpunit.xml'));

    preg_match_all('/<env name="(DB_USERNAME|DB_PASSWORD)" value="([^"]*)"/', $config, $matches, PREG_SET_ORDER);

    $hardcoded = array_map(
        static fn (array $m): string => "{$m[1]}={$m[2]}",
        array_filter($matches, static fn (array $m): bool => $m[2] !== '')
    );

    expect(array_values($hardcoded))->toBe(
        [],
        'phpunit.xml pins a database credential, so the suite only runs where that account exists. '.
        'Let it fall through to the environment: '.implode(', ', $hardcoded)
    );
});

it('declares the PHP version its lock file can actually install', function (): void {
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
    $floor = '8.0';

    foreach ([...$lock['packages'] ?? [], ...$lock['packages-dev'] ?? []] as $package) {
        if (preg_match('/>=\s*(\d+\.\d+)/', (string) ($package['require']['php'] ?? ''), $found) === 1) {
            $floor = version_compare($found[1], $floor, '>') ? $found[1] : $floor;
        }
    }

    $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    preg_match("/php: \[(.*?)\]/", $workflow, $matrix);
    $versions = array_map(
        static fn (string $v): string => trim($v, " '\""),
        explode(',', $matrix[1] ?? '')
    );

    $tooOld = array_values(array_filter(
        $versions,
        static fn (string $v): bool => $v !== '' && version_compare($v, $floor, '<')
    ));

    expect($tooOld)->toBe(
        [],
        "The lock file needs PHP {$floor}, but CI tests ".implode(', ', $tooOld).
        '. composer install cannot succeed there, so the matrix promises support that does not exist.'
    );
});

it('migrates its own database in any suite that does not get one', function (): void {
    $offenders = [];

    foreach (['Unit', 'Architecture'] as $suite) {
        foreach (glob(base_path("tests/{$suite}/*.php")) ?: [] as $path) {
            if (realpath($path) === __FILE__) {
                continue;
            }

            $source = (string) file_get_contents($path);

            $touchesDatabase = preg_match('/\$this->seed\(|->withCompany\(|Schema::|DB::select\(/', $source) === 1;
            $declaresRefresh = preg_match('/uses\([^)]*RefreshDatabase::class/', $source) === 1;

            if ($touchesDatabase && ! $declaresRefresh) {
                $offenders[] = "{$suite}/".basename($path);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'These tests read or write the database but sit in a suite that never migrates one. '.
        'They pass only where a previous run left the schema behind: '.implode(', ', $offenders)
    );
});

it('does not need a built frontend to test the backend', function (): void {
    $base = (string) file_get_contents(base_path('tests/TestCase.php'));

    expect(str_contains($base, 'withoutVite()'))->toBeTrue(
        'The PHP suite renders Inertia pages, so without this it fails wherever public/build has not '.
        'been produced - which is every machine except one that has already run npm run build.'
    );
});
