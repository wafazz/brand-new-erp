<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use App\Models\User;
use BackedEnum;
use Illuminate\Foundation\Events\Dispatchable;

class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly BackedEnum $from,
        public readonly BackedEnum $to,
        public readonly ?User $actor = null,
    ) {}
}
