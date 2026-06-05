<?php

namespace Modules\Operations\Inventory\Enums;

enum IssueStatusEnum: string
{
    case Draft     = 'draft';
    case Posted    = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Posted    => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Transition rules:
     *   draft     → posted, cancelled
     *   posted    → []  (terminal)
     *   cancelled → []  (terminal)
     *
     * @return IssueStatusEnum[]
     */
    public static function validTransitionsFrom(self $current): array
    {
        return match ($current) {
            self::Draft     => [self::Posted, self::Cancelled],
            self::Posted    => [],
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
            self::Posted, self::Cancelled => true,
            default                       => false,
        };
    }
}
