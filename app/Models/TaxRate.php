<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['rate_percent' => '0', 'is_inclusive' => false, 'is_active' => true];

    protected $fillable = ['code', 'name', 'rate_percent', 'is_inclusive', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:4',
            'is_inclusive' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
