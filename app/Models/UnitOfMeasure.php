<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    use BelongsToCompany;
    use HasUlid;

    protected $table = 'units_of_measure';

    protected $fillable = ['code', 'name', 'decimals'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
        ];
    }
}
