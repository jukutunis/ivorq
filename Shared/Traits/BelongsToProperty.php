<?php

namespace Shared\Traits;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToProperty
{
    protected static function bootBelongsToProperty(): void
    {
        static::creating(function ($model) {
            if (empty($model->property_id)) {
                $model->property_id = app(\Shared\Services\CurrentPropertyService::class)->resolveOrFail();
            }
        });

        static::addGlobalScope('property', function (Builder $query) {
            $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getId();
            if ($propertyId) {
                $query->where($query->getModel()->getTable() . '.property_id', $propertyId);
            }
        });
    }

    public function scopeForProperty(Builder $query, string $propertyId): Builder
    {
        return $query->withoutGlobalScope('property')
            ->where($this->getTable() . '.property_id', $propertyId);
    }
}
