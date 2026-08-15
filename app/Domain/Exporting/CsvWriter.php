<?php

declare(strict_types=1);

namespace App\Domain\Exporting;

class CsvWriter
{
    private const RISKY_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  array<int, string>  $headings
     * @param  iterable<int, array<int, string>>  $rows
     * @return resource
     */
    public function open(array $headings, iterable $rows)
    {
        $handle = fopen('php://output', 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Could not open the output stream.');
        }

        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, array_map([$this, 'neutralise'], $headings), ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, array_map([$this, 'neutralise'], $row), ',', '"', '\\');
        }

        return $handle;
    }

    public function neutralise(string $value): string
    {
        $value = str_replace(["\0", "\r\n"], ['', "\n"], $value);

        foreach (self::RISKY_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'".$value;
            }
        }

        return $value;
    }
}
