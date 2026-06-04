<?php

namespace Modules\Operations\Housekeeping\Enums;

enum TaskStatusEnum: string
{
    case Pending    = 'pending';
    case Assigned   = 'assigned';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending    => 'Pending',
            self::Assigned   => 'Assigned',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Cancelled  => 'Cancelled',
        };
    }

    /**
     * Returns the valid target statuses reachable from $current.
     *
     * Transition rules:
     *   pending    → assigned, cancelled
     *   assigned   → in_progress, cancelled
     *   in_progress → completed, cancelled
     *   completed  → []   (terminal)
     *   cancelled  → []   (terminal)
     *
     * @return TaskStatusEnum[]
     */
    public static function validTransitionsFrom(self $current): array
    {
        return match($current) {
            self::Pending    => [self::Assigned, self::Cancelled],
            self::Assigned   => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed  => [],
            self::Cancelled  => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitionsFrom($this), strict: true);
    }

    public function isTerminal(): bool
    {
        return match($this) {
            self::Completed, self::Cancelled => true,
            default                          => false,
        };
    }
}
