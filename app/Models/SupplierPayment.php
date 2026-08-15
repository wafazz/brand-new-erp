<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['method' => 'bank_transfer'];

    protected $fillable = ['supplier_bill_id', 'paid_by', 'method', 'reference', 'amount', 'paid_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'paid_at' => 'immutable_datetime',
        ];
    }
}
