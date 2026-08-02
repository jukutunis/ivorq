<?php

namespace Tests\Postgres\Operations\Housekeeping;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutHousekeepingHandoffDeliveryService;
use Modules\Operations\Housekeeping\Models\HousekeepingCheckoutTurnoverIntake;
use Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService;
use RuntimeException;
use Shared\Services\CurrentPropertyService;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingCheckoutTurnoverIntakeData;
use Tests\PostgresTestCase;

class HousekeepingCheckoutTurnoverIntakeFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;
    use CreatesHousekeepingCheckoutTurnoverIntakeData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutTurnoverFixture();
    }

    public function test_ready_room_checkout_handoff_creates_one_durable_turnover_outcome(): void
    {
        $roomId = $this->p11Room($this->property, [
            'cleanliness_status' => 'inspected',
            'readiness_state' => 'ready_for_sale',
        ]);
        $source = $this->p11CheckoutSource($this->property, $roomId);

        $result = app(HousekeepingCheckoutTurnoverIntakeService::class)
            ->consumeNextAvailable($this->property->id, 60);

        $this->assertNotNull($result);
        $this->assertSame($source['handoff']->id, $result->handoffId);
        $this->assertSame($roomId, $result->roomId);
        $this->assertSame('DELIVERED', $result->handoffDeliveryStatus);
        $this->assertFalse($result->deliveryConfirmationPending);
        $this->assertSame('waiting_cleaning', $result->roomReadinessStatus);
        $this->assertSame('dirty', $result->roomCleanlinessStatus);

        $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->count());
        $this->assertSame(1, DB::table('cleaning_tasks')->where('task_type', 'checkout_cleaning')->count());
        $this->assertSame(1, DB::table('housekeeping_room_readiness_transitions')->where('transition_type', 'CHECKOUT_TURNOVER_INTAKE')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'housekeeping_checkout_turnover_intake_committed')->count());

        $this->assertDatabaseHas('cleaning_tasks', [
            'id' => $result->cleaningTaskId,
            'property_id' => $this->property->id,
            'room_id' => $roomId,
            'task_type' => 'checkout_cleaning',
            'status' => 'pending',
            'priority' => 'normal',
            'sla_minutes_target' => 45,
        ]);
        $this->assertDatabaseHas('rooms', [
            'id' => $roomId,
            'property_id' => $this->property->id,
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'waiting_cleaning',
        ]);
    }

    public function test_vip_room_creates_rush_checkout_cleaning_task(): void
    {
        $roomId = $this->p11Room($this->property, [
            'cleanliness_status' => 'clean',
            'readiness_state' => 'ready_for_vip',
            'is_vip' => true,
        ]);
        $this->p11CheckoutSource($this->property, $roomId);

        $result = app(HousekeepingCheckoutTurnoverIntakeService::class)
            ->consumeNextAvailable($this->property->id, 60);

        $this->assertDatabaseHas('cleaning_tasks', [
            'id' => $result->cleaningTaskId,
            'priority' => 'rush',
            'credits' => '1.00',
        ]);
    }

    public function test_compatible_dirty_waiting_cleaning_room_is_accepted(): void
    {
        $roomId = $this->p11Room($this->property, [
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'waiting_cleaning',
        ]);
        $this->p11CheckoutSource($this->property, $roomId);

        $result = app(HousekeepingCheckoutTurnoverIntakeService::class)
            ->consumeNextAvailable($this->property->id, 60);

        $this->assertSame('waiting_cleaning', $result->roomReadinessStatus);
        $this->assertSame('dirty', $result->roomCleanlinessStatus);
        $this->assertDatabaseHas('housekeeping_checkout_turnover_intakes', [
            'id' => $result->intakeId,
            'room_readiness_before' => 'waiting_cleaning',
            'cleanliness_before' => 'dirty',
        ]);
    }

    public function test_committed_replay_returns_same_intake_task_and_transition_without_duplicate_audit(): void
    {
        $roomId = $this->p11Room($this->property);
        $source = $this->p11CheckoutSource($this->property, $roomId);
        $claim = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class)
            ->claimAvailable($this->property->id, $source['handoff']->id, 60);

        $service = app(HousekeepingCheckoutTurnoverIntakeService::class);
        $first = $service->consumeClaimed($this->property->id, $source['handoff']->id, $claim['claim_token']);
        $second = $service->consumeClaimed($this->property->id, $source['handoff']->id, $claim['claim_token']);

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->intakeId, $second->intakeId);
        $this->assertSame($first->cleaningTaskId, $second->cleaningTaskId);
        $this->assertSame($first->readinessTransitionId, $second->readinessTransitionId);
        $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'housekeeping_checkout_turnover_intake_committed')->count());
    }

    public function test_delivered_handoff_replay_returns_existing_intake_without_duplicate_mutation(): void
    {
        $roomId = $this->p11Room($this->property);
        $source = $this->p11CheckoutSource($this->property, $roomId);
        $delivery = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);
        $claim = $delivery->claimAvailable($this->property->id, $source['handoff']->id, 60);
        $service = app(HousekeepingCheckoutTurnoverIntakeService::class);

        $first = $service->consumeClaimed($this->property->id, $source['handoff']->id, $claim['claim_token']);
        $delivery->markDelivered($this->property->id, $source['handoff']->id, $claim['claim_token']);
        $replay = $service->consumeClaimed($this->property->id, $source['handoff']->id, $claim['claim_token']);

        $this->assertTrue($replay->replayed);
        $this->assertSame($first->intakeId, $replay->intakeId);
        $this->assertSame($first->cleaningTaskId, $replay->cleaningTaskId);
        $this->assertSame($first->readinessTransitionId, $replay->readinessTransitionId);
        $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->count());
        $this->assertSame(1, DB::table('cleaning_tasks')->where('task_type', 'checkout_cleaning')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'housekeeping_checkout_turnover_intake_committed')->count());
    }

    public function test_expired_claim_after_housekeeping_commit_returns_pending_delivery_result_and_later_reclaims_same_ids(): void
    {
        $roomId = $this->p11Room($this->property);
        $source = $this->p11CheckoutSource($this->property, $roomId);
        $service = app(HousekeepingCheckoutTurnoverIntakeService::class);

        $service->setPostCommitTestingHookForTesting(fn (string $handoffId) => $this->waitForHandoffClaimExpiry($handoffId));

        try {
            $pending = $service->consumeNextAvailable($this->property->id, 1);
        } finally {
            $service->setPostCommitTestingHookForTesting(null);
        }

        $this->assertNotNull($pending);
        $this->assertSame($source['handoff']->id, $pending->handoffId);
        $this->assertTrue($pending->deliveryConfirmationPending);
        $this->assertSame('CLAIMED', $pending->handoffDeliveryStatus);
        $this->assertFalse($pending->replayed);
        $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->count());

        $delivered = $service->consumeNextAvailable($this->property->id, 60);

        $this->assertNotNull($delivered);
        $this->assertTrue($delivered->replayed);
        $this->assertFalse($delivered->deliveryConfirmationPending);
        $this->assertSame('DELIVERED', $delivered->handoffDeliveryStatus);
        $this->assertSame($pending->intakeId, $delivered->intakeId);
        $this->assertSame($pending->cleaningTaskId, $delivered->cleaningTaskId);
        $this->assertSame($pending->readinessTransitionId, $delivered->readinessTransitionId);
        $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->count());
        $this->assertSame(1, DB::table('cleaning_tasks')->where('task_type', 'checkout_cleaning')->count());
        $this->assertSame(1, DB::table('housekeeping_room_readiness_transitions')->where('transition_type', 'CHECKOUT_TURNOVER_INTAKE')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'housekeeping_checkout_turnover_intake_committed')->count());
    }

    public function test_stale_token_after_housekeeping_commit_returns_pending_without_marking_failed(): void
    {
        $roomId = $this->p11Room($this->property);
        $source = $this->p11CheckoutSource($this->property, $roomId);
        $delivery = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);
        $service = app(HousekeepingCheckoutTurnoverIntakeService::class);

        $service->setPostCommitTestingHookForTesting(function (string $handoffId) use ($delivery): void {
            $this->waitForHandoffClaimExpiry($handoffId);
            $delivery->claimAvailable($this->property->id, $handoffId, 60);
        });

        try {
            $pending = $service->consumeNextAvailable($this->property->id, 1);
        } finally {
            $service->setPostCommitTestingHookForTesting(null);
        }

        $this->assertNotNull($pending);
        $this->assertSame($source['handoff']->id, $pending->handoffId);
        $this->assertTrue($pending->deliveryConfirmationPending);
        $this->assertSame('CLAIMED', $pending->handoffDeliveryStatus);
        $this->assertSame(2, $pending->attempts);
        $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->count());
        $this->assertSame(0, DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $source['handoff']->id)->where('delivery_status', 'FAILED')->count());
    }

    public function test_allowlisted_phase_c_domain_exception_does_not_mark_failed_after_commit(): void
    {
        $roomId = $this->p11Room($this->property);
        $source = $this->p11CheckoutSource($this->property, $roomId);
        $realDelivery = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);
        $fakeDelivery = new class(app(CurrentPropertyService::class), $realDelivery) extends FrontDeskCheckoutHousekeepingHandoffDeliveryService {
            public bool $markFailedCalled = false;

            public function __construct(
                CurrentPropertyService $currentProperty,
                private readonly FrontDeskCheckoutHousekeepingHandoffDeliveryService $realDelivery,
            ) {
                parent::__construct($currentProperty);
            }

            public function claimNextAvailable(string $propertyId, int $leaseSeconds = 60): ?array
            {
                return $this->realDelivery->claimNextAvailable($propertyId, $leaseSeconds);
            }

            public function markDelivered(string $propertyId, string $handoffId, string $claimToken): \Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff
            {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');
            }

            public function markFailed(string $propertyId, string $handoffId, string $claimToken, string $errorCode, \DateTimeInterface $retryAt): \Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff
            {
                $this->markFailedCalled = true;

                return $this->realDelivery->markFailed($propertyId, $handoffId, $claimToken, $errorCode, $retryAt);
            }
        };

        $service = new HousekeepingCheckoutTurnoverIntakeService(app(CurrentPropertyService::class), $fakeDelivery);

        $result = $service->consumeNextAvailable($this->property->id, 60);

        $this->assertNotNull($result);
        $this->assertSame($source['handoff']->id, $result->handoffId);
        $this->assertTrue($result->deliveryConfirmationPending);
        $this->assertFalse($fakeDelivery->markFailedCalled);
        $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->count());
        $this->assertSame(0, DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $source['handoff']->id)->where('delivery_status', 'FAILED')->count());
    }

    public function test_unexpected_phase_c_throwable_is_not_converted_to_pending_delivery(): void
    {
        $roomId = $this->p11Room($this->property);
        $this->p11CheckoutSource($this->property, $roomId);
        $realDelivery = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);
        $fakeDelivery = new class(app(CurrentPropertyService::class), $realDelivery) extends FrontDeskCheckoutHousekeepingHandoffDeliveryService {
            public function __construct(
                CurrentPropertyService $currentProperty,
                private readonly FrontDeskCheckoutHousekeepingHandoffDeliveryService $realDelivery,
            ) {
                parent::__construct($currentProperty);
            }

            public function claimNextAvailable(string $propertyId, int $leaseSeconds = 60): ?array
            {
                return $this->realDelivery->claimNextAvailable($propertyId, $leaseSeconds);
            }

            public function markDelivered(string $propertyId, string $handoffId, string $claimToken): \Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff
            {
                throw new RuntimeException('HK_P11_TEST_UNEXPECTED_PHASE_C_THROWABLE');
            }
        };

        $service = new HousekeepingCheckoutTurnoverIntakeService(app(CurrentPropertyService::class), $fakeDelivery);

        try {
            $service->consumeNextAvailable($this->property->id, 60);
            $this->fail('Unexpected Phase C throwable must not be converted to delivery pending.');
        } catch (RuntimeException $exception) {
            $this->assertSame('HK_P11_TEST_UNEXPECTED_PHASE_C_THROWABLE', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('housekeeping_checkout_turnover_intakes')->count());
    }

    public function test_phase_b_original_domain_exception_survives_secondary_mark_failed_rejection(): void
    {
        $roomId = $this->p11Room($this->property, [
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'blocked',
        ]);
        $source = $this->p11CheckoutSource($this->property, $roomId);
        $realDelivery = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);
        $fakeDelivery = new class(app(CurrentPropertyService::class), $realDelivery) extends FrontDeskCheckoutHousekeepingHandoffDeliveryService {
            public function __construct(
                CurrentPropertyService $currentProperty,
                private readonly FrontDeskCheckoutHousekeepingHandoffDeliveryService $realDelivery,
            ) {
                parent::__construct($currentProperty);
            }

            public function claimNextAvailable(string $propertyId, int $leaseSeconds = 60): ?array
            {
                return $this->realDelivery->claimNextAvailable($propertyId, $leaseSeconds);
            }

            public function markFailed(string $propertyId, string $handoffId, string $claimToken, string $errorCode, \DateTimeInterface $retryAt): \Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff
            {
                throw new DomainException('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');
            }
        };

        $service = new HousekeepingCheckoutTurnoverIntakeService(app(CurrentPropertyService::class), $fakeDelivery);

        try {
            $service->consumeNextAvailable($this->property->id, 60);
            $this->fail('Phase B lifecycle conflict must fail closed.');
        } catch (DomainException $exception) {
            $this->assertSame(HousekeepingCheckoutTurnoverIntakeService::ERROR_ROOM_LIFECYCLE_CONFLICT, $exception->getMessage());
        }

        $this->assertSame(0, DB::table('housekeeping_checkout_turnover_intakes')->count());
        $this->assertSame(0, DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $source['handoff']->id)->where('delivery_status', 'FAILED')->count());
    }

    public function test_active_checkout_cleaning_task_without_intake_fails_closed(): void
    {
        $roomId = $this->p11Room($this->property);
        $this->p11CheckoutSource($this->property, $roomId);
        DB::table('cleaning_tasks')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'property_id' => $this->property->id,
            'room_id' => $roomId,
            'task_type' => 'checkout_cleaning',
            'status' => 'pending',
            'priority' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(HousekeepingCheckoutTurnoverIntakeService::ERROR_ACTIVE_TASK_CONFLICT);

        app(HousekeepingCheckoutTurnoverIntakeService::class)
            ->consumeNextAvailable($this->property->id, 60);
    }

    public function test_blocked_room_fails_closed_with_zero_housekeeping_outcome(): void
    {
        $roomId = $this->p11Room($this->property, [
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'blocked',
        ]);
        $this->p11CheckoutSource($this->property, $roomId);

        try {
            app(HousekeepingCheckoutTurnoverIntakeService::class)
                ->consumeNextAvailable($this->property->id, 60);
            $this->fail('Blocked room should fail closed.');
        } catch (DomainException $exception) {
            $this->assertSame(HousekeepingCheckoutTurnoverIntakeService::ERROR_ROOM_LIFECYCLE_CONFLICT, $exception->getMessage());
        }

        $this->assertSame(0, DB::table('housekeeping_checkout_turnover_intakes')->count());
        $this->assertSame(0, DB::table('housekeeping_room_readiness_transitions')->where('transition_type', 'CHECKOUT_TURNOVER_INTAKE')->count());
    }

    public function test_intake_model_is_application_immutable(): void
    {
        $roomId = $this->p11Room($this->property);
        $this->p11CheckoutSource($this->property, $roomId);

        $result = app(HousekeepingCheckoutTurnoverIntakeService::class)
            ->consumeNextAvailable($this->property->id, 60);

        $intake = HousekeepingCheckoutTurnoverIntake::findOrFail($result->intakeId);

        $this->expectException(DomainException::class);
        $intake->room_readiness_after = 'ready_for_sale';
        $intake->save();
    }

    private function waitForHandoffClaimExpiry(string $handoffId): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $expired = DB::table('front_desk_checkout_housekeeping_handoffs')
                ->where('id', $handoffId)
                ->whereRaw("claim_expires_at <= (clock_timestamp() AT TIME ZONE 'UTC')")
                ->exists();

            if ($expired) {
                return;
            }

            usleep(100_000);
        }

        $this->fail("Claim did not expire for handoff {$handoffId}");
    }
}
