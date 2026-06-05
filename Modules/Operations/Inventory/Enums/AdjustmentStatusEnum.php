<?php

namespace Modules\Operations\Inventory\Enums;

enum AdjustmentStatusEnum: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved  => 'Approved',
            self::Rejected  => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Transition rules:
     *   draft     → submitted, cancelled
     *   submitted → approved, rejected
     *   approved  → []  (terminal)
     *   rejected  → []  (terminal)
     *   cancelled → []  (terminal)
     *
     * @return AdjustmentStatusEnum[]
     */
    public static function validTransitionsFrom(self $current): array
    {
        return match ($current) {
            self::Draft     => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Rejected],
            self::Approved  => [],
            self::Rejected  => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitionsFrom($this), strict: true);
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Approved, self::Rejected, self::Cancelled => true,
            default                                         => false,
        };
    }
}
