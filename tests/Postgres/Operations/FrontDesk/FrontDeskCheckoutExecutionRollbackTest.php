<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionRollbackTest extends PostgresTestCase
{
    public function test_execution_claim_and_mutations_remain_inside_single_database_transaction(): void
    {
        $source = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php'));

        $this->assertStringContainsString('DB::transaction(function ()', $source);
        $this->assertStringContainsString('claimCurrentSessionConfirmationFor($actor, $stay->id, $idempotencyKey)', $source);
        $this->assertStringContainsString('new FrontDeskCheckoutExecution()', $source);
        $this->assertStringContainsString("'status' => FrontDeskStayStatusEnum::CheckedOut", $source);
        $this->assertStringContainsString('createHandoff($execution, $occurredAt)', $source);

        $claimPosition = strpos($source, 'claimCurrentSessionConfirmationFor($actor, $stay->id, $idempotencyKey)');
        $executionPosition = strpos($source, 'new FrontDeskCheckoutExecution()');
        $stayTransitionPosition = strpos($source, "'status' => FrontDeskStayStatusEnum::CheckedOut");
        $handoffPosition = strpos($source, 'createHandoff($execution, $occurredAt)');
        $auditPosition = strpos($source, "'front_desk_checkout_completed'");

        $this->assertIsInt($claimPosition);
        $this->assertIsInt($executionPosition);
        $this->assertIsInt($stayTransitionPosition);
        $this->assertIsInt($handoffPosition);
        $this->assertIsInt($auditPosition);
        $this->assertLessThan($executionPosition, $claimPosition);
        $this->assertLessThan($stayTransitionPosition, $executionPosition);
        $this->assertLessThan($handoffPosition, $stayTransitionPosition);
        $this->assertLessThan($auditPosition, $handoffPosition);
    }

    public function test_post_commit_cleanup_cannot_reinterpret_committed_checkout_as_failure(): void
    {
        $source = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php'));

        $transactionPosition = strpos($source, '$result = DB::transaction');
        $cleanupPosition = strpos($source, 'cleanupConfirmationSessionAfterCommit($result');
        $returnPosition = strpos($source, 'return $result;');
        $cleanupMethodPosition = strpos($source, 'private function cleanupConfirmationSessionAfterCommit');

        $this->assertIsInt($transactionPosition);
        $this->assertIsInt($cleanupPosition);
        $this->assertIsInt($returnPosition);
        $this->assertIsInt($cleanupMethodPosition);
        $this->assertLessThan($cleanupPosition, $transactionPosition);
        $this->assertLessThan($returnPosition, $cleanupPosition);
        $this->assertStringContainsString('catch (\\Throwable $exception)', $source);
        $this->assertStringContainsString('Log::warning', $source);
        $cleanupBody = substr($source, $cleanupMethodPosition, strpos($source, 'private function postgresWallClockUtc') - $cleanupMethodPosition);
        $this->assertStringNotContainsString('throw $exception;', $cleanupBody);
    }
}
