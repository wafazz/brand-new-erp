<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use Stringable;

final readonly class Money implements Stringable
{
    public const SCALE = 4;

    public const DEFAULT_CURRENCY = 'MYR';

    private function __construct(
        public string $amount,
        public string $currency,
    ) {}

    public static function of(string|int|float $amount, string $currency = self::DEFAULT_CURRENCY): self
    {
        if (is_float($amount)) {
            throw new InvalidArgumentException(
                'Refusing to build Money from a float. Money is exact decimal — pass "12.50", not 12.50.'
            );
        }

        if (is_string($amount) && ! is_numeric($amount)) {
            throw new InvalidArgumentException("[{$amount}] is not a numeric amount.");
        }

        return new self(bcadd((string) $amount, '0', self::SCALE), strtoupper($currency));
    }

    public static function zero(string $currency = self::DEFAULT_CURRENCY): self
    {
        return new self(bcadd('0', '0', self::SCALE), strtoupper($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(bcadd($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(bcsub($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    public function times(string|int $factor): self
    {
        return new self(bcmul($this->amount, (string) $factor, self::SCALE), $this->currency);
    }

    public function percentage(string|int $percent): self
    {
        $rate = bcdiv((string) $percent, '100', self::SCALE + 2);

        return new self(bcmul($this->amount, $rate, self::SCALE), $this->currency);
    }

    public function negated(): self
    {
        return new self(bcmul($this->amount, '-1', self::SCALE), $this->currency);
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === -1;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE) === 1;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::SCALE) === -1;
    }

    /** @param iterable<self> $items */
    public static function sum(iterable $items, string $currency = self::DEFAULT_CURRENCY): self
    {
        $total = self::zero($currency);

        foreach ($items as $item) {
            $total = $total->plus($item);
        }

        return $total;
    }

    public function format(): string
    {
        return $this->currency.' '.number_format((float) $this->amount, 2, '.', ',');
    }

    public function toDecimal(): string
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency} with {$other->currency}."
            );
        }
    }
}
