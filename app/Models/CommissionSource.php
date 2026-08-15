<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionSource extends Model
{
    use BelongsToCompany;
    use HasUlid;

    public const UPDATED_AT = null;

    protected $fillable = ['commission_id', 'order_id', 'order_item_id', 'contribution'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'contribution' => 'decimal:4',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
