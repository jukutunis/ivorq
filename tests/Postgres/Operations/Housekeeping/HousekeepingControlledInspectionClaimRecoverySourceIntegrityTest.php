<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Tests\PostgresTestCase;

class HousekeepingControlledInspectionClaimRecoverySourceIntegrityTest extends PostgresTestCase
{
    public function test_package_19_has_one_writer_one_aggregate_and_no_forbidden_expansion(): void
    {
        $service = file_get_contents(base_path('Modules/Operations/Housekeeping/Services/HousekeepingInspectionClaimRecoveryService.php'));
        $claim = file_get_contents(base_path('Modules/Operations/Housekeeping/Services/HousekeepingInspectionClaimService.php'));
        $request = file_get_contents(base_path('Modules/Operations/Housekeeping/Http/Requests/ConfirmInspectionClaimReassignmentRequest.php'));
        $policy = file_get_contents(base_path('Modules/Operations/Housekeeping/Policies/RoomInspectionPolicy.php'));
        $intent = file_get_contents(base_path('Modules/Foundation/Authorization/Services/SensitiveActionConfirmationService.php'));
        $model = file_get_contents(base_path('Modules/Operations/Housekeeping/Models/HousekeepingInspectionClaimReassignment.php'));
        $migration = file_get_contents(base_path('Modules/Operations/Housekeeping/database/migrations/2026_08_13_000001_control_housekeeping_inspection_claim_reassignments.php'));
        $contract = file_get_contents(base_path('.agents/contracts/IVORQ-Package-Execution-Contract.md'));
        $all = $service.$claim.$request.$policy.$intent.$model;

        $this->assertSame(1, substr_count($all, 'class HousekeepingInspectionClaimRecoveryService'));
        $this->assertSame(1, substr_count($all, 'class HousekeepingInspectionClaimReassignment'));
        $this->assertStringNotContainsString("'supervisor_id' => \$replacement", $service);
        foreach (['claimed_at', 'claim_idempotency_key', 'claim_source_hash', 'claim_evidence_version'] as $field) {
            $this->assertStringNotContainsString("'{$field}' =>", $service);
        }
        foreach (['replacement_inspector_id', 'reason', 'idempotency_key', 'password'] as $field) {
            $this->assertStringContainsString("'{$field}'", $request);
        }
        $this->assertStringContainsString("hasPermissionTo('housekeeping.inspection.approve')", $policy);
        $this->assertStringContainsString("REPLACEMENT_PERMISSION = 'housekeeping.inspection.conduct'", $service);
        $this->assertSame(1, substr_count($intent, "'housekeeping-inspection-claim-reassignment'"));
        $this->assertStringContainsString("'occurred_at' => \$occurredAt", $service);
        $this->assertStringContainsString("'created_at' => \$occurredAt", $service);
        $this->assertStringContainsString('public $timestamps = false;', $model);
        $this->assertStringContainsString("'created_at',", $model);
        $this->assertStringNotContainsString('const UPDATED_AT', $model);
        $this->assertStringNotContainsString("'occurred_at'", $request);
        $this->assertStringNotContainsString("'created_at'", $request);
        $this->assertStringContainsString('AND occurred_at = created_at', $migration);
        $this->assertStringContainsString("AND inspection.status = 'in_progress'", $migration);
        $this->assertStringContainsString('Version: 1.22', $contract);
        foreach (['ShouldQueue', 'Queue::', 'Http::', 'WebSocket', 'Package 20'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $all);
        }
    }
}
