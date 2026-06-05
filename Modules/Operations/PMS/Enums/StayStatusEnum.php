<?php

namespace Modules\Operations\PMS\Enums;

enum StayStatusEnum: string
{
    case Reserved   = 'reserved';
    case CheckedIn  = 'checked_in';
    case CheckedOut = 'checked_out';
    case Transferred = 'transferred';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Reserved    => 'Reserved',
            self::CheckedIn   => 'Checked In',
            self::CheckedOut  => 'Checked Out',
            self::Transferred => 'Transferred',
            self::Cancelled   => 'Cancelled',
        };
    }

    /** @return array<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Reserved    => [self::CheckedIn, self::Transferred, self::Cancelled],
            self::CheckedIn   => [self::CheckedOut, self::Transferred],
            self::Transferred => [self::CheckedIn],
            self::CheckedOut  => [],
            self::Cancelled   => [],
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
