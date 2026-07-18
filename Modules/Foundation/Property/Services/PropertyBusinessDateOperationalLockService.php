<?php

namespace Modules\Foundation\Property\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext;
use RuntimeException;
use Throwable;

class PropertyBusinessDateOperationalLockService
{
    public const ERROR_REQUIRES_ACTIVE_TRANSACTION = 'NA_A2_REQUIRES_ACTIVE_TRANSACTION';
    public const ERROR_POSTGRESQL_REQUIRED = 'NA_A2_POSTGRESQL_REQUIRED';
    public const ERROR_PROPERTY_LOCK_UNAVAILABLE = 'NA_A2_PROPERTY_LOCK_UNAVAILABLE';
    public const ERROR_BUSINESS_DATE_LOCK_UNAVAILABLE = 'NA_A2_BUSINESS_DATE_LOCK_UNAVAILABLE';
    public const ERROR_CONTEXT_CHANGED = 'NA_A2_PROPERTY_BUSINESS_DATE_CONTEXT_CHANGED';
    public const ERROR_OPERATIONAL_LOCK_TIMEOUT = 'NA_A2_OPERATIONAL_LOCK_TIMEOUT';

    private const LOCK_TIMEOUT = '5s';
    private const LOCK_TIMEOUT_SQLSTATE = '55P03';

    /**
     * @param array<string, mixed> $expectedEvidence
     */
    public function acquire(string $companyId, string $propertyId, array $expectedEvidence): PropertyBusinessDateOperationalLockContext
    {
        $this->assertParticipatingPostgresTransaction();
        DB::statement("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");

        try {
            $property = DB::table('properties')
                ->where('id', $propertyId)
                ->lockForUpdate()
                ->first();

            if (! $property) {
                throw new RuntimeException(self::ERROR_PROPERTY_LOCK_UNAVAILABLE);
            }

            if (! $this->isTrue($property->is_active)
                || (string) $property->company_id !== $companyId
                || trim((string) $property->timezone) === '') {
                throw new DomainException(self::ERROR_CONTEXT_CHANGED);
            }

            $businessDate = PropertyBusinessDate::withoutGlobalScopes()
                ->whereKey((string) ($expectedEvidence['property_business_date_id'] ?? ''))
                ->where('property_id', $propertyId)
                ->lockForUpdate()
                ->first();

            if (! $businessDate) {
                throw new RuntimeException(self::ERROR_BUSINESS_DATE_LOCK_UNAVAILABLE);
            }

            $this->assertLockedBusinessDate($businessDate, $property, $expectedEvidence);

            $openedAt = $businessDate->opened_at?->utc()->toISOString();
            if (! is_string($openedAt) || trim($openedAt) === '') {
                throw new DomainException(self::ERROR_CONTEXT_CHANGED);
            }

            return new PropertyBusinessDateOperationalLockContext(
                company_id: $companyId,
                property_id: (string) $property->id,
                property_business_date_id: (string) $businessDate->id,
                business_date: $businessDate->business_date->format('Y-m-d'),
                property_timezone: (string) $businessDate->timezone_snapshot,
                opened_by: (string) $businessDate->opened_by,
                opened_at: $openedAt,
                source_fingerprint: $this->fingerprint($businessDate, $openedAt),
                postgres_backend_pid: $this->postgresBackendPid(),
                lock_acquired_at: CarbonImmutable::now('UTC')->toISOString(),
            );
        } catch (QueryException $exception) {
            if ($this->sqlState($exception) === self::LOCK_TIMEOUT_SQLSTATE) {
                throw new RuntimeException(self::ERROR_OPERATIONAL_LOCK_TIMEOUT, 0, $exception);
            }

            throw $exception;
        }
    }

    private function assertParticipatingPostgresTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(self::ERROR_REQUIRES_ACTIVE_TRANSACTION);
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(self::ERROR_POSTGRESQL_REQUIRED);
        }
    }

    /**
     * @param object $property
     * @param array<string, mixed> $expectedEvidence
     */
    private function assertLockedBusinessDate(PropertyBusinessDate $businessDate, object $property, array $expectedEvidence): void
    {
        $openedAt = $businessDate->opened_at?->utc()->toISOString();

        if ((string) $businessDate->property_id !== (string) $property->id
            || $businessDate->status !== PropertyBusinessDateStatusEnum::Open
            || $businessDate->is_open !== true
            || $businessDate->business_date?->format('Y-m-d') !== (string) ($expectedEvidence['business_date'] ?? '')
            || (string) $businessDate->timezone_snapshot !== (string) ($expectedEvidence['property_timezone'] ?? '')
            || (string) $property->timezone !== (string) $businessDate->timezone_snapshot
            || (string) $businessDate->opened_by !== (string) ($expectedEvidence['opened_by'] ?? '')
            || $openedAt !== (string) ($expectedEvidence['opened_at'] ?? '')) {
            throw new DomainException(self::ERROR_CONTEXT_CHANGED);
        }

        if ((string) $businessDate->id !== (string) ($expectedEvidence['property_business_date_id'] ?? '')
            || (string) $businessDate->property_id !== (string) ($expectedEvidence['property_id'] ?? '')) {
            throw new DomainException(self::ERROR_CONTEXT_CHANGED);
        }
    }

    private function fingerprint(PropertyBusinessDate $businessDate, string $openedAt): string
    {
        return hash('sha256', json_encode([
            'property_business_date_id' => (string) $businessDate->id,
            'property_id' => (string) $businessDate->property_id,
            'business_date' => $businessDate->business_date->format('Y-m-d'),
            'lifecycle_status' => $businessDate->status->value,
            'is_open' => $businessDate->is_open === true,
            'timezone_snapshot' => (string) $businessDate->timezone_snapshot,
            'opened_by' => (string) $businessDate->opened_by,
            'opened_at' => $openedAt,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function postgresBackendPid(): ?int
    {
        try {
            return (int) DB::selectOne('SELECT pg_backend_pid() as pid')->pid;
        } catch (Throwable) {
            return null;
        }
    }

    private function sqlState(Throwable $exception): ?string
    {
        if (isset($exception->errorInfo) && is_array($exception->errorInfo) && isset($exception->errorInfo[0])) {
            return (string) $exception->errorInfo[0];
        }

        return (string) $exception->getCode() ?: null;
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
