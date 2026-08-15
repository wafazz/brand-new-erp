<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\CrossCompanyAccessException;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            $bound = app(CompanyContext::class)->idOrFail($model::class);

            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', $bound);

                return;
            }

            if ($model->getAttribute('company_id') !== $bound) {
                throw CrossCompanyAccessException::onCreate(
                    $model::class,
                    (string) $model->getAttribute('company_id'),
                    $bound
                );
            }
        });

        static::saving(function (Model $model): void {
            if ($model->exists && $model->isDirty('company_id')) {
                throw CrossCompanyAccessException::onMove($model::class);
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return Builder<static> */
    public static function acrossCompanies(): Builder
    {
        return static::query()->withoutGlobalScope(CompanyScope::class);
    }
}
