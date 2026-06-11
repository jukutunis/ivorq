<?php

namespace Modules\Operations\AssetManagement\Services;

use Modules\Operations\AssetManagement\Models\AssetRelationship;

class AssetRelationshipService
{
    public function createRelationship(string $propertyId, string $sourceId, string $targetId, string $type): AssetRelationship
    {
        return AssetRelationship::create([
            'property_id' => $propertyId,
            'source_asset_id' => $sourceId,
            'target_asset_id' => $targetId,
            'relationship_type' => $type,
        ]);
    }
}
