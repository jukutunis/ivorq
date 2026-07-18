<?php

namespace Modules\Operations\FrontDesk\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use Modules\Foundation\Property\Services\PropertyBusinessDateProjectionService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Services\NightAuditAuthorizationService;
use Modules\Operations\NightAudit\Services\NightAuditLockProjectionService;

class FrontDeskNightAuditLockDependencyService
{
    public const VIEW_PERMISSION = NightAuditAuthorizationService::VIEW_PERMISSION;

    public const PROJECTION_VERSION = 'FD-B12-NIGHT-AUDIT-LOCK-v1';
    public const SOURCE_PROJECTION_VERSION = 'NA-A1-NIGHT-AUDIT-LOCK-v1';
    public const STATUS_ACTIVE = 'NIGHT_AUDIT_LOCK_ACTIVE';
    public const STATUS_CLEAR = 'NIGHT_AUDIT_LOCK_CLEAR';
    public const STATUS_UNAVAILABLE = 'NIGHT_AUDIT_LOCK_EVIDENCE_UNAVAILABLE';
    public const SOURCE_PROVEN = 'NIGHT_AUDIT_RUN_SOURCE_PROVEN';
    public const SOURCE_UNAVAILABLE = 'NIGHT_AUDIT_RUN_SOURCE_UNAVAILABLE';
    public const INVALID_PROJECTION = 'FD_B12_INVALID_NIGHT_AUDIT_LOCK_PROJECTION';
    public const UNKNOWN_STATUS = 'FD_B12_UNKNOWN_NIGHT_AUDIT_LOCK_STATUS';

    private const KNOWN_UNAVAILABLE_CODES = [
        PropertyBusinessDateProjectionService::ERROR_NOT_INITIALIZED,
        PropertyBusinessDateProjectionService::ERROR_OPEN_UNAVAILABLE,
        PropertyBusinessDateProjectionService::ERROR_MULTIPLE_OPEN,
        PropertyBusinessDateProjectionService::ERROR_EVIDENCE_INCOMPLETE,
        PropertyBusinessDateProjectionService::ERROR_TIMEZONE_MISMATCH,
    ];

    public function __construct(
        private readonly NightAuditLockProjectionService $projectionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function project(User $actor): array
    {
        $projection = $this->projectionService->project($actor);
        $this->assertCommonProjection($projection);

        return match ($projection['status']) {
            self::STATUS_ACTIVE => $this->active($projection),
            self::STATUS_CLEAR => $this->clear($projection),
            self::STATUS_UNAVAILABLE => $this->unavailable($projection),
            default => throw new DomainException(self::UNKNOWN_STATUS),
        };
    }

    /**
     * @param array<string, mixed> $projection
     */
    private function assertCommonProjection(array $projection): void
    {
        foreach ([
            'projection_version',
            'status',
            'source_status',
            'source_classification',
            'owner',
            'read_only',
            'close_lock_active',
            'evidence_unavailable_codes',
            'evaluated_at',
            'markers',
        ] as $field) {
            if (! array_key_exists($field, $projection)) {
                throw new DomainException(self::INVALID_PROJECTION);
            }
        }

        if ($projection['projection_version'] !== self::SOURCE_PROJECTION_VERSION
            || $projection['owner'] !== 'Business Date / Night Audit'
            || $projection['read_only'] !== true
            || ! $this->isAbsoluteTimestamp((string) $projection['evaluated_at'])
            || ! is_array($projection['evidence_unavailable_codes'])
            || ! is_array($projection['markers'])) {
            throw new DomainException(self::INVALID_PROJECTION);
        }

        if (! in_array($projection['source_classification'], [self::SOURCE_PROVEN, self::SOURCE_UNAVAILABLE], true)) {
            throw new DomainException(self::INVALID_PROJECTION);
        }

        if (! in_array($projection['status'], [self::STATUS_ACTIVE, self::STATUS_CLEAR, self::STATUS_UNAVAILABLE], true)) {
            throw new DomainException(self::UNKNOWN_STATUS);
        }
    }

    /**
     * @param array<string, mixed> $projection
     * @return array<string, mixed>
     */
    private function active(array $projection): array
    {
        $this->requireValues($projection, [
            'property_id',
            'property_business_date_id',
            'business_date',
            'property_timezone',
            'night_audit_run_id',
            'attempt_number',
            'run_status',
            'started_at',
            'started_by',
            'source_fingerprint',
        ]);

        if ($projection['source_status'] !== self::STATUS_ACTIVE
            || $projection['source_classification'] !== self::SOURCE_PROVEN
            || $projection['close_lock_active'] !== true
            || $projection['run_status'] !== 'IN_PROGRESS'
            || $projection['evidence_unavailable_codes'] !== []
            || ! $this->isUlid((string) $projection['property_id'])
            || ! $this->isUlid((string) $projection['property_business_date_id'])
            || ! $this->isUlid((string) $projection['night_audit_run_id'])
            || ! $this->isUlid((string) $projection['started_by'])
            || (int) $projection['attempt_number'] < 1
            || ! $this->isBusinessDate((string) $projection['business_date'])
            || ! $this->isTimezone((string) $projection['property_timezone'])
            || ! $this->isAbsoluteTimestamp((string) $projection['started_at'])
            || ! $this->isSha256((string) $projection['source_fingerprint'])) {
            throw new DomainException(self::INVALID_PROJECTION);
        }

        return $this->whitelist($projection);
    }

    /**
     * @param array<string, mixed> $projection
     * @return array<string, mixed>
     */
    private function clear(array $projection): array
    {
        $this->requireValues($projection, [
            'property_id',
            'property_business_date_id',
            'business_date',
            'property_timezone',
            'source_fingerprint',
        ]);

        foreach (['night_audit_run_id', 'attempt_number', 'run_status', 'started_at', 'started_by'] as $field) {
            if (($projection[$field] ?? null) !== null) {
                throw new DomainException(self::INVALID_PROJECTION);
            }
        }

        if ($projection['source_status'] !== self::STATUS_CLEAR
            || $projection['source_classification'] !== self::SOURCE_PROVEN
            || $projection['close_lock_active'] !== false
            || $projection['evidence_unavailable_codes'] !== []
            || ! $this->isUlid((string) $projection['property_id'])
            || ! $this->isUlid((string) $projection['property_business_date_id'])
            || ! $this->isBusinessDate((string) $projection['business_date'])
            || ! $this->isTimezone((string) $projection['property_timezone'])
            || ! $this->isSha256((string) $projection['source_fingerprint'])) {
            throw new DomainException(self::INVALID_PROJECTION);
        }

        return $this->whitelist($projection);
    }

    /**
     * @param array<string, mixed> $projection
     * @return array<string, mixed>
     */
    private function unavailable(array $projection): array
    {
        if ($projection['source_classification'] !== self::SOURCE_UNAVAILABLE
            || $projection['close_lock_active'] !== null
            || $projection['source_fingerprint'] !== null
            || ! in_array($projection['source_status'], self::KNOWN_UNAVAILABLE_CODES, true)
            || $projection['evidence_unavailable_codes'] !== [$projection['source_status']]) {
            throw new DomainException(self::INVALID_PROJECTION);
        }

        foreach ([
            'property_id',
            'property_business_date_id',
            'business_date',
            'property_timezone',
            'night_audit_run_id',
            'attempt_number',
            'run_status',
            'started_at',
            'started_by',
        ] as $field) {
            if (($projection[$field] ?? null) !== null) {
                throw new DomainException(self::INVALID_PROJECTION);
            }
        }

        return $this->whitelist($projection);
    }

    /**
     * @param array<string, mixed> $projection
     * @param string[] $fields
     */
    private function requireValues(array $projection, array $fields): void
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $projection) || $projection[$field] === null || trim((string) $projection[$field]) === '') {
                throw new DomainException(self::INVALID_PROJECTION);
            }
        }
    }

    /**
     * @param array<string, mixed> $projection
     * @return array<string, mixed>
     */
    private function whitelist(array $projection): array
    {
        return [
            'projection_version' => self::PROJECTION_VERSION,
            'source_projection_version' => self::SOURCE_PROJECTION_VERSION,
            'status' => $projection['status'],
            'source_status' => $projection['source_status'],
            'source_classification' => $projection['source_classification'],
            'owner' => 'Business Date / Night Audit',
            'read_only' => true,
            'close_lock_active' => $projection['close_lock_active'],
            'property_id' => $projection['property_id'],
            'property_business_date_id' => $projection['property_business_date_id'],
            'business_date' => $projection['business_date'],
            'property_timezone' => $projection['property_timezone'],
            'night_audit_run_id' => $projection['night_audit_run_id'],
            'attempt_number' => $projection['attempt_number'],
            'run_status' => $projection['run_status'],
            'started_at' => $projection['started_at'],
            'started_by' => $projection['started_by'],
            'evidence_unavailable_codes' => $projection['evidence_unavailable_codes'],
            'source_fingerprint' => $projection['source_fingerprint'],
            'evaluated_at' => $projection['evaluated_at'],
            'markers' => $this->markers(),
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
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/' => 'Y-m-d\TH:i:s.u\Z',
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/' => 'Y-m-d\TH:i:s\Z',
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}\z/' => 'Y-m-d\TH:i:s.uP',
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\z/' => DateTimeInterface::ATOM,
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{4}\z/' => 'Y-m-d\TH:i:s.uO',
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{4}\z/' => 'Y-m-d\TH:i:sO',
        ] as $shape => $format) {
            if (preg_match($shape, $value) !== 1) {
                continue;
            }

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

    private function isSha256(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/i', $value) === 1;
    }

    /**
     * @return array<string, string>
     */
    private function markers(): array
    {
        return [
            'source_marker' => 'Front Desk consumes accepted NA-A1 Night Audit lock projection.',
            'ownership_marker' => 'Night Audit owns run and close-lock evidence.',
            'read_only_marker' => 'Front Desk consumes Night Audit evidence read-only.',
            'business_date_marker' => 'Front Desk does not initialize, close, advance, or reopen Business Date.',
            'checkpoint_marker' => 'Front Desk does not run checkpoints, start, or abort Night Audit.',
            'checkout_marker' => 'FD-B12 does not execute checkout.',
        ];
    }
}
