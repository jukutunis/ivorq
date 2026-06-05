<?php

namespace Modules\Operations\PMS\Enums;

enum RoomBlockStatusEnum: string
{
    case Active        = 'active';
    case PartiallyUsed = 'partially_used';
    case Released      = 'released';
    case Expired       = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active        => 'Active',
            self::PartiallyUsed => 'Partially Used',
            self::Released      => 'Released',
            self::Expired       => 'Expired',
        };
    }

    /** @return array<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active        => [self::PartiallyUsed, self::Released, self::Expired],
            self::PartiallyUsed => [self::Released, self::Expired],
            self::Released      => [],
            self::Expired       => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
