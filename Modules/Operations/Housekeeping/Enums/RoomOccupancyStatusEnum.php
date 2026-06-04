<?php

namespace Modules\Operations\Housekeeping\Enums;

enum RoomOccupancyStatusEnum: string
{
    case Vacant   = 'vacant';
    case Occupied = 'occupied';
    case Blocked  = 'blocked';

    public function label(): string
    {
        return match($this) {
            self::Vacant   => 'Vacant',
            self::Occupied => 'Occupied',
            self::Blocked  => 'Blocked',
        };
    }

    /**
     * Returns the valid target statuses reachable from $current.
     *
     * Null represents an untracked room (Sprint 03 — PMS not yet active).
     * Any status is a valid first assignment from null.
     *
     * Transition rules:
     *   null     → vacant, occupied, blocked   (initial status assignment)
     *   vacant   → occupied, blocked
     *   occupied → vacant                      (checkout)
     *   blocked  → vacant                      (block released)
     *
     * Prohibited: occupied → blocked, blocked → occupied
     * (these require an explicit vacant step via checkout/unblock)
     *
     * @return RoomOccupancyStatusEnum[]
     */
    public static function validTransitionsFrom(?self $current): array
    {
        if ($current === null) {
            return [self::Vacant, self::Occupied, self::Blocked];
        }

        return match($current) {
            self::Vacant   => [self::Occupied, self::Blocked],
            self::Occupied => [self::Vacant],
            self::Blocked  => [self::Vacant],
        };
    }

    /**
     * Whether this (current) status can transition to $target.
     * For the null-current case use the static isValidTransition() helper.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::validTransitionsFrom($this), strict: true);
    }

    /**
     * Null-safe transition check for use in service layer where
     * occupancy_status may be null (PMS not yet active).
     */
    public static function isValidTransition(?self $from, self $to): bool
    {
        return in_array($to, self::validTransitionsFrom($from), strict: true);
    }
}
