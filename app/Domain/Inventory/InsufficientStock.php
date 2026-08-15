<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use RuntimeException;

class InsufficientStock extends RuntimeException {}
