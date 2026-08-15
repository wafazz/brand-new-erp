<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class SalesRollup extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    protected $fillable = ['rollup_date', 'branch_id', 'salesperson_user_id', 'sales_team_id', 'marketer_id', 'campaign_id', 'channel_id', 'orders_count', 'revenue', 'cost', 'margin', 'tax'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rollup_date' => 'immutable_date',
            'orders_count' => 'integer',
            'revenue' => 'decimal:4',
            'cost' => 'decimal:4',
            'margin' => 'decimal:4',
            'tax' => 'decimal:4',
        ];
    }

    public static function ownerColumn(): ?string
    {
        return 'salesperson_user_id';
    }

    public static function branchColumn(): ?string
    {
        return 'branch_id';
    }
}
