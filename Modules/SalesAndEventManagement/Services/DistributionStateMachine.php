<?php

namespace Modules\SalesAndEventManagement\Services;

use Modules\SalesAndEventManagement\Enums\DistributionStatusEnum;
use Modules\SalesAndEventManagement\Exceptions\DistributionStateException;

/**
 * DistributionStateMachine
 *
 * Centralises all BEO Distribution lifecycle rules.
 * Approved transition matrix — Sprint 14.8.5.1.
 *
 * Terminal states: COMPLETED, SUPERSEDED, CANCELLED.
 */
class DistributionStateMachine
{
    /**
     * Approved allowed transitions.
     *
     * @var array<string, list<DistributionStatusEnum>>
     */
    private const ALLOWED = [
        'DRAFT' => [
            DistributionStatusEnum::DISTRIBUTED,
            DistributionStatusEnum::CANCELLED,
        ],
        'DISTRIBUTED' => [
            DistributionStatusEnum::PARTIALLY_ACKNOWLEDGED,
            DistributionStatusEnum::FULLY_ACKNOWLEDGED,
            DistributionStatusEnum::SUPERSEDED,
            DistributionStatusEnum::CANCELLED,
        ],
        'PARTIALLY_ACKNOWLEDGED' => [
            DistributionStatusEnum::FULLY_ACKNOWLEDGED,
            DistributionStatusEnum::SUPERSEDED,
            DistributionStatusEnum::CANCELLED,
        ],
        'FULLY_ACKNOWLEDGED' => [
            DistributionStatusEnum::COMPLETED,
            DistributionStatusEnum::SUPERSEDED,
            DistributionStatusEnum::CANCELLED,
        ],
        'ESCALATED' => [
            DistributionStatusEnum::PARTIALLY_ACKNOWLEDGED,
            DistributionStatusEnum::FULLY_ACKNOWLEDGED,
            DistributionStatusEnum::SUPERSEDED,
            DistributionStatusEnum::CANCELLED,
        ],
        // Terminal states — no outbound transitions
        'COMPLETED'  => [],
        'SUPERSEDED' => [],
        'CANCELLED'  => [],
        // PUBLISHED is a legacy/unused status in the approved revision; treated as no outbound
        'PUBLISHED'  => [],
    ];

    /**
     * Guard a transition. Throws DistributionStateException if invalid.
     */
    public function guard(DistributionStatusEnum $from, DistributionStatusEnum $to): void
    {
        $terminalStates = [
            DistributionStatusEnum::COMPLETED,
            DistributionStatusEnum::SUPERSEDED,
            DistributionStatusEnum::CANCELLED,
        ];

        if (in_array($from, $terminalStates, true)) {
            throw DistributionStateException::terminalState($from->value);
        }

        $allowed = self::ALLOWED[$from->value] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw DistributionStateException::illegalTransition($from->value, $to->value);
        }
    }
}
