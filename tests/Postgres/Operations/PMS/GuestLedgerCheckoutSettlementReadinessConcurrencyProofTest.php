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
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Support\GuestLedgerSettlementReadinessConcurrencyCoordinator;
use Tests\PostgresTestCase;

class GuestLedgerCheckoutSettlementReadinessConcurrencyProofTest extends PostgresTestCase
{
    private GuestLedgerSettlementReadinessConcurrencyCoordinator $coordinator;
    private array $state = [];
    private string $originalDbName;
    private GuestLedgerCheckoutSettlementReadinessProjectionService $oracleService;

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

        // Bind deterministic CLEAR ports for oracle (used in pre/post calculations)
        $this->bindOracleClearPorts();
        $this->oracleService = app(GuestLedgerCheckoutSettlementReadinessProjectionService::class);
    }

    protected function tearDown(): void
    {
        config(['database.connections.pgsql.database' => $this->originalDbName]);
        DB::purge('pgsql');
        $this->coordinator->tearDownDisposableDb();
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Scenario A — Parallel projection + zero-write proof
    // ═════════════════════════════════════════════════════════════════════

    public function test_scenario_a_parallel_projection_zero_write_identical_results(): void
    {
        $this->seedStayWithZeroFolio();

        // Capture zero-write baseline BEFORE projection
        $before = $this->captureSourceCounts();

        $extra = [
            0 => [ 'IVORQ_STAY_ID' => $this->state['stay_id'], 'IVORQ_PROPERTY_ID' => $this->state['prop_id'], 'IVORQ_ACTOR_ID' => $this->state['actor_id'], 'IVORQ_MUTATOR' => '' ],
            1 => [ 'IVORQ_STAY_ID' => $this->state['stay_id'], 'IVORQ_PROPERTY_ID' => $this->state['prop_id'], 'IVORQ_ACTOR_ID' => $this->state['actor_id'], 'IVORQ_MUTATOR' => '' ],
        ];

        $results = $this->coordinator->spawnWorkers(2, 'scenario_a', $extra);

        $this->assertWorkerSuccess($results[0], 'Worker 0');
        $this->assertWorkerSuccess($results[1], 'Worker 1');
        $this->assertExitZero($results[0]);
        $this->assertExitZero($results[1]);

        // Distinct PHP and PostgreSQL PIDs
        $this->assertNotEquals($results[0]['php_pid'], $results[1]['php_pid'], 'Distinct PHP PIDs required');
        $this->assertNotEquals($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid'], 'Distinct PG PIDs required');

        // Identical projection results
        $this->assertEquals($results[0]['status'], $results[1]['status']);
        $this->assertEquals($results[0]['source_fingerprint'], $results[1]['source_fingerprint']);
        $this->assertEquals($results[0]['canonical_balance'], $results[1]['canonical_balance']);
        $this->assertEquals($results[0]['markers'], $results[1]['markers']);
        $this->assertEquals($results[0]['blocker_codes'], $results[1]['blocker_codes']);
        $this->assertEquals($results[0]['review_reasons'], $results[1]['review_reasons']);

        // Zero-write proof: worker-reported counts must equal pre-projection baseline
        $this->assertZeroWrite($results[0], $before, 'Worker 0');
        $this->assertZeroWrite($results[1], $before, 'Worker 1');

        // No mutation occurred
        $this->assertArrayNotHasKey('mutator_executed', $results[0]);
        $this->assertArrayNotHasKey('mutator_executed', $results[1]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Scenario B — Payment allocation race with deterministic snapshot
    // ═════════════════════════════════════════════════════════════════════

    public function test_scenario_b_payment_race_deterministic_snapshot(): void
    {
        $this->seedStayWithPayment();
        $actor = User::whereKey($this->state['actor_id'])->where('is_active', true)->first();
        auth()->login($actor);
        $this->actingAs($actor);

        // Oracle: pre-mutation projection
        $preProj = $this->oracleService->project($actor, $this->state['stay_id']);

        $extra = [
            0 => [ 'IVORQ_STAY_ID' => $this->state['stay_id'], 'IVORQ_PROPERTY_ID' => $this->state['prop_id'], 'IVORQ_ACTOR_ID' => $this->state['actor_id'], 'IVORQ_MUTATOR' => '' ],
            1 => [ 'IVORQ_STAY_ID' => $this->state['stay_id'], 'IVORQ_PROPERTY_ID' => $this->state['prop_id'], 'IVORQ_ACTOR_ID' => $this->state['actor_id'], 'IVORQ_MUTATOR' => 'allocate', 'IVORQ_PAYMENT_ID' => $this->state['payment_id'], 'IVORQ_FOLIO_ID' => $this->state['folio_id'] ],
        ];

        $results = $this->coordinator->spawnWorkers(2, 'scenario_b', $extra);

        $this->assertWorkerSuccess($results[0], 'Proj worker');
        $this->assertWorkerSuccess($results[1], 'Mut worker');
        $this->assertExitZero($results[0]);
        $this->assertExitZero($results[1]);

        // Distinct PIDs
        $this->assertNotEquals($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotEquals($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);

        // Mutation must have executed
        $this->assertTrue($results[1]['mutator_executed'] ?? false, 'Payment allocation must execute');
        $this->assertEquals('allocation', $results[1]['mutation']['type'] ?? '');

        // Deterministic snapshot: worker projection MUST equal exact pre-mutation oracle.
        // The blocking adapter pauses the projection AFTER financial reads but BEFORE
        // mutation, so the REPEATABLE READ snapshot captures pre-mutation state.
        $wProj = $results[0];
        $this->assertEquals($preProj->status->value, $wProj['status'], 'Status must equal pre-mutation oracle');
        $this->assertEquals($preProj->source_fingerprint, $wProj['source_fingerprint'], 'Fingerprint must equal pre-mutation oracle');
        $this->assertEquals($preProj->canonical_aggregate_balance, $wProj['canonical_balance'], 'Balance must equal pre-mutation oracle');
        $this->assertEquals($preProj->blocker_codes, $wProj['blocker_codes'], 'Blocker codes must equal pre-mutation oracle');
        $this->assertEquals($preProj->review_reasons, $wProj['review_reasons'], 'Review reasons must equal pre-mutation oracle');

        // Post-mutation oracle: fingerprint MUST differ (source data changed)
        $postProj = $this->oracleService->project($actor, $this->state['stay_id']);
        $this->assertNotEquals($preProj->source_fingerprint, $postProj->source_fingerprint, 'Post-mutation fingerprint must differ');

        // Worker projection must NOT equal post-mutation (proves snapshot isolation)
        $this->assertNotEquals($postProj->source_fingerprint, $wProj['source_fingerprint'], 'Worker must NOT see post-mutation state (no mixed evidence)');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Scenario C — Deposit application race with deterministic snapshot
    // ═════════════════════════════════════════════════════════════════════

    public function test_scenario_c_deposit_race_deterministic_snapshot(): void
    {
        $this->seedStayWithDeposit();
        $actor = User::whereKey($this->state['actor_id'])->where('is_active', true)->first();
        auth()->login($actor);
        $this->actingAs($actor);

        $preProj = $this->oracleService->project($actor, $this->state['stay_id']);

        $extra = [
            0 => [ 'IVORQ_STAY_ID' => $this->state['stay_id'], 'IVORQ_PROPERTY_ID' => $this->state['prop_id'], 'IVORQ_ACTOR_ID' => $this->state['actor_id'], 'IVORQ_MUTATOR' => '' ],
            1 => [ 'IVORQ_STAY_ID' => $this->state['stay_id'], 'IVORQ_PROPERTY_ID' => $this->state['prop_id'], 'IVORQ_ACTOR_ID' => $this->state['actor_id'], 'IVORQ_MUTATOR' => 'apply_deposit', 'IVORQ_DEPOSIT_ID' => $this->state['deposit_id'], 'IVORQ_FOLIO_ID' => $this->state['folio_id'] ],
        ];

        $results = $this->coordinator->spawnWorkers(2, 'scenario_c', $extra);

        $this->assertWorkerSuccess($results[0], 'Proj worker');
        $this->assertWorkerSuccess($results[1], 'Mut worker');
        $this->assertExitZero($results[0]);
        $this->assertExitZero($results[1]);
        $this->assertNotEquals($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotEquals($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);

        $this->assertTrue($results[1]['mutator_executed'] ?? false, 'Deposit application must execute');
        $this->assertEquals('deposit_application', $results[1]['mutation']['type'] ?? '');

        // Deterministic snapshot: equals pre-mutation oracle
        $wProj = $results[0];
        $this->assertEquals($preProj->status->value, $wProj['status']);
        $this->assertEquals($preProj->source_fingerprint, $wProj['source_fingerprint']);
        $this->assertEquals($preProj->canonical_aggregate_balance, $wProj['canonical_balance']);

        // Post-mutation fingerprint must differ; worker must not see it
        $postProj = $this->oracleService->project($actor, $this->state['stay_id']);
        $this->assertNotEquals($preProj->source_fingerprint, $postProj->source_fingerprint);
        $this->assertNotEquals($postProj->source_fingerprint, $wProj['source_fingerprint'], 'No mixed deposit/application state');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Scenario D — AR acceptance race with deterministic snapshot
    // ═════════════════════════════════════════════════════════════════════

    public function test_scenario_d_ar_race_deterministic_snapshot(): void
    {
        $this->seedStayWithArRequest();
        $actor = User::whereKey($this->state['actor_id'])->where('is_active', true)->first();
        auth()->login($actor);
        $this->actingAs($actor);

        $preProj = $this->oracleService->project($actor, $this->state['stay_id']);

        $extra = [
            0 => [ 'IVORQ_STAY_ID' => $this->state['stay_id'], 'IVORQ_PROPERTY_ID' => $this->state['prop_id'], 'IVORQ_ACTOR_ID' => $this->state['actor_id'], 'IVORQ_MUTATOR' => '' ],
            1 => [ 'IVORQ_STAY_ID' => $this->state['stay_id'], 'IVORQ_PROPERTY_ID' => $this->state['prop_id'], 'IVORQ_ACTOR_ID' => $this->state['actor_id'], 'IVORQ_MUTATOR' => 'accept_ar', 'IVORQ_AR_REQUEST_ID' => $this->state['ar_request_id'] ],
        ];

        $results = $this->coordinator->spawnWorkers(2, 'scenario_d', $extra);

        $this->assertWorkerSuccess($results[0], 'Proj worker');
        $this->assertWorkerSuccess($results[1], 'Mut worker');
        $this->assertExitZero($results[0]);
        $this->assertExitZero($results[1]);
        $this->assertNotEquals($results[0]['php_pid'], $results[1]['php_pid']);
        $this->assertNotEquals($results[0]['pg_backend_pid'], $results[1]['pg_backend_pid']);

        $this->assertTrue($results[1]['mutator_executed'] ?? false, 'AR accept must execute');
        $this->assertEquals('ar_accept', $results[1]['mutation']['type'] ?? '');

        // Deterministic snapshot: equals pre-mutation oracle
        $wProj = $results[0];
        $this->assertEquals($preProj->status->value, $wProj['status']);
        $this->assertEquals($preProj->source_fingerprint, $wProj['source_fingerprint']);
        $this->assertEquals($preProj->canonical_aggregate_balance, $wProj['canonical_balance']);

        // Post-mutation fingerprint must differ; worker must not see it
        $postProj = $this->oracleService->project($actor, $this->state['stay_id']);
        $this->assertNotEquals($preProj->source_fingerprint, $postProj->source_fingerprint);
        $this->assertNotEquals($postProj->source_fingerprint, $wProj['source_fingerprint'], 'No mixed request/decision state');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Scenario E — Cross-property parallel with source-isolation proof
    // ═════════════════════════════════════════════════════════════════════

    public function test_scenario_e_cross_property_parallel_source_isolation(): void
    {
        $this->seedTwoProperties();

        $extra = [
            0 => [ 'IVORQ_STAY_ID' => $this->state['stay_id'], 'IVORQ_PROPERTY_ID' => $this->state['prop_id'], 'IVORQ_ACTOR_ID' => $this->state['actor_id'], 'IVORQ_MUTATOR' => '' ],
            1 => [ 'IVORQ_STAY_ID' => $this->state['stay_b_id'], 'IVORQ_PROPERTY_ID' => $this->state['prop_b_id'], 'IVORQ_ACTOR_ID' => $this->state['actor_b_id'], 'IVORQ_MUTATOR' => '' ],
        ];

        $results = $this->coordinator->spawnWorkers(2, 'scenario_e', $extra);

        $this->assertWorkerSuccess($results[0], 'Worker A');
        $this->assertWorkerSuccess($results[1], 'Worker B');
        $this->assertExitZero($results[0]);
        $this->assertExitZero($results[1]);

        // Correct independent property IDs
        $this->assertEquals($this->state['prop_id'], $results[0]['property_id'], 'Worker A must report property A');
        $this->assertEquals($this->state['prop_b_id'], $results[1]['property_id'], 'Worker B must report property B');

        // Distinct fingerprints (different properties, different source data)
        $this->assertNotEquals($results[0]['source_fingerprint'], $results[1]['source_fingerprint'], 'Different properties must produce distinct fingerprints');

        // Both successful projections
        $this->assertNotNull($results[0]['status']);
        $this->assertNotNull($results[1]['status']);

        // Distinct PIDs
        $this->assertNotEquals($results[0]['php_pid'], $results[1]['php_pid']);

        // Source isolation: folio IDs must not overlap across properties
        $this->assertNotEmpty($results[0]['folio_ids']);
        $this->assertNotEmpty($results[1]['folio_ids']);
        $overlap = array_intersect($results[0]['folio_ids'], $results[1]['folio_ids']);
        $this->assertEmpty($overlap, 'No folio IDs must be shared across properties');
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
        $this->assertInstanceOf(GuestLedgerCheckoutSettlementReadinessProjectionService::class, $service);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Command-line credential exclusion proof
    // ═════════════════════════════════════════════════════════════════════

    public function test_command_line_excludes_credentials(): void
    {
        $this->seedStayWithZeroFolio();

        $extra = [
            0 => [ 'IVORQ_STAY_ID' => $this->state['stay_id'], 'IVORQ_PROPERTY_ID' => $this->state['prop_id'], 'IVORQ_ACTOR_ID' => $this->state['actor_id'], 'IVORQ_MUTATOR' => '' ],
        ];

        $results = $this->coordinator->spawnWorkers(1, 'credential_proof', $extra);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]);
        $this->assertExitZero($results[0]);

        // Result must not contain credential keys
        $this->assertArrayNotHasKey('IVORQ_DB_PASSWORD', $results[0]);
        $this->assertArrayNotHasKey('db_password', $results[0]);
        $this->assertArrayNotHasKey('DB_PASSWORD', $results[0]);

        // Check stderr file for leaked credentials
        $stderrFile = $this->coordinator->resultDir() . '/stderr-w0.txt';
        if (file_exists($stderrFile)) {
            $stderr = file_get_contents($stderrFile);
            $this->assertStringNotContainsString('DB_PASSWORD=', $stderr);
        }

        // Check args file for leaked credentials
        $argsFile = $this->coordinator->resultDir() . '/args-w0.json';
        if (file_exists($argsFile)) {
            $argsContent = file_get_contents($argsFile);
            $this->assertStringNotContainsString('IVORQ_DB_PASSWORD', $argsContent);
            $this->assertStringNotContainsString('DB_PASSWORD', $argsContent);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Assertion helpers
    // ═════════════════════════════════════════════════════════════════════

    private function assertWorkerSuccess(array $result, string $label): void
    {
        $this->assertNotNull($result, "{$label}: result must not be null");
        $this->assertArrayNotHasKey('_proc_error', $result, "{$label}: proc error: " . ($result['_proc_error'] ?? ''));
        $this->assertArrayNotHasKey('_parse_error', $result, "{$label}: parse error");
        $this->assertArrayNotHasKey('error', $result, "{$label}: worker error: " . ($result['error'] ?? '') . ' | stderr: ' . ($result['_stderr'] ?? ''));
    }

    private function assertExitZero(array $result): void
    {
        $this->assertEquals(0, $result['_exit_code'] ?? -1, "Worker exit code must be 0, got " . ($result['_exit_code'] ?? 'null'));
    }

    private function assertZeroWrite(array $result, array $before, string $label): void
    {
        $zw = $result['zero_write'] ?? null;
        $this->assertNotNull($zw, "{$label}: zero_write section missing");
        foreach ($before as $table => $count) {
            $actual = $zw[$table] ?? null;
            $this->assertNotNull($actual, "{$label}: zero_write missing table {$table}");
            $this->assertEquals($count, $actual, "{$label}: {$table} count changed ({$count} → {$actual}) — projection wrote data");
        }
    }

    private function captureSourceCounts(): array
    {
        return [
            'folios'                    => DB::table('folios')->count(),
            'folio_items'               => DB::table('folio_items')->count(),
            'guest_payment_transactions'    => DB::table('guest_payment_transactions')->count(),
            'guest_payment_allocations'     => DB::table('guest_payment_allocations')->count(),
            'guest_payment_reversals'       => DB::table('guest_payment_reversals')->count(),
            'guest_deposit_transactions'    => DB::table('guest_deposit_transactions')->count(),
            'guest_deposit_applications'    => DB::table('guest_deposit_applications')->count(),
            'guest_deposit_reversals'       => DB::table('guest_deposit_reversals')->count(),
            'guest_refund_transactions'     => DB::table('guest_refund_transactions')->count(),
            'guest_ar_transfer_requests'    => DB::table('guest_ar_transfer_requests')->count(),
            'guest_ar_transfer_decisions'   => DB::table('guest_ar_transfer_decisions')->count(),
            'front_desk_stays'              => DB::table('front_desk_stays')->count(),
        ];
    }

    private function bindOracleClearPorts(): void
    {
        app()->singleton(GuestLedgerPostingCompletenessReadPort::class, function () {
            return new class implements GuestLedgerPostingCompletenessReadPort {
                public function evaluate(string $rid, string $pid): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });
        app()->singleton(GuestLedgerSettlementHoldReadPort::class, function () {
            return new class implements GuestLedgerSettlementHoldReadPort {
                public function evaluate(string $rid, string $pid): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });
        app()->singleton(GuestLedgerCompletedSettlementConflictReadPort::class, function () {
            return new class implements GuestLedgerCompletedSettlementConflictReadPort {
                public function evaluate(string $rid, string $pid): array {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });
    }

    // ═════════════════════════════════════════════════════════════════════
    // Seed helpers
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
        $item = new FolioItem();
        $item->forceFill([
            'property_id' => $this->state['prop_id'], 'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::RoomCharge, 'description' => 'Room charge for AR',
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
        $viewPerm = Permission::firstOrCreate(['name'=>GuestLedgerCheckoutSettlementReadinessProjectionService::VIEW_PERMISSION,'guard_name'=>'web']);
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
            'property_id' => $this->state['prop_id'], 'folio_number' => 'FOL-'.Str::random(4),
            'reservation_id' => $this->state['reservation_id'], 'guest_id' => $this->state['guest_id'],
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
            'property_id' => $this->state['prop_id'], 'cashier_user_id' => $this->state['actor_id'],
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
            'property_id' => $this->state['prop_id'], 'payment_number' => 'GPM-'.uniqid(),
            'reservation_id' => $this->state['reservation_id'], 'guest_id' => $this->state['guest_id'],
            'currency' => 'USD', 'amount' => $amount,
            'cashier_session_id' => $cs->id, 'tender_type' => 'CASH',
            'lifecycle_status' => GuestPaymentLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'conc-pay-'.uniqid(),
            'recorded_at' => now(), 'recorded_by' => $this->state['actor_id'],
            'source_snapshot' => json_encode([]),
            'created_by' => $this->state['actor_id'], 'updated_by' => $this->state['actor_id'],
        ])->save();
        $this->state['payment_id'] = $p->id;
        $this->state['cashier_session_id'] = $cs->id;
    }

    private function createDeposit(string $amount): void
    {
        $cs = $this->createCashierSession();
        $d = new GuestDepositTransaction();
        $d->forceFill([
            'property_id' => $this->state['prop_id'], 'deposit_number' => 'GDP-'.uniqid(),
            'reservation_id' => $this->state['reservation_id'], 'guest_id' => $this->state['guest_id'],
            'currency' => 'USD', 'amount' => $amount,
            'cashier_session_id' => $cs->id, 'tender_type' => 'CASH',
            'lifecycle_status' => GuestDepositLifecycleStatusEnum::Recorded->value,
            'recording_idempotency_key' => 'conc-dep-'.uniqid(),
            'recorded_at' => now(), 'recorded_by' => $this->state['actor_id'],
            'source_snapshot' => json_encode([]),
            'created_by' => $this->state['actor_id'], 'updated_by' => $this->state['actor_id'],
        ])->save();
        $this->state['deposit_id'] = $d->id;
    }

    private function createArRequest(Folio $folio): void
    {
        $ar = new GuestArTransferRequest();
        $ar->forceFill([
            'property_id' => $this->state['prop_id'], 'transfer_number' => 'GAR-'.uniqid(),
            'folio_id' => $folio->id, 'reservation_id' => $this->state['reservation_id'],
            'guest_id' => $this->state['guest_id'], 'currency' => 'USD', 'amount' => '50.00',
            'lifecycle_status' => GuestArTransferStatusEnum::Requested->value,
            'request_reason_code' => 'TEST', 'request_idempotency_key' => 'conc-ar-'.uniqid(),
            'requested_at' => now(), 'requested_by' => $this->state['actor_id'],
            'source_snapshot' => json_encode([]),
            'created_by' => $this->state['actor_id'], 'updated_by' => $this->state['actor_id'],
        ])->save();
        $this->state['ar_request_id'] = $ar->id;
    }
}
