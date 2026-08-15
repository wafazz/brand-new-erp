<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'MYR', 'total_debit' => '0', 'total_credit' => '0'];

    protected $fillable = ['posted_by', 'reference', 'description', 'source_type', 'source_id', 'currency', 'posted_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_debit' => 'decimal:4',
            'total_credit' => 'decimal:4',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
