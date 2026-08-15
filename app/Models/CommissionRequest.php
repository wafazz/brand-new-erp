<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class CommissionRequest extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    protected $fillable = ['commission_payout_id', 'recipient_user_id', 'bank_name', 'bank_account', 'bank_holder', 'amount', 'status', 'voucher_path', 'processed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
