<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['type' => 'shipping', 'country' => 'MY', 'is_default' => false];

    protected $fillable = ['customer_id', 'label', 'type', 'line1', 'line2', 'city', 'postcode', 'state', 'country', 'is_default'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
