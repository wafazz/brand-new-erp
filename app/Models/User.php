<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory;
    use HasRoles;
    use HasUlid;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function activeCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'active_company_id');
    }

    /** @return HasMany<CompanyUser, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user');
    }

    public function membershipFor(string $companyId): ?CompanyUser
    {
        return CompanyUser::acrossCompanies()
            ->where('company_id', $companyId)
            ->where('user_id', $this->getKey())
            ->where('is_active', true)
            ->first();
    }

    /** @return Collection<int, string> */
    public function branchIdsFor(string $companyId): Collection
    {
        return Branch::acrossCompanies()
            ->join('branch_user', 'branch_user.branch_id', '=', 'branches.id')
            ->where('branch_user.user_id', $this->getKey())
            ->where('branches.company_id', $companyId)
            ->pluck('branches.id');
    }

    /** @return Collection<int, string> */
    public function subordinateUserIdsFor(string $companyId): Collection
    {
        $direct = CompanyUser::acrossCompanies()
            ->where('company_id', $companyId)
            ->where('manager_id', $this->getKey())
            ->where('is_active', true)
            ->pluck('user_id');

        return $direct->push($this->getKey())->unique()->values();
    }
}
