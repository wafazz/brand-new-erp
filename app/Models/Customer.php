<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = ['type' => 'individual', 'currency' => 'MYR', 'credit_limit' => '0', 'payment_terms_days' => 0, 'status' => 'active'];

    protected $fillable = ['branch_id', 'customer_group_id', 'price_list_id', 'owner_user_id', 'code', 'type', 'name', 'company_name', 'registration_no', 'tax_no', 'email', 'phone', 'currency', 'credit_limit', 'payment_terms_days', 'status', 'acquisition_source', 'last_interaction_at', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'payment_terms_days' => 'integer',
            'last_interaction_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CustomerGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<CustomerContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public static function ownerColumn(): ?string
    {
        return 'owner_user_id';
    }

    public static function branchColumn(): ?string
    {
        return 'branch_id';
    }
}
