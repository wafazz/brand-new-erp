<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Scopeable;
use App\Enums\CompanyRole;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyUser extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    protected $fillable = [
        'user_id', 'branch_id', 'department_id', 'manager_id',
        'role', 'employee_no', 'is_active', 'joined_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'is_active' => 'boolean',
            'joined_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public static function ownerColumn(): ?string
    {
        return 'user_id';
    }

    public static function branchColumn(): ?string
    {
        return 'branch_id';
    }
}
