<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequest extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['branch_id', 'requested_by', 'reference', 'status', 'needed_by', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'needed_by' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PurchaseRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public static function ownerColumn(): ?string
    {
        return null;
    }

    public static function branchColumn(): ?string
    {
        return 'branch_id';
    }
}
