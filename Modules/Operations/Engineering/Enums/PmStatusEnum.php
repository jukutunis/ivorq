<?php

namespace Modules\Operations\Engineering\Enums;

enum PmStatusEnum: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
    case Paused   = 'paused';

    public function label(): string
    {
        return match($this) {
            self::Active   => 'Active',
            self::Inactive => 'Inactive',
            self::Paused   => 'Paused',
        };
    }

    /**
     * Returns the valid target statuses reachable from $current.
     *
     * Transition rules:
     *   active   → paused, inactive
     *   paused   → active, inactive
     *   inactive → active
     *
     * No terminal states — a PM program can always be reactivated.
     *
     * @return PmStatusEnum[]
     */
    public static function validTransitionsFrom(self $current): array
    {
        return match($current) {
            self::Active   => [self::Paused, self::Inactive],
            self::Paused   => [self::Active, self::Inactive],
            self::Inactive => [self::Active],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitionsFrom($this), strict: true);
    }

    public function isTerminal(): bool
    {
        return false;
    }
}
