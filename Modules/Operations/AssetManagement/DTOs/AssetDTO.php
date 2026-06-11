<?php

namespace Modules\Operations\AssetManagement\DTOs;

class AssetDTO
{
    public function __construct(
        public readonly string $property_id,
        public readonly string $asset_category_id,
        public readonly string $asset_type_id,
        public readonly string $name,
        public readonly string $status,
        public readonly string $condition,
        public readonly string $criticality,
        public readonly ?string $department_id = null,
        public readonly ?string $location_id = null,
        public readonly ?string $asset_group_id = null,
        public readonly ?string $asset_number = null,
        public readonly ?string $qr_uri = null,
        public readonly ?string $serial_number = null,
        public readonly ?string $model_number = null,
        public readonly ?string $manufacturer = null,
        public readonly ?string $purchase_date = null,
        public readonly ?string $installation_date = null,
        public readonly ?string $commissioning_date = null,
        public readonly ?string $disposal_date = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            property_id: $data['property_id'],
            asset_category_id: $data['asset_category_id'],
            asset_type_id: $data['asset_type_id'],
            name: $data['name'],
            status: $data['status'],
            condition: $data['condition'],
            criticality: $data['criticality'],
            department_id: $data['department_id'] ?? null,
            location_id: $data['location_id'] ?? null,
            asset_group_id: $data['asset_group_id'] ?? null,
            asset_number: $data['asset_number'] ?? null,
            qr_uri: $data['qr_uri'] ?? null,
            serial_number: $data['serial_number'] ?? null,
            model_number: $data['model_number'] ?? null,
            manufacturer: $data['manufacturer'] ?? null,
            purchase_date: $data['purchase_date'] ?? null,
            installation_date: $data['installation_date'] ?? null,
            commissioning_date: $data['commissioning_date'] ?? null,
            disposal_date: $data['disposal_date'] ?? null,
        );
    }
}
