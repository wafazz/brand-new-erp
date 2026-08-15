<?php

declare(strict_types=1);

use App\Events\OrderStatusChanged;
use App\Http\Middleware\ResolveCompany;
use App\Models\Order;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Finder\Finder;

/** @return array<int, string> */
function phpSourceFiles(): array
{
    $files = [];
    $root = dirname(__DIR__, 2);

    foreach (Finder::create()->files()->name('*.php')->in([$root.'/app', $root.'/database', $root.'/routes']) as $file) {
        $files[] = $file->getRealPath();
    }

    return $files;
}

it('declares strict types in every source file', function (): void {
    $offenders = [];

    foreach (phpSourceFiles() as $path) {
        $head = (string) file_get_contents($path, length: 512);

        if (! str_contains($head, 'declare(strict_types=1);')) {
            $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $path);
        }
    }

    expect($offenders)->toBeEmpty();
});

it('writes no prose comments in source', function (): void {
    $offenders = [];
    $allowed = '/@(param|return|var|template|throws|phpstan|extends|implements|method|property|deprecated|see)/';

    foreach (phpSourceFiles() as $path) {
        foreach (file($path) ?: [] as $number => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || ! preg_match('#^(//|/\*|\*)#', $trimmed)) {
                continue;
            }

            if (preg_match('#^(/\*\*|\*/|\*\s*$)#', $trimmed) || preg_match($allowed, $trimmed)) {
                continue;
            }

            $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $path).':'.($number + 1).' '.$trimmed;
        }
    }

    expect($offenders)->toBeEmpty();
});

it('leaves no TODO markers in source', function (): void {
    $offenders = [];

    foreach (phpSourceFiles() as $path) {
        $contents = (string) file_get_contents($path);

        if (preg_match('/\b(TODO|FIXME|XXX|HACK)\b/', $contents)) {
            $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $path);
        }
    }

    expect($offenders)->toBeEmpty();
});

it('confines the tenancy escape hatch to the models that define it', function (): void {
    $offenders = [];
    $allowedFiles = ['app/Models/Concerns/BelongsToCompany.php'];

    foreach (phpSourceFiles() as $path) {
        $relative = str_replace(dirname(__DIR__, 2).'/', '', $path);

        if (in_array($relative, $allowedFiles, true)) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        if (str_contains($contents, 'withoutGlobalScope')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBeEmpty();
});

it('resolves the company after authentication and before route model binding', function (): void {
    $priority = app(Kernel::class)->getMiddlewarePriority();

    $auth = array_search(Authenticate::class, $priority, true);
    $company = array_search(ResolveCompany::class, $priority, true);
    $bindings = array_search(SubstituteBindings::class, $priority, true);

    expect($auth)->not->toBeFalse('Authenticate is missing from the middleware priority list')
        ->and($company)->not->toBeFalse('ResolveCompany is missing from the middleware priority list')
        ->and($bindings)->not->toBeFalse('SubstituteBindings is missing from the middleware priority list')
        ->and($auth)->toBeLessThan($company)
        ->and($company)->toBeLessThan($bindings);
});

it('writes an order status column only from the state machine', function (): void {
    $allowed = [
        'app/Domain/Orders/OrderStateMachine.php',
        'app/Models/Order.php',
    ];

    $columns = ['payment_status', 'fulfilment_status', 'exception_status'];
    $offenders = [];

    foreach (phpSourceFiles() as $path) {
        $relative = str_replace(dirname(__DIR__, 2).'/', '', $path);

        if (in_array($relative, $allowed, true) || str_starts_with($relative, 'database/')) {
            continue;
        }

        foreach (file($path) ?: [] as $number => $line) {
            foreach ($columns as $column) {
                if (preg_match("/'{$column}'\s*=>/", $line)) {
                    $offenders[] = $relative.':'.($number + 1).' '.trim($line);
                }
            }
        }
    }

    expect($offenders)->toBeEmpty();
});

it('keeps every order status column out of mass assignment', function (): void {
    $fillable = (new Order)->getFillable();

    foreach (['payment_status', 'fulfilment_status', 'exception_status'] as $column) {
        expect(in_array($column, $fillable, true))
            ->toBeFalse("Order exposes {$column} to mass assignment; only the state machine may write it.");
    }
});

it('keeps order money totals out of mass assignment', function (): void {
    $fillable = (new Order)->getFillable();

    foreach (['subtotal', 'total', 'paid_amount', 'tax_amount', 'discount_amount'] as $column) {
        expect(in_array($column, $fillable, true))
            ->toBeFalse("Order exposes {$column} to mass assignment; only OrderService may write it.");
    }
});

it('registers each domain listener exactly once', function (): void {
    $listeners = Event::getListeners(OrderStatusChanged::class);

    expect($listeners)->toHaveCount(1,
        'OrderStatusChanged has more than one registered listener. Laravel auto-discovers app/Listeners, '.
        'so a manual Event::listen for the same pair doubles every side effect.'
    );
});
