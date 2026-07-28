<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\CheckoutSensitiveConfirmationConsumption;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskCheckoutHousekeepingHandoffStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureCheckoutFinalReviewStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecutionService;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;
use Tests\Postgres\Operations\FrontDesk\Concerns\ManagesConcurrencyDatabase;
use Tests\Postgres\Operations\FrontDesk\Support\P9CheckoutExecutionConcurrencyCoordinator;
use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionIsolatedConcurrencyProofTest extends PostgresTestCase
{
    use ManagesConcurrencyDatabase;

    private Company $company;
    private Property $property;
    private User $actor;
    private string $markerDir;
    private bool $concurrencyDbCleaned = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrencyDatabase('ivorq_concurrency_p9_', '2026-07-28 10:00:00');
        $this->markerDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p9_markers_' . Str::random(8);
        mkdir($this->markerDir, 0700, true);
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        if (!$this->concurrencyDbCleaned) {
            $this->tearDownConcurrencyDatabase();
        }
        $this->rmdirRecursive($this->markerDir);
        parent::tearDown();
    }

    private function cleanUpConcurrencyDbOnce(): void
    {
        if (!$this->concurrencyDbCleaned) {
            $this->tearDownConcurrencyDatabase();
            $this->concurrencyDbCleaned = true;
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function seedFixtures(): void
    {
        $prev = config('database.default');
        DB::setDefaultConnection('pgsql_concurrency');
        config(['database.default' => 'pgsql_concurrency']);
        try {
            $this->company = Company::create(['name' => 'P9 Iso Co', 'slug' => 'p9-iso-co-' . Str::lower(Str::random(6)), 'is_active' => true]);
            $this->property = Property::on('pgsql_concurrency')->create(['company_id' => $this->company->id, 'name' => 'P9 Iso Prop', 'slug' => 'p9-iso-prop-' . Str::lower(Str::random(6)), 'code' => 'P9I' . Str::upper(Str::random(2)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
            $this->actor = User::on('pgsql_concurrency')->create(['name' => 'P9 Iso Actor', 'email' => 'p9-iso-' . Str::lower(Str::random(6)) . '@test', 'password' => bcrypt('password'), 'is_active' => true]);
            \Modules\Foundation\Authorization\Models\Permission::firstOrCreate(['name' => \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION, 'guard_name' => 'web']);
            $this->actor->givePermissionTo(\Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION);
            app(CurrentPropertyService::class)->setPropertyId($this->property->id);
            // Create one business date per property (unique constraint)
            PropertyBusinessDate::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'business_date' => Carbon::now()->toDateString(), 'status' => PropertyBusinessDateStatusEnum::Open, 'is_open' => true, 'timezone_snapshot' => 'UTC', 'opened_by' => $this->actor->id, 'opened_at' => Carbon::now()]);
        } finally {
            DB::setDefaultConnection($prev);
            config(['database.default' => $prev]);
        }
    }

    private function actingAsConcurrencyActor(array $fixture): void
    {
        $this->actingAs($this->actor, 'web');
        session([
            'active_property_id'  => $this->property->id,
            'current_property_id' => $this->property->id,
            'active_company_id'   => $this->property->company_id,
            CheckoutSensitiveConfirmationService::SESSION_KEY => [
                CheckoutSensitiveConfirmationService::INTENT => [
                    'actor_id'                 => $this->actor->id,
                    'intent'                   => CheckoutSensitiveConfirmationService::INTENT,
                    'company_id'               => $this->property->company_id,
                    'property_id'              => $this->property->id,
                    'front_desk_stay_id'       => $fixture['front_desk_stay_id'],
                    'checkout_idempotency_key' => $fixture['checkout_idempotency_key'],
                    'issuance_id'              => $fixture['issuance_id'],
                    'confirmation_identity'    => $fixture['confirmation_identity'],
                    'confirmation_fingerprint' => $fixture['confirmation_fingerprint'],
                    'session_fingerprint'      => $fixture['session_fingerprint'],
                    'confirmed_at'             => $fixture['confirmed_at'],
                    'expires_at'               => $fixture['expires_at'],
                ],
            ],
        ]);
    }

    private function createCheckoutFixture(string $roomNum, string $idempotencyKey, ?string $stayIdOverride = null): array
    {
        $prev = config('database.default');
        DB::setDefaultConnection('pgsql_concurrency');
        config(['database.default' => 'pgsql_concurrency']);
        try {
            $guest = Guest::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'guest_code' => 'G-' . Str::upper(Str::random(6)), 'full_name' => 'Guest ' . Str::random(4), 'guest_type' => 'individual']);
            $res = Reservation::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'primary_guest_id' => $guest->id, 'reservation_number' => 'R-' . Str::upper(Str::random(6)), 'arrival_date' => Carbon::now()->toDateString(), 'departure_date' => Carbon::now()->addDays(2)->toDateString(), 'nights' => 2, 'reservation_source' => 'direct', 'status' => 'checked_in', 'reserved_room_type' => 'standard']);
            $stayId = $stayIdOverride ?? (string) Str::ulid();
            $stay = FrontDeskStay::on('pgsql_concurrency')->create(['id' => $stayId, 'property_id' => $this->property->id, 'reservation_id' => $res->id, 'guest_id' => $res->primary_guest_id, 'status' => FrontDeskStayStatusEnum::InHouse->value, 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
            $occ = Carbon::now();
            FrontDeskDepartureCheckoutFinalReview::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'front_desk_stay_id' => $stay->id, 'reservation_id' => $stay->reservation_id, 'guest_id' => $stay->guest_id, 'final_review_status' => FrontDeskDepartureCheckoutFinalReviewStatusEnum::CheckoutFinalReviewReady->value, 'idempotency_key' => 'review-' . Str::ulid(), 'source_hash' => hash('sha256', implode('|', [$stay->id, 'CHECKOUT_FINAL_REVIEW_READY', '', $occ->toISOString()])), 'occurred_at' => $occ, 'created_by' => $this->actor->id, 'created_at' => $occ]);
            $issId = (string) Str::ulid(); $ident = (string) Str::ulid();
            $sessId = session()->getId(); $sessFp = CheckoutSensitiveConfirmationService::fingerprintSession($sessId);
            $confAt = Carbon::now(); $expAt = Carbon::now()->addMinutes(15);
            $fp = hash('sha256', implode('|', [CheckoutSensitiveConfirmationService::INTENT, $ident, $this->actor->id, $this->property->company_id, $this->property->id, $stay->id, $idempotencyKey, $sessFp, $confAt->toISOString(), $expAt->toISOString()]));
            DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_issuances')->insert(['id' => $issId, 'confirmation_identity' => $ident, 'intent' => CheckoutSensitiveConfirmationService::INTENT, 'actor_id' => $this->actor->id, 'company_id' => $this->property->company_id, 'property_id' => $this->property->id, 'front_desk_stay_id' => $stay->id, 'checkout_idempotency_key' => $idempotencyKey, 'session_fingerprint' => $sessFp, 'confirmation_fingerprint' => $fp, 'confirmed_at' => $confAt, 'expires_at' => $expAt, 'created_at' => $confAt]);
            return ['property_id' => $this->property->id, 'company_id' => $this->property->company_id, 'actor_id' => $this->actor->id, 'front_desk_stay_id' => $stay->id, 'reservation_id' => $res->id, 'checkout_idempotency_key' => $idempotencyKey, 'issuance_id' => $issId, 'confirmation_identity' => $ident, 'confirmation_fingerprint' => $fp, 'session_fingerprint' => $sessFp, 'session_id' => $sessId, 'confirmed_at' => $confAt->toISOString(), 'expires_at' => $expAt->toISOString(), 'marker_dir' => $this->markerDir, 'database' => $this->concurrencyDb, 'night_audit_active' => false, 'stay' => $stay];
        } finally {
            DB::setDefaultConnection($prev);
            config(['database.default' => $prev]);
        }
    }

    // ═══ Scenario A: same stay, same key ═══

    public function test_scenario_a_same_stay_same_key_one_commits_one_replays_with_real_blocking(): void
    {
        $f = $this->createCheckoutFixture('A01', 'p9-iso-key-A');
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        try {
            $c->spawnWorker('lock_hold', $f);
            $locked = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'a_locked.json', 15);
            $pidA = (int) $locked['backend_pid'];
            $this->assertGreaterThan(0, $pidA);

            $c->spawnWorker('execute_blocked', $f);
            $ready = $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_ready.json', 15);
            $pidB = (int) $ready['backend_pid'];
            $this->assertGreaterThan(0, $pidB);
            $this->assertNotSame($pidA, $pidB);

            $this->assertTrue($c->proveBlocking($pidB, $pidA, 15), 'Worker B must block behind Worker A');

            $c->releaseWorker($this->markerDir . DIRECTORY_SEPARATOR . 'release_a');
            $deadline = time() + 20;
            while (time() < $deadline && ($c->isWorkerRunning(0) || $c->isWorkerRunning(1))) usleep(200000);

            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, FrontDeskStay::on('pgsql_concurrency')->find($f['front_desk_stay_id'])->status);
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_housekeeping_handoffs')->count());
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('audit_logs')->where('event', 'front_desk_checkout_completed')->count());
        } finally {
            $c->terminateAllWorkers();
            $this->cleanUpConcurrencyDbOnce();
        }
    }

    // ═══ Scenario B: same stay, different keys ═══

    public function test_scenario_b_same_stay_different_keys_one_wins_one_already_completed(): void
    {
        $fA = $this->createCheckoutFixture('B01', 'p9-iso-key-B1');
        $fB = $this->createCheckoutFixture('B01', 'p9-iso-key-B2', $fA['front_desk_stay_id']);
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        try {
            $c->spawnWorker('lock_hold', $fA);
            $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'a_locked.json', 15);
            $c->spawnWorker('execute_blocked', $fB);
            $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_ready.json', 15);
            $c->releaseWorker($this->markerDir . DIRECTORY_SEPARATOR . 'release_a');
            $deadline = time() + 20;
            while (time() < $deadline && ($c->isWorkerRunning(0) || $c->isWorkerRunning(1))) usleep(200000);
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, FrontDeskStay::on('pgsql_concurrency')->find($fA['front_desk_stay_id'])->status);
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_housekeeping_handoffs')->count());
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
        } finally {
            $c->terminateAllWorkers();
            $this->cleanUpConcurrencyDbOnce();
        }
    }

    // ═══ Scenario C: same key, different stays ═══

    public function test_scenario_c_same_key_different_stays_one_wins_one_idempotency_conflict(): void
    {
        $fA = $this->createCheckoutFixture('C01', 'p9-iso-key-C');
        $fB = $this->createCheckoutFixture('C02', 'p9-iso-key-C');
        $this->assertNotSame($fA['front_desk_stay_id'], $fB['front_desk_stay_id']);
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        try {
            $c->spawnWorker('lock_hold', $fA);
            $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'a_locked.json', 15);
            $c->spawnWorker('execute_blocked', $fB);
            $c->waitForMarker($this->markerDir . DIRECTORY_SEPARATOR . 'b_ready.json', 15);
            $c->releaseWorker($this->markerDir . DIRECTORY_SEPARATOR . 'release_a');
            $deadline = time() + 20;
            while (time() < $deadline && ($c->isWorkerRunning(0) || $c->isWorkerRunning(1))) usleep(200000);
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $checkedOut = FrontDeskStay::on('pgsql_concurrency')->where('status', FrontDeskStayStatusEnum::CheckedOut->value)->count();
            $this->assertLessThanOrEqual(1, $checkedOut);
            $this->assertSame(1, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
        } finally {
            $c->terminateAllWorkers();
            $this->cleanUpConcurrencyDbOnce();
        }
    }

    // ═══ Scenario D: checkout vs Night Audit ═══

    public function test_scenario_d_night_audit_active_blocks_checkout(): void
    {
        $f = $this->createCheckoutFixture('D01', 'p9-iso-key-D');
        $this->actingAsConcurrencyActor($f);

        $mockNa = \Mockery::mock(\Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService::class);
        $mockNa->shouldReceive('attest')->andReturn(new \Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation(\Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation::VERSION, \Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation::STATUS_ACTIVE, \Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation::OWNER, false, true, $this->property->id, 'date-id', '2099-01-01', 'UTC', hash('sha256', 'na-active'), now()->toISOString(), []));
        app()->instance(\Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService::class, $mockNa);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(FrontDeskCheckoutExecutionService::ERROR_NIGHT_AUDIT_ACTIVE);
        app(FrontDeskCheckoutExecutionService::class)->execute($this->actor, $f['front_desk_stay_id'], $f['checkout_idempotency_key']);
        $this->assertSame(0, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
        $this->assertSame(FrontDeskStayStatusEnum::InHouse, FrontDeskStay::on('pgsql_concurrency')->find($f['front_desk_stay_id'])->status);
    }

    // ═══ Scenario E: confirmation expiry (create pre-expired issuance) ═══

    public function test_scenario_e_expired_confirmation_fails_closed(): void
    {
        // Create a fixture with an already-expired confirmation
        $prev = config('database.default');
        DB::setDefaultConnection('pgsql_concurrency');
        config(['database.default' => 'pgsql_concurrency']);
        try {
            $guest = Guest::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'guest_code' => 'G-' . Str::upper(Str::random(6)), 'full_name' => 'Guest ' . Str::random(4), 'guest_type' => 'individual']);
            $res = Reservation::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'primary_guest_id' => $guest->id, 'reservation_number' => 'R-' . Str::upper(Str::random(6)), 'arrival_date' => Carbon::now()->toDateString(), 'departure_date' => Carbon::now()->addDays(2)->toDateString(), 'nights' => 2, 'reservation_source' => 'direct', 'status' => 'checked_in', 'reserved_room_type' => 'standard']);
            $stay = FrontDeskStay::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'reservation_id' => $res->id, 'guest_id' => $res->primary_guest_id, 'status' => FrontDeskStayStatusEnum::InHouse->value, 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
            $occ = Carbon::now();
            FrontDeskDepartureCheckoutFinalReview::on('pgsql_concurrency')->create(['property_id' => $this->property->id, 'front_desk_stay_id' => $stay->id, 'reservation_id' => $stay->reservation_id, 'guest_id' => $stay->guest_id, 'final_review_status' => FrontDeskDepartureCheckoutFinalReviewStatusEnum::CheckoutFinalReviewReady->value, 'idempotency_key' => 'review-' . Str::ulid(), 'source_hash' => hash('sha256', implode('|', [$stay->id, 'CHECKOUT_FINAL_REVIEW_READY', '', $occ->toISOString()])), 'occurred_at' => $occ, 'created_by' => $this->actor->id, 'created_at' => $occ]);

            $idempotencyKey = 'p9-iso-key-E';
            $issId = (string) Str::ulid(); $ident = (string) Str::ulid();
            $sessId = session()->getId(); $sessFp = CheckoutSensitiveConfirmationService::fingerprintSession($sessId);
            $confAt = Carbon::now()->subMinutes(20);
            $expAt = Carbon::now()->subMinutes(5);
            $fp = hash('sha256', implode('|', [CheckoutSensitiveConfirmationService::INTENT, $ident, $this->actor->id, $this->property->company_id, $this->property->id, $stay->id, $idempotencyKey, $sessFp, $confAt->toISOString(), $expAt->toISOString()]));
            DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_issuances')->insert(['id' => $issId, 'confirmation_identity' => $ident, 'intent' => CheckoutSensitiveConfirmationService::INTENT, 'actor_id' => $this->actor->id, 'company_id' => $this->property->company_id, 'property_id' => $this->property->id, 'front_desk_stay_id' => $stay->id, 'checkout_idempotency_key' => $idempotencyKey, 'session_fingerprint' => $sessFp, 'confirmation_fingerprint' => $fp, 'confirmed_at' => $confAt, 'expires_at' => $expAt, 'created_at' => $confAt]);

            $fixture = [
                'front_desk_stay_id' => $stay->id, 'checkout_idempotency_key' => $idempotencyKey,
                'issuance_id' => $issId, 'confirmation_identity' => $ident,
                'confirmation_fingerprint' => $fp, 'session_fingerprint' => $sessFp,
                'confirmed_at' => $confAt->toISOString(), 'expires_at' => $expAt->toISOString(),
            ];
            $this->actingAsConcurrencyActor($fixture);

            try {
                app(FrontDeskCheckoutExecutionService::class)->execute($this->actor, $stay->id, $idempotencyKey);
                $this->fail('Expired confirmation must throw');
            } catch (\DomainException $e) {
                // Expected — confirmation expired
            }
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $this->assertSame(0, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
            $this->assertSame(FrontDeskStayStatusEnum::InHouse, FrontDeskStay::on('pgsql_concurrency')->find($stay->id)->status);
        } finally {
            DB::setDefaultConnection($prev);
            config(['database.default' => $prev]);
        }
    }

    // ═══ Scenario G: response-loss replay ═══

    public function test_scenario_g_response_loss_replay_returns_existing_execution(): void
    {
        $f = $this->createCheckoutFixture('G01', 'p9-iso-key-G');
        $this->actingAsConcurrencyActor($f);
        $r1 = app(FrontDeskCheckoutExecutionService::class)->execute($this->actor, $f['front_desk_stay_id'], $f['checkout_idempotency_key']);
        $this->assertFalse($r1->replayed);
        $execId = $r1->checkoutExecutionId; $execCount = DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count();
        $r2 = app(FrontDeskCheckoutExecutionService::class)->execute($this->actor, $f['front_desk_stay_id'], $f['checkout_idempotency_key']);
        $this->assertTrue($r2->replayed);
        $this->assertSame($execId, $r2->checkoutExecutionId);
        $this->assertSame($execCount, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
        $this->assertSame(1, DB::connection('pgsql_concurrency')->table('checkout_sensitive_confirmation_consumptions')->count());
        $this->assertSame(1, DB::connection('pgsql_concurrency')->table('front_desk_checkout_housekeeping_handoffs')->count());
        $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, FrontDeskStay::on('pgsql_concurrency')->find($f['front_desk_stay_id'])->status);
        $this->assertSame(1, DB::connection('pgsql_concurrency')->table('audit_logs')->where('event', 'front_desk_checkout_completed')->count());
    }

    // ═══ Scenario H: different Properties ═══

    public function test_scenario_h_different_properties_independent_execution_distinct_pids(): void
    {
        Property::on('pgsql_concurrency')->create(['company_id' => $this->company->id, 'name' => 'P9 Iso Prop 2', 'slug' => 'p9-iso-prop2-' . Str::lower(Str::random(6)), 'code' => 'P9J' . Str::upper(Str::random(2)), 'timezone' => 'UTC', 'currency' => 'USD', 'is_active' => true]);
        $f1 = $this->createCheckoutFixture('H01', 'p9-iso-key-H1');
        $f2 = $this->createCheckoutFixture('H02', 'p9-iso-key-H2');
        $c = new P9CheckoutExecutionConcurrencyCoordinator();
        try {
            $c->spawnWorker('execute', $f1);
            $c->spawnWorker('execute', $f2);
            $deadline = time() + 20;
            while (time() < $deadline && ($c->isWorkerRunning(0) || $c->isWorkerRunning(1))) usleep(200000);
            $r1 = json_decode($c->getWorkerOutput(0), true) ?: [];
            $r2 = json_decode($c->getWorkerOutput(1), true) ?: [];
            $this->assertGreaterThan(0, $r1['backend_pid'] ?? 0);
            $this->assertGreaterThan(0, $r2['backend_pid'] ?? 0);
            $this->assertNotSame($r1['backend_pid'] ?? 0, $r2['backend_pid'] ?? 0, 'Distinct backend PIDs required');
            $this->assertSame(2, DB::connection('pgsql_concurrency')->table('front_desk_checkout_executions')->count());
            $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, FrontDeskStay::on('pgsql_concurrency')->find($f1['front_desk_stay_id'])->status);
            $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, FrontDeskStay::on('pgsql_concurrency')->find($f2['front_desk_stay_id'])->status);
        } finally {
            $c->terminateAllWorkers();
            $this->cleanUpConcurrencyDbOnce();
        }
    }

    // ═══ Scenario I: bounded retry ═══

    public function test_scenario_i_bounded_retry_policy_present(): void
    {
        $source = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php'));
        $this->assertStringContainsString('private const MAX_ATTEMPTS = 3;', $source);
        $this->assertStringContainsString("in_array(\$sqlState, ['40001', '40P01'], true)", $source);
        $this->assertStringContainsString('isRetryableSqlState', $source);
        $this->assertStringContainsString('resolveAuthorizedContext', $source);
        $this->assertStringContainsString('committedReplay(', $source);
    }

    // ═══ No global application lock ═══

    public function test_no_global_application_lock(): void
    {
        $source = file_get_contents(base_path('Modules/Operations/FrontDesk/Services/FrontDeskCheckoutExecutionService.php'));
        $this->assertStringNotContainsString('Cache::lock', $source);
        $this->assertStringNotContainsString('GET_LOCK', $source);
        $this->assertStringNotContainsString('pg_advisory_lock', $source);
    }
}
