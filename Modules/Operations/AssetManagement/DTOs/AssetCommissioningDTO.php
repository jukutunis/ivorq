<?php

namespace Modules\Operations\AssetManagement\DTOs;

class AssetCommissioningDTO
{
    public function __construct(
        public readonly string $property_id,
        public readonly string $asset_id,
        public readonly string $status,
        public readonly ?string $acceptance_test_date = null,
        public readonly ?string $vendor_signoff_user_id = null,
        public readonly ?string $engineer_signoff_user_id = null,
        public readonly ?string $notes = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            property_id: $data['property_id'],
            asset_id: $data['asset_id'],
            status: $data['status'],
            acceptance_test_date: $data['acceptance_test_date'] ?? null,
            vendor_signoff_user_id: $data['vendor_signoff_user_id'] ?? null,
            engineer_signoff_user_id: $data['engineer_signoff_user_id'] ?? null,
            notes: $data['notes'] ?? null
        );
    }
}
