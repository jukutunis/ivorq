<?php

namespace Modules\Operations\PMS\Enums;

enum ReservationStatusEnum: string
{
    case Tentative  = 'tentative';
    case Confirmed  = 'confirmed';
    case Waitlisted = 'waitlisted';
    case CheckedIn  = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled  = 'cancelled';
    case NoShow     = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Tentative  => 'Tentative',
            self::Confirmed  => 'Confirmed',
            self::Waitlisted => 'Waitlisted',
            self::CheckedIn  => 'Checked In',
            self::CheckedOut => 'Checked Out',
            self::Cancelled  => 'Cancelled',
            self::NoShow     => 'No Show',
        };
    }

    /** @return array<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Tentative  => [self::Confirmed, self::Waitlisted, self::Cancelled],
            self::Confirmed  => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::Waitlisted => [self::Confirmed, self::Cancelled],
            self::CheckedIn  => [self::CheckedOut],
            self::CheckedOut => [],
            self::Cancelled  => [],
            self::NoShow     => [],
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
