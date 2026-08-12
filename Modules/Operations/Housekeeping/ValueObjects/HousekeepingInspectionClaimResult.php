<?php

namespace Modules\Operations\Housekeeping\ValueObjects;

use Modules\Operations\Housekeeping\Models\RoomInspection;

final readonly class HousekeepingInspectionClaimResult
{
    public function __construct(
        public RoomInspection $inspection,
        public bool $replayed,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'inspection_id' => $this->inspection->id,
            'status' => $this->inspection->status instanceof \BackedEnum
                ? $this->inspection->status->value
                : (string) $this->inspection->status,
            'claimed_at' => $this->inspection->claimed_at?->toIso8601String(),
            'replayed' => $this->replayed,
        ];
    }
}
