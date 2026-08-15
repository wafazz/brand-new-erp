<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use BelongsToCompany;
    use HasUlid;
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'MYR', 'credit_limit' => '0', 'payment_terms_days' => 30, 'status' => 'active'];

    protected $fillable = ['code', 'name', 'registration_no', 'tax_no', 'email', 'phone', 'currency', 'credit_limit', 'payment_terms_days', 'status', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'payment_terms_days' => 'integer',
        ];
    }

    /** @return HasMany<SupplierContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    /** @return HasMany<SupplierAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(SupplierAddress::class);
    }
}
