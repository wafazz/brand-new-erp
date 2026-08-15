<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['type' => 'customer', 'currency' => 'MYR', 'is_active' => true];

    protected $fillable = ['branch_id', 'code', 'name', 'type', 'currency', 'valid_from', 'valid_to', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }
}
