<?php

namespace Modules\Operations\Engineering\Enums;

enum WorkOrderStatusEnum: string
{
    case Pending    = 'pending';
    case Assigned   = 'assigned';
    case InProgress = 'in_progress';
    case OnHold     = 'on_hold';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending    => 'Pending',
            self::Assigned   => 'Assigned',
            self::InProgress => 'In Progress',
            self::OnHold     => 'On Hold',
            self::Completed  => 'Completed',
            self::Cancelled  => 'Cancelled',
        };
    }

    /**
     * Returns the valid target statuses reachable from $current.
     *
     * Transition rules:
     *   pending     → assigned, cancelled
     *   assigned    → in_progress, on_hold, cancelled
     *   in_progress → completed, on_hold, cancelled
     *   on_hold     → in_progress, assigned, cancelled
     *   completed   → []  (terminal)
     *   cancelled   → []  (terminal)
     *
     * @return WorkOrderStatusEnum[]
     */
    public static function validTransitionsFrom(self $current): array
    {
        return match($current) {
            self::Pending    => [self::Assigned, self::Cancelled],
            self::Assigned   => [self::InProgress, self::OnHold, self::Cancelled],
            self::InProgress => [self::Completed, self::OnHold, self::Cancelled],
            self::OnHold     => [self::InProgress, self::Assigned, self::Cancelled],
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
