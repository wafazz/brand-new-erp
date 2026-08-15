<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['period' => '', 'prefix' => '', 'next_number' => 1, 'padding' => 5];

    protected $fillable = ['key', 'period', 'prefix', 'padding'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
            'padding' => 'integer',
        ];
    }
}
