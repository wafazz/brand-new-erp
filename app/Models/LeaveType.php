<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = ['days_per_year' => '0', 'is_paid' => true, 'requires_document' => false, 'is_active' => true];

    protected $fillable = ['code', 'name', 'days_per_year', 'is_paid', 'requires_document', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'days_per_year' => 'decimal:2',
            'is_paid' => 'boolean',
            'requires_document' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<LeaveRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
