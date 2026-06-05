<?php

namespace Modules\Operations\PMS\Enums;

enum FolioStatusEnum: string
{
    case Open   = 'open';
    case Closed = 'closed';
    case Void   = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Open   => 'Open',
            self::Closed => 'Closed',
            self::Void   => 'Void',
        };
    }

    /** @return array<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open   => [self::Closed, self::Void],
            self::Closed => [],
            self::Void   => [],
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
