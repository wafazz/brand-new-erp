<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CompanyContext::class);

        if ($context->isScopeDisabled()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('company_id'),
            $context->idOrFail($model::class)
        );
    }
}
