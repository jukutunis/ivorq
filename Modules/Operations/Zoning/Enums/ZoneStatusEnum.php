<?php

namespace Modules\Operations\Zoning\Enums;

enum ZoneStatusEnum: string
{
    case Draft     = 'draft';
    case Active    = 'active';
    case Suspended = 'suspended';
    case Archived  = 'archived';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Draft',
            self::Active    => 'Active',
            self::Suspended => 'Suspended',
            self::Archived  => 'Archived',
        };
    }

    /**
     * Returns the valid target statuses from a given current status.
     *
     * @return ZoneStatusEnum[]
     */
    public static function validTransitionsFrom(self $current): array
    {
        return match($current) {
            self::Draft     => [self::Active],
            self::Active    => [self::Suspended, self::Archived],
            self::Suspended => [self::Active, self::Archived],
            self::Archived  => [],
        };
    }

    /**
     * Returns true if this status can transition to the given target.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitionsFrom($this), strict: true);
    }
}
