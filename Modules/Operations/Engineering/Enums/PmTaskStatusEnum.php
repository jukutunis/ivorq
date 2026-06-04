<?php

namespace Modules\Operations\Engineering\Enums;

enum PmTaskStatusEnum: string
{
    case Scheduled  = 'scheduled';
    case Assigned   = 'assigned';
    case InProgress = 'in_progress';
    case Overdue    = 'overdue';
    case Completed  = 'completed';
    case Skipped    = 'skipped';

    public function label(): string
    {
        return match($this) {
            self::Scheduled  => 'Scheduled',
            self::Assigned   => 'Assigned',
            self::InProgress => 'In Progress',
            self::Overdue    => 'Overdue',
            self::Completed  => 'Completed',
            self::Skipped    => 'Skipped',
        };
    }

    /**
     * Returns the valid target statuses reachable from $current.
     *
     * Transition rules:
     *   scheduled   → assigned, skipped
     *   assigned    → in_progress, skipped
     *   in_progress → completed, skipped
     *   overdue     → assigned, completed, skipped  (system may set overdue; all resolution paths remain)
     *   completed   → []  (terminal)
     *   skipped     → []  (terminal)
     *
     * @return PmTaskStatusEnum[]
     */
    public static function validTransitionsFrom(self $current): array
    {
        return match($current) {
            self::Scheduled  => [self::Assigned, self::Skipped],
            self::Assigned   => [self::InProgress, self::Skipped],
            self::InProgress => [self::Completed, self::Skipped],
            self::Overdue    => [self::Assigned, self::Completed, self::Skipped],
            self::Completed  => [],
            self::Skipped    => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitionsFrom($this), strict: true);
    }

    public function isTerminal(): bool
    {
        return match($this) {
            self::Completed, self::Skipped => true,
            default                        => false,
        };
    }
}
