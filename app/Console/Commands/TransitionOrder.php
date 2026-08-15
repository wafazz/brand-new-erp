<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Orders\IllegalOrderTransition;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\ExceptionStatus;
use App\Enums\FulfilmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Support\CompanyContext;
use Illuminate\Console\Command;

class TransitionOrder extends Command
{
    protected $signature = 'erp:transition-order {company} {order} {axis} {target}';

    protected $description = 'Move one order status axis. Used by the concurrency suite.';

    public function handle(CompanyContext $context, OrderStateMachine $states): int
    {
        $companyId = (string) $this->argument('company');
        $orderId = (string) $this->argument('order');
        $axis = (string) $this->argument('axis');
        $value = (string) $this->argument('target');

        $target = match ($axis) {
            'payment' => PaymentStatus::from($value),
            'fulfilment' => FulfilmentStatus::from($value),
            'exception' => ExceptionStatus::from($value),
            default => null,
        };

        if ($target === null) {
            $this->line('INVALID_AXIS');

            return self::FAILURE;
        }

        return $context->runAs($companyId, function () use ($states, $orderId, $target): int {
            $order = Order::query()->find($orderId);

            if ($order === null) {
                $this->line('NOT_FOUND');

                return self::FAILURE;
            }

            try {
                $states->transition($order, $target);
                $this->line('APPLIED');
            } catch (IllegalOrderTransition $exception) {
                $this->line('REFUSED: '.$exception->getMessage());
            }

            return self::SUCCESS;
        });
    }
}
