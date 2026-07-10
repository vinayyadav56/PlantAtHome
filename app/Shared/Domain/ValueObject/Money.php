<?php

namespace App\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Immutable money value object. Amount is stored as an INTEGER number of minor
 * units (paise for INR) so arithmetic is never lossy — never use float for
 * money (guardrail, Section 4.1 / 18 of the architecture plan). Currency is an
 * ISO-4217 code (always INR for now, but present so multi-currency is a data
 * change, not a rewrite).
 */
final class Money
{
    private function __construct(
        private readonly int $amountMinor,
        private readonly string $currency,
    ) {
    }

    public static function fromMinor(int $amountMinor, string $currency = 'INR'): self
    {
        return new self($amountMinor, self::normalizeCurrency($currency));
    }

    /**
     * Build from a major-unit decimal (e.g. "199.50" or 199.5). Rounds to the
     * currency's minor precision (2 for INR) using banker's-safe half-up.
     */
    public static function fromDecimal(string|int|float $amount, string $currency = 'INR'): self
    {
        $minor = (int) round(((float) $amount) * 100);

        return new self($minor, self::normalizeCurrency($currency));
    }

    public static function zero(string $currency = 'INR'): self
    {
        return new self(0, self::normalizeCurrency($currency));
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** Major-unit decimal string, always 2dp (e.g. "199.00"). */
    public function toDecimal(): string
    {
        return number_format($this->amountMinor / 100, 2, '.', '');
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    /** Multiply by a scalar (e.g. quantity), rounding to whole minor units. */
    public function multiply(int|float $factor): self
    {
        return new self((int) round($this->amountMinor * $factor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function equals(Money $other): bool
    {
        return $this->amountMinor === $other->amountMinor && $this->currency === $other->currency;
    }

    public function __toString(): string
    {
        return $this->currency . ' ' . $this->toDecimal();
    }

    /** For persistence / event payloads. */
    public function toArray(): array
    {
        return ['amount_minor' => $this->amountMinor, 'currency' => $this->currency];
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot operate on {$this->currency} and {$other->currency} amounts."
            );
        }
    }

    private static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException("Currency must be a 3-letter ISO-4217 code, got '{$currency}'.");
        }

        return $currency;
    }
}
