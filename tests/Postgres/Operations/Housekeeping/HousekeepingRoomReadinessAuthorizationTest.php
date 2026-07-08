<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\PostgresTestCase;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingRoomReadinessData;

class HousekeepingRoomReadinessAuthorizationTest extends PostgresTestCase
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

    public function test_housekeeping_view_permission_required_for_projection(): void
    {
        $roomId = $this->hkInspectedRoom($this->property, '401');

        try {
            app(HousekeepingRoomReadinessProjectionService::class)
                ->forHousekeeping($this->engineeringActor, $roomId);
            $this->fail('Housekeeping view permission must be required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_front_desk_view_permission_required_for_front_desk_projection(): void
    {
        $roomId = $this->hkInspectedRoom($this->property, '402');

        try {
            app(HousekeepingRoomReadinessProjectionService::class)
                ->forFrontDesk($this->engineeringActor, $roomId);
            $this->fail('Front desk HK readiness view permission must be required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_front_desk_can_read_projection_with_permission(): void
    {
        $roomId = $this->hkInspectedRoom($this->property, '403');

        $result = app(HousekeepingRoomReadinessProjectionService::class)
            ->forFrontDesk($this->frontDeskActor, $roomId);

        $this->assertArrayHasKey('readiness_status', $result);
        $this->assertEquals(HousekeepingRoomReadinessProjectionService::READY, $result['readiness_status']);
    }

    public function test_clean_permission_required_for_start_cleaning(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '404');

        try {
            $this->transitionService()->startCleaning(
                $this->frontDeskActor, $roomId, 'idem-auth-404',
            );
            $this->fail('Clean permission must be required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_submit_inspection_permission_required(): void
    {
        $roomId = $this->hkRoom($this->property, '405', [
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'cleaning',
        ]);

        try {
            $this->transitionService()->submitInspection(
                $this->frontDeskActor, $roomId, 'idem-auth-405',
            );
            $this->fail('Submit-inspection permission must be required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_release_ready_permission_required(): void
    {
        $roomId = $this->hkCleanRoom($this->property, '406');

        try {
            $this->transitionService()->releaseReady(
                $this->housekeepingActor, $roomId, 'Release', 'ctx-406',
            );
            $this->fail('Release-ready permission must be required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_engineering_cannot_start_cleaning(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '407');

        try {
            $this->transitionService()->startCleaning(
                $this->engineeringActor, $roomId, 'idem-auth-407',
            );
            $this->fail('Engineering must not be able to start cleaning.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_engineering_cannot_release_ready(): void
    {
        $roomId = $this->hkCleanRoom($this->property, '408');

        try {
            $this->transitionService()->releaseReady(
                $this->engineeringActor, $roomId, 'Release', 'ctx-408',
            );
            $this->fail('Engineering must not be able to release ready.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_finance_cannot_transition_readiness(): void
    {
        $roomId = $this->hkDirtyRoom($this->property, '409');

        try {
            $this->transitionService()->startCleaning(
                $this->financeActor, $roomId, 'idem-auth-409',
            );
            $this->fail('Finance must not be able to transition readiness.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_release_ready_requires_separate_permission_from_clean_and_submit(): void
    {
        $roomId = $this->hkCleanRoom($this->property, '410');

        try {
            $this->transitionService()->releaseReady(
                $this->housekeepingActor, $roomId, 'Release', 'ctx-410',
            );
            $this->fail('Release-ready requires separate permission from clean/submit.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
