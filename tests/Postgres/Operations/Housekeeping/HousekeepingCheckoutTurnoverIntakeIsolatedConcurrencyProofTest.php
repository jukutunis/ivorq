<?php

namespace Tests\Postgres\Operations\Housekeeping;

use DomainException;
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

    public function test_scenarios_a_and_g_use_real_workers_with_distinct_process_and_backend_identity(): void
    {
        $coordinator = new P11CheckoutTurnoverConcurrencyCoordinator();

        try {
            $roomId = $this->onConcurrencyConnection(fn () => $this->p11Room($this->property));
            $source = $this->onConcurrencyConnection(fn () => $this->p11CheckoutSource($this->property, $roomId));

            $readyA = $coordinator->tempFile('p11_ready_a_');
            $readyB = $coordinator->tempFile('p11_ready_b_');
            $release = $coordinator->tempFile('p11_release_');
            @unlink($release);
            $env = $this->workerEnv();

            $workerA = $coordinator->spawn('consume_next', [
                'property_id' => $this->property->id,
                'lease_seconds' => 60,
                'ready_marker' => $readyA,
                'release_path' => $release,
            ], $env);
            $workerB = $coordinator->spawn('consume_next', [
                'property_id' => $this->property->id,
                'lease_seconds' => 60,
                'ready_marker' => $readyB,
                'release_path' => $release,
            ], $env);

            $readyEvidenceA = $coordinator->waitForReady($readyA);
            $readyEvidenceB = $coordinator->waitForReady($readyB);
            $this->assertNotSame($readyEvidenceA['php_pid'], $readyEvidenceB['php_pid']);
            $this->assertNotSame($readyEvidenceA['postgres_backend_pid'], $readyEvidenceB['postgres_backend_pid']);

            $coordinator->release($release);
            $first = $coordinator->wait($workerA);
            $second = $coordinator->wait($workerB);
            $workers = [$first['data'], $second['data']];
            $winners = array_values(array_filter($workers, fn (array $worker) => $worker['outcome'] === 'consumed'));
            $losers = array_values(array_filter($workers, fn (array $worker) => $worker['outcome'] === 'no_available'));

            $this->assertCount(1, $winners, 'Scenario A requires exactly one claim/consume winner. Workers: ' . json_encode($workers));
            $this->assertCount(1, $losers, 'Scenario A requires the loser to make no mutation. Workers: ' . json_encode($workers));
            $this->assertSame($source['handoff']->id, $winners[0]['result']['handoff_id']);
            $this->assertScenarioCounts(1, 1, 1, 1);
            $this->assertSame('DELIVERED', $this->handoffStatus($source['handoff']->id));
            $this->assertRoomState($roomId, 'waiting_cleaning', 'dirty');

            $roomA = $this->onConcurrencyConnection(fn () => $this->p11Room($this->property));
            $this->onConcurrencyConnection(fn () => $this->p11CheckoutSource($this->property, $roomA));
            $this->onConcurrencyConnection(function () {
                app(CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
            });
            $roomB = $this->onConcurrencyConnection(fn () => $this->p11Room($this->otherProperty));
            $this->onConcurrencyConnection(fn () => $this->p11CheckoutSource($this->otherProperty, $roomB));

            $readyPropertyA = $coordinator->tempFile('p11_ready_pa_');
            $readyPropertyB = $coordinator->tempFile('p11_ready_pb_');
            $releaseProperties = $coordinator->tempFile('p11_release_props_');
            @unlink($releaseProperties);
            $propertyWorkerA = $coordinator->spawn('consume_next', [
                'property_id' => $this->property->id,
                'lease_seconds' => 60,
                'ready_marker' => $readyPropertyA,
                'release_path' => $releaseProperties,
            ], $env);
            $propertyWorkerB = $coordinator->spawn('consume_next', [
                'property_id' => $this->otherProperty->id,
                'lease_seconds' => 60,
                'ready_marker' => $readyPropertyB,
                'release_path' => $releaseProperties,
            ], $env);

            $readyPropertyEvidenceA = $coordinator->waitForReady($readyPropertyA);
            $readyPropertyEvidenceB = $coordinator->waitForReady($readyPropertyB);
            $this->assertSame([], $coordinator->blockingPids((int) $readyPropertyEvidenceA['postgres_backend_pid']));
            $this->assertSame([], $coordinator->blockingPids((int) $readyPropertyEvidenceB['postgres_backend_pid']));
            $this->assertNotSame($readyPropertyEvidenceA['postgres_backend_pid'], $readyPropertyEvidenceB['postgres_backend_pid']);

            $coordinator->release($releaseProperties);
            $propertyResultA = $coordinator->wait($propertyWorkerA);
            $propertyResultB = $coordinator->wait($propertyWorkerB);
            $this->assertSame('consumed', $propertyResultA['data']['outcome']);
            $this->assertSame('consumed', $propertyResultB['data']['outcome']);
            $this->assertNotSame($propertyResultA['data']['result']['handoff_id'], $propertyResultB['data']['result']['handoff_id']);
            $this->assertScenarioCounts(3, 3, 3, 3);
        } finally {
            $coordinator->terminateAll();
        }
    }

    public function test_scenarios_b_c_d_e_f_h_i_and_j_are_durable_and_fail_closed(): void
    {
        $this->onConcurrencyConnection(function () {
            app(CurrentPropertyService::class)->setPropertyId($this->property->id);
            $delivery = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);
            $service = app(HousekeepingCheckoutTurnoverIntakeService::class);

            $roomB = $this->p11Room($this->property);
            $sourceB = $this->p11CheckoutSource($this->property, $roomB);
            $claimB = $delivery->claimAvailable($this->property->id, $sourceB['handoff']->id, 60);
            $firstB = $service->consumeClaimed($this->property->id, $sourceB['handoff']->id, $claimB['claim_token']);
            $delivery->markDelivered($this->property->id, $sourceB['handoff']->id, $claimB['claim_token']);
            $replayB = $service->consumeClaimed($this->property->id, $sourceB['handoff']->id, $claimB['claim_token']);
            $this->assertSame($firstB->intakeId, $replayB->intakeId, 'Scenario B intake replay ID mismatch.');
            $this->assertSame($firstB->cleaningTaskId, $replayB->cleaningTaskId, 'Scenario B task replay ID mismatch.');
            $this->assertSame($firstB->readinessTransitionId, $replayB->readinessTransitionId, 'Scenario B transition replay ID mismatch.');
            $this->assertTrue($replayB->replayed);

            $roomC = $this->p11Room($this->property);
            $sourceC = $this->p11CheckoutSource($this->property, $roomC);
            $claimC = $delivery->claimAvailable($this->property->id, $sourceC['handoff']->id, 1);
            $firstC = $service->consumeClaimed($this->property->id, $sourceC['handoff']->id, $claimC['claim_token']);
            $this->waitForClaimExpiry($sourceC['handoff']->id);
            $reclaimC = $delivery->claimAvailable($this->property->id, $sourceC['handoff']->id, 60);
            $this->assertSame($sourceC['handoff']->id, $reclaimC['handoff_id'], 'Scenario C must reclaim the expired handoff.');
            $replayC = $service->consumeClaimed($this->property->id, $sourceC['handoff']->id, $reclaimC['claim_token']);
            $delivery->markDelivered($this->property->id, $sourceC['handoff']->id, $reclaimC['claim_token']);
            $this->assertSame($firstC->intakeId, $replayC->intakeId, 'Scenario C intake replay ID mismatch.');
            $this->assertTrue($replayC->replayed);
            $this->assertSame('DELIVERED', $this->handoffStatus($sourceC['handoff']->id));

            $roomD = $this->p11Room($this->property);
            $sourceD = $this->p11CheckoutSource($this->property, $roomD);
            $claimD = $delivery->claimAvailable($this->property->id, $sourceD['handoff']->id, 1);
            $this->waitForClaimExpiry($sourceD['handoff']->id);
            $this->expectDomain(fn () => $delivery->markDelivered($this->property->id, $sourceD['handoff']->id, $claimD['claim_token']), 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');
            $this->expectDomain(fn () => $delivery->markFailed($this->property->id, $sourceD['handoff']->id, $claimD['claim_token'], 'HK_P11_INTERNAL_RETRYABLE_FAILURE', now()->addMinutes(5)), 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');
            $this->assertNoOutcomeForHandoff($sourceD['handoff']->id);

            // Cleanup D so its expired claim doesn't get picked up by consumeNextAvailable in Scenario J
            $cleanupClaimD = $delivery->claimAvailable($this->property->id, $sourceD['handoff']->id, 60);
            $service->consumeClaimed($this->property->id, $sourceD['handoff']->id, $cleanupClaimD['claim_token']);
            $delivery->markDelivered($this->property->id, $sourceD['handoff']->id, $cleanupClaimD['claim_token']);

            $roomE = $this->p11Room($this->property);
            $sourceE = $this->p11CheckoutSource($this->property, $roomE);
            $claimE1 = $delivery->claimAvailable($this->property->id, $sourceE['handoff']->id, 1);
            $this->waitForClaimExpiry($sourceE['handoff']->id);
            $claimE2 = $delivery->claimAvailable($this->property->id, $sourceE['handoff']->id, 60);
            $this->assertSame($sourceE['handoff']->id, $claimE2['handoff_id'], 'Scenario E must reclaim the stale-token handoff.');
            $this->expectDomain(fn () => $delivery->markDelivered($this->property->id, $sourceE['handoff']->id, $claimE1['claim_token']), 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_CLAIM_TOKEN');
            $this->expectDomain(fn () => $delivery->markFailed($this->property->id, $sourceE['handoff']->id, $claimE1['claim_token'], 'HK_P11_INTERNAL_RETRYABLE_FAILURE', now()->addMinutes(5)), 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_CLAIM_TOKEN');
            $resultE = $service->consumeClaimed($this->property->id, $sourceE['handoff']->id, $claimE2['claim_token']);
            $delivery->markDelivered($this->property->id, $sourceE['handoff']->id, $claimE2['claim_token']);
            $this->assertSame($roomE, $resultE->roomId);

            $roomF = $this->p11Room($this->property, [
                'readiness_state' => 'blocked',
                'cleanliness_status' => 'dirty',
            ]);
            $sourceF = $this->p11CheckoutSource($this->property, $roomF);
            $beforeF = $this->outcomeCounts();
            $claimF = $delivery->claimAvailable($this->property->id, $sourceF['handoff']->id, 60);
            $this->expectDomain(function () use ($service, $delivery, $sourceF, $claimF) {
                try {
                    $service->consumeClaimed($this->property->id, $sourceF['handoff']->id, $claimF['claim_token']);
                } catch (DomainException $exception) {
                    $delivery->markFailed(
                        $this->property->id,
                        $sourceF['handoff']->id,
                        $claimF['claim_token'],
                        $exception->getMessage(),
                        $this->databaseRetryAt(),
                    );

                    throw $exception;
                }
            }, HousekeepingCheckoutTurnoverIntakeService::ERROR_ROOM_LIFECYCLE_CONFLICT);
            $afterF = $this->outcomeCounts();
            $this->assertSame($beforeF, $afterF);
            $failedF = DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $sourceF['handoff']->id)->first();
            $this->assertSame('FAILED', $failedF->delivery_status);
            $this->assertSame(HousekeepingCheckoutTurnoverIntakeService::ERROR_ROOM_LIFECYCLE_CONFLICT, $failedF->last_error_code);

            $this->assertCrossPropertyAccessFailsClosed($service, $delivery);
            $this->assertMalformedSourcesFailClosed($service, $delivery);
            $this->assertDeliveryReplayNeverRerunsCheckout($service);

            // Scenario J: Post-commit crash simulation
            $roomJ = $this->p11Room($this->property);
            $sourceJ = $this->p11CheckoutSource($this->property, $roomJ);

            $service->setPostCommitTestingHookForTesting(function () {
                throw new \RuntimeException('Simulated post-commit crash');
            });

            $beforeJ = $this->outcomeCounts();

            try {
                $service->consumeNextAvailable($this->property->id, 1);
                $this->fail('Scenario J should have crashed before markDelivered.');
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'Simulated post-commit crash') {
                    throw $e; // Re-throw if it's PHPUnit's assertion failure
                }
                $this->assertSame('Simulated post-commit crash', $e->getMessage());
            }

            $service->setPostCommitTestingHookForTesting(null);

            $afterJ = $this->outcomeCounts();
            $this->assertSame($beforeJ['intakes'] + 1, $afterJ['intakes'], 'Scenario J must commit exactly 1 intake.');
            $this->assertSame($beforeJ['tasks'] + 1, $afterJ['tasks'], 'Scenario J must commit exactly 1 task.');
            $this->assertSame($beforeJ['transitions'] + 1, $afterJ['transitions'], 'Scenario J must commit exactly 1 transition.');

            $this->assertSame('CLAIMED', $this->handoffStatus($sourceJ['handoff']->id), 'Scenario J handoff must remain CLAIMED after simulated crash.');
            $this->assertRoomState($roomJ, 'waiting_cleaning', 'dirty');

            // Phase C replay logic: The consumer catches up by replaying Phase B and then proceeding to Phase C
            // We simulate claim expiration so consumeNextAvailable can pick it up.
            $this->waitForClaimExpiry($sourceJ['handoff']->id);

            // Now consumeNextAvailable will pick it up again!
            $resultJ = $service->consumeNextAvailable($this->property->id, 60);

            $this->assertNotNull($resultJ);
            $this->assertTrue($resultJ->replayed, 'Scenario J replay must indicate it was replayed.');
            $this->assertFalse($resultJ->deliveryConfirmationPending, 'Scenario J replay via consumeNextAvailable successfully delivers.');

            $this->assertSame('DELIVERED', $this->handoffStatus($sourceJ['handoff']->id), 'Scenario J handoff must become DELIVERED after explicit recovery.');

            $finalJ = $this->outcomeCounts();
            $this->assertSame($afterJ['intakes'], $finalJ['intakes'], 'Scenario J must not create duplicate facts upon recovery.');
        });
    }

    private function assertCrossPropertyAccessFailsClosed(HousekeepingCheckoutTurnoverIntakeService $service, FrontDeskCheckoutHousekeepingHandoffDeliveryService $delivery): void
    {
        app(CurrentPropertyService::class)->setPropertyId($this->otherProperty->id);
        $room = $this->p11Room($this->otherProperty);
        $source = $this->p11CheckoutSource($this->otherProperty, $room);
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);

        $before = $this->outcomeCounts();
        $this->expectDomain(fn () => $delivery->claimAvailable($this->property->id, $source['handoff']->id, 60), 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
        $this->expectDomain(fn () => $service->consumeClaimed($this->otherProperty->id, $source['handoff']->id, 'not-a-token'), HousekeepingCheckoutTurnoverIntakeService::ERROR_SOURCE_CONFLICT);
        $this->assertSame($before, $this->outcomeCounts());
    }

    private function assertMalformedSourcesFailClosed(HousekeepingCheckoutTurnoverIntakeService $service, FrontDeskCheckoutHousekeepingHandoffDeliveryService $delivery): void
    {
        foreach ([
            'checkout_execution_id' => HousekeepingCheckoutTurnoverIntakeService::ERROR_SOURCE_CONFLICT,
            'reservation_id' => HousekeepingCheckoutTurnoverIntakeService::ERROR_SOURCE_CONFLICT,
            'property_business_date_id' => HousekeepingCheckoutTurnoverIntakeService::ERROR_SOURCE_CONFLICT,
            'source_hash' => HousekeepingCheckoutTurnoverIntakeService::ERROR_SOURCE_CONFLICT,
            'stay_room_mismatch' => HousekeepingCheckoutTurnoverIntakeService::ERROR_SOURCE_CONFLICT,
            'stay_status' => HousekeepingCheckoutTurnoverIntakeService::ERROR_SOURCE_CONFLICT,
            'inactive_room' => HousekeepingCheckoutTurnoverIntakeService::ERROR_ROOM_UNAVAILABLE,
            'room_property' => HousekeepingCheckoutTurnoverIntakeService::ERROR_ROOM_UNAVAILABLE,
        ] as $mutation => $expectedError) {
            $room = $this->p11Room($this->property);
            $source = $this->p11CheckoutSource($this->property, $room);
            $claim = $delivery->claimAvailable($this->property->id, $source['handoff']->id, 60);
            $before = $this->outcomeCounts();

            match ($mutation) {
                'checkout_execution_id' => $this->withoutUserTriggers(['front_desk_checkout_executions'], fn () => DB::table('front_desk_checkout_executions')->where('id', $source['execution']->id)->update(['front_desk_stay_id' => $this->bareAlternateStay()['stay_id']])),
                'reservation_id' => $this->withoutUserTriggers(['front_desk_checkout_executions'], fn () => DB::table('front_desk_checkout_executions')->where('id', $source['execution']->id)->update(['reservation_id' => $this->bareAlternateStay()['reservation_id']])),
                'property_business_date_id' => $this->withoutUserTriggers(['front_desk_checkout_executions'], fn () => DB::table('front_desk_checkout_executions')->where('id', $source['execution']->id)->update(['business_date' => today()->addDay()])),
                'source_hash' => $this->withoutUserTriggers(['front_desk_checkout_executions'], fn () => DB::table('front_desk_checkout_executions')->where('id', $source['execution']->id)->update(['source_hash' => str_repeat('a', 64)])),
                'stay_room_mismatch' => DB::table('front_desk_stays')->where('id', $source['stay']->id)->update(['current_room_id' => null]),
                'stay_status' => DB::table('front_desk_stays')->where('id', $source['stay']->id)->update(['status' => 'IN_HOUSE']),
                'inactive_room' => DB::table('rooms')->where('id', $room)->update(['is_active' => false]),
                'room_property' => DB::table('rooms')->where('id', $room)->update(['property_id' => $this->otherProperty->id]),
            };

            try {
                $service->consumeClaimed($this->property->id, $source['handoff']->id, $claim['claim_token']);
                $this->fail("Mutation {$mutation} expected DomainException {$expectedError}");
            } catch (DomainException $exception) {
                $this->assertSame($expectedError, $exception->getMessage(), "Mutation {$mutation} must fail closed.");
            }
            $this->assertSame($before, $this->outcomeCounts(), "Mutation {$mutation} must fail without Housekeeping outcome.");
        }
    }

    private function assertDeliveryReplayNeverRerunsCheckout(HousekeepingCheckoutTurnoverIntakeService $service): void
    {
        $before = [
            'confirmation_consumptions' => DB::table('checkout_sensitive_confirmation_consumptions')->count(),
            'checkout_executions' => DB::table('front_desk_checkout_executions')->count(),
            'checkout_handoffs' => DB::table('front_desk_checkout_housekeeping_handoffs')->count(),
        ];

        $delivery = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);
        $room = $this->p11Room($this->property);
        $source = $this->p11CheckoutSource($this->property, $room);
        $claim = $delivery->claimAvailable($this->property->id, $source['handoff']->id, 60);
        $result = $service->consumeClaimed($this->property->id, $source['handoff']->id, $claim['claim_token']);
        $delivery->markDelivered($this->property->id, $source['handoff']->id, $claim['claim_token']);
        $replay = $service->consumeClaimed($this->property->id, $source['handoff']->id, $claim['claim_token']);

        $after = [
            'confirmation_consumptions' => DB::table('checkout_sensitive_confirmation_consumptions')->count(),
            'checkout_executions' => DB::table('front_desk_checkout_executions')->count(),
            'checkout_handoffs' => DB::table('front_desk_checkout_housekeeping_handoffs')->count(),
        ];

        $this->assertTrue($replay->replayed);
        $this->assertSame($before['confirmation_consumptions'], $after['confirmation_consumptions']);
        $this->assertSame($before['checkout_executions'] + 1, $after['checkout_executions']);
        $this->assertSame($before['checkout_handoffs'] + 1, $after['checkout_handoffs']);
    }

    private function bareAlternateStay(): array
    {
        $guestId = (string) Str::ulid();
        $reservationId = (string) Str::ulid();
        $stayId = (string) Str::ulid();

        DB::table('guests')->insert([
            'id' => $guestId,
            'property_id' => $this->property->id,
            'guest_code' => 'P11ALT-' . Str::upper(Str::random(5)),
            'full_name' => 'P11 Alternate Guest',
            'guest_type' => 'individual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('reservations')->insert([
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
        DB::table('front_desk_stays')->insert([
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

    private function expectDomain(callable $callback, string $expectedMessage): void
    {
        try {
            $callback();
            $this->fail("Expected DomainException {$expectedMessage}");
        } catch (DomainException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }

    private function databaseRetryAt(): \DateTimeImmutable
    {
        $row = DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' + interval '5 minutes' AS retry_at");

        return new \DateTimeImmutable((string) $row->retry_at, new \DateTimeZone('UTC'));
    }

    /**
     * @param list<string> $tables
     */
    private function withoutUserTriggers(array $tables, callable $callback): mixed
    {
        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
        }

        try {
            return $callback();
        } finally {
            foreach (array_reverse($tables) as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
        }
    }

    private function assertNoOutcomeForHandoff(string $handoffId): void
    {
        $this->assertSame(0, DB::table('housekeeping_checkout_turnover_intakes')->where('front_desk_checkout_housekeeping_handoff_id', $handoffId)->count());
        $this->assertSame(0, DB::table('housekeeping_room_readiness_transitions')->where('source_id', $handoffId)->where('transition_type', 'CHECKOUT_TURNOVER_INTAKE')->count());
    }

    private function assertScenarioCounts(int $intakes, int $tasks, int $transitions, int $audits): void
    {
        $this->assertSame($intakes, DB::connection('pgsql_concurrency')->table('housekeeping_checkout_turnover_intakes')->count());
        $this->assertSame($tasks, DB::connection('pgsql_concurrency')->table('cleaning_tasks')->where('task_type', 'checkout_cleaning')->count());
        $this->assertSame($transitions, DB::connection('pgsql_concurrency')->table('housekeeping_room_readiness_transitions')->where('transition_type', 'CHECKOUT_TURNOVER_INTAKE')->count());
        $this->assertSame($audits, DB::connection('pgsql_concurrency')->table('audit_logs')->where('event', 'housekeeping_checkout_turnover_intake_committed')->count());
    }

    private function outcomeCounts(): array
    {
        return [
            'intakes' => DB::table('housekeeping_checkout_turnover_intakes')->count(),
            'tasks' => DB::table('cleaning_tasks')->where('task_type', 'checkout_cleaning')->count(),
            'transitions' => DB::table('housekeeping_room_readiness_transitions')->where('transition_type', 'CHECKOUT_TURNOVER_INTAKE')->count(),
            'audits' => DB::table('audit_logs')->where('event', 'housekeeping_checkout_turnover_intake_committed')->count(),
        ];
    }

    private function handoffStatus(string $handoffId): string
    {
        return (string) DB::connection('pgsql_concurrency')
            ->table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoffId)
            ->value('delivery_status');
    }

    private function assertRoomState(string $roomId, string $readiness, string $cleanliness): void
    {
        $room = DB::connection('pgsql_concurrency')->table('rooms')->where('id', $roomId)->first();
        $this->assertSame($readiness, $room->readiness_state);
        $this->assertSame($cleanliness, $room->cleanliness_status);
    }
}
