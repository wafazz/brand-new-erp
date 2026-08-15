<?php

declare(strict_types=1);

namespace App\Domain\Numbering;

use App\Models\DocumentSequence;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DocumentNumberService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly CompanyContext $context) {}

    public function next(string $key, string $prefix = '', string $period = ''): string
    {
        $companyId = $this->context->idOrFail(self::class);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($key, $prefix, $period, $companyId): string {
                    $sequence = DocumentSequence::query()
                        ->where('company_id', $companyId)
                        ->where('key', $key)
                        ->where('period', $period)
                        ->lockForUpdate()
                        ->first();

                    if ($sequence === null) {
                        $sequence = DocumentSequence::create([
                            'key' => $key,
                            'period' => $period,
                            'prefix' => $prefix,
                        ]);
                    }

                    $number = (int) $sequence->next_number;
                    $sequence->forceFill(['next_number' => $number + 1])->save();

                    return $this->format($sequence->prefix, $period, $number, (int) $sequence->padding);
                });
            } catch (QueryException $exception) {
                if ($attempt === self::MAX_ATTEMPTS || ! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException("Could not allocate a document number for [{$key}] after ".self::MAX_ATTEMPTS.' attempts.');
    }

    public function peek(string $key, string $period = ''): int
    {
        $companyId = $this->context->idOrFail(self::class);

        $sequence = DocumentSequence::query()
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->where('period', $period)
            ->first();

        return (int) ($sequence->next_number ?? 1);
    }

    private function format(string $prefix, string $period, int $number, int $padding): string
    {
        $parts = array_filter([$prefix, $period], static fn (string $part): bool => $part !== '');
        $parts[] = str_pad((string) $number, $padding, '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505';
    }
}
