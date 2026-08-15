<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class RollupRun extends Model
{
    use BelongsToCompany;
    use HasUlid;

    public const UPDATED_AT = null;

    protected $fillable = ['kind', 'scope_key', 'rows_written', 'ran_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rows_written' => 'integer',
            'ran_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
