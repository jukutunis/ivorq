<?php

namespace Tests\Postgres\Operations\Housekeeping;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutHousekeepingHandoffDeliveryService;
use Modules\Operations\Housekeeping\Models\HousekeepingCheckoutTurnoverIntake;
use Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService;
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
}
