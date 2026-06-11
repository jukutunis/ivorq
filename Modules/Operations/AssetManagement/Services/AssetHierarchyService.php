<?php

namespace Modules\Operations\AssetManagement\Services;

use Modules\Operations\AssetManagement\Models\AssetHierarchy;
use Modules\Operations\AssetManagement\DTOs\AssetHierarchyDTO;

class AssetHierarchyService
{
    public function link(AssetHierarchyDTO $dto): AssetHierarchy
    {
        return AssetHierarchy::create([
            'property_id' => $dto->property_id,
            'ancestor_id' => $dto->ancestor_id,
            'descendant_id' => $dto->descendant_id,
            'depth' => $dto->depth,
        ]);
    }

    public function unlink(string $ancestorId, string $descendantId): void
    {
        AssetHierarchy::where('ancestor_id', $ancestorId)
            ->where('descendant_id', $descendantId)
            ->delete();
    }
}
