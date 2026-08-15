<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class OrderEvent extends Model
{
    use BelongsToCompany;
    use HasUlid;

    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = ['actor_type' => 'user'];

    protected $fillable = [
        'order_id', 'actor_user_id', 'actor_type', 'event', 'summary', 'before', 'after', 'correlation_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('order_events is append-only. An order event can never be edited.');
        });
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
