<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Operations\Housekeeping\Models\HousekeepingRoomReadinessTransition;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Tests\PostgresTestCase;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingRoomReadinessData;

class HousekeepingRoomReadinessImmutabilityTest extends PostgresTestCase
{
    use RefreshDatabase;
    use CreatesHousekeepingRoomReadinessData;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-08 12:00:00');
        $this->setUpHousekeepingRoomReadinessFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_transition_evidence_update_blocked_at_application_level(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '501');
        $transition = app(HousekeepingRoomReadinessTransitionService::class)->startCleaning(
            $this->housekeepingActor, $roomId, 'idem-imm-501-' . Str::ulid(),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');

        $transition->update(['from_status' => 'something_else']);
    }

    public function test_transition_evidence_delete_blocked_at_application_level(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '502');
        $transition = app(HousekeepingRoomReadinessTransitionService::class)->startCleaning(
            $this->housekeepingActor, $roomId, 'idem-imm-502-' . Str::ulid(),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('immutable');

        $transition->delete();
    }

    public function test_transition_evidence_has_no_updated_at_column(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '503');
        $transition = app(HousekeepingRoomReadinessTransitionService::class)->startCleaning(
            $this->housekeepingActor, $roomId, 'idem-imm-503-' . Str::ulid(),
        );

        $this->assertNull($transition->getUpdatedAtColumn());
    }

    public function test_transition_timestamps_has_no_updated_at_column(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '503');
        $transition = app(HousekeepingRoomReadinessTransitionService::class)->startCleaning(
            $this->housekeepingActor, $roomId, 'idem-imm-503-' . Str::ulid(),
        );

        $this->assertNull($transition->getUpdatedAtColumn());
    }

    public function test_release_ready_replay_fails(): void
    {
        $roomId = $this->hkCleanRoom($this->property, '505');

        app(\Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService::class)->confirm(
            $this->housekeepingInspector,
            HousekeepingRoomReadinessTransitionService::RELEASE_INTENT,
            'password',
            session('active_company_id'),
            $this->property->id,
            app(HousekeepingRoomReadinessTransitionService::class)->releaseEvidenceHash(
                \Modules\Operations\Housekeeping\Models\Room::find($roomId), 'waiting_inspection', 'ready_for_sale', 'Release', 'ctx-505'
            ),
        );

        $first = app(HousekeepingRoomReadinessTransitionService::class)->releaseReady(
            $this->housekeepingInspector, $roomId, 'Release', 'ctx-505',
        );

        $this->assertNotNull($first);

        $this->expectException(DomainException::class);
        app(HousekeepingRoomReadinessTransitionService::class)->releaseReady(
            $this->housekeepingInspector, $roomId, 'Release again', 'ctx-505',
        );
    }
}
