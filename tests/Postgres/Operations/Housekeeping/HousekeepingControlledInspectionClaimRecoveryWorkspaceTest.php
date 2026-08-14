<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Tests\PostgresTestCase;

class HousekeepingControlledInspectionClaimRecoveryWorkspaceTest extends PostgresTestCase
{
    public function test_workspace_preserves_claim_history_and_uses_server_authoritative_controlled_recovery(): void
    {
        $workspace = file_get_contents(base_path('resources/js/Pages/Operations/Housekeeping/Inspections/Show.tsx'));
        $resource = file_get_contents(base_path('Modules/Operations/Housekeeping/Http/Resources/RoomInspectionResource.php'));
        foreach (['Original claimant', 'Effective claimant', 'Intervened by', 'Objective recovery reason', 'Human reason', 'Occurred at', 'Reassign Inspector'] as $text) {
            $this->assertStringContainsString($text, $workspace);
        }
        $this->assertStringContainsString('reassignment_context.replacement_candidates.map', $workspace);
        $this->assertStringContainsString('type="password"', $workspace);
        $this->assertStringContainsString('window.crypto.randomUUID()', $workspace);
        $this->assertStringNotContainsString('localStorage', $workspace);
        $this->assertStringNotContainsString('sessionStorage', $workspace);
        $this->assertStringContainsString('is_effective_claimant_current_actor', $resource);
        $this->assertStringContainsString('$isEffectiveOwner', $resource);
        $this->assertStringContainsString('The original Package 17 claimant evidence remains unchanged', $workspace);
    }
}
