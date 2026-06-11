<?php

namespace Modules\Operations\AssetManagement\DTOs;

class AssetMovementDTO
{
    public function __construct(
        public readonly string $property_id,
        public readonly string $asset_id,
        public readonly string $movement_type,
        public readonly string $movement_date,
        public readonly ?string $from_location_id = null,
        public readonly ?string $to_location_id = null,
        public readonly ?string $from_department_id = null,
        public readonly ?string $to_department_id = null,
        public readonly ?string $user_id = null,
        public readonly ?string $reason = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            property_id: $data['property_id'],
            asset_id: $data['asset_id'],
            movement_type: $data['movement_type'],
            movement_date: $data['movement_date'],
            from_location_id: $data['from_location_id'] ?? null,
            to_location_id: $data['to_location_id'] ?? null,
            from_department_id: $data['from_department_id'] ?? null,
            to_department_id: $data['to_department_id'] ?? null,
            user_id: $data['user_id'] ?? null,
            reason: $data['reason'] ?? null
        );
    }
}
