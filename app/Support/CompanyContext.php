<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\MissingCompanyContextException;
use Closure;

class CompanyContext
{
    private ?string $companyId = null;

    private bool $scopeDisabled = false;

    public function set(string $companyId): void
    {
        $this->companyId = $companyId;
    }

    public function forget(): void
    {
        $this->companyId = null;
    }

    public function id(): ?string
    {
        return $this->companyId;
    }

    public function idOrFail(string $model): string
    {
        if ($this->companyId === null) {
            throw MissingCompanyContextException::forModel($model);
        }

        return $this->companyId;
    }

    public function hasContext(): bool
    {
        return $this->companyId !== null;
    }

    public function isScopeDisabled(): bool
    {
        return $this->scopeDisabled;
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function runAs(string $companyId, Closure $callback): mixed
    {
        $previous = $this->companyId;
        $this->companyId = $companyId;

        try {
            return $callback();
        } finally {
            $this->companyId = $previous;
        }
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function runWithoutScope(Closure $callback): mixed
    {
        $previous = $this->scopeDisabled;
        $this->scopeDisabled = true;

        try {
            return $callback();
        } finally {
            $this->scopeDisabled = $previous;
        }
    }
}
