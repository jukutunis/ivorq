<?php

namespace Tests\Postgres\Operations\GeneralCashier;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutObligationStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutObligationProjectionService;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestRefundTransaction;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;

class GeneralCashierCheckoutObligationProjectionTest extends PostgresTestCase
{
    use DatabaseMigrations;
    use CreatesGuestLedgerFolioData;

    private GeneralCashierCheckoutObligationProjectionService $service;
    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $actor;
    private User $otherActor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpGuestLedgerFolioFixture();

        $this->company = $this->glfCompany;
        $this->property = $this->glfProperty;
        $this->otherProperty = $this->glfOtherProperty;
        $this->actor = $this->glfActor;
        $this->otherActor = $this->glfOtherActor;

        Permission::firstOrCreate([
            'name' => GeneralCashierCheckoutObligationProjectionService::VIEW_PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo(GeneralCashierCheckoutObligationProjectionService::VIEW_PERMISSION);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        session([
            'active_property_id' => $this->property->id,
            'current_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
        ]);
        auth()->login($this->actor);
        $this->actingAs($this->actor);

        $this->service = app(GeneralCashierCheckoutObligationProjectionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_clear_when_no_authoritative_cashier_obligation_is_linked(): void
    {
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationProjectionService::PROJECTION_VERSION, $projection->projection_version);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationClear, $projection->status);
        $this->assertSame($this->property->id, $projection->property_id);
        $this->assertSame($stay->id, $projection->front_desk_stay_id);
        $this->assertSame($reservation->id, $projection->reservation_id);
        $this->assertSame($reservation->primary_guest_id, $projection->guest_id);
        $this->assertSame([], $projection->related_guest_payment_transaction_ids);
        $this->assertSame([], $projection->related_cashier_session_ids);
        $this->assertSame('NO_AUTHORITATIVE_CASHIER_OBLIGATIONS', $projection->markers['cashier_obligation_scope_marker']);
        $this->assertSame('CASHIER_ACCOUNTABILITY_CLEAR', $projection->markers['cashier_accountability_marker']);
        $this->assertNotEmpty($projection->evaluated_at);
        $this->assertNotEmpty($projection->source_fingerprint);
        $this->assertArrayHasKey('source_identifiers', get_object_vars($projection));
    }

    public function test_open_cash_payment_session_blocks_checkout_obligation(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $payment = $this->payment($reservation, $guest, $session);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationBlocked, $projection->status);
        $this->assertContains('CASHIER_SESSION_OPEN', $projection->blocker_codes);
        $this->assertContains($payment->id, $projection->related_guest_payment_transaction_ids);
        $this->assertContains($session->id, $projection->related_cashier_session_ids);
        $this->assertSame('CASHIER_ACCOUNTABILITY_BLOCKED', $projection->markers['cashier_accountability_marker']);
    }

    public function test_closed_linked_payment_fails_closed_when_accountability_evidence_is_unavailable(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::CLOSED);
        $this->payment($reservation, $guest, $session);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationEvidenceUnavailable, $projection->status);
        $this->assertContains('CASHIER_SESSION_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $projection->evidence_unavailable_codes);
        $this->assertSame('CASHIER_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE', $projection->markers['cashier_accountability_marker']);
    }

    public function test_conflicting_source_snapshot_requires_review(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $this->payment($reservation, $guest, $session, [
            'cashier_session_id' => $session->id,
            'cashier_user_id' => (string) Str::ulid(),
            'cashier_session_status' => 'OPEN',
        ]);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $projection->status);
        $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $projection->review_reasons);
        $this->assertSame('CASHIER_ACCOUNTABILITY_REVIEW_REQUIRED', $projection->markers['cashier_accountability_marker']);
    }

    public function test_deposit_and_refund_cash_sources_are_included_as_authoritative_session_obligations(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $deposit = $this->deposit($reservation, $guest, $session);
        $refund = $this->refund($reservation, $guest, $session, $deposit);

        $projection = $this->service->project($this->actor, $stay->id);

        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationBlocked, $projection->status);
        $this->assertContains($session->id, $projection->related_cashier_session_ids);
        $this->assertContains($deposit->id, $projection->source_identifiers['related_guest_deposit_transaction_ids']);
        $this->assertContains($refund->id, $projection->source_identifiers['related_guest_refund_transaction_ids']);
    }

    public function test_authorization_occurs_before_stay_lookup(): void
    {
        $unauthorized = $this->userWithoutPermission();
        auth()->login($unauthorized);
        $this->actingAs($unauthorized);

        $this->expectException(AuthorizationException::class);
        $this->service->project($unauthorized, (string) Str::ulid());
    }

    public function test_unknown_and_cross_property_stays_are_non_disclosing_after_authorization(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->project($this->actor, (string) Str::ulid());
    }

    public function test_cross_property_stay_matches_unknown_stay_denial(): void
    {
        $reservation = $this->makeGlfReservation($this->otherProperty);
        $stay = $this->makeStay($reservation, $this->otherProperty);

        $this->expectException(NotFoundException::class);
        $this->service->project($this->actor, $stay->id);
    }

    public function test_actor_mismatch_is_rejected(): void
    {
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);

        $this->expectException(AuthorizationException::class);
        $this->service->project($this->otherActor, $stay->id);
    }

    public function test_fingerprint_excludes_evaluated_at_and_changes_with_source_facts(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $this->payment($reservation, $guest, $session);

        Carbon::setTestNow(Carbon::parse('2026-07-14 10:00:00'));
        $first = $this->service->project($this->actor, $stay->id);

        Carbon::setTestNow(Carbon::parse('2026-07-14 10:05:00'));
        $second = $this->service->project($this->actor, $stay->id);

        $this->assertNotSame($first->evaluated_at, $second->evaluated_at);
        $this->assertSame($first->source_fingerprint, $second->source_fingerprint);

        $session->forceFill([
            'status' => CashierSessionStatusEnum::CLOSED->value,
            'closed_at' => Carbon::parse('2026-07-14 10:06:00'),
            'closed_by' => $this->actor->id,
        ])->save();

        $third = $this->service->project($this->actor, $stay->id);
        $this->assertNotSame($first->source_fingerprint, $third->source_fingerprint);
    }

    public function test_projection_is_zero_write_across_source_tables(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $this->payment($reservation, $guest, $session);
        $before = $this->sourceCounts();

        $this->service->project($this->actor, $stay->id);

        $this->assertSame($before, $this->sourceCounts());
    }

    public function test_projection_requires_top_level_read_transaction(): void
    {
        $reservation = $this->makeGlfReservation();
        $stay = $this->makeStay($reservation);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(GeneralCashierCheckoutObligationProjectionService::STABLE_ERROR_NESTED_TX);

        DB::transaction(fn () => $this->service->project($this->actor, $stay->id));
    }

    public function test_repeatable_read_snapshot_remains_coherent_during_concurrent_session_mutation(): void
    {
        [$stay, $reservation, $guest] = $this->stayTriplet();
        $session = $this->cashierSession(CashierSessionStatusEnum::OPEN);
        $this->payment($reservation, $guest, $session);

        $preMutation = $this->service->project($this->actor, $stay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationBlocked, $preMutation->status);

        $results = $this->spawnConcurrencyWorkers($stay, $session);
        $projectionWorker = $results[0];
        $mutationWorker = $results[1];

        $this->assertArrayNotHasKey('error', $projectionWorker, $projectionWorker['error'] ?? '');
        $this->assertArrayNotHasKey('error', $mutationWorker, $mutationWorker['error'] ?? '');
        $this->assertSame(0, $projectionWorker['_exit_code']);
        $this->assertSame(0, $mutationWorker['_exit_code']);
        $this->assertNotSame($projectionWorker['php_pid'], $mutationWorker['php_pid']);
        $this->assertNotSame($projectionWorker['pg_backend_pid'], $mutationWorker['pg_backend_pid']);
        $this->assertTrue($mutationWorker['mutator_executed']);

        $this->assertSame($preMutation->status->value, $projectionWorker['status']);
        $this->assertSame($preMutation->source_fingerprint, $projectionWorker['source_fingerprint']);
        $this->assertSame($preMutation->blocker_codes, $projectionWorker['blocker_codes']);

        $postMutation = $this->service->project($this->actor, $stay->id);
        $this->assertSame(GeneralCashierCheckoutObligationStatusEnum::CashierObligationReviewRequired, $postMutation->status);
        $this->assertContains('CASHIER_SESSION_SOURCE_SNAPSHOT_CONFLICT', $postMutation->review_reasons);
        $this->assertNotSame($postMutation->source_fingerprint, $projectionWorker['source_fingerprint']);
    }

    private function stayTriplet(): array
    {
        $reservation = $this->makeGlfReservation();
        $guest = Guest::withoutGlobalScopes()->findOrFail($reservation->primary_guest_id);
        $stay = $this->makeStay($reservation);

        return [$stay, $reservation, $guest];
    }

    private function makeStay(Reservation $reservation, ?Property $property = null): FrontDeskStay
    {
        $property = $property ?? $this->property;

        $stay = new FrontDeskStay();
        $stay->forceFill([
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $reservation->primary_guest_id,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        return $stay->fresh();
    }

    private function cashierSession(CashierSessionStatusEnum $status): CashierSession
    {
        $session = new CashierSession();
        $session->forceFill([
            'property_id' => $this->property->id,
            'cashier_user_id' => $this->actor->id,
            'status' => $status->value,
            'opened_at' => Carbon::parse('2026-07-14 08:00:00'),
            'opened_by' => $this->actor->id,
            'closed_at' => $status === CashierSessionStatusEnum::CLOSED ? Carbon::parse('2026-07-14 09:00:00') : null,
            'closed_by' => $status === CashierSessionStatusEnum::CLOSED ? $this->actor->id : null,
        ])->save();

        return $session->fresh();
    }

    private function payment(Reservation $reservation, Guest $guest, CashierSession $session, array $snapshot = []): GuestPaymentTransaction
    {
        $payment = new GuestPaymentTransaction();
        $payment->forceFill([
            'property_id' => $this->property->id,
            'payment_number' => 'GPM-GCA1-' . Str::upper(Str::random(6)),
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'currency' => 'USD',
            'amount' => '0.01',
            'tender_type' => 'CASH',
            'cashier_session_id' => $session->id,
            'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'gc-a1-payment-' . Str::ulid(),
            'recorded_at' => Carbon::parse('2026-07-14 08:10:00'),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => $snapshot ?: $this->snapshot($session),
            'created_at' => now(),
            'updated_at' => now(),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        return $payment->fresh();
    }

    private function deposit(Reservation $reservation, Guest $guest, CashierSession $session): GuestDepositTransaction
    {
        $deposit = new GuestDepositTransaction();
        $deposit->forceFill([
            'property_id' => $this->property->id,
            'deposit_number' => 'GDP-GCA1-' . Str::upper(Str::random(6)),
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'currency' => 'USD',
            'amount' => '10.00',
            'tender_type' => 'CASH',
            'cashier_session_id' => $session->id,
            'lifecycle_status' => GuestDepositLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'gc-a1-deposit-' . Str::ulid(),
            'recorded_at' => Carbon::parse('2026-07-14 08:20:00'),
            'recorded_by' => $this->actor->id,
            'source_snapshot' => $this->snapshot($session),
            'created_at' => now(),
            'updated_at' => now(),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->save();

        return $deposit->fresh();
    }

    private function refund(
        Reservation $reservation,
        Guest $guest,
        CashierSession $session,
        GuestDepositTransaction $deposit
    ): GuestRefundTransaction {
        $refund = new GuestRefundTransaction();
        $refund->forceFill([
            'property_id' => $this->property->id,
            'refund_number' => 'GRF-GCA1-' . Str::upper(Str::random(6)),
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'currency' => 'USD',
            'amount' => '1.00',
            'tender_type' => 'CASH',
            'cashier_session_id' => $session->id,
            'refund_source_type' => 'GUEST_DEPOSIT',
            'guest_payment_transaction_id' => null,
            'guest_deposit_transaction_id' => $deposit->id,
            'reason_code' => 'GC_A1_TEST',
            'refund_idempotency_key' => 'gc-a1-refund-' . Str::ulid(),
            'refunded_at' => Carbon::parse('2026-07-14 08:30:00'),
            'refunded_by' => $this->actor->id,
            'source_snapshot' => $this->snapshot($session),
            'created_at' => now(),
            'created_by' => $this->actor->id,
        ])->save();

        return $refund->fresh();
    }

    private function snapshot(CashierSession $session): array
    {
        return [
            'cashier_session_id' => $session->id,
            'cashier_user_id' => $session->cashier_user_id,
            'cashier_session_status' => $session->status->value,
        ];
    }

    private function userWithoutPermission(): User
    {
        $user = User::create([
            'name' => 'GC A1 No Permission',
            'email' => 'gc-a1-no-perm-' . Str::lower(Str::random(8)) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->properties()->attach($this->property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function sourceCounts(): array
    {
        return [
            'front_desk_stays' => DB::table('front_desk_stays')->count(),
            'guest_payment_transactions' => DB::table('guest_payment_transactions')->count(),
            'guest_deposit_transactions' => DB::table('guest_deposit_transactions')->count(),
            'guest_refund_transactions' => DB::table('guest_refund_transactions')->count(),
            'cashier_sessions' => DB::table('cashier_sessions')->count(),
            'cash_count_evidence' => DB::table('cash_count_evidence')->count(),
            'cash_reconciliation_baselines' => DB::table('cash_reconciliation_baselines')->count(),
            'cash_reconciliations' => DB::table('cash_reconciliations')->count(),
        ];
    }

    private function spawnConcurrencyWorkers(FrontDeskStay $stay, CashierSession $session): array
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gc-a1-conc-' . Str::lower(Str::random(8));
        mkdir($dir, 0700, true);

        $worker = __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'GeneralCashierCheckoutObligationConcurrencyWorker.php';
        $barrier = $dir . DIRECTORY_SEPARATOR . 'barrier';
        $processes = [];

        for ($i = 0; $i < 2; $i++) {
            $argsFile = $dir . DIRECTORY_SEPARATOR . "args-w{$i}.json";
            $resultFile = $dir . DIRECTORY_SEPARATOR . "result-w{$i}.json";
            $stderrFile = $dir . DIRECTORY_SEPARATOR . "stderr-w{$i}.txt";
            file_put_contents($argsFile, json_encode([
                'worker_id' => "w{$i}",
                'index' => $i,
                'result_file' => $resultFile,
                'barrier' => $barrier,
                'property_id' => $this->property->id,
                'company_id' => $this->company->id,
                'stay_id' => $stay->id,
                'actor_id' => $this->actor->id,
                'cashier_session_id' => $session->id,
            ]));

            $command = sprintf('%s %s %s', PHP_BINARY, escapeshellarg($worker), escapeshellarg($argsFile));
            $spec = [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']];
            $proc = proc_open($command, $spec, $pipes, base_path(), array_merge(getenv(), [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => 'ivorq_testing',
            ]));
            if (! is_resource($proc)) {
                throw new \RuntimeException('Unable to spawn GC-A1 concurrency worker.');
            }

            fclose($pipes[0]);
            $processes[$i] = [
                'proc' => $proc,
                'result_file' => $resultFile,
                'stderr_file' => $stderrFile,
            ];
        }

        $results = [];
        foreach ($processes as $i => $process) {
            $exitCode = proc_close($process['proc']);
            $decoded = file_exists($process['result_file'])
                ? json_decode(file_get_contents($process['result_file']), true)
                : ['error' => 'missing result file'];
            $decoded = is_array($decoded) ? $decoded : ['error' => 'malformed result json'];
            $decoded['_exit_code'] = $exitCode;
            if (file_exists($process['stderr_file'])) {
                $decoded['_stderr'] = trim(file_get_contents($process['stderr_file']));
            }
            $results[$i] = $decoded;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);

        ksort($results);
        return array_values($results);
    }
}
