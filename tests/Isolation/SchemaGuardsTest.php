<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

/** @return array<int, class-string<Model>> */
function everyModel(): array
{
    $models = [];

    foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 2).'/app/Models') as $file) {
        $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $models[] = $class;
        }
    }

    sort($models);

    return $models;
}

it('never puts a privilege boolean on the users table', function (): void {
    $columns = Schema::getColumnListing('users');

    foreach (['is_platform_owner', 'is_super_admin', 'is_admin', 'is_staff'] as $forbidden) {
        expect(in_array($forbidden, $columns, true))
            ->toBeFalse("users.{$forbidden} exists. Elevated access comes from a role, never a boolean.");
    }
});

it('applies the company trait to every model that has a company_id column', function (string $class): void {
    $table = (new $class)->getTable();

    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
        expect(true)->toBeTrue();

        return;
    }

    $usesTrait = in_array(BelongsToCompany::class, class_uses_recursive($class), true);

    expect($usesTrait)->toBeTrue(
        "[{$class}] maps to table [{$table}] which has a company_id column, but does not use BelongsToCompany. ".
        'It is therefore invisible to the isolation suite and queries unscoped.'
    );
})->with(everyModel());

it('maps every model to a table that exists', function (string $class): void {
    $table = (new $class)->getTable();

    expect(Schema::hasTable($table))->toBeTrue(
        "[{$class}] maps to table [{$table}], which does not exist. ".
        'Laravel pluralisation and the migration disagree.'
    );
})->with(everyModel());

it('gives every money column the same precision', function (): void {
    $offenders = DB::select(<<<'SQL'
        SELECT table_name, column_name, numeric_precision, numeric_scale
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND (column_name LIKE '%price%' OR column_name LIKE '%amount%' OR column_name = 'credit_limit')
          AND column_name NOT LIKE '%_id'
          AND column_name NOT LIKE '%_mode'
          AND column_name NOT LIKE '%_type'
          AND NOT (data_type = 'numeric' AND numeric_precision = 15 AND numeric_scale = 4)
    SQL);

    expect($offenders)->toBeEmpty();
});
