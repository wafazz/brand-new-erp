<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class ApprovalLevel extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['min_amount' => '0'];

    protected $fillable = ['approval_flow_id', 'approver_role_id', 'approver_user_id', 'sequence', 'min_amount', 'max_amount'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'min_amount' => 'decimal:4',
            'max_amount' => 'decimal:4',
        ];
    }
}
