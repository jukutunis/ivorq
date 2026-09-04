<?php

namespace Modules\Operations\Inventory\ValueObjects;

use InvalidArgumentException;
use RuntimeException;

/** Inventory-owned fixed-scale arithmetic for the legacy movement projection. */
final class InventoryProjectionDecimal
{
    private const SCALE = 4;

    private readonly string $value;

    public function __construct(string $value)
    {
        if (! extension_loaded('bcmath')) {
            throw new RuntimeException('BCMath extension is required for safe inventory projection.');
        }
        if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $value)) {
            throw new InvalidArgumentException('Invalid decimal string format or scale.');
        }
        $this->value = bcadd($value, '0', self::SCALE);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function add(self $other): self
    {
        return new self(bcadd($this->value, $other->value, self::SCALE));
    }

    public function sub(self $other): self
    {
        return new self(bcsub($this->value, $other->value, self::SCALE));
    }

    public function mul(self $other): self
    {
        return new self(bcmul($this->value, $other->value, self::SCALE));
    }

    public function div(self $other): self
    {
        if ($other->isZero()) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return new self(bcdiv($this->value, $other->value, self::SCALE));
    }

    public function compareTo(self $other): int
    {
        return bccomp($this->value, $other->value, self::SCALE);
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    public static function zero(): self
    {
        return new self('0');
    }
}
