<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CompanyContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(private readonly CompanyContext $context) {}

    public function lineFor(string $variantId, Warehouse $warehouse): Stock
    {
        return Stock::query()->firstOrCreate(
            [
                'warehouse_id' => $warehouse->getKey(),
                'product_variant_id' => $variantId,
            ],
            ['branch_id' => $warehouse->branch_id]
        );
    }

    public function available(Stock $stock): string
    {
        return bcsub((string) $stock->on_hand, (string) $stock->reserved, 4);
    }

    public function receive(
        Stock $stock,
        string $quantity,
        StockReason $reason = StockReason::Received,
        ?Model $reference = null,
        ?User $actor = null,
        ?string $note = null,
    ): StockMovement {
        return $this->applyDelta($stock, $quantity, $reason, $reference, $actor, $note);
    }

    public function adjust(
        Stock $stock,
        string $delta,
        StockReason $reason = StockReason::Adjustment,
        ?Model $reference = null,
        ?User $actor = null,
        ?string $note = null,
    ): StockMovement {
        return $this->applyDelta($stock, $delta, $reason, $reference, $actor, $note);
    }

    public function reserve(
        Stock $stock,
        string $quantity,
        ?Order $order = null,
        ?OrderItem $item = null,
        ?CarbonInterface $expiresAt = null,
    ): StockReservation {
        return DB::transaction(function () use ($stock, $quantity, $order, $item, $expiresAt): StockReservation {
            $locked = $this->lockLine($stock);
            $available = $this->available($locked);

            if (bccomp($available, $quantity, 4) === -1) {
                throw new InsufficientStock(
                    "Only {$this->trim($available)} of this item is available and {$this->trim($quantity)} was requested."
                );
            }

            $locked->forceFill([
                'reserved' => bcadd((string) $locked->reserved, $quantity, 4),
            ])->save();

            return StockReservation::create([
                'stock_id' => $locked->getKey(),
                'order_id' => $order?->getKey(),
                'order_item_id' => $item?->getKey(),
                'quantity' => $quantity,
                'status' => 'held',
                'expires_at' => $order === null ? $expiresAt : null,
            ]);
        });
    }

    public function commit(StockReservation $reservation, ?User $actor = null): ?StockMovement
    {
        return DB::transaction(function () use ($reservation, $actor): ?StockMovement {
            $locked = StockReservation::query()->lockForUpdate()->find($reservation->getKey());

            if ($locked === null || $locked->status !== 'held') {
                return null;
            }

            $stock = $this->lockLine($locked->stock);

            $stock->forceFill([
                'reserved' => bcsub((string) $stock->reserved, (string) $locked->quantity, 4),
            ])->save();

            $locked->forceFill(['status' => 'committed', 'committed_at' => now()])->save();

            return $this->writeMovement(
                $stock->refresh(),
                '-'.$locked->quantity,
                StockReason::Sold,
                $locked->order_id === null ? null : $locked->order,
                $actor,
                null,
            );
        });
    }

    public function release(StockReservation $reservation): bool
    {
        return DB::transaction(function () use ($reservation): bool {
            $locked = StockReservation::query()->lockForUpdate()->find($reservation->getKey());

            if ($locked === null || $locked->status !== 'held') {
                return false;
            }

            $stock = $this->lockLine($locked->stock);

            $stock->forceFill([
                'reserved' => bcsub((string) $stock->reserved, (string) $locked->quantity, 4),
            ])->save();

            $locked->forceFill(['status' => 'released', 'released_at' => now()])->save();

            return true;
        });
    }

    public function sweepExpired(): int
    {
        $expired = StockReservation::query()
            ->where('status', 'held')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        $released = 0;

        foreach ($expired as $reservation) {
            if ($this->release($reservation)) {
                $released++;
            }
        }

        return $released;
    }

    private function applyDelta(
        Stock $stock,
        string $delta,
        StockReason $reason,
        ?Model $reference,
        ?User $actor,
        ?string $note,
    ): StockMovement {
        return DB::transaction(function () use ($stock, $delta, $reason, $reference, $actor, $note): StockMovement {
            $locked = $this->lockLine($stock);

            return $this->writeMovement($locked, $delta, $reason, $reference, $actor, $note);
        });
    }

    private function writeMovement(
        Stock $stock,
        string $delta,
        StockReason $reason,
        ?Model $reference,
        ?User $actor,
        ?string $note,
    ): StockMovement {
        $balance = bcadd((string) $stock->on_hand, $delta, 4);

        $stock->forceFill(['on_hand' => $balance])->save();

        return StockMovement::create([
            'stock_id' => $stock->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'quantity_delta' => $delta,
            'balance_after' => $balance,
            'reason' => $reason->value,
            'note' => $note,
            'reference_type' => $reference === null ? null : $reference::class,
            'reference_id' => $reference?->getKey(),
            'correlation_id' => Context::get('correlation_id'),
        ]);
    }

    private function lockLine(Stock $stock): Stock
    {
        $this->context->idOrFail(self::class);

        return Stock::query()->lockForUpdate()->findOrFail($stock->getKey());
    }

    private function trim(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
