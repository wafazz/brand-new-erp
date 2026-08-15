<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerGroup extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['discount_percent' => '0', 'is_active' => true];

    protected $fillable = ['code', 'name', 'price_list_id', 'discount_percent', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
