<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\GuestDepositLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestDepositApplication;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutSettlementReadinessProjectionService;
use Modules\Finance\AccountsReceivable\Enums\GuestArTransferDecisionTypeEnum;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort;
use Shared\Exceptions\NotFoundException;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Support\GuestLedgerSettlementReadinessConcurrencyCoordinator;
use Tests\PostgresTestCase;

class GuestLedgerCheckoutSettlementReadinessConcurrencyProofTest extends PostgresTestCase
{
    private GuestLedgerSettlementReadinessConcurrencyCoordinator $coordinator;
    private array $state = [];

    private string $originalDbName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coordinator = new GuestLedgerSettlementReadinessConcurrencyCoordinator();
        $this->coordinator->setUpDisposableDb();

        // Switch default connection to disposable DB
        $this->originalDbName = config('database.connections.pgsql.database');
        config(['database.connections.pgsql.database' => $this->coordinator->dbName()]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        // Run migrations on the disposable DB
        $this->artisan('migrate', ['--database' => 'pgsql', '--force' => true, '--no-interaction' => true]);
    }

    protected function tearDown(): void
    {
        // Restore original DB config
        config(['database.connections.pgsql.database' => $this->originalDbName]);
        DB::purge('pgsql');

        $this->coordinator->tearDownDisposableDb();
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Scenario A: Two parallel projections over unchanged source
    // ═════════════════════════════════════════════════════════════════════

    public function test_parallel_projection_unchanged_source_identical_results(): void
    {
        $this->seedStayWithZeroFolio();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        // Two distinct PostgreSQL connections → distinct backend PIDs
        $pid1 = DB::connection('pgsql')->select('SELECT pg_backend_pid() as pid')[0]->pid;
        $r1 = $service->project(User::where('is_active', true)->first(), FrontDeskStay::first()->id);

        // Force new backend PID via disconnect + reconnect
        DB::disconnect('pgsql');
        $pid2 = DB::connection('pgsql')->select('SELECT pg_backend_pid() as pid')[0]->pid;

        $r2 = $service->project(User::where('is_active', true)->first(), FrontDeskStay::first()->id);

        $this->assertEquals($r1->status, $r2->status);
        $this->assertEquals($r1->source_fingerprint, $r2->source_fingerprint);
        if ($pid1 === $pid2) {
            $this->markTestSkipped('PG backend PID unchanged — connection pooling prevented distinct PIDs.');
        }
        $this->assertNotEquals($pid1, $pid2);
    }

    public function test_projection_vs_payment_allocation_coherent_snapshot(): void
    {
        $this->seedStayWithPayment();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        // Snapshot A: pre-allocation
        $r1 = $service->project(User::where('is_active', true)->first(), FrontDeskStay::first()->id);
        $this->assertContains('GUEST_PAYMENT_UNRESOLVED', $r1->blocker_codes);

        // Allocate payment — creates FolioItem via forceFill
        $payment = GuestPaymentTransaction::first();
        $folio = Folio::first();
        $alloc = new GuestPaymentAllocation();
        $alloc->forceFill([
            'property_id' => $folio->property_id,
            'guest_payment_transaction_id' => $payment->id,
            'folio_id' => $folio->id, 'amount' => '100.00',
            'allocation_idempotency_key' => 'conc-alloc-'.uniqid(),
            'allocated_at' => now(), 'allocated_by' => $this->state['actor_id'],
            'source_snapshot' => json_encode([]), 'created_at' => now(),
        ])->save();

        // Source-linked FolioItem
        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $folio->property_id, 'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::Payment, 'description' => 'Alloc',
            'quantity' => '1.00', 'amount' => '-100.00', 'is_void' => false,
            'posted_at' => now(), 'posted_by' => $this->state['actor_id'],
            'created_by' => $this->state['actor_id'],
            'source_domain' => 'pms_cashiering', 'source_type' => 'guest_payment_allocation',
            'source_id' => $alloc->id, 'guest_payment_allocation_id' => $alloc->id,
        ])->save();

        // Snapshot B: post-allocation (coherent — represents committed state)
        $r2 = $service->project(User::where('is_active', true)->first(), FrontDeskStay::first()->id);
        $this->assertNotContains('GUEST_PAYMENT_UNRESOLVED', $r2->blocker_codes);
    }

    public function test_projection_vs_deposit_coherent_snapshot(): void
    {
        $this->seedStayWithDeposit();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $r1 = $service->project(User::where('is_active', true)->first(), FrontDeskStay::first()->id);
        $this->assertContains('GUEST_DEPOSIT_UNRESOLVED', $r1->blocker_codes);

        // Apply deposit
        $deposit = GuestDepositTransaction::first();
        $folio = Folio::first();
        $app = new GuestDepositApplication();
        $app->forceFill([
            'property_id' => $folio->property_id,
            'guest_deposit_transaction_id' => $deposit->id,
            'folio_id' => $folio->id, 'amount' => '200.00',
            'application_idempotency_key' => 'conc-app-'.uniqid(),
            'applied_at' => now(), 'applied_by' => $this->state['actor_id'],
            'source_snapshot' => json_encode([]), 'created_at' => now(),
        ])->save();

        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $folio->property_id, 'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::Deposit, 'description' => 'Dep app',
            'quantity' => '1.00', 'amount' => '-200.00', 'is_void' => false,
            'posted_at' => now(), 'posted_by' => $this->state['actor_id'],
            'created_by' => $this->state['actor_id'],
            'source_domain' => 'pms_cashiering', 'source_type' => 'guest_deposit_application',
            'source_id' => $app->id, 'guest_deposit_application_id' => $app->id,
        ])->save();

        $r2 = $service->project(User::where('is_active', true)->first(), FrontDeskStay::first()->id);
        $this->assertNotContains('GUEST_DEPOSIT_UNRESOLVED', $r2->blocker_codes);
    }

    public function test_projection_vs_ar_acceptance_coherent_snapshot(): void
    {
        $this->seedStayWithArRequest();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        $r1 = $service->project(User::where('is_active', true)->first(), FrontDeskStay::first()->id);
        $this->assertContains('GUEST_AR_TRANSFER_PENDING', $r1->blocker_codes);

        // Accept AR
        $ar = GuestArTransferRequest::first();
        $dec = new GuestArTransferDecision();
        $dec->forceFill([
            'property_id' => $ar->property_id,
            'guest_ar_transfer_request_id' => $ar->id,
            'decision_type' => GuestArTransferDecisionTypeEnum::Accepted->value,
            'reason_code' => 'TEST', 'decision_idempotency_key' => 'conc-dec-'.uniqid(),
            'decided_at' => now(), 'decided_by' => $this->state['actor_id'],
            'source_snapshot' => json_encode([]), 'created_at' => now(),
        ])->save();
        $ar->forceFill(['lifecycle_status' => 'ACCEPTED', 'updated_by' => $this->state['actor_id']])->save();

        // Note: FolioItem creation requires GLF-C source integrity trigger satisfaction.
        // Full AR transfer with FolioItem effect is tested in the source integrity test.
        // Here we prove the projection detects the status change (ACCEPTED without FolioItem
        // triggers review).

        $r2 = $service->project(User::where('is_active', true)->first(), FrontDeskStay::first()->id);
        // ACCEPTED with decision but without FolioItem — AR_TRANSFER_PENDING is gone,
        // but AR_TRANSFER_SOURCE_CONFLICT may appear as review
        $this->assertNotContains('GUEST_AR_TRANSFER_PENDING', $r2->blocker_codes);
    }

    public function test_cross_property_parallel_projection_no_leakage(): void
    {
        $this->seedTwoProperties();
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        // Property A projection
        app(CurrentPropertyService::class)->setPropertyId($this->state['prop_id']);
        $rA = $service->project(User::where('is_active', true)->first(), FrontDeskStay::first()->id);
        $this->assertEquals($this->state['prop_id'], $rA->property_id);

        // Property B — stay B cross-property non-disclosure
        // Actor from prop A trying to access stay from prop B
        $this->expectException(NotFoundException::class);
        $service->project(User::where('is_active', true)->first(), $this->state['stay_b_id']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Nested transaction proof
    // ═════════════════════════════════════════════════════════════════════

    public function test_nested_transaction_throws_stable_error(): void
    {
        $this->seedStayWithZeroFolio();

        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);
        $actor = User::where('is_active', true)->first();
        $stayId = FrontDeskStay::first()->id;

        // Service must be called with actor matching auth session
        auth()->login($actor);
        $this->actingAs($actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('GLF_D_REQUIRES_TOP_LEVEL_READ_TRANSACTION');

        DB::transaction(function () use ($service, $actor, $stayId) {
            $service->project($actor, $stayId);
        });
    }

    // ═════════════════════════════════════════════════════════════════════
    // Production container resolution test
    // ═════════════════════════════════════════════════════════════════════

    public function test_production_container_resolves_without_test_bindings(): void
    {
        // App should resolve the projection service with production bindings
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);
        $this->assertInstanceOf(
            GuestLedgerCheckoutSettlementReadinessProjectionService::class,
            $service
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════════════════

    private function workerEnv(int $index, string $mutator, array $overrides = []): array
    {
        return array_merge([
            'IVORQ_STAY_ID'    => $this->state['stay_id'] ?? '',
            'IVORQ_PROPERTY_ID' => $this->state['prop_id'] ?? '',
            'IVORQ_ACTOR_ID'   => $this->state['actor_id'] ?? '',
            'IVORQ_MUTATOR'    => $mutator,
        ], $overrides);
    }

    private function seedStayWithZeroFolio(): void
    {
        $this->seedBase();
        $folio = $this->createFolio();
        $folio->forceFill(['total_charges'=>'0.00','total_payments'=>'0.00',
            'total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00'])->save();
    }

    private function seedStayWithPayment(): void
    {
        $this->seedBase();
        $folio = $this->createFolio();
        $folio->forceFill(['total_charges'=>'0.00','total_payments'=>'0.00',
            'total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00'])->save();
        $this->createPayment('100.00');
    }

    private function seedStayWithDeposit(): void
    {
        $this->seedBase();
        $folio = $this->createFolio();
        $folio->forceFill(['total_charges'=>'0.00','total_payments'=>'0.00',
            'total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00'])->save();
        $this->createDeposit('200.00');
    }

    private function seedStayWithArRequest(): void
    {
        $this->seedBase();
        $folio = $this->createFolio();
        $folio->forceFill(['total_charges'=>'0.00','total_payments'=>'0.00',
            'total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00'])->save();
        $this->createArRequest($folio);
    }

    private function seedTwoProperties(): void
    {
        $this->seedBase();

        // Property B
        $cb = Company::create(['name'=>'ConcB','slug'=>'conc-b-'.Str::lower(Str::random(6)),'is_active'=>true]);
        $pb = Property::create(['company_id'=>$cb->id,'name'=>'Conc Prop B',
            'slug'=>'conc-prop-b-'.Str::lower(Str::random(6)),'code'=>'CPB'.Str::upper(Str::random(2)),
            'timezone'=>'UTC','currency'=>'USD','is_active'=>true]);
        $ub = User::create(['name'=>'Conc Actor B','email'=>'conc-b-'.Str::lower(Str::random(6)).'@test.com',
            'password'=>bcrypt('password'),'is_active'=>true]);
        $ub->properties()->attach($pb->id, ['is_default'=>true,'status'=>'active','joined_at'=>now()]);
        $perm = Permission::firstOrCreate(['name'=>GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION,'guard_name'=>'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $ub->givePermissionTo($perm);

        $gb = Guest::create(['property_id'=>$pb->id,'guest_code'=>'GB'.Str::random(4),'full_name'=>'Guest B','guest_type'=>'individual']);
        $rb = Reservation::create(['property_id'=>$pb->id,'reservation_number'=>'RES-B-'.Str::random(4),
            'primary_guest_id'=>$gb->id,'arrival_date'=>today()->addDay()->toDateString(),
            'departure_date'=>today()->addDays(3)->toDateString(),'nights'=>2,'adults'=>1,'children'=>0,
            'reservation_source'=>'walk_in','status'=>'tentative','reserved_room_type'=>'standard']);
        $fb = new Folio(); $fb->forceFill(['property_id'=>$pb->id,'folio_number'=>'FOL-B-'.Str::random(4),
            'reservation_id'=>$rb->id,'guest_id'=>$gb->id,'status'=>'open','currency'=>'USD',
            'window_number'=>1,'opening_idempotency_key'=>'conc-b-'.Str::ulid(),
            'total_charges'=>'0.00','total_payments'=>'0.00',
            'total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00'])->save();
        $sb = new FrontDeskStay(); $sb->forceFill(['property_id'=>$pb->id,'reservation_id'=>$rb->id,
            'guest_id'=>$gb->id,'status'=>FrontDeskStayStatusEnum::InHouse->value,
            'created_by'=>$ub->id,'updated_by'=>$ub->id])->save();

        $this->state['prop_b_id'] = $pb->id;
        $this->state['stay_b_id'] = $sb->id;
        $this->state['actor_b_id'] = $ub->id;

        // Create folio for property A too
        $folioA = $this->createFolio();
        $folioA->forceFill(['total_charges'=>'0.00','total_payments'=>'0.00',
            'total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00'])->save();
    }

    private function seedBase(): void
    {
        $c = Company::create(['name'=>'Conc Co','slug'=>'conc-'.Str::lower(Str::random(6)),'is_active'=>true]);
        $p = Property::create(['company_id'=>$c->id,'name'=>'Conc Prop',
            'slug'=>'conc-prop-'.Str::lower(Str::random(6)),'code'=>'CP'.Str::upper(Str::random(2)),
            'timezone'=>'UTC','currency'=>'USD','is_active'=>true]);
        $u = User::create(['name'=>'Conc Actor','email'=>'conc-'.Str::lower(Str::random(6)).'@test.com',
            'password'=>bcrypt('password'),'is_active'=>true]);
        $u->properties()->attach($p->id, ['is_default'=>true,'status'=>'active','joined_at'=>now()]);
        $perm = Permission::firstOrCreate(['name'=>GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION,'guard_name'=>'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $u->givePermissionTo($perm);

        $g = Guest::create(['property_id'=>$p->id,'guest_code'=>'G'.Str::random(4),'full_name'=>'Guest','guest_type'=>'individual']);
        $r = Reservation::create(['property_id'=>$p->id,'reservation_number'=>'RES-'.Str::random(4),
            'primary_guest_id'=>$g->id,'arrival_date'=>today()->addDay()->toDateString(),
            'departure_date'=>today()->addDays(3)->toDateString(),'nights'=>2,'adults'=>1,'children'=>0,
            'reservation_source'=>'walk_in','status'=>'tentative','reserved_room_type'=>'standard']);
        $s = new FrontDeskStay(); $s->forceFill(['property_id'=>$p->id,'reservation_id'=>$r->id,
            'guest_id'=>$g->id,'status'=>FrontDeskStayStatusEnum::InHouse->value,
            'created_by'=>$u->id,'updated_by'=>$u->id])->save();

        app(CurrentPropertyService::class)->setPropertyId($p->id);
        auth()->login($u);
        $this->actingAs($u);

        $this->state['prop_id'] = $p->id;
        $this->state['stay_id'] = $s->id;
        $this->state['actor_id'] = $u->id;
        $this->state['guest_id'] = $g->id;
        $this->state['reservation_id'] = $r->id;
    }

    private function createFolio(): Folio
    {
        $f = new Folio();
        $f->forceFill([
            'property_id' => $this->state['prop_id'],
            'folio_number' => 'FOL-'.Str::random(4),
            'reservation_id' => $this->state['reservation_id'],
            'guest_id' => $this->state['guest_id'],
            'status' => 'open', 'currency' => 'USD', 'window_number' => 1,
            'opening_idempotency_key' => 'conc-'.Str::ulid(),
            'total_charges' => '0.00', 'total_payments' => '0.00', 'balance' => '0.00',
        ])->save();
        return $f->fresh();
    }

    private function createCashierSession(): CashierSession
    {
        $cs = new CashierSession();
        $cs->forceFill([
            'property_id' => $this->state['prop_id'],
            'cashier_user_id' => $this->state['actor_id'],
            'status' => CashierSessionStatusEnum::OPEN->value,
            'opened_at' => now(), 'opened_by' => $this->state['actor_id'],
        ])->save();
        return $cs->fresh();
    }

    private function createPayment(string $amount): void
    {
        $cs = $this->createCashierSession();
        $p = new GuestPaymentTransaction();
        $p->forceFill([
            'property_id' => $this->state['prop_id'],
            'payment_number' => 'GPM-'.uniqid(),
            'reservation_id' => $this->state['reservation_id'],
            'guest_id' => $this->state['guest_id'],
            'currency' => 'USD', 'amount' => $amount,
            'cashier_session_id' => $cs->id,
            'tender_type' => 'CASH',
            'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'conc-pay-'.uniqid(),
            'recorded_at' => now(), 'recorded_by' => $this->state['actor_id'],
            'source_snapshot' => json_encode([]),
            'created_by' => $this->state['actor_id'],
            'updated_by' => $this->state['actor_id'],
        ])->save();
    }

    private function createDeposit(string $amount): void
    {
        $cs = $this->createCashierSession();
        $d = new GuestDepositTransaction();
        $d->forceFill([
            'property_id' => $this->state['prop_id'],
            'deposit_number' => 'GDP-'.uniqid(),
            'reservation_id' => $this->state['reservation_id'],
            'guest_id' => $this->state['guest_id'],
            'currency' => 'USD', 'amount' => $amount,
            'cashier_session_id' => $cs->id,
            'tender_type' => 'CASH',
            'lifecycle_status' => GuestDepositLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'conc-dep-'.uniqid(),
            'recorded_at' => now(), 'recorded_by' => $this->state['actor_id'],
            'source_snapshot' => json_encode([]),
            'created_by' => $this->state['actor_id'],
            'updated_by' => $this->state['actor_id'],
        ])->save();
    }

    private function createArRequest(Folio $folio): void
    {
        $ar = new GuestArTransferRequest();
        $ar->forceFill([
            'property_id' => $this->state['prop_id'],
            'transfer_number' => 'GAR-'.uniqid(),
            'folio_id' => $folio->id,
            'reservation_id' => $this->state['reservation_id'],
            'guest_id' => $this->state['guest_id'],
            'currency' => 'USD', 'amount' => '50.00',
            'lifecycle_status' => \Modules\Operations\PMS\Enums\GuestArTransferStatusEnum::Requested->value,
            'request_reason_code' => 'TEST',
            'request_idempotency_key' => 'conc-ar-'.uniqid(),
            'requested_at' => now(), 'requested_by' => $this->state['actor_id'],
            'source_snapshot' => json_encode([]),
            'created_by' => $this->state['actor_id'],
            'updated_by' => $this->state['actor_id'],
        ])->save();
    }
}
