<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class PromotionRule extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['applies_to' => 'variant', 'discount_type' => 'percent', 'min_quantity' => '1', 'priority' => 0, 'is_active' => true];

    protected $fillable = ['code', 'name', 'applies_to', 'target_id', 'discount_type', 'discount_value', 'min_quantity', 'valid_from', 'valid_to', 'priority', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:4',
            'min_quantity' => 'decimal:4',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
