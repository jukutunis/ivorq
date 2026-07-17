<?php

namespace Modules\Operations\FrontDesk\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use Modules\Foundation\Property\Services\PropertyBusinessDateAuthorizationService;
use Modules\Foundation\Property\Services\PropertyBusinessDateProjectionService;
use Modules\Foundation\User\Models\User;
use RuntimeException;

class FrontDeskBusinessDateDependencyService
{
    public const VIEW_PERMISSION = PropertyBusinessDateAuthorizationService::VIEW_PERMISSION;

    public const PROJECTION_VERSION = 'FD-B11-BUSINESS-DATE-v1';
    public const BUSINESS_DATE_OPEN = 'BUSINESS_DATE_OPEN';
    public const BUSINESS_DATE_EVIDENCE_UNAVAILABLE = 'BUSINESS_DATE_EVIDENCE_UNAVAILABLE';
    public const SOURCE_CLASSIFICATION_PROVEN = 'PROPERTY_BUSINESS_DATE_SOURCE_PROVEN';
    public const SOURCE_CLASSIFICATION_UNAVAILABLE = 'PROPERTY_BUSINESS_DATE_SOURCE_UNAVAILABLE';
    public const INVALID_PROJECTION = 'FD_B11_INVALID_BUSINESS_DATE_PROJECTION';
    public const UNKNOWN_STATUS = 'FD_B11_UNKNOWN_BUSINESS_DATE_STATUS';

    private const KNOWN_UNAVAILABLE_CODES = [
        PropertyBusinessDateProjectionService::ERROR_NOT_INITIALIZED,
        PropertyBusinessDateProjectionService::ERROR_OPEN_UNAVAILABLE,
        PropertyBusinessDateProjectionService::ERROR_MULTIPLE_OPEN,
        PropertyBusinessDateProjectionService::ERROR_EVIDENCE_INCOMPLETE,
        PropertyBusinessDateProjectionService::ERROR_TIMEZONE_MISMATCH,
    ];

    public function __construct(
        private readonly PropertyBusinessDateProjectionService $projectionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function project(User $actor): array
    {
        try {
            $projection = $this->projectionService->project($actor);
        } catch (RuntimeException $exception) {
            if (in_array($exception->getMessage(), self::KNOWN_UNAVAILABLE_CODES, true)) {
                return $this->unavailable($exception->getMessage());
            }

            throw $exception;
        }

        $status = $projection['status'] ?? null;

        if ($status === self::BUSINESS_DATE_OPEN) {
            return $this->open($projection);
        }

        throw new DomainException(self::UNKNOWN_STATUS);
    }

    /**
     * @param array<string, mixed> $projection
     * @return array<string, mixed>
     */
    private function open(array $projection): array
    {
        foreach ([
            'status',
            'source_classification',
            'owner',
            'read_only',
            'property_business_date_id',
            'property_id',
            'business_date',
            'lifecycle_status',
            'property_timezone',
            'opened_at',
            'opened_by',
            'source_fingerprint',
            'evaluated_at',
        ] as $field) {
            if (! array_key_exists($field, $projection) || $projection[$field] === null || trim((string) $projection[$field]) === '') {
                throw new DomainException(self::INVALID_PROJECTION);
            }
        }

        if ($projection['status'] !== self::BUSINESS_DATE_OPEN
            || $projection['source_classification'] !== self::SOURCE_CLASSIFICATION_PROVEN
            || $projection['owner'] !== 'Business Date / Night Audit'
            || $projection['read_only'] !== true
            || $projection['lifecycle_status'] !== 'Open') {
            throw new DomainException(self::INVALID_PROJECTION);
        }

        foreach (['property_business_date_id', 'property_id', 'opened_by'] as $field) {
            if (! $this->isUlid((string) $projection[$field])) {
                throw new DomainException(self::INVALID_PROJECTION);
            }
        }

        if (! $this->isValidBusinessDate((string) $projection['business_date'])
            || ! $this->isValidTimezone((string) $projection['property_timezone'])
            || ! $this->isAbsoluteTimestamp((string) $projection['opened_at'])
            || ! $this->isAbsoluteTimestamp((string) $projection['evaluated_at'])
            || ! preg_match('/\A[0-9a-fA-F]{64}\z/', (string) $projection['source_fingerprint'])) {
            throw new DomainException(self::INVALID_PROJECTION);
        }

        return [
            'projection_version' => self::PROJECTION_VERSION,
            'status' => self::BUSINESS_DATE_OPEN,
            'source_status' => self::BUSINESS_DATE_OPEN,
            'source_classification' => self::SOURCE_CLASSIFICATION_PROVEN,
            'owner' => 'Business Date / Night Audit',
            'read_only' => true,
            'property_business_date_id' => (string) $projection['property_business_date_id'],
            'property_id' => (string) $projection['property_id'],
            'business_date' => (string) $projection['business_date'],
            'lifecycle_status' => (string) $projection['lifecycle_status'],
            'property_timezone' => (string) $projection['property_timezone'],
            'opened_at' => (string) $projection['opened_at'],
            'opened_by' => (string) $projection['opened_by'],
            'evidence_unavailable_codes' => [],
            'source_fingerprint' => (string) $projection['source_fingerprint'],
            'evaluated_at' => (string) $projection['evaluated_at'],
            'markers' => $this->markers(),
        ];
    }

    private function isUlid(string $value): bool
    {
        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) === 1;
    }

    private function isValidBusinessDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function isValidTimezone(string $value): bool
    {
        return $value !== '' && in_array($value, DateTimeZone::listIdentifiers(), true);
    }

    private function isAbsoluteTimestamp(string $value): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})\z/', $value) !== 1) {
            return false;
        }

        $timestamp = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:sP', $value);

        return $timestamp instanceof DateTimeImmutable;
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $code): array
    {
        return [
            'projection_version' => self::PROJECTION_VERSION,
            'status' => self::BUSINESS_DATE_EVIDENCE_UNAVAILABLE,
            'source_status' => $code,
            'source_classification' => self::SOURCE_CLASSIFICATION_UNAVAILABLE,
            'owner' => 'Business Date / Night Audit',
            'read_only' => true,
            'property_business_date_id' => null,
            'property_id' => null,
            'business_date' => null,
            'lifecycle_status' => null,
            'property_timezone' => null,
            'opened_at' => null,
            'opened_by' => null,
            'evidence_unavailable_codes' => [$code],
            'source_fingerprint' => null,
            'evaluated_at' => now()->toISOString(),
            'markers' => $this->markers(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function markers(): array
    {
        return [
            'source_marker' => 'Business Date evidence is sourced from BD-A1.',
            'read_only_marker' => 'Front Desk does not initialize, close, advance, reopen, or mutate Business Date.',
            'night_audit_marker' => 'FD-B11 does not run Night Audit.',
            'checkout_marker' => 'FD-B11 does not execute checkout.',
        ];
    }
}
