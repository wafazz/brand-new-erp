<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class CrossCompanyAccessException extends RuntimeException
{
    public static function onCreate(string $model, string $attempted, string $bound): self
    {
        return new self(
            "Refusing to create [{$model}] for company [{$attempted}] while company [{$bound}] is bound."
        );
    }

    public static function onMove(string $model): self
    {
        return new self(
            "Refusing to move an existing [{$model}] to a different company. company_id is immutable."
        );
    }
}
