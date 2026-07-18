<?php

namespace Modules\Operations\NightAudit\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext;
use Modules\Operations\NightAudit\Enums\NightAuditRunStatusEnum;
use Modules\Operations\NightAudit\Models\NightAuditRun;
use Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation;
use RuntimeException;
use Throwable;

class NightAuditCheckoutConcurrencyGuardService
{
    public const ERROR_REQUIRES_ACTIVE_TRANSACTION = 'NA_A2_REQUIRES_ACTIVE_TRANSACTION';
    public const ERROR_INVALID_CONTEXT = 'NA_A2_INVALID_OPERATIONAL_LOCK_CONTEXT';
    public const ERROR_MULTIPLE_ACTIVE_RUNS = 'NA_A2_MULTIPLE_ACTIVE_RUNS';
    public const ERROR_ACTIVE_RUN_CONTEXT_CONFLICT = 'NA_A2_ACTIVE_RUN_CONTEXT_CONFLICT';

    public function attest(PropertyBusinessDateOperationalLockContext $context): NightAuditCheckoutConcurrencyAttestation
    {
        $this->assertActivePostgresTransaction();
        $this->assertSameBackend($context);

        // Future checkout must acquire its Front Desk locks between the shared
        // Property/Business Date locks and this owner-domain Night Audit step.
        $activeRuns = NightAuditRun::withoutGlobalScopes()
            ->where('property_id', $context->property_id)
            ->where('status', NightAuditRunStatusEnum::InProgress->value)
            ->lockForUpdate()
            ->orderBy('created_at')
            ->get();

        if ($activeRuns->count() > 1) {
            throw new RuntimeException(self::ERROR_MULTIPLE_ACTIVE_RUNS);
        }

        if ($activeRuns->count() === 1) {
            $run = $activeRuns->first();
            try {
                NightAuditLockProjectionService::assertRunEvidence($run, $context->toNightAuditRunEvidence());
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === NightAuditLockProjectionService::ERROR_CONTEXT_CONFLICT) {
                    throw new RuntimeException(self::ERROR_ACTIVE_RUN_CONTEXT_CONFLICT, 0, $exception);
                }

                throw $exception;
            }

            return $this->active($context, $run);
        }

        return $this->clear($context);
    }

    private function assertActivePostgresTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(self::ERROR_REQUIRES_ACTIVE_TRANSACTION);
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(self::ERROR_INVALID_CONTEXT);
        }
    }

    private function assertSameBackend(PropertyBusinessDateOperationalLockContext $context): void
    {
        if ($context->postgres_backend_pid === null) {
            return;
        }

        try {
            $current = (int) DB::selectOne('SELECT pg_backend_pid() as pid')->pid;
        } catch (Throwable) {
            throw new DomainException(self::ERROR_INVALID_CONTEXT);
        }

        if ($current !== $context->postgres_backend_pid) {
            throw new DomainException(self::ERROR_INVALID_CONTEXT);
        }
    }

    private function clear(PropertyBusinessDateOperationalLockContext $context): NightAuditCheckoutConcurrencyAttestation
    {
        return new NightAuditCheckoutConcurrencyAttestation(
            attestation_version: NightAuditCheckoutConcurrencyAttestation::VERSION,
            status: NightAuditCheckoutConcurrencyAttestation::STATUS_CLEAR,
            owner: NightAuditCheckoutConcurrencyAttestation::OWNER,
            transaction_bound: true,
            close_lock_active: false,
            property_id: $context->property_id,
            property_business_date_id: $context->property_business_date_id,
            business_date: $context->business_date,
            property_timezone: $context->property_timezone,
            source_fingerprint: $this->fingerprint($context, null),
            evaluated_at: CarbonImmutable::now('UTC')->toISOString(),
            markers: $this->markers(),
        );
    }

    private function active(PropertyBusinessDateOperationalLockContext $context, NightAuditRun $run): NightAuditCheckoutConcurrencyAttestation
    {
        return new NightAuditCheckoutConcurrencyAttestation(
            attestation_version: NightAuditCheckoutConcurrencyAttestation::VERSION,
            status: NightAuditCheckoutConcurrencyAttestation::STATUS_ACTIVE,
            owner: NightAuditCheckoutConcurrencyAttestation::OWNER,
            transaction_bound: true,
            close_lock_active: true,
            property_id: $context->property_id,
            property_business_date_id: $context->property_business_date_id,
            business_date: $context->business_date,
            property_timezone: $context->property_timezone,
            source_fingerprint: $this->fingerprint($context, $run),
            evaluated_at: CarbonImmutable::now('UTC')->toISOString(),
            markers: $this->markers(),
        );
    }

    private function fingerprint(PropertyBusinessDateOperationalLockContext $context, ?NightAuditRun $run): string
    {
        $canonical = [
            'attestation_version' => NightAuditCheckoutConcurrencyAttestation::VERSION,
            'property_id' => $context->property_id,
            'property_business_date_id' => $context->property_business_date_id,
            'business_date' => $context->business_date,
            'property_timezone' => $context->property_timezone,
            'business_date_source_fingerprint' => $context->source_fingerprint,
            'close_lock_active' => $run !== null,
        ];

        if ($run !== null) {
            $canonical['active_run'] = [
                'property_id' => (string) $run->property_id,
                'property_business_date_id' => (string) $run->property_business_date_id,
                'business_date_snapshot' => $run->business_date_snapshot?->format('Y-m-d'),
                'property_timezone_snapshot' => (string) $run->property_timezone_snapshot,
                'attempt_number' => (int) $run->attempt_number,
                'status' => $run->status->value,
                'started_at' => $run->started_at?->utc()->toISOString(),
            ];
        }

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, string>
     */
    private function markers(): array
    {
        return [
            'owner_marker' => 'Night Audit owns active close-lock evidence.',
            'transaction_marker' => 'Attestation is valid only while the caller keeps the outer transaction open.',
            'lock_order_marker' => 'Property and Property Business Date locks must already be held before Night Audit scope.',
            'checkout_marker' => 'NA-A2 does not authorize or execute checkout.',
        ];
    }
}
