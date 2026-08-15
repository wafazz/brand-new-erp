<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class CommissionRollup extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    protected $fillable = ['period', 'recipient_user_id', 'recipient_role', 'pending', 'approved', 'payable', 'paid', 'reversed', 'net'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pending' => 'decimal:4',
            'approved' => 'decimal:4',
            'payable' => 'decimal:4',
            'paid' => 'decimal:4',
            'reversed' => 'decimal:4',
            'net' => 'decimal:4',
        ];
    }

    public static function ownerColumn(): ?string
    {
        return 'recipient_user_id';
    }

    public static function branchColumn(): ?string
    {
        return null;
    }
}
