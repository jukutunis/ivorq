<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\Housekeeping\Models\HousekeepingInspectionClaimReassignment;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimRecoveryService;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingInspectionClaimRecoveryData;
use Tests\PostgresTestCase;

class HousekeepingControlledInspectionClaimRecoveryHttpTest extends PostgresTestCase
{
    use CreatesHousekeepingInspectionClaimRecoveryData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInspectionClaimRecoveryFixture();
    }

    public function test_confirmation_and_execution_are_property_scoped_and_return_bounded_server_evidence(): void
    {
        [, , $inspection] = $this->p19ClaimedInspection('P19-H-SUCCESS');
        $this->p19MakeOriginalInactive();
        $key = 'p19-http-'.Str::uuid();
        $payload = [
            'replacement_inspector_id' => $this->p19Replacement->id,
            'reason' => 'Supervisor restores a blocked inspection.',
            'idempotency_key' => $key,
        ];

        $this->actingAs($this->p19Intervenor, 'web')->withSession($this->hkPropertySession($this->property))
            ->postJson("/operations/inspections/{$inspection->id}/claim-reassignment-confirmation", [...$payload, 'password' => 'password'], $this->p19Headers())
            ->assertOk()->assertJsonPath('reassignment_context.confirmed', true)
            ->assertJsonMissingPath('reassignment_context.source_hash');
        $this->postJson("/operations/inspections/{$inspection->id}/claim-reassignment", $payload, $this->p19Headers())
            ->assertOk()->assertJsonPath('replayed', false)
            ->assertJsonPath('effective_claimant_id', $this->p19Replacement->id)
            ->assertJsonPath('original_ineligibility_code', 'original_user_inactive_or_deleted')
            ->assertJsonPath('evidence_version', 1)
            ->assertJsonMissingPath('source_hash')->assertJsonMissingPath('idempotency_key');
        $this->assertSame(1, HousekeepingInspectionClaimReassignment::count());

        $this->postJson("/operations/inspections/{$inspection->id}/claim-reassignment", $payload, $this->p19Headers())
            ->assertOk()->assertJsonPath('replayed', true);
    }

    public function test_header_authority_and_confirmation_are_required(): void
    {
        [, , $inspection] = $this->p19ClaimedInspection('P19-H-GUARDS');
        $this->p19MakeOriginalInactive();
        $payload = [
            'replacement_inspector_id' => $this->p19Replacement->id,
            'reason' => 'Controlled missing-confirmation proof.',
            'idempotency_key' => 'p19-http-guards',
        ];
        $this->actingAs($this->p19Intervenor, 'web')->withSession($this->hkPropertySession($this->property));
        $this->postJson("/operations/inspections/{$inspection->id}/claim-reassignment", $payload, $this->p19Headers())
            ->assertUnprocessable()->assertJsonPath('message', HousekeepingInspectionClaimRecoveryService::CONFIRMATION_REQUIRED);
        $this->postJson("/operations/inspections/{$inspection->id}/claim-reassignment-confirmation", [...$payload, 'password' => 'password'])
            ->assertForbidden();
        $this->assertSame(0, HousekeepingInspectionClaimReassignment::count());
    }

    public function test_browser_authority_fields_and_uncontrolled_evidence_routes_are_rejected(): void
    {
        [, , $inspection] = $this->p19ClaimedInspection('P19-H-BOUNDARY');
        $this->p19MakeOriginalInactive();
        $payload = [
            'replacement_inspector_id' => $this->p19Replacement->id,
            'reason' => 'Browser authority boundary.',
            'idempotency_key' => 'p19-http-boundary',
            'password' => 'password',
            'intervened_by' => $this->p19Replacement->id,
        ];
        $this->actingAs($this->p19Intervenor, 'web')->withSession($this->hkPropertySession($this->property))
            ->postJson("/operations/inspections/{$inspection->id}/claim-reassignment-confirmation", $payload, $this->p19Headers())
            ->assertUnprocessable()->assertJsonValidationErrors('request');
        $this->putJson('/operations/housekeeping-inspection-claim-reassignments/arbitrary', [], $this->p19Headers())->assertNotFound();
        $this->deleteJson('/operations/housekeeping-inspection-claim-reassignments/arbitrary', [], $this->p19Headers())->assertNotFound();
        $this->assertSame(0, DB::table('housekeeping_inspection_claim_reassignments')->count());
    }
}
