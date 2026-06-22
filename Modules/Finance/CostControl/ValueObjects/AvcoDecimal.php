<?php

namespace Modules\Finance\CostControl\ValueObjects;

use InvalidArgumentException;
use RuntimeException;

class AvcoDecimal
{
    private readonly string $value;
    public const SCALE = 4;

    public function __construct(string $value)
    {
        if (!extension_loaded('bcmath')) {
            throw new RuntimeException("BCMath extension is required for safe valuation.");
        }
        if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $value)) {
            throw new InvalidArgumentException("Invalid decimal string format or exceeds scale limit.");
        }
        
        $this->value = bcadd($value, '0', self::SCALE);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function add(AvcoDecimal $other): AvcoDecimal
    {
        return new self(bcadd($this->value, $other->getValue(), self::SCALE));
    }

    public function sub(AvcoDecimal $other): AvcoDecimal
    {
        return new self(bcsub($this->value, $other->getValue(), self::SCALE));
    }

    public function mul(AvcoDecimal $other): AvcoDecimal
    {
        return new self(bcmul($this->value, $other->getValue(), self::SCALE));
    }

    public function div(AvcoDecimal $other): AvcoDecimal
    {
        if ($other->isZero()) {
            throw new InvalidArgumentException("Division by zero.");
        }
        return new self(bcdiv($this->value, $other->getValue(), self::SCALE));
    }

    public function compareTo(AvcoDecimal $other): int
    {
        return bccomp($this->value, $other->getValue(), self::SCALE);
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', self::SCALE) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->value, '0', self::SCALE) < 0;
    }

    public function abs(): AvcoDecimal
    {
        if ($this->isNegative()) {
            return new self(bcsub('0', $this->value, self::SCALE));
        }
        return new self($this->value);
    }

    public static function zero(): self
    {
        return new self('0');
    }
}
