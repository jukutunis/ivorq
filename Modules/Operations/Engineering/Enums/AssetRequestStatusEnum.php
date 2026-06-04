<?php

namespace Modules\Operations\Engineering\Enums;

enum AssetRequestStatusEnum: string
{
    case Pending   = 'pending';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending   => 'Pending',
            self::Approved  => 'Approved',
            self::Rejected  => 'Rejected',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Returns the valid target statuses reachable from $current.
     *
     * Transition rules:
     *   pending   → approved, rejected, cancelled
     *   approved  → fulfilled, cancelled
     *   rejected  → []  (terminal)
     *   fulfilled → []  (terminal)
     *   cancelled → []  (terminal)
     *
     * @return AssetRequestStatusEnum[]
     */
    public static function validTransitionsFrom(self $current): array
    {
        return match($current) {
            self::Pending   => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved  => [self::Fulfilled, self::Cancelled],
            self::Rejected  => [],
            self::Fulfilled => [],
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
            self::Rejected, self::Fulfilled, self::Cancelled => true,
            default                                           => false,
        };
    }
}
