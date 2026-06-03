<?php

namespace Modules\Foundation\Property\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Foundation\Property\Models\PropertySetting;

class PropertySettingRepository
{
    public function allForProperty(string $propertyId): Collection
    {
        return PropertySetting::where('property_id', $propertyId)->get();
    }

    public function get(string $propertyId, string $group, string $key): ?PropertySetting
    {
        return PropertySetting::where('property_id', $propertyId)
            ->where('group', $group)
            ->where('key', $key)
            ->first();
    }

    public function set(string $propertyId, string $group, string $key, ?string $value): PropertySetting
    {
        return PropertySetting::updateOrCreate(
            ['property_id' => $propertyId, 'group' => $group, 'key' => $key],
            ['value' => $value]
        );
    }

    public function getGroup(string $propertyId, string $group): Collection
    {
        return PropertySetting::where('property_id', $propertyId)
            ->where('group', $group)
            ->get();
    }

    public function delete(string $propertyId, string $group, string $key): bool
    {
        return PropertySetting::where('property_id', $propertyId)
            ->where('group', $group)
            ->where('key', $key)
            ->delete() > 0;
    }
}
