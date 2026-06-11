<?php

namespace Modules\Operations\AssetManagement\DTOs;

class AssetWarrantyDTO
{
    public function __construct(
        public readonly string $property_id,
        public readonly string $asset_id,
        public readonly string $start_date,
        public readonly string $end_date,
        public readonly ?string $vendor_id = null,
        public readonly ?string $coverage_type = null,
        public readonly ?string $terms = null,
        public readonly bool $is_active = true
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            property_id: $data['property_id'],
            asset_id: $data['asset_id'],
            start_date: $data['start_date'],
            end_date: $data['end_date'],
            vendor_id: $data['vendor_id'] ?? null,
            coverage_type: $data['coverage_type'] ?? null,
            terms: $data['terms'] ?? null,
            is_active: $data['is_active'] ?? true
        );
    }
}
