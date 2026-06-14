<?php

namespace Modules\Finance\GeneralLedger\Repositories;

use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Illuminate\Database\Eloquent\Collection;

class OperationalIdentityMappingRepository
{
    public function findById(string $id): ?OperationalIdentityMapping
    {
        return OperationalIdentityMapping::find($id);
    }

    public function create(array $data): OperationalIdentityMapping
    {
        return OperationalIdentityMapping::create($data);
    }

    public function update(string $id, array $data): OperationalIdentityMapping
    {
        $mapping = OperationalIdentityMapping::findOrFail($id);
        $mapping->update($data);
        return $mapping;
    }

    public function softDelete(string $id): void
    {
        $mapping = OperationalIdentityMapping::findOrFail($id);
        $mapping->delete();
    }

    public function findActiveMappings(string $propertyId): Collection
    {
        return OperationalIdentityMapping::where('property_id', $propertyId)
            ->where('is_active', true)
            ->get();
    }
}
