<?php

namespace Modules\Operations\Housekeeping\Enums;

enum RoomCleanlinessStatusEnum: string
{
    case Dirty     = 'dirty';
    case Clean     = 'clean';
    case Inspected = 'inspected';

    public function label(): string
    {
        return match($this) {
            self::Dirty     => 'Dirty',
            self::Clean     => 'Clean',
            self::Inspected => 'Inspected',
        };
    }

    /**
     * Returns the valid target statuses reachable from $current.
     *
     * Transition rules:
     *   dirty     → clean              (cleaning completed)
     *   clean     → inspected, dirty   (inspected: passed; dirty: failed or re-soiled)
     *   inspected → dirty              (next guest cycle / checkout)
     *
     * Prohibited: dirty → inspected (must be cleaned before inspection)
     *
     * @return RoomCleanlinessStatusEnum[]
     */
    public static function validTransitionsFrom(self $current): array
    {
        return match($current) {
            self::Dirty     => [self::Clean],
            self::Clean     => [self::Inspected, self::Dirty],
            self::Inspected => [self::Dirty],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitionsFrom($this), strict: true);
    }
}
