<?php

declare(strict_types=1);

use App\Exceptions\CrossCompanyAccessException;
use App\Exceptions\MissingCompanyContextException;
use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

/** @return array<int, class-string<Model>> */
function scopedModels(): array
{
    $models = [];

    foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 2).'/app/Models') as $file) {
        $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $class = 'App\\Models\\'.$relative;

        if (! class_exists($class)) {
            continue;
        }

        if (! in_array(BelongsToCompany::class, class_uses_recursive($class), true)) {
            continue;
        }

        $models[] = $class;
    }

    sort($models);

    return $models;
}

function seedRowFor(string $class, Company $company): Model
{
    return app(CompanyContext::class)->runAs($company->getKey(), function () use ($class, $company): Model {
        $suffix = str()->random(6);

        $attributes = match ($class) {
            App\Models\Branch::class => ['code' => 'BR-'.$suffix, 'name' => 'Branch '.$suffix],
            App\Models\Department::class => ['code' => 'DP-'.$suffix, 'name' => 'Dept '.$suffix],
            App\Models\CompanyUser::class => [
                'user_id' => User::create([
                    'name' => 'User '.$suffix,
                    'email' => strtolower($suffix).'@example.test',
                    'password' => 'secret-password',
                ])->getKey(),
                'role' => 'staff',
            ],
            App\Models\CompanyModuleSetting::class => [
                'module_key' => Module::firstOrCreate(
                    ['key' => 'orders'],
                    ['name' => 'Orders', 'is_core' => true]
                )->key,
                'enabled' => true,
            ],
            App\Models\Role::class => ['name' => 'role-'.$suffix, 'guard_name' => 'web'],
            App\Models\RolePermissionScope::class => [
                'role_id' => Role::create([
                    'name' => 'role-'.$suffix,
                    'guard_name' => 'web',
                ])->getKey(),
                'permission_id' => Permission::firstOrCreate(
                    ['name' => 'orders.view', 'guard_name' => 'web'],
                    ['group' => 'orders', 'ability' => 'view']
                )->getKey(),
                'scope' => 'own',
            ],
            default => throw new RuntimeException(
                "[{$class}] carries BelongsToCompany but has no seed recipe in seedRowFor(). ".
                'Add one so the isolation suite covers it.'
            ),
        };

        return $class::create($attributes);
    });
}

it('discovers at least one company-scoped model', function (): void {
    expect(scopedModels())->not->toBeEmpty();
});

it('has a seed recipe for every company-scoped model', function (string $class): void {
    $company = $this->company();

    expect(seedRowFor($class, $company))->toBeInstanceOf($class);
})->with(scopedModels());

it('never returns another company\'s rows through a normal query', function (string $class): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');

    seedRowFor($class, $a);
    seedRowFor($class, $b);

    $visible = $this->withCompany($a, fn (): int => $class::query()->count());
    $actual = $class::acrossCompanies()->where('company_id', $a->getKey())->count();

    expect($visible)->toBe($actual)
        ->and($visible)->toBeGreaterThan(0);
})->with(scopedModels());

it('never finds another company\'s record by primary key', function (string $class): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');

    $foreign = seedRowFor($class, $b);

    $found = $this->withCompany($a, fn () => $class::query()->find($foreign->getKey()));

    expect($found)->toBeNull("[{$class}] leaked a row across companies via find()");
})->with(scopedModels());

it('stamps the bound company on create rather than trusting input', function (string $class): void {
    $a = $this->company('Company A');

    $row = seedRowFor($class, $a);

    expect($row->getAttribute('company_id'))->toBe($a->getKey());
})->with(scopedModels());

it('refuses to create a row for a company other than the bound one', function (string $class): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');

    $row = seedRowFor($class, $a);
    $attributes = collect($row->getAttributes())
        ->except([$row->getKeyName(), 'created_at', 'updated_at'])
        ->put('company_id', $b->getKey())
        ->all();

    expect(fn () => $this->withCompany($a, function () use ($class, $attributes): void {
        $model = new $class;
        $model->forceFill($attributes);
        $model->save();
    }))->toThrow(CrossCompanyAccessException::class);
})->with(scopedModels());

it('refuses to move an existing record between companies', function (string $class): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');

    $row = seedRowFor($class, $a);

    expect(fn () => $this->withCompany($a, function () use ($row, $b): void {
        $row->company_id = $b->getKey();
        $row->save();
    }))->toThrow(CrossCompanyAccessException::class);
})->with(scopedModels());

it('fails closed when no company is bound', function (string $class): void {
    app(CompanyContext::class)->forget();

    expect(fn (): int => $class::query()->count())
        ->toThrow(MissingCompanyContextException::class);
})->with(scopedModels());

it('keeps company_id out of mass assignment', function (string $class): void {
    $fillable = (new $class)->getFillable();

    expect(in_array('company_id', $fillable, true))
        ->toBeFalse("[{$class}] exposes company_id to mass assignment");
})->with(scopedModels());

it('declares company_id NOT NULL on every scoped table', function (string $class): void {
    $table = (new $class)->getTable();

    $nullable = DB::selectOne(
        'select is_nullable from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        ['public', $table, 'company_id']
    );

    expect($nullable)->not->toBeNull("[{$table}] has no company_id column")
        ->and($nullable->is_nullable)->toBe('NO', "[{$table}].company_id is nullable");
})->with(scopedModels());

it('does not carry company context between tests', function (): void {
    expect(app(CompanyContext::class)->hasContext())->toBeFalse();
});

it('restores the previous company after runAs unwinds', function (): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');
    $context = app(CompanyContext::class);

    $context->set($a->getKey());
    $context->runAs($b->getKey(), fn () => null);

    expect($context->id())->toBe($a->getKey());
});

it('restores the previous company even when the callback throws', function (): void {
    $a = $this->company('Company A');
    $b = $this->company('Company B');
    $context = app(CompanyContext::class);

    $context->set($a->getKey());

    try {
        $context->runAs($b->getKey(), fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect($context->id())->toBe($a->getKey());
});
