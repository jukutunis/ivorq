<?php

namespace Modules\Operations\PMS\Repositories\Concerns;

use Shared\Services\CurrentPropertyService;

trait ScopedPmsRepository
{
    private function propertyId(): string
    {
        $propertyId = app(CurrentPropertyService::class)->getExplicitPropertyId();
        abort_unless(is_string($propertyId) && $propertyId !== '', 403);

        return $propertyId;
    }
}
