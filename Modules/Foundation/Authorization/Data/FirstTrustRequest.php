<?php

namespace Modules\Foundation\Authorization\Data;

final readonly class FirstTrustRequest
{
    public function __construct(
        public string $ownerName,
        public string $ownerEmail,
        public string $companyName,
        public string $companySlug,
        public string $propertyName,
        public string $propertySlug,
        public string $propertyCode,
        public string $propertyTimezone,
        public string $propertyCurrency,
    ) {}
}
