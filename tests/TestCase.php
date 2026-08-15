<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Company;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->afterApplicationCreated(function (): void {
            $database = config('database.connections.pgsql.database');

            if ($database !== 'sme_erp_test') {
                throw new RuntimeException(
                    "Refusing to run: tests are pointed at database [{$database}], not [sme_erp_test]. ".
                    'A suite that migrates the development database is how a dev dataset gets destroyed.'
                );
            }
        });

        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            app(CompanyContext::class)->forget();
        }

        parent::tearDown();
    }

    protected function company(string $name = 'Acme Trading'): Company
    {
        return Company::create([
            'name' => $name,
            'slug' => str()->slug($name).'-'.str()->random(6),
        ]);
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    protected function withCompany(Company $company, Closure $callback): mixed
    {
        return app(CompanyContext::class)->runAs($company->getKey(), $callback);
    }
}
