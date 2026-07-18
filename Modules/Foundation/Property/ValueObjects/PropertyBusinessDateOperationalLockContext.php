<?php

namespace Modules\Foundation\Property\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class PropertyBusinessDateOperationalLockContext
{
    public function __construct(
        public string $company_id,
        public string $property_id,
        public string $property_business_date_id,
        public string $business_date,
        public string $property_timezone,
        public string $opened_by,
        public string $opened_at,
        public string $source_fingerprint,
        public ?int $postgres_backend_pid,
        public string $lock_acquired_at,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toNightAuditRunEvidence(): array
    {
        return [
            'status' => 'BUSINESS_DATE_OPEN',
            'source_status' => 'BUSINESS_DATE_OPEN',
            'source_classification' => 'PROPERTY_BUSINESS_DATE_SOURCE_PROVEN',
            'owner' => 'Business Date / Night Audit',
            'read_only' => true,
            'property_business_date_id' => $this->property_business_date_id,
            'property_id' => $this->property_id,
            'business_date' => $this->business_date,
            'lifecycle_status' => 'Open',
            'property_timezone' => $this->property_timezone,
            'opened_at' => $this->opened_at,
            'opened_by' => $this->opened_by,
            'evidence_unavailable_codes' => [],
            'source_fingerprint' => $this->source_fingerprint,
            'evaluated_at' => CarbonImmutable::now('UTC')->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'company_id' => $this->company_id,
            'property_id' => $this->property_id,
            'property_business_date_id' => $this->property_business_date_id,
            'business_date' => $this->business_date,
            'property_timezone' => $this->property_timezone,
            'opened_by' => $this->opened_by,
            'opened_at' => $this->opened_at,
            'source_fingerprint' => $this->source_fingerprint,
            'postgres_backend_pid' => $this->postgres_backend_pid,
            'lock_acquired_at' => $this->lock_acquired_at,
        ];
    }
}
