<?php

declare(strict_types=1);

namespace App\Domain\Exporting;

use Illuminate\Database\Eloquent\Model;

final class ExportDefinition
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, callable>  $columns
     * @param  array<int, string>  $with
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $ability,
        public readonly ?string $scopeAbility,
        public readonly string $model,
        public readonly array $columns,
        public readonly array $with = [],
        public readonly string $orderBy = 'created_at',
        public readonly string $direction = 'desc',
    ) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return array_keys($this->columns);
    }

    public function filename(): string
    {
        return $this->key.'-'.now()->format('Y-m-d').'.csv';
    }
}
