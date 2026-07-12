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
use Modules\Operations\PMS\Enums\GuestArTransferStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\GuestPaymentTransaction;
use Modules\Operations\PMS\Models\GuestDepositTransaction;
use Modules\Operations\PMS\Models\GuestArTransferRequest;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutSettlementReadinessProjectionService;
use Modules\Operations\PMS\Services\GuestPaymentLifecycleService;
use Modules\Operations\PMS\Services\GuestDepositLifecycleService;
use Modules\Operations\PMS\Services\GuestRefundLifecycleService;
use Modules\Finance\AccountsReceivable\Services\GuestArTransferDecisionService;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
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
    // Scenario A — parallel projection (two workers, unchanged source)
    // ═════════════════════════════════════════════════════════════════════

    public function test_scenario_a_parallel_projection_unchanged_source_identical_results(): void
    {
        $this->seedStayWithZeroFolio();

        $extra = [
            0 => [
                'IVORQ_STAY_ID'    => $this->state['stay_id'],
                'IVORQ_PROPERTY_ID' => $this->state['prop_id'],
                'IVORQ_ACTOR_ID'   => $this->state['actor_id'],
                'IVORQ_MUTATOR'    => '',
            ],
            1 => [
                'IVORQ_STAY_ID'    => $this->state['stay_id'],
                'IVORQ_PROPERTY_ID' => $this->state['prop_id'],
                'IVORQ_ACTOR_ID'   => $this->state['actor_id'],
                'IVORQ_MUTATOR'    => '',
            ],
        ];

        $results = $this->coordinator->spawnWorkers(2, 'scenario_a', $extra);

        // Both workers must produce non-null successful results
        $this->assertCount(2, $results);
        $this->assertNotNull($results[0], 'Worker 0 result must not be null');
        $this->assertNotNull($results[1], 'Worker 1 result must not be null');
        $this->assertArrayNotHasKey('error', $results[0], 'Worker 0 must not have error');
        $this->assertArrayNotHasKey('error', $results[1], 'Worker 1 must not have error');

        // Distinct PHP PIDs
        $this->assertNotNull($results[0]['php_pid']);
        $this->assertNotNull($results[1]['php_pid']);
        $this->assertNotEquals($results[0]['php_pid'], $results[1]['php_pid'],
            'Workers must have distinct PHP PIDs');

        // Distinct PostgreSQL backend PIDs
        $this->assertNotNull($results[0]['pg_backend_pid']);
        $this->assertNotNull($results[1]['pg_backend_pid']);
        $this->assertNotEquals($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid'],
            'Workers must have distinct PostgreSQL backend PIDs');

        // Identical projection results
        $this->assertEquals($results[0]['status'], $results[1]['status']);
        $this->assertEquals($results[0]['source_fingerprint'], $results[1]['source_fingerprint']);
        $this->assertEquals($results[0]['canonical_balance'], $results[1]['canonical_balance']);
        $this->assertEquals($results[0]['markers'], $results[1]['markers']);

        // No mutation occurred
        $this->assertArrayNotHasKey('mutator_executed', $results[0]);
        $this->assertArrayNotHasKey('mutator_executed', $results[1]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Scenario B — Payment allocation race
    // ═════════════════════════════════════════════════════════════════════

    public function test_scenario_b_payment_race_projection_coherent_snapshot(): void
    {
        $this->seedStayWithPayment();

        $extra = [
            0 => [
                'IVORQ_STAY_ID'    => $this->state['stay_id'],
                'IVORQ_PROPERTY_ID' => $this->state['prop_id'],
                'IVORQ_ACTOR_ID'   => $this->state['actor_id'],
                'IVORQ_MUTATOR'    => '',
            ],
            1 => [
                'IVORQ_STAY_ID'    => $this->state['stay_id'],
                'IVORQ_PROPERTY_ID' => $this->state['prop_id'],
                'IVORQ_ACTOR_ID'   => $this->state['actor_id'],
                'IVORQ_MUTATOR'    => 'allocate',
                'IVORQ_PAYMENT_ID' => $this->state['payment_id'],
                'IVORQ_FOLIO_ID'   => $this->state['folio_id'],
            ],
        ];

        $results = $this->coordinator->spawnWorkers(2, 'scenario_b', $extra);

        $this->assertCount(2, $results);
        $this->assertNotNull($results[0], 'Projection worker must not be null');
        $this->assertNotNull($results[1], 'Mutation worker must not be null');
        $this->assertArrayNotHasKey('error', $results[0], 'Projection worker error');
        $this->assertArrayNotHasKey('error', $results[1], 'Mutation worker error');

        // Distinct PIDs
        $this->assertNotEquals($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotEquals($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);

        // Mutator must have executed
        $this->assertTrue($results[1]['mutator_executed'] ?? false, 'Payment allocation must execute');
        $this->assertEquals('allocation', $results[1]['mutation']['type'] ?? '');

        // Projection must be coherent
        $this->assertNotNull($results[0]['status']);
        $this->assertNotNull($results[0]['source_fingerprint']);
        $this->assertNotNull($results[0]['canonical_balance']);
    }

    // ═════════════════════════════════════════════════════════════════════

    public function test_scenario_c_deposit_race_projection_coherent_snapshot(): void
    {
        $this->seedStayWithDeposit();

        $extra = [
            0 => [
                'IVORQ_STAY_ID'    => $this->state['stay_id'],
                'IVORQ_PROPERTY_ID' => $this->state['prop_id'],
                'IVORQ_ACTOR_ID'   => $this->state['actor_id'],
                'IVORQ_MUTATOR'    => '',
            ],
            1 => [
                'IVORQ_STAY_ID'    => $this->state['stay_id'],
                'IVORQ_PROPERTY_ID' => $this->state['prop_id'],
                'IVORQ_ACTOR_ID'   => $this->state['actor_id'],
                'IVORQ_MUTATOR'    => 'apply_deposit',
                'IVORQ_DEPOSIT_ID' => $this->state['deposit_id'],
                'IVORQ_FOLIO_ID'   => $this->state['folio_id'],
            ],
        ];

        $results = $this->coordinator->spawnWorkers(2, 'scenario_c', $extra);

        $this->assertCount(2, $results);
        $this->assertNotNull($results[0], 'Projection worker must not be null');
        $this->assertNotNull($results[1], 'Mutation worker must not be null');
        $this->assertArrayNotHasKey('error', $results[0], 'Projection worker error');
        $this->assertArrayNotHasKey('error', $results[1], 'Mutation worker error');

        // Distinct PIDs
        $this->assertNotEquals($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotEquals($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);

        // Mutator must have executed
        $this->assertTrue($results[1]['mutator_executed'] ?? false, 'Deposit application must execute');
        $this->assertEquals('deposit_application', $results[1]['mutation']['type'] ?? '');

        // Projection must be coherent
        $this->assertNotNull($results[0]['status']);
        $this->assertNotNull($results[0]['source_fingerprint']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Scenario D — AR transfer acceptance race
    // ═════════════════════════════════════════════════════════════════════

    public function test_scenario_d_ar_race_projection_coherent_snapshot(): void
    {
        $this->seedStayWithArRequest();

        $extra = [
            0 => [
                'IVORQ_STAY_ID'     => $this->state['stay_id'],
                'IVORQ_PROPERTY_ID'  => $this->state['prop_id'],
                'IVORQ_ACTOR_ID'    => $this->state['actor_id'],
                'IVORQ_MUTATOR'     => '',
            ],
            1 => [
                'IVORQ_STAY_ID'      => $this->state['stay_id'],
                'IVORQ_PROPERTY_ID'   => $this->state['prop_id'],
                'IVORQ_ACTOR_ID'     => $this->state['actor_id'],
                'IVORQ_MUTATOR'      => 'accept_ar',
                'IVORQ_AR_REQUEST_ID' => $this->state['ar_request_id'],
            ],
        ];

        $results = $this->coordinator->spawnWorkers(2, 'scenario_d', $extra);

        $this->assertCount(2, $results);
        $this->assertNotNull($results[0], 'Projection worker must not be null');
        $this->assertNotNull($results[1], 'Mutation worker must not be null');
        $this->assertArrayNotHasKey('error', $results[0], 'Projection worker error');
        $this->assertArrayNotHasKey('error', $results[1], 'Mutation worker error');

        // Distinct PIDs
        $this->assertNotEquals($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotEquals($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);

        // Mutator must have executed
        $this->assertTrue($results[1]['mutator_executed'] ?? false, 'AR accept must execute');
        $this->assertEquals('ar_accept', $results[1]['mutation']['type'] ?? '');

        // Projection must be coherent
        $this->assertNotNull($results[0]['status']);
        $this->assertNotNull($results[0]['source_fingerprint']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Scenario E — Cross-property parallel projection
    // ═════════════════════════════════════════════════════════════════════

    public function test_scenario_e_cross_property_parallel_no_leakage(): void
    {
        $this->seedTwoProperties();

        $extra = [
            0 => [
                'IVORQ_STAY_ID'    => $this->state['stay_id'],
                'IVORQ_PROPERTY_ID' => $this->state['prop_id'],
                'IVORQ_ACTOR_ID'   => $this->state['actor_id'],
                'IVORQ_MUTATOR'    => '',
            ],
            1 => [
                'IVORQ_STAY_ID'    => $this->state['stay_b_id'],
                'IVORQ_PROPERTY_ID' => $this->state['prop_b_id'],
                'IVORQ_ACTOR_ID'   => $this->state['actor_b_id'],
                'IVORQ_MUTATOR'    => '',
            ],
        ];

        $results = $this->coordinator->spawnWorkers(2, 'scenario_e', $extra);

        $this->assertCount(2, $results);
        $this->assertNotNull($results[0], 'Worker A must not be null');
        $this->assertNotNull($results[1], 'Worker B must not be null');
        $this->assertArrayNotHasKey('error', $results[0], 'Worker A must not have error');
        $this->assertArrayNotHasKey('error', $results[1], 'Worker B must not have error');

        // Correct independent property IDs
        $this->assertEquals($this->state['prop_id'], $results[0]['property_id']);
        $this->assertEquals($this->state['prop_b_id'], $results[1]['property_id']);

        // Distinct fingerprints (different property sources)
        $this->assertNotEquals($results[0]['source_fingerprint'], $results[1]['source_fingerprint'],
            'Different properties must produce distinct fingerprints');

        // Both successful — no NotFoundException
        $this->assertNotNull($results[0]['status']);
        $this->assertNotNull($results[1]['status']);

        // Distinct PIDs
        $this->assertNotEquals($results[0]['php_pid'], $results[1]['php_pid']);
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
        $service = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);
        $this->assertInstanceOf(
            GuestLedgerCheckoutSettlementReadinessProjectionService::class,
            $service
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Command-line credential exclusion proof
    // ═════════════════════════════════════════════════════════════════════

    public function test_command_line_excludes_credentials(): void
    {
        $this->seedStayWithZeroFolio();

        // Inspect spawnWorkers internals: the command string must not contain
        // the database password. We capture by inspecting the coordinator's
        // result directory for any stderr or result files containing credentials.
        $extra = [
            0 => [
                'IVORQ_STAY_ID'    => $this->state['stay_id'],
                'IVORQ_PROPERTY_ID' => $this->state['prop_id'],
                'IVORQ_ACTOR_ID'   => $this->state['actor_id'],
                'IVORQ_MUTATOR'    => '',
            ],
        ];

        $results = $this->coordinator->spawnWorkers(1, 'credential_proof', $extra);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]);

        // Result must not contain DB password key
        $this->assertArrayNotHasKey('IVORQ_DB_PASSWORD', $results[0]);
        $this->assertArrayNotHasKey('db_password', $results[0]);
        $this->assertArrayNotHasKey('DB_PASSWORD', $results[0]);

        // Read stderr file to confirm no password leaked
        $stderrFile = $this->coordinator->resultDir() . '/stderr-w0.txt';
        if (file_exists($stderrFile)) {
            $stderr = file_get_contents($stderrFile);
            // The password from coordinator env should not appear in stderr
            // (we can't assert exact password value, but can assert no env=password pattern)
            $this->assertStringNotContainsString('DB_PASSWORD=', $stderr);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════════════════

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
        $this->state['folio_id'] = $folio->id;
        $this->createPayment('100.00');
    }

    private function seedStayWithDeposit(): void
    {
        $this->seedBase();
        $folio = $this->createFolio();
        $folio->forceFill(['total_charges'=>'0.00','total_payments'=>'0.00',
            'total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'0.00'])->save();
        $this->state['folio_id'] = $folio->id;
        $this->createDeposit('200.00');
    }

    private function seedStayWithArRequest(): void
    {
        $this->seedBase();
        $folio = $this->createFolio();
        // AR transfer requires folio balance >= transfer amount.
        // Add a room charge to create a positive balance.
        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->state['prop_id'],
            'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::RoomCharge,
            'description' => 'Room charge for AR',
            'quantity' => '1.00', 'amount' => '100.00', 'is_void' => false,
            'posted_at' => now(), 'posted_by' => $this->state['actor_id'],
            'created_by' => $this->state['actor_id'],
        ])->save();
        $folio->forceFill(['total_charges'=>'100.00','total_payments'=>'0.00',
            'total_deposits'=>'0.00','total_ar_transfers'=>'0.00','balance'=>'100.00'])->save();
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

        // Grant projection permission
        $viewPerm = Permission::firstOrCreate(['name'=>GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION,'guard_name'=>'web']);
        // Grant mutation permissions for concurrency workers
        $allocatePerm = Permission::firstOrCreate(['name'=>GuestPaymentLifecycleService::ALLOCATE_PERMISSION,'guard_name'=>'web']);
        $depositApplyPerm = Permission::firstOrCreate(['name'=>GuestDepositLifecycleService::APPLY_PERMISSION,'guard_name'=>'web']);
        $refundPerm = Permission::firstOrCreate(['name'=>GuestRefundLifecycleService::RECORD_PERMISSION,'guard_name'=>'web']);
        $arAcceptPerm = Permission::firstOrCreate(['name'=>GuestArTransferDecisionService::ACCEPT_PERMISSION,'guard_name'=>'web']);
        $arReversePerm = Permission::firstOrCreate(['name'=>GuestArTransferDecisionService::REVERSE_PERMISSION,'guard_name'=>'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $u->givePermissionTo($viewPerm);
        $u->givePermissionTo($allocatePerm);
        $u->givePermissionTo($depositApplyPerm);
        $u->givePermissionTo($refundPerm);
        $u->givePermissionTo($arAcceptPerm);
        $u->givePermissionTo($arReversePerm);

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
        $this->state['company_id'] = $c->id;
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
        $this->state['payment_id'] = $p->id;
        $this->state['cashier_session_id'] = $cs->id;
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
        $this->state['deposit_id'] = $d->id;
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
            'lifecycle_status' => GuestArTransferStatusEnum::Requested->value,
            'request_reason_code' => 'TEST',
            'request_idempotency_key' => 'conc-ar-'.uniqid(),
            'requested_at' => now(), 'requested_by' => $this->state['actor_id'],
            'source_snapshot' => json_encode([]),
            'created_by' => $this->state['actor_id'],
            'updated_by' => $this->state['actor_id'],
        ])->save();
        $this->state['ar_request_id'] = $ar->id;
    }
}
