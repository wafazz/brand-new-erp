<?php

declare(strict_types=1);

namespace App\Contracts;

interface Scopeable
{
    public static function ownerColumn(): ?string;

    public static function branchColumn(): ?string;
}
