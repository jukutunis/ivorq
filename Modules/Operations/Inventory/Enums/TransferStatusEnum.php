<?php

namespace Modules\Operations\Inventory\Enums;

enum TransferStatusEnum: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Submitted => 'Submitted',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Transition rules:
     *   draft     → submitted, cancelled
     *   submitted → completed, cancelled
     *   completed → []  (terminal)
     *   cancelled → []  (terminal)
     *
     * @return TransferStatusEnum[]
     */
    public static function validTransitionsFrom(self $current): array
    {
        return match ($current) {
            self::Draft     => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Completed, self::Cancelled],
            self::Completed => [],
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
            self::Completed, self::Cancelled => true,
            default                          => false,
        };
    }
}
