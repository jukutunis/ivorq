<?php

namespace Modules\Operations\AssetManagement\DTOs;

class AssetHierarchyDTO
{
    public function __construct(
        public readonly string $property_id,
        public readonly string $ancestor_id,
        public readonly string $descendant_id,
        public readonly int $depth
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            property_id: $data['property_id'],
            ancestor_id: $data['ancestor_id'],
            descendant_id: $data['descendant_id'],
            depth: $data['depth'] ?? 1
        );
    }
}
