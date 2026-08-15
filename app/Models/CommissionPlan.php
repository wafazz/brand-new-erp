<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionPlan extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['ad_spend_allocation' => 'pro_rata_by_order_value', 'is_active' => true];

    protected $fillable = ['code', 'name', 'strategy', 'recipient_role', 'ad_spend_allocation', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<CommissionRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }
}
