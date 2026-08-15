<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class MissingCompanyContextException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(
            "No company is bound to the current context, so [{$model}] cannot be queried. ".
            'Resolve a company first, or wrap the call in CompanyContext::runAs().'
        );
    }
}
