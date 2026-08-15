<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class PipelineStage extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['probability' => 0, 'sort' => 0, 'is_won' => false, 'is_lost' => false];

    protected $fillable = ['code', 'name', 'probability', 'sort', 'is_won', 'is_lost'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'probability' => 'integer',
            'sort' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
        ];
    }
}
