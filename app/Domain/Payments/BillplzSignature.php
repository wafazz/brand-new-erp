<?php

declare(strict_types=1);

namespace App\Domain\Payments;

class BillplzSignature
{
    public function __construct(private readonly ?string $key) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function validCallback(array $params): bool
    {
        $given = $params['x_signature'] ?? null;
        unset($params['x_signature']);

        return $this->matches($this->source($params), is_string($given) ? $given : null);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function validRedirect(array $params): bool
    {
        /** @var array<string, mixed> $billplz */
        $billplz = is_array($params['billplz'] ?? null) ? $params['billplz'] : [];

        $given = $billplz['x_signature'] ?? null;
        unset($billplz['x_signature']);

        $flat = [];

        foreach ($billplz as $key => $value) {
            $flat['billplz'.$key] = $value;
        }

        return $this->matches($this->source($flat), is_string($given) ? $given : null);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function source(array $params): string
    {
        ksort($params);

        $parts = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $parts[] = $key.(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        return implode('|', $parts);
    }

    private function matches(string $source, ?string $given): bool
    {
        if ($this->key === null || $this->key === '' || $given === null) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $source, $this->key), $given);
    }
}
