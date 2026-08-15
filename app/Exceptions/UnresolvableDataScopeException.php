<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class UnresolvableDataScopeException extends RuntimeException
{
    public static function forPermission(string $permission): self
    {
        return new self(
            "No data scope could be resolved for permission [{$permission}]. ".
            'The query was refused rather than run unscoped.'
        );
    }
}
