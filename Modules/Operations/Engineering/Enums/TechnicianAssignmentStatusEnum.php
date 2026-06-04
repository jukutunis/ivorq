<?php

namespace Modules\Operations\Engineering\Enums;

enum TechnicianAssignmentStatusEnum: string
{
    case Active    = 'active';
    case Completed = 'completed';
    case Relieved  = 'relieved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Active    => 'Active',
            self::Completed => 'Completed',
            self::Relieved  => 'Relieved',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Returns the valid target statuses reachable from $current.
     *
     * Transition rules:
     *   active    → completed, relieved, cancelled
     *   completed → []  (terminal — technician finished their work)
     *   relieved  → []  (terminal — replaced by another technician)
     *   cancelled → []  (terminal)
     *
     * @return TechnicianAssignmentStatusEnum[]
     */
    public static function validTransitionsFrom(self $current): array
    {
        return match($current) {
            self::Active    => [self::Completed, self::Relieved, self::Cancelled],
            self::Completed => [],
            self::Relieved  => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitionsFrom($this), strict: true);
    }

    public function isTerminal(): bool
    {
        return match($this) {
            self::Completed, self::Relieved, self::Cancelled => true,
            default                                           => false,
        };
    }
}
