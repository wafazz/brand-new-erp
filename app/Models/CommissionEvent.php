<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CommissionEvent extends Model
{
    use BelongsToCompany;
    use HasUlid;

    public const UPDATED_AT = null;

    protected $fillable = ['commission_id', 'actor_user_id', 'event', 'summary', 'before', 'after'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('CommissionEvent rows are immutable.');
        });
    }
}
