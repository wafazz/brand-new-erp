<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToCompany;
    use HasUlid;

    protected $fillable = ['parent_id', 'code', 'name', 'sort', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
