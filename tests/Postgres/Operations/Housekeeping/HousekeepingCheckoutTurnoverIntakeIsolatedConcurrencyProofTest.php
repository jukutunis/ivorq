<?php

namespace Tests\Postgres\Operations\Housekeeping;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutHousekeepingHandoffDeliveryService;
use Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService;
use Shared\Services\CurrentPropertyService;
use Tests\Postgres\Operations\FrontDesk\Concerns\ManagesConcurrencyDatabase;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingCheckoutTurnoverIntakeData;
use Tests\Postgres\Operations\Housekeeping\Support\P11CheckoutTurnoverConcurrencyCoordinator;
use Tests\PostgresTestCase;

class HousekeepingCheckoutTurnoverIntakeIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use ManagesConcurrencyDatabase;
    use CreatesHousekeepingCheckoutTurnoverIntakeData;

    private bool $concurrencyDatabaseCleaned = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrencyDatabase('ivorq_concurrency_hk_p11_', '2026-07-30 10:00:00');
        $this->onConcurrencyConnection(fn () => $this->setUpCheckoutTurnoverFixture());
    }

    protected function tearDown(): void
    {
        if (! $this->concurrencyDatabaseCleaned) {
            $this->tearDownConcurrencyDatabase();
            $this->concurrencyDatabaseCleaned = true;
        }

        parent::tearDown();
    }

    public function test_scenario_a_two_workers_same_handoff_has_one_winner_and_one_safe_loser(): void
    {
        $coordinator = new P11CheckoutTurnoverConcurrencyCoordinator();

        try {
            $source = $this->createTurnoverSource();
            $env = $this->workerEnv();

            $workerA = $coordinator->spawn('consume_next', [
                'property_id' => $this->property->id,
                'lease_seconds' => 60,
            ], $env);
            $workerB = $coordinator->spawn('consume_next', [
                'property_id' => $this->property->id,
                'lease_seconds' => 60,
            ], $env);

            $first = $this->assertWorkerResult($coordinator->wait($workerA), 0);
            $second = $this->assertWorkerResult($coordinator->wait($workerB), 0);
            $this->assertNotSame($first['php_pid'], $second['php_pid']);
            $this->assertNotSame($first['postgres_backend_pid'], $second['postgres_backend_pid']);

            $workers = [$first, $second];
            $winners = array_values(array_filter($workers, fn (array $worker) => $worker['outcome'] === 'consumed'));
            $losers = array_values(array_filter($workers, fn (array $worker) => $worker['outcome'] === 'no_available'));

            $this->assertCount(1, $winners);
            $this->assertCount(1, $losers);
            $this->assertSame($source['handoff_id'], $winners[0]['result']['handoff_id']);
            $this->assertScenarioCounts(1, 1, 1, 1);
            $this->assertSame(1, $this->cx()->table('rooms')->where('readiness_state', 'waiting_cleaning')->where('cleanliness_status', 'dirty')->count());
            $this->assertSame('DELIVERED', $this->handoffStatus($source['handoff_id']));
            $this->assertRoomState($source['room_id'], 'waiting_cleaning', 'dirty');
        } finally {
            $coordinator->terminateAll();
        }
    }

    public function test_scenario_b_committed_replay_uses_second_real_worker_and_returns_identical_ids(): void
    {
        $coordinator = new P11CheckoutTurnoverConcurrencyCoordinator();

        try {
            $source = $this->createTurnoverSource();
            $token = $coordinator->tempFile('p11_secret_b_');
            $env = $this->workerEnv();

            $firstWorker = $coordinator->spawn('consume_next_store_secret', [
                'property_id' => $this->property->id,
                'lease_seconds' => 60,
                'token_path' => $token,
            ], $env);
            $first = $this->assertWorkerResult($coordinator->wait($firstWorker), 0, 'consumed');
            $this->assertFalse($first['result']['replayed']);

            $replayWorker = $coordinator->spawn('consume_claimed_from_secret', [
                'property_id' => $this->property->id,
                'token_path' => $token,
                'mark_delivered' => true,
            ], $env);
            $replay = $this->assertWorkerResult($coordinator->wait($replayWorker), 0, 'consumed');
            $this->assertTrue($replay['result']['replayed']);
            $this->assertSame($source['handoff_id'], $replay['result']['handoff_id']);
            $this->assertSame($first['result']['intake_id'], $replay['result']['intake_id']);
            $this->assertSame($first['result']['cleaning_task_id'], $replay['result']['cleaning_task_id']);
            $this->assertSame($first['result']['readiness_transition_id'], $replay['result']['readiness_transition_id']);
            $this->assertScenarioCounts(1, 1, 1, 1);

            $coordinator->deleteFile($token);
            $this->assertFileDoesNotExist($token);
        } finally {
            $coordinator->terminateAll();
        }
    }

    public function test_scenario_c_real_worker_exits_after_housekeeping_commit_and_recovery_replays(): void
    {
        $coordinator = new P11CheckoutTurnoverConcurrencyCoordinator();

        try {
            $source = $this->createTurnoverSource();
            $env = $this->workerEnv();

            $crashWorker = $coordinator->spawn('consume_next', [
                'property_id' => $this->property->id,
                'lease_seconds' => 1,
                'post_commit_exit_code' => 77,
            ], $env);
            $crash = $this->assertWorkerResult($coordinator->wait($crashWorker), 77, 'post_commit_terminated');
            $this->assertSame('P11_WORKER_POST_COMMIT_TERMINATED', $crash['marker']);
            $this->assertSame($source['handoff_id'], $crash['handoff_id']);
            $this->assertSame('CLAIMED', $this->handoffStatus($source['handoff_id']));
            $this->assertScenarioCounts(1, 1, 1, 1);
            $this->assertRoomState($source['room_id'], 'waiting_cleaning', 'dirty');

            $committed = $this->intakeForHandoff($source['handoff_id']);
            $this->waitForClaimExpiry($source['handoff_id']);

            $recoveryWorker = $coordinator->spawn('consume_next', [
                'property_id' => $this->property->id,
                'lease_seconds' => 60,
            ], $env);
            $recovery = $this->assertWorkerResult($coordinator->wait($recoveryWorker), 0, 'consumed');
            $this->assertTrue($recovery['result']['replayed']);
            $this->assertSame($committed->id, $recovery['result']['intake_id']);
            $this->assertSame($committed->cleaning_task_id, $recovery['result']['cleaning_task_id']);
            $this->assertSame($committed->room_readiness_transition_id, $recovery['result']['readiness_transition_id']);
            $this->assertSame('DELIVERED', $this->handoffStatus($source['handoff_id']));
            $this->assertScenarioCounts(1, 1, 1, 1);
        } finally {
            $coordinator->terminateAll();
        }
    }

    public function test_scenario_d_expired_claim_token_cannot_deliver_or_fail_from_real_workers(): void
    {
        $coordinator = new P11CheckoutTurnoverConcurrencyCoordinator();

        try {
            $source = $this->createTurnoverSource();
            $token = $coordinator->tempFile('p11_secret_d_');
            $env = $this->workerEnv();

            $claimWorker = $coordinator->spawn('claim_available', [
                'property_id' => $this->property->id,
                'handoff_id' => $source['handoff_id'],
                'lease_seconds' => 1,
                'token_path' => $token,
            ], $env);
            $claim = $this->assertWorkerResult($coordinator->wait($claimWorker), 0, 'claimed');
            $this->assertSame($source['handoff_id'], $claim['handoff_id']);
            $this->waitForClaimExpiry($source['handoff_id']);

            $deliverWorker = $coordinator->spawn('mark_delivered_from_secret', [
                'property_id' => $this->property->id,
                'token_path' => $token,
            ], $env);
            $deliver = $this->assertWorkerResult($coordinator->wait($deliverWorker), 0, 'domain_error');
            $this->assertSame('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM', $deliver['domain_error']);

            $failWorker = $coordinator->spawn('mark_failed_from_secret', [
                'property_id' => $this->property->id,
                'token_path' => $token,
            ], $env);
            $fail = $this->assertWorkerResult($coordinator->wait($failWorker), 0, 'domain_error');
            $this->assertSame('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM', $fail['domain_error']);
            $this->assertNoOutcomeForHandoff($source['handoff_id']);
            $this->assertSame('CLAIMED', $this->handoffStatus($source['handoff_id']));

            $coordinator->deleteFile($token);
            $this->assertFileDoesNotExist($token);
        } finally {
            $coordinator->terminateAll();
        }
    }

    public function test_scenario_e_stale_token_after_reclaim_cannot_deliver_or_fail(): void
    {
        $coordinator = new P11CheckoutTurnoverConcurrencyCoordinator();

        try {
            $source = $this->createTurnoverSource();
            $oldToken = $coordinator->tempFile('p11_secret_e_old_');
            $newToken = $coordinator->tempFile('p11_secret_e_new_');
            $env = $this->workerEnv();

            $oldClaimWorker = $coordinator->spawn('claim_available', [
                'property_id' => $this->property->id,
                'handoff_id' => $source['handoff_id'],
                'lease_seconds' => 1,
                'token_path' => $oldToken,
            ], $env);
            $this->assertWorkerResult($coordinator->wait($oldClaimWorker), 0, 'claimed');
            $this->waitForClaimExpiry($source['handoff_id']);

            $newClaimWorker = $coordinator->spawn('claim_available', [
                'property_id' => $this->property->id,
                'handoff_id' => $source['handoff_id'],
                'lease_seconds' => 60,
                'token_path' => $newToken,
            ], $env);
            $newClaim = $this->assertWorkerResult($coordinator->wait($newClaimWorker), 0, 'claimed');
            $this->assertSame(2, $newClaim['attempts']);

            foreach (['mark_delivered_from_secret', 'mark_failed_from_secret'] as $mode) {
                $oldWorker = $coordinator->spawn($mode, [
                    'property_id' => $this->property->id,
                    'token_path' => $oldToken,
                ], $env);
                $oldResult = $this->assertWorkerResult($coordinator->wait($oldWorker), 0, 'domain_error');
                $this->assertSame('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_CLAIM_TOKEN', $oldResult['domain_error']);
            }

            $currentWorker = $coordinator->spawn('consume_claimed_from_secret', [
                'property_id' => $this->property->id,
                'token_path' => $newToken,
                'mark_delivered' => true,
            ], $env);
            $current = $this->assertWorkerResult($coordinator->wait($currentWorker), 0, 'consumed');
            $this->assertFalse($current['result']['replayed']);
            $this->assertScenarioCounts(1, 1, 1, 1);
            $this->assertSame('DELIVERED', $this->handoffStatus($source['handoff_id']));

            $coordinator->deleteFile($oldToken);
            $coordinator->deleteFile($newToken);
            $this->assertFileDoesNotExist($oldToken);
            $this->assertFileDoesNotExist($newToken);
        } finally {
            $coordinator->terminateAll();
        }
    }

    public function test_scenario_f_phase_b_failure_uses_consume_next_and_persists_failed_retry_evidence(): void
    {
        $coordinator = new P11CheckoutTurnoverConcurrencyCoordinator();

        try {
            $source = $this->createTurnoverSource($this->property, [
                'readiness_state' => 'blocked',
                'cleanliness_status' => 'dirty',
            ]);
            $before = $this->outcomeCounts();

            $worker = $coordinator->spawn('consume_next', [
                'property_id' => $this->property->id,
                'lease_seconds' => 60,
            ], $this->workerEnv());
            $result = $this->assertWorkerResult($coordinator->wait($worker), 0, 'domain_error');
            $this->assertSame(HousekeepingCheckoutTurnoverIntakeService::ERROR_ROOM_LIFECYCLE_CONFLICT, $result['domain_error']);
            $this->assertSame($before, $this->outcomeCounts());

            $handoff = $this->cx()->table('front_desk_checkout_housekeeping_handoffs')->where('id', $source['handoff_id'])->first();
            $this->assertSame('FAILED', $handoff->delivery_status);
            $this->assertSame(HousekeepingCheckoutTurnoverIntakeService::ERROR_ROOM_LIFECYCLE_CONFLICT, $handoff->last_error_code);
            $this->assertNotNull($handoff->failed_at);
            $this->assertDatabaseTimestampInFuture((string) $handoff->available_at);
        } finally {
            $coordinator->terminateAll();
        }
    }

    public function test_scenario_g_different_properties_hold_real_locks_without_serializing(): void
    {
        $coordinator = new P11CheckoutTurnoverConcurrencyCoordinator();

        try {
            $sourceA = $this->createTurnoverSource($this->property);
            $sourceB = $this->createTurnoverSource($this->otherProperty);
            $readyA = $coordinator->tempFile('p11_ready_g_a_');
            $readyB = $coordinator->tempFile('p11_ready_g_b_');
            $release = $coordinator->tempFile('p11_release_g_');
            @unlink($release);
            $env = $this->workerEnv();

            $workerA = $coordinator->spawn('consume_next', [
                'property_id' => $this->property->id,
                'lease_seconds' => 60,
                'inside_tx_ready_marker' => $readyA,
                'inside_tx_release_path' => $release,
            ], $env);
            $workerB = $coordinator->spawn('consume_next', [
                'property_id' => $this->otherProperty->id,
                'lease_seconds' => 60,
                'inside_tx_ready_marker' => $readyB,
                'inside_tx_release_path' => $release,
            ], $env);

            $markerA = $coordinator->waitForReady($readyA);
            $markerB = $coordinator->waitForReady($readyB);
            $this->assertSame('P11_INSIDE_TRANSACTION_LOCKS_HELD', $markerA['marker']);
            $this->assertSame('P11_INSIDE_TRANSACTION_LOCKS_HELD', $markerB['marker']);
            $this->assertNotSame($markerA['php_pid'], $markerB['php_pid']);
            $this->assertNotSame($markerA['postgres_backend_pid'], $markerB['postgres_backend_pid']);
            $this->assertGreaterThan(0, $markerA['transaction_level']);
            $this->assertGreaterThan(0, $markerB['transaction_level']);
            $this->assertTrue((bool) $markerA['xact_start_present']);
            $this->assertTrue((bool) $markerB['xact_start_present']);

            $this->onConcurrencyConnection(function () use ($coordinator, $markerA, $markerB): void {
                $this->assertTrue($coordinator->backendHasActiveTransaction((int) $markerA['postgres_backend_pid']));
                $this->assertTrue($coordinator->backendHasActiveTransaction((int) $markerB['postgres_backend_pid']));
                $this->assertTrue($coordinator->rowLockIsHeld('front_desk_checkout_housekeeping_handoffs', (string) $markerA['handoff_id']));
                $this->assertTrue($coordinator->rowLockIsHeld('rooms', (string) $markerA['room_id']));
                $this->assertTrue($coordinator->rowLockIsHeld('front_desk_checkout_housekeeping_handoffs', (string) $markerB['handoff_id']));
                $this->assertTrue($coordinator->rowLockIsHeld('rooms', (string) $markerB['room_id']));
                $this->assertSame([], $coordinator->blockingPids((int) $markerA['postgres_backend_pid']));
                $this->assertSame([], $coordinator->blockingPids((int) $markerB['postgres_backend_pid']));
            });

            $coordinator->release($release);
            $resultA = $this->assertWorkerResult($coordinator->wait($workerA), 0, 'consumed');
            $resultB = $this->assertWorkerResult($coordinator->wait($workerB), 0, 'consumed');
            $this->assertSame($sourceA['handoff_id'], $resultA['result']['handoff_id']);
            $this->assertSame($sourceB['handoff_id'], $resultB['result']['handoff_id']);
            $this->assertScenarioCounts(2, 2, 2, 2);
            $this->assertSame('DELIVERED', $this->handoffStatus($sourceA['handoff_id']));
            $this->assertSame('DELIVERED', $this->handoffStatus($sourceB['handoff_id']));
        } finally {
            $coordinator->terminateAll();
        }
    }

    public function test_scenario_h_cross_property_handoff_is_non_disclosing_and_mutates_nothing(): void
    {
        $coordinator = new P11CheckoutTurnoverConcurrencyCoordinator();

        try {
            $sourceB = $this->createTurnoverSource($this->otherProperty);
            $before = $this->outcomeCounts();
            $worker = $coordinator->spawn('claim_available', [
                'property_id' => $this->property->id,
                'handoff_id' => $sourceB['handoff_id'],
                'lease_seconds' => 60,
            ], $this->workerEnv());
            $result = $this->assertWorkerResult($coordinator->wait($worker), 0, 'domain_error');
            $this->assertSame('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE', $result['domain_error']);
            $this->assertSame($before, $this->outcomeCounts());
            $this->assertSame('PENDING', $this->handoffStatus($sourceB['handoff_id']));
            $this->assertSame(0, (int) $this->cx()->table('front_desk_checkout_housekeeping_handoffs')->where('id', $sourceB['handoff_id'])->value('attempts'));
        } finally {
            $coordinator->terminateAll();
        }
    }

    public function test_scenario_i_malformed_sources_use_separate_workers_and_safe_markers(): void
    {
        $coordinator = new P11CheckoutTurnoverConcurrencyCoordinator();

        try {
            foreach ($this->malformedSourceCases() as $case => $definition) {
                $source = $this->createTurnoverSource($this->property, $definition['room_overrides'] ?? [], $definition['source_overrides'] ?? []);
                $before = $this->outcomeCounts();

                if (($definition['guarded_by_predecessor'] ?? false) === true) {
                    $this->retireHandoffWithoutHousekeeping($source['handoff_id']);
                    $this->assertPredecessorMutationGuard($source, $definition['mutate']);
                    $expectedOutcome = 'no_available';
                    $expectedError = null;
                } else {
                    ($definition['mutate'])($source);
                    $expectedOutcome = 'domain_error';
                    $expectedError = $definition['expected_error'];
                }

                $worker = $coordinator->spawn('consume_next', [
                    'property_id' => $this->property->id,
                    'lease_seconds' => 60,
                ], $this->workerEnv());
                $result = $this->assertWorkerResult($coordinator->wait($worker), 0, $expectedOutcome);
                if ($expectedError !== null) {
                    $this->assertSame($expectedError, $result['domain_error'], "Scenario I case {$case} returned the wrong marker.");
                }
                $this->assertSame($before, $this->outcomeCounts(), "Scenario I case {$case} created Housekeeping facts.");
            }
        } finally {
            $coordinator->terminateAll();
        }
    }

    public function test_scenario_j_replay_worker_has_exact_five_source_zero_delta_and_no_checkout_rerun(): void
    {
        $coordinator = new P11CheckoutTurnoverConcurrencyCoordinator();

        try {
            $source = $this->createTurnoverSource();
            $token = $coordinator->tempFile('p11_secret_j_');
            $env = $this->workerEnv();

            $firstWorker = $coordinator->spawn('consume_next_store_secret', [
                'property_id' => $this->property->id,
                'lease_seconds' => 60,
                'token_path' => $token,
                'guard_checkout_execution' => true,
            ], $env);
            $first = $this->assertWorkerResult($coordinator->wait($firstWorker), 0, 'consumed');
            $beforeSources = $this->checkoutSourceCounts();
            $beforePackage11 = $this->outcomeCounts();

            $replayWorker = $coordinator->spawn('consume_claimed_from_secret', [
                'property_id' => $this->property->id,
                'token_path' => $token,
                'mark_delivered' => true,
                'guard_checkout_execution' => true,
            ], $env);
            $replay = $this->assertWorkerResult($coordinator->wait($replayWorker), 0, 'consumed');
            $this->assertTrue($replay['result']['replayed']);
            $this->assertSame($source['handoff_id'], $replay['result']['handoff_id']);
            $this->assertSame($first['result']['intake_id'], $replay['result']['intake_id']);
            $this->assertSame($first['result']['cleaning_task_id'], $replay['result']['cleaning_task_id']);
            $this->assertSame($first['result']['readiness_transition_id'], $replay['result']['readiness_transition_id']);
            $this->assertSame($beforeSources, $this->checkoutSourceCounts());
            $this->assertSame($beforePackage11, $this->outcomeCounts());
            $this->assertScenarioCounts(1, 1, 1, 1);

            $coordinator->deleteFile($token);
            $this->assertFileDoesNotExist($token);
        } finally {
            $coordinator->terminateAll();
        }
    }

    private function createTurnoverSource($property = null, array $roomOverrides = [], array $sourceOverrides = []): array
    {
        return $this->onConcurrencyConnection(function () use ($property, $roomOverrides, $sourceOverrides): array {
            $property ??= $this->property;
            app(CurrentPropertyService::class)->setPropertyId($property->id);
            $roomId = $this->p11Room($property, $roomOverrides);
            $source = $this->p11CheckoutSource($property, $roomId, $sourceOverrides);

            return [
                'property_id' => $property->id,
                'room_id' => $roomId,
                'handoff_id' => $source['handoff']->id,
                'execution_id' => $source['execution']->id,
                'stay_id' => $source['stay']->id,
                'reservation_id' => $source['reservation']->id,
                'business_date_id' => $source['businessDate']->id,
            ];
        });
    }

    private function malformedSourceCases(): array
    {
        return [
            'execution mismatch' => [
                'guarded_by_predecessor' => true,
                'mutate' => fn (array $source) => $this->cx()->table('front_desk_checkout_executions')->where('id', $source['execution_id'])->update(['front_desk_stay_id' => $this->bareAlternateStay()['stay_id']]),
            ],
            'reservation mismatch' => [
                'guarded_by_predecessor' => true,
                'mutate' => fn (array $source) => $this->cx()->table('front_desk_checkout_executions')->where('id', $source['execution_id'])->update(['reservation_id' => $this->bareAlternateStay()['reservation_id']]),
            ],
            'Business Date mismatch' => [
                'guarded_by_predecessor' => true,
                'mutate' => fn (array $source) => $this->cx()->table('front_desk_checkout_executions')->where('id', $source['execution_id'])->update(['business_date' => today()->addDay()]),
            ],
            'source hash mismatch' => [
                'guarded_by_predecessor' => true,
                'mutate' => fn (array $source) => $this->cx()->table('front_desk_checkout_housekeeping_handoffs')->where('id', $source['handoff_id'])->update(['source_hash' => str_repeat('a', 64)]),
            ],
            'stay not CHECKED_OUT' => [
                'source_overrides' => ['stay_status' => 'IN_HOUSE'],
                'mutate' => fn (array $source) => null,
                'expected_error' => HousekeepingCheckoutTurnoverIntakeService::ERROR_SOURCE_CONFLICT,
            ],
            'missing authoritative room' => [
                'guarded_by_predecessor' => true,
                'mutate' => fn (array $source) => $this->cx()->table('front_desk_stays')->where('id', $source['stay_id'])->update(['current_room_id' => (string) Str::ulid()]),
            ],
            'inactive room' => [
                'mutate' => fn (array $source) => $this->cx()->table('rooms')->where('id', $source['room_id'])->update(['is_active' => false]),
                'expected_error' => HousekeepingCheckoutTurnoverIntakeService::ERROR_ROOM_UNAVAILABLE,
            ],
            'wrong-Property room' => [
                'mutate' => fn (array $source) => $this->cx()->table('rooms')->where('id', $source['room_id'])->update(['property_id' => $this->otherProperty->id]),
                'expected_error' => HousekeepingCheckoutTurnoverIntakeService::ERROR_ROOM_UNAVAILABLE,
            ],
        ];
    }

    private function assertWorkerResult(array $worker, int $expectedExit, ?string $expectedOutcome = null): array
    {
        $this->assertSame($expectedExit, $worker['exit']);
        $this->assertSame('', $worker['stderr']);
        $data = $worker['data'];
        $this->assertGreaterThan(0, (int) $data['php_pid']);
        $this->assertGreaterThan(0, (int) $data['postgres_backend_pid']);
        $this->assertArrayHasKey('completed_at', $data);
        $this->assertArrayNotHasKey('claim', $data);
        $this->assertArrayNotHasKey('claim_token', $data);
        $this->assertArrayNotHasKey('claim_token_hash', $data);
        $this->assertArrayNotHasKey('source_hash', $data);
        $this->assertArrayNotHasKey('exception_class', $data);
        $this->assertArrayNotHasKey('database_message', $data);

        if ($expectedOutcome !== null) {
            $this->assertSame($expectedOutcome, $data['outcome']);
        }

        return $data;
    }

    private function retireHandoffWithoutHousekeeping(string $handoffId): void
    {
        $this->onConcurrencyConnection(function () use ($handoffId): void {
            app(CurrentPropertyService::class)->setPropertyId($this->property->id);
            $delivery = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);
            $claim = $delivery->claimAvailable($this->property->id, $handoffId, 60);
            $delivery->markDelivered($this->property->id, $handoffId, $claim['claim_token']);
        });
    }

    private function assertPredecessorMutationGuard(array $source, callable $mutation): void
    {
        try {
            $this->onConcurrencyConnection(fn () => $mutation($source));
            $this->fail('Predecessor guard should prevent malformed source construction.');
        } catch (QueryException|DomainException) {
            $this->assertTrue(true);
        }
    }

    private function bareAlternateStay(): array
    {
        $guestId = (string) Str::ulid();
        $reservationId = (string) Str::ulid();
        $stayId = (string) Str::ulid();

        $this->cx()->table('guests')->insert([
            'id' => $guestId,
            'property_id' => $this->property->id,
            'guest_code' => 'P11ALT-' . Str::upper(Str::random(5)),
            'full_name' => 'P11 Alternate Guest',
            'guest_type' => 'individual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cx()->table('reservations')->insert([
            'id' => $reservationId,
            'property_id' => $this->property->id,
            'primary_guest_id' => $guestId,
            'reservation_number' => 'P11ALT-' . Str::upper(Str::random(6)),
            'arrival_date' => today(),
            'departure_date' => today()->addDay(),
            'nights' => 1,
            'reservation_source' => 'direct',
            'status' => 'checked_in',
            'reserved_room_type' => 'deluxe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cx()->table('front_desk_stays')->insert([
            'id' => $stayId,
            'property_id' => $this->property->id,
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'status' => 'CHECKED_OUT',
            'current_room_id' => $this->p11Room($this->property),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['stay_id' => $stayId, 'reservation_id' => $reservationId];
    }

    private function onConcurrencyConnection(callable $callback): mixed
    {
        $previousDefault = config('database.default');
        DB::setDefaultConnection('pgsql_concurrency');
        config(['database.default' => 'pgsql_concurrency']);

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($previousDefault);
            config(['database.default' => $previousDefault]);
        }
    }

    private function cx()
    {
        return DB::connection('pgsql_concurrency');
    }

    private function workerEnv(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $this->concurrencyDb,
        ];
    }

    private function waitForClaimExpiry(string $handoffId): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $expired = $this->cx()->table('front_desk_checkout_housekeeping_handoffs')
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

    private function assertDatabaseTimestampInFuture(string $timestamp): void
    {
        $row = $this->cx()->selectOne(
            "SELECT ?::timestamp(0) > (clock_timestamp() AT TIME ZONE 'UTC')::timestamp(0) AS future",
            [$timestamp],
        );
        $this->assertTrue((bool) $row->future);
    }

    private function assertNoOutcomeForHandoff(string $handoffId): void
    {
        $this->assertSame(0, $this->cx()->table('housekeeping_checkout_turnover_intakes')->where('front_desk_checkout_housekeeping_handoff_id', $handoffId)->count());
        $this->assertSame(0, $this->cx()->table('housekeeping_room_readiness_transitions')->where('source_id', $handoffId)->where('transition_type', 'CHECKOUT_TURNOVER_INTAKE')->count());
    }

    private function assertScenarioCounts(int $intakes, int $tasks, int $transitions, int $audits): void
    {
        $this->assertSame($intakes, $this->cx()->table('housekeeping_checkout_turnover_intakes')->count());
        $this->assertSame($tasks, $this->cx()->table('cleaning_tasks')->where('task_type', 'checkout_cleaning')->count());
        $this->assertSame($transitions, $this->cx()->table('housekeeping_room_readiness_transitions')->where('transition_type', 'CHECKOUT_TURNOVER_INTAKE')->count());
        $this->assertSame($audits, $this->cx()->table('audit_logs')->where('event', 'housekeeping_checkout_turnover_intake_committed')->count());
    }

    private function outcomeCounts(): array
    {
        return [
            'intakes' => $this->cx()->table('housekeeping_checkout_turnover_intakes')->count(),
            'tasks' => $this->cx()->table('cleaning_tasks')->where('task_type', 'checkout_cleaning')->count(),
            'transitions' => $this->cx()->table('housekeeping_room_readiness_transitions')->where('transition_type', 'CHECKOUT_TURNOVER_INTAKE')->count(),
            'audits' => $this->cx()->table('audit_logs')->where('event', 'housekeeping_checkout_turnover_intake_committed')->count(),
        ];
    }

    private function checkoutSourceCounts(): array
    {
        return [
            'sensitive_confirmation_consumptions' => $this->cx()->table('checkout_sensitive_confirmation_consumptions')->count(),
            'front_desk_checkout_executions' => $this->cx()->table('front_desk_checkout_executions')->count(),
            'terminal_front_desk_stays' => $this->cx()->table('front_desk_stays')->where('status', 'CHECKED_OUT')->count(),
            'front_desk_checkout_housekeeping_handoffs' => $this->cx()->table('front_desk_checkout_housekeeping_handoffs')->count(),
            'front_desk_checkout_completed_audits' => $this->cx()->table('audit_logs')->where('event', 'front_desk_checkout_completed')->count(),
        ];
    }

    private function intakeForHandoff(string $handoffId): object
    {
        return $this->cx()->table('housekeeping_checkout_turnover_intakes')
            ->where('front_desk_checkout_housekeeping_handoff_id', $handoffId)
            ->first();
    }

    private function handoffStatus(string $handoffId): string
    {
        return (string) $this->cx()
            ->table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoffId)
            ->value('delivery_status');
    }

    private function assertRoomState(string $roomId, string $readiness, string $cleanliness): void
    {
        $room = $this->cx()->table('rooms')->where('id', $roomId)->first();
        $this->assertSame($readiness, $room->readiness_state);
        $this->assertSame($cleanliness, $room->cleanliness_status);
    }
}
