<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionPayout extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'total_amount' => '0'];

    protected $fillable = ['created_by', 'reference', 'period', 'status', 'total_amount', 'paid_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:4',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<CommissionPayoutItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CommissionPayoutItem::class);
    }
}
