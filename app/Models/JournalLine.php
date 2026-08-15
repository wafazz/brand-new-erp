<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class JournalLine extends Model
{
    use BelongsToCompany;
    use HasUlid;

    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = ['debit' => '0', 'credit' => '0'];

    protected $fillable = ['journal_entry_id', 'account_id', 'debit', 'credit', 'memo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('JournalLine rows are append-only. Post a reversing entry instead.');
        });
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
