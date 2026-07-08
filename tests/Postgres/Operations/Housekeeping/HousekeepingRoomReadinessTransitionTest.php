<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\Housekeeping\Enums\HousekeepingRoomReadinessTransitionTypeEnum;
use Modules\Operations\Housekeeping\Models\HousekeepingRoomReadinessTransition;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\PostgresTestCase;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingRoomReadinessData;

class HousekeepingRoomReadinessTransitionTest extends PostgresTestCase
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

    private function transitionService(): HousekeepingRoomReadinessTransitionService
    {
        return app(HousekeepingRoomReadinessTransitionService::class);
    }

    public function test_start_cleaning_transition_is_controlled(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '201');
        $before = $this->hkDomainTableCounts();

        $transition = $this->transitionService()->startCleaning(
            $this->housekeepingActor,
            $roomId,
            'idem-start-201-' . Str::ulid(),
        );

        $this->assertInstanceOf(HousekeepingRoomReadinessTransition::class, $transition);
        $this->assertEquals(HousekeepingRoomReadinessTransitionTypeEnum::StartCleaning, $transition->transition_type);
        $this->assertEquals('waiting_cleaning', $transition->from_status);
        $this->assertEquals('cleaning', $transition->to_status);
        $this->assertEquals($roomId, $transition->room_id);
        $this->assertEquals($this->property->id, $transition->property_id);

        $after = $this->hkDomainTableCounts();
        $this->assertEquals(($before['housekeeping_room_readiness_transitions'] ?? 0) + 1, $after['housekeeping_room_readiness_transitions']);
        $this->assertEquals(($before['rooms'] ?? 0), $after['rooms']);

        $projection = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);
        $this->assertEquals(HousekeepingRoomReadinessProjectionService::BLOCKED, $projection['readiness_status']);
    }

    public function test_start_cleaning_from_ready_state_fails(): void
    {
        $roomId = $this->hkInspectedRoom($this->property, '202');

        $this->expectException(DomainException::class);
        $this->transitionService()->startCleaning(
            $this->housekeepingActor,
            $roomId,
            'idem-start-202',
        );
    }

    public function test_submit_inspection_transition_is_controlled(): void
    {
        $roomId = $this->hkRoom($this->property, '203', [
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'cleaning',
        ]);

        $before = $this->hkDomainTableCounts();

        $transition = $this->transitionService()->submitInspection(
            $this->housekeepingActor,
            $roomId,
            'idem-submit-203-' . Str::ulid(),
            'Cleaning completed',
        );

        $this->assertInstanceOf(HousekeepingRoomReadinessTransition::class, $transition);
        $this->assertEquals(HousekeepingRoomReadinessTransitionTypeEnum::SubmitInspection, $transition->transition_type);
        $this->assertEquals('cleaning', $transition->from_status);
        $this->assertEquals('waiting_inspection', $transition->to_status);
        $this->assertEquals('Cleaning completed', $transition->reason);

        $after = $this->hkDomainTableCounts();
        $this->assertEquals(($before['housekeeping_room_readiness_transitions'] ?? 0) + 1, $after['housekeeping_room_readiness_transitions']);
    }

    public function test_submit_inspection_from_non_cleaning_fails(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '204');

        $this->expectException(DomainException::class);
        $this->transitionService()->submitInspection(
            $this->housekeepingActor,
            $roomId,
            'idem-submit-204',
        );
    }

    public function test_release_ready_transition_is_controlled(): void
    {
        $roomId = $this->hkCleanRoom($this->property, '205');
        $before = $this->hkDomainTableCounts();

        $service = $this->transitionService();
        $room = \Modules\Operations\Housekeeping\Models\Room::find($roomId);
        $hash = $service->releaseEvidenceHash($room, 'waiting_inspection', 'ready_for_sale', 'Release', 'ctx-205');

        app(SensitiveActionConfirmationService::class)->confirm(
            $this->housekeepingInspector,
            HousekeepingRoomReadinessTransitionService::RELEASE_INTENT,
            'password',
            session('active_company_id'),
            $this->property->id,
            $hash,
        );

        $transition = $service->releaseReady(
            $this->housekeepingInspector,
            $roomId,
            'Release',
            'ctx-205',
        );

        $this->assertInstanceOf(HousekeepingRoomReadinessTransition::class, $transition);
        $this->assertEquals(HousekeepingRoomReadinessTransitionTypeEnum::ReleaseReady, $transition->transition_type);
        $this->assertEquals('waiting_inspection', $transition->from_status);
        $this->assertEquals('ready_for_sale', $transition->to_status);
        $this->assertEquals('Release', $transition->reason);

        $after = $this->hkDomainTableCounts();
        $this->assertEquals(($before['housekeeping_room_readiness_transitions'] ?? 0) + 1, $after['housekeeping_room_readiness_transitions']);

        $projection = app(HousekeepingRoomReadinessProjectionService::class)
            ->forHousekeeping($this->housekeepingActor, $roomId);
        $this->assertEquals(HousekeepingRoomReadinessProjectionService::READY, $projection['readiness_status']);
    }

    public function test_release_ready_from_non_waiting_inspection_fails(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '206');

        $this->expectException(DomainException::class);
        $this->transitionService()->releaseReady(
            $this->housekeepingInspector,
            $roomId,
            'Bad release',
            'ctx-206',
        );
    }

    public function test_release_ready_without_confirmation_fails(): void
    {
        $roomId = $this->hkCleanRoom($this->property, '207');

        $this->expectException(DomainException::class);
        $this->transitionService()->releaseReady(
            $this->housekeepingInspector,
            $roomId,
            'No confirmation',
            'ctx-207',
        );
    }

    public function test_release_ready_stale_confirmation_fails(): void
    {
        $roomId = $this->hkCleanRoom($this->property, '208');

        $service = $this->transitionService();
        $room = \Modules\Operations\Housekeeping\Models\Room::find($roomId);
        $hash = $service->releaseEvidenceHash($room, 'waiting_inspection', 'ready_for_sale', 'Release', 'ctx-208');

        Carbon::setTestNow('2026-07-08 12:00:00');
        app(SensitiveActionConfirmationService::class)->confirm(
            $this->housekeepingInspector,
            HousekeepingRoomReadinessTransitionService::RELEASE_INTENT,
            'password',
            session('active_company_id'),
            $this->property->id,
            $hash,
        );

        Carbon::setTestNow('2026-07-08 12:20:00');

        $this->expectException(DomainException::class);
        $service->releaseReady(
            $this->housekeepingInspector,
            $roomId,
            'Release',
            'ctx-208',
        );
    }

    public function test_release_ready_wrong_confirmation_hash_fails(): void
    {
        $roomId = $this->hkCleanRoom($this->property, '209');

        app(SensitiveActionConfirmationService::class)->confirm(
            $this->housekeepingInspector,
            HousekeepingRoomReadinessTransitionService::RELEASE_INTENT,
            'password',
            session('active_company_id'),
            $this->property->id,
            'wrong-hash',
        );

        $this->expectException(DomainException::class);
        $this->transitionService()->releaseReady(
            $this->housekeepingInspector,
            $roomId,
            'Release',
            'ctx-209',
        );
    }

    public function test_duplicate_start_cleaning_idempotent(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '210');
        $key = 'idem-dup-start-' . Str::ulid();

        $first = $this->transitionService()->startCleaning(
            $this->housekeepingActor, $roomId, $key,
        );

        $second = $this->transitionService()->startCleaning(
            $this->housekeepingActor, $roomId, $key,
        );

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(HousekeepingRoomReadinessTransitionTypeEnum::StartCleaning, $second->transition_type);

        $count = HousekeepingRoomReadinessTransition::count();
        $this->assertEquals(1, HousekeepingRoomReadinessTransition::withoutGlobalScopes()
            ->where('property_id', $this->property->id)
            ->where('idempotency_key', $key)
            ->count());
    }

    public function test_duplicate_idempotency_different_params_fails(): void
    {
        $roomA = $this->hkDirtyRoom($this->property, '211A');
        $roomB = $this->hkDirtyRoom($this->property, '211B');
        $key = 'idem-conflict-' . Str::ulid();

        $this->transitionService()->startCleaning($this->housekeepingActor, $roomA, $key);

        $this->expectException(DomainException::class);
        $this->transitionService()->startCleaning($this->housekeepingActor, $roomB, $key);
    }

    public function test_rooms_table_is_the_only_domain_table_mutated_by_start_cleaning(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '212');
        $before = $this->hkDomainTableCounts();

        $this->transitionService()->startCleaning(
            $this->housekeepingActor, $roomId, 'idem-isolation-212-' . Str::ulid(),
        );

        $after = $this->hkDomainTableCounts();

        $beforeHk = $before['housekeeping_room_readiness_transitions'] ?? 0;
        $afterHk = $after['housekeeping_room_readiness_transitions'] ?? 0;
        $this->assertEquals($beforeHk + 1, $afterHk, 'Only transitions table count should change.');

        unset($before['housekeeping_room_readiness_transitions']);
        unset($after['housekeeping_room_readiness_transitions']);
        $this->assertEquals($before, $after, 'No domain tables other than transitions were affected.');
    }
}
