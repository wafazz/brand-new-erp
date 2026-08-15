<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Support\Facades\Context;

class OrderEventRecorder
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        Order $order,
        string $event,
        string $summary,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
        ?string $reason = null,
    ): OrderEvent {
        return OrderEvent::create([
            'order_id' => $order->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'actor_type' => $actor === null ? 'system' : 'user',
            'event' => $event,
            'summary' => $reason === null ? $summary : $summary.' Reason: '.$reason,
            'before' => $before,
            'after' => $after,
            'correlation_id' => Context::get('correlation_id'),
        ]);
    }
}
