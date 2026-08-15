<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use RuntimeException;

class OrderNotEditable extends RuntimeException {}
