<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTarget extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['metric' => 'revenue'];

    protected $fillable = ['sales_team_id', 'user_id', 'period', 'metric', 'target_amount'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SalesTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
