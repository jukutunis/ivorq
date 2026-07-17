<?php

namespace Modules\Operations\NightAudit\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use Modules\Foundation\User\Models\User;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use RuntimeException;

class NightAuditLockProjectionService
{
    public const PROJECTION_VERSION = 'NA-A1-NIGHT-AUDIT-LOCK-v1';
    public const OWNER = 'Business Date / Night Audit';
    public const STATUS_ACTIVE = 'NIGHT_AUDIT_LOCK_ACTIVE';
    public const STATUS_CLEAR = 'NIGHT_AUDIT_LOCK_CLEAR';
    public const STATUS_UNAVAILABLE = 'NIGHT_AUDIT_LOCK_EVIDENCE_UNAVAILABLE';
    public const SOURCE_PROVEN = 'NIGHT_AUDIT_RUN_SOURCE_PROVEN';
    public const SOURCE_UNAVAILABLE = 'NIGHT_AUDIT_RUN_SOURCE_UNAVAILABLE';
    public const ERROR_MULTIPLE_ACTIVE_RUNS = 'NA_A1_MULTIPLE_ACTIVE_RUNS';
    public const ERROR_INVALID_RUN = 'NA_A1_INVALID_RUN_EVIDENCE';
    public const ERROR_CONTEXT_CONFLICT = 'NA_A1_ACTIVE_RUN_CONTEXT_CONFLICT';

    public function __construct(
        private readonly NightAuditAuthorizationService $authorization,
        private readonly NightAuditBusinessDateDependencyService $businessDateDependency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function project(User $actor): array
    {
        $property = $this->authorization->authorizeView($actor);
        $businessDate = $this->businessDateDependency->project($actor);

        if (($businessDate['status'] ?? null) === NightAuditBusinessDateDependencyService::STATUS_UNAVAILABLE) {
            return $this->whitelist([
                'projection_version' => self::PROJECTION_VERSION,
                'status' => self::STATUS_UNAVAILABLE,
                'source_status' => $businessDate['source_status'],
                'source_classification' => self::SOURCE_UNAVAILABLE,
                'owner' => self::OWNER,
                'read_only' => true,
                'close_lock_active' => null,
                'property_id' => null,
                'property_business_date_id' => null,
                'business_date' => null,
                'property_timezone' => null,
                'night_audit_run_id' => null,
                'attempt_number' => null,
                'run_status' => null,
                'started_by' => null,
                'started_at' => null,
                'evidence_unavailable_codes' => $businessDate['evidence_unavailable_codes'],
                'source_fingerprint' => null,
                'evaluated_at' => now()->toISOString(),
                'markers' => $this->markers(),
            ]);
        }

        $activeRuns = NightAuditRun::withoutGlobalScopes()
            ->where('property_id', $property->id)
            ->where('status', NightAuditRunStatusEnum::InProgress->value)
            ->orderBy('created_at')
            ->get();

        if ($activeRuns->count() > 1) {
            throw new RuntimeException(self::ERROR_MULTIPLE_ACTIVE_RUNS);
        }

        if ($activeRuns->count() === 1) {
            $run = $activeRuns->first();
            self::assertRunEvidence($run, $businessDate);
            return $this->active($businessDate, $run);
        }

        return $this->clear($businessDate);
    }

    /**
     * @param array<string, mixed> $businessDate
     * @return array<string, mixed>
     */
    private function active(array $businessDate, NightAuditRun $run): array
    {
        $businessDateSnapshot = $run->business_date_snapshot?->format('Y-m-d');
        $timezoneSnapshot = (string) $run->property_timezone_snapshot;
        $startedAt = $run->started_at?->utc()->toISOString();

        return $this->whitelist([
            'projection_version' => self::PROJECTION_VERSION,
            'status' => self::STATUS_ACTIVE,
            'source_status' => self::STATUS_ACTIVE,
            'source_classification' => self::SOURCE_PROVEN,
            'owner' => self::OWNER,
            'read_only' => true,
            'close_lock_active' => true,
            'property_id' => (string) $run->property_id,
            'property_business_date_id' => (string) $run->property_business_date_id,
            'business_date' => $businessDateSnapshot,
            'property_timezone' => $timezoneSnapshot,
            'night_audit_run_id' => (string) $run->id,
            'attempt_number' => (int) $run->attempt_number,
            'run_status' => $run->status->value,
            'started_by' => (string) $run->started_by,
            'started_at' => $startedAt,
            'evidence_unavailable_codes' => [],
            'source_fingerprint' => $this->fingerprint([
                'property_id' => (string) $run->property_id,
                'property_business_date_id' => (string) $run->property_business_date_id,
                'business_date' => $businessDateSnapshot,
                'property_timezone' => $timezoneSnapshot,
                'night_audit_run_id' => (string) $run->id,
                'attempt_number' => (int) $run->attempt_number,
                'run_status' => $run->status->value,
                'started_by' => (string) $run->started_by,
                'started_at' => $startedAt,
                'close_lock_active' => true,
            ]),
            'evaluated_at' => now()->toISOString(),
            'markers' => $this->markers(),
        ]);
    }

    /**
     * @param array<string, mixed> $businessDate
     * @return array<string, mixed>
     */
    private function clear(array $businessDate): array
    {
        return $this->whitelist([
            'projection_version' => self::PROJECTION_VERSION,
            'status' => self::STATUS_CLEAR,
            'source_status' => self::STATUS_CLEAR,
            'source_classification' => self::SOURCE_PROVEN,
            'owner' => self::OWNER,
            'read_only' => true,
            'close_lock_active' => false,
            'property_id' => $businessDate['property_id'],
            'property_business_date_id' => $businessDate['property_business_date_id'],
            'business_date' => $businessDate['business_date'],
            'property_timezone' => $businessDate['property_timezone'],
            'night_audit_run_id' => null,
            'attempt_number' => null,
            'run_status' => null,
            'started_by' => null,
            'started_at' => null,
            'evidence_unavailable_codes' => [],
            'source_fingerprint' => $this->fingerprint([
                'property_id' => $businessDate['property_id'],
                'property_business_date_id' => $businessDate['property_business_date_id'],
                'business_date' => $businessDate['business_date'],
                'property_timezone' => $businessDate['property_timezone'],
                'close_lock_active' => false,
            ]),
            'evaluated_at' => now()->toISOString(),
            'markers' => $this->markers(),
        ]);
    }

    /**
     * @param array<string, mixed> $businessDate
     */
    public static function assertRunEvidence(NightAuditRun $run, array $businessDate): void
    {
        $businessDateSnapshot = $run->business_date_snapshot?->format('Y-m-d');
        $timezoneSnapshot = (string) $run->property_timezone_snapshot;

        if (! self::isUlid((string) $run->id)
            || ! self::isUlid((string) $run->property_id)
            || ! self::isUlid((string) $run->property_business_date_id)
            || ! self::isBusinessDate((string) $businessDateSnapshot)
            || ! self::isTimezone($timezoneSnapshot)
            || $run->status !== NightAuditRunStatusEnum::InProgress
            || (int) $run->attempt_number < 1
            || ! self::isUlid((string) $run->started_by)
            || ! self::isAbsoluteTimestamp($run->started_at?->utc()->toISOString())
            || ! self::isUlid((string) $run->created_by)
            || ! self::isAbsoluteTimestamp($run->created_at?->utc()->toISOString())
            || $run->aborted_by !== null
            || $run->aborted_at !== null
            || $run->abort_reason !== null) {
            throw new DomainException(self::ERROR_INVALID_RUN);
        }

        if ((string) $run->property_id !== (string) ($businessDate['property_id'] ?? '')
            || (string) $run->property_business_date_id !== (string) ($businessDate['property_business_date_id'] ?? '')) {
            throw new RuntimeException(self::ERROR_CONTEXT_CONFLICT);
        }

        if ($businessDateSnapshot !== (string) ($businessDate['business_date'] ?? '')
            || $timezoneSnapshot !== (string) ($businessDate['property_timezone'] ?? '')) {
            throw new DomainException(self::ERROR_INVALID_RUN);
        }
    }

    /**
     * @param array<string, mixed> $canonical
     */
    private function fingerprint(array $canonical): string
    {
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $projection
     * @return array<string, mixed>
     */
    private function whitelist(array $projection): array
    {
        $keys = [
            'projection_version',
            'status',
            'source_status',
            'source_classification',
            'owner',
            'read_only',
            'close_lock_active',
            'property_id',
            'property_business_date_id',
            'business_date',
            'property_timezone',
            'night_audit_run_id',
            'attempt_number',
            'run_status',
            'started_at',
            'started_by',
            'evidence_unavailable_codes',
            'source_fingerprint',
            'evaluated_at',
            'markers',
        ];

        return array_replace(array_fill_keys($keys, null), array_intersect_key($projection, array_flip($keys)));
    }

    private static function isUlid(string $value): bool
    {
        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) === 1;
    }

    private static function isBusinessDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && $date->format('Y-m-d') === $value
            && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0));
    }

    private static function isTimezone(string $value): bool
    {
        return $value !== '' && in_array($value, DateTimeZone::listIdentifiers(), true);
    }

    private static function isAbsoluteTimestamp(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        foreach ([
            'Y-m-d\TH:i:s.u\Z',
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:s.uP',
            DateTimeInterface::ATOM,
            'Y-m-d\TH:i:s.uO',
            'Y-m-d\TH:i:sO',
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

    /**
     * @return array<string, string>
     */
    private function markers(): array
    {
        return [
            'ownership_marker' => 'Night Audit owns run and close-lock evidence.',
            'business_date_marker' => 'NA-A1 does not close or advance Business Date.',
            'checkpoint_marker' => 'NA-A1 does not run checkpoint orchestration.',
            'source_domain_marker' => 'Source domains remain owners of their own outcomes.',
            'front_desk_marker' => 'Front Desk does not consume this source until FD-B12.',
            'checkout_marker' => 'Checkout execution is not authorized.',
        ];
    }
}
