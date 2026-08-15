<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model implements Scopeable
{
    use AppliesDataScope;
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'currency' => 'MYR', 'is_recurring' => false];

    protected $fillable = ['branch_id', 'expense_category_id', 'bank_account_id', 'requested_by', 'reference', 'description', 'status', 'currency', 'amount', 'is_recurring', 'spent_on', 'attachment_path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'is_recurring' => 'boolean',
            'spent_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public static function ownerColumn(): ?string
    {
        return 'requested_by';
    }

    public static function branchColumn(): ?string
    {
        return 'branch_id';
    }
}
