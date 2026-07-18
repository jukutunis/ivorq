<?php

namespace Modules\Operations\NightAudit\ValueObjects;

final readonly class NightAuditCheckoutConcurrencyAttestation
{
    public const VERSION = 'NA-A2-CHECKOUT-CONCURRENCY-v1';
    public const OWNER = 'Business Date / Night Audit';
    public const STATUS_CLEAR = 'NIGHT_AUDIT_LOCK_CLEAR';
    public const STATUS_ACTIVE = 'NIGHT_AUDIT_LOCK_ACTIVE';

    /**
     * @param array<string, string> $markers
     */
    public function __construct(
        public string $attestation_version,
        public string $status,
        public string $owner,
        public bool $transaction_bound,
        public bool $close_lock_active,
        public string $property_id,
        public string $property_business_date_id,
        public string $business_date,
        public string $property_timezone,
        public string $source_fingerprint,
        public string $evaluated_at,
        public array $markers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attestation_version' => $this->attestation_version,
            'status' => $this->status,
            'owner' => $this->owner,
            'transaction_bound' => $this->transaction_bound,
            'close_lock_active' => $this->close_lock_active,
            'property_id' => $this->property_id,
            'property_business_date_id' => $this->property_business_date_id,
            'business_date' => $this->business_date,
            'property_timezone' => $this->property_timezone,
            'source_fingerprint' => $this->source_fingerprint,
            'evaluated_at' => $this->evaluated_at,
            'markers' => $this->markers,
        ];
    }
}
