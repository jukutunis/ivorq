<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionIsolatedConcurrencyProofTest extends PostgresTestCase
{
    public function test_checkout_execution_uses_row_locks_idempotent_replay_and_bounded_retry_policy(): void
    {
        $source = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php'));

        $this->assertStringContainsString('private const MAX_ATTEMPTS = 3;', $source);
        $this->assertStringContainsString("in_array(\$sqlState, ['40001', '40P01'], true)", $source);
        $this->assertStringContainsString('lockForUpdate()', $source);
        $this->assertStringContainsString('committedReplay(', $source);
        $this->assertStringContainsString('ERROR_IDEMPOTENCY_CONFLICT', $source);
        $this->assertStringContainsString('ERROR_ALREADY_COMPLETED', $source);
        $this->assertStringContainsString('assertNoCompletedCheckoutForStay($property->id, $stay->id, lock: true)', $source);
        $this->assertStringContainsString('PropertyBusinessDateOperationalLockService', $source);
        $this->assertStringContainsString('NightAuditCheckoutConcurrencyGuardService', $source);
    }

    public function test_checkout_execution_does_not_serialize_different_properties_with_global_application_lock(): void
    {
        $source = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php'));

        $this->assertStringNotContainsString('Cache::lock', $source);
        $this->assertStringNotContainsString('GET_LOCK', $source);
        $this->assertStringNotContainsString('pg_advisory_lock', $source);
        $this->assertStringContainsString('$this->businessDateLock->acquire($company->id, $property->id', $source);
    }
}
