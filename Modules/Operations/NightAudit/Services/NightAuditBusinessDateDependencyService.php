<?php

namespace Modules\Operations\NightAudit\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use Modules\Foundation\Property\Services\PropertyBusinessDateProjectionService;
use Modules\Foundation\User\Models\User;
use RuntimeException;

class NightAuditBusinessDateDependencyService
{
    public const STATUS_OPEN = 'BUSINESS_DATE_OPEN';
    public const STATUS_UNAVAILABLE = 'NIGHT_AUDIT_BUSINESS_DATE_EVIDENCE_UNAVAILABLE';
    public const SOURCE_CLASSIFICATION_PROVEN = 'PROPERTY_BUSINESS_DATE_SOURCE_PROVEN';
    public const SOURCE_CLASSIFICATION_UNAVAILABLE = 'PROPERTY_BUSINESS_DATE_SOURCE_UNAVAILABLE';
    public const INVALID_PROJECTION = 'NA_A1_INVALID_BUSINESS_DATE_PROJECTION';

    private const KNOWN_UNAVAILABLE_CODES = [
        PropertyBusinessDateProjectionService::ERROR_NOT_INITIALIZED,
        PropertyBusinessDateProjectionService::ERROR_OPEN_UNAVAILABLE,
        PropertyBusinessDateProjectionService::ERROR_MULTIPLE_OPEN,
        PropertyBusinessDateProjectionService::ERROR_EVIDENCE_INCOMPLETE,
        PropertyBusinessDateProjectionService::ERROR_TIMEZONE_MISMATCH,
    ];

    public function __construct(
        private readonly PropertyBusinessDateProjectionService $projectionService,
        private readonly NightAuditAuthorizationService $authorization,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function project(User $actor): array
    {
        $authorizedProperty = $this->authorization->authorizeView($actor);

        try {
            $projection = $this->projectionService->project($actor);
        } catch (RuntimeException $exception) {
            if (in_array($exception->getMessage(), self::KNOWN_UNAVAILABLE_CODES, true)) {
                return $this->unavailable($exception->getMessage());
            }

            throw $exception;
        }

        $this->assertOpenProjection($projection);

        if ((string) $projection['property_id'] !== (string) $authorizedProperty->id) {
            throw new DomainException(self::INVALID_PROJECTION);
        }

        return [
            'status' => self::STATUS_OPEN,
            'source_status' => self::STATUS_OPEN,
            'source_classification' => self::SOURCE_CLASSIFICATION_PROVEN,
            'owner' => 'Business Date / Night Audit',
            'read_only' => true,
            'property_business_date_id' => (string) $projection['property_business_date_id'],
            'property_id' => (string) $projection['property_id'],
            'business_date' => (string) $projection['business_date'],
            'lifecycle_status' => 'Open',
            'property_timezone' => (string) $projection['property_timezone'],
            'opened_at' => (string) $projection['opened_at'],
            'opened_by' => (string) $projection['opened_by'],
            'evidence_unavailable_codes' => [],
            'source_fingerprint' => (string) $projection['source_fingerprint'],
            'evaluated_at' => (string) $projection['evaluated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $projection
     */
    private function assertOpenProjection(array $projection): void
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

        if ($projection['status'] !== self::STATUS_OPEN
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

        if (! $this->isBusinessDate((string) $projection['business_date'])
            || ! $this->isTimezone((string) $projection['property_timezone'])
            || ! $this->isAbsoluteTimestamp((string) $projection['opened_at'])
            || ! $this->isAbsoluteTimestamp((string) $projection['evaluated_at'])
            || ! preg_match('/\A[0-9a-f]{64}\z/i', (string) $projection['source_fingerprint'])) {
            throw new DomainException(self::INVALID_PROJECTION);
        }
    }

    private function unavailable(string $code): array
    {
        return [
            'status' => self::STATUS_UNAVAILABLE,
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
        ];
    }

    private function isUlid(string $value): bool
    {
        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) === 1;
    }

    private function isBusinessDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && $date->format('Y-m-d') === $value
            && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0));
    }

    private function isTimezone(string $value): bool
    {
        return $value !== '' && in_array($value, DateTimeZone::listIdentifiers(), true);
    }

    private function isAbsoluteTimestamp(string $value): bool
    {
        foreach ([
            'Y-m-d\TH:i:s.u\Z',
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:s.uP',
            DateTimeInterface::ATOM,
            'Y-m-d\TH:i:s.uO',
            'Y-m-d\TH:i:sO',
            'Y-m-d H:i:s.uP',
            'Y-m-d H:i:sP',
            'Y-m-d H:i:s.uO',
            'Y-m-d H:i:sO',
        ] as $format) {
            $timestamp = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($timestamp instanceof DateTimeImmutable
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
                && $timestamp->format($format) === $value) {
                return true;
            }
        }

        return false;
    }
}
