<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    use HasUlid;

    protected $fillable = ['key', 'name', 'icon', 'route', 'permission', 'nav_group', 'sort', 'is_core', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_core' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
