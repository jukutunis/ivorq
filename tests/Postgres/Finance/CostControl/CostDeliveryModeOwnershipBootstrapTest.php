<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Adapters\InventoryCostDeliveryModeAdapter;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentScopeSnapshot;
use Modules\Finance\CostControl\Models\CostAvcoState;
use Modules\Finance\CostControl\Models\CostDeliveryPilotProperty;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Services\CostDeliveryModeOwnershipBootstrapService;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use Modules\Operations\Inventory\ValueObjects\CostDeliveryPostingDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\PostgresTestCase;

class CostDeliveryModeOwnershipBootstrapTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;

    private User $actor;

    private InventoryItem $item;

    private InventoryLocation $location;

    private FinancialPeriod $period;

    private CostAuthorityEnrollmentRepository $enrollmentRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::where('currency', 'USD')->firstOrFail();
        $this->actor = User::firstOrFail();
        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'CC-P01A Bootstrap',
        ]);
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'sku' => 'CCP01A-BOOT-'.Str::random(10),
            'name' => 'CC-P01A Bootstrap Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'CC-P01A Bootstrap Location '.Str::random(8),
            'type' => 'internal',
        ]);
        $this->period = FinancialPeriod::updateOrCreate(
            ['property_id' => $this->property->id, 'period_year' => 2026, 'period_month' => 8],
            ['status' => FinancialPeriodStatusEnum::Open]
        );
        $this->enrollmentRepository = app(CostAuthorityEnrollmentRepository::class);
    }

    public function test_bootstrap_requires_active_outer_transaction(): void
    {
        DB::rollBack();
        try {
            app(CostDeliveryModeOwnershipBootstrapService::class)->bootstrap(
                (string) Str::ulid(),
                $this->actor->id,
            );
            $this->fail('Bootstrap must reject calls without an outer transaction.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires an active outer transaction', $exception->getMessage());
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_enrolled_group_bootstraps_once_and_exact_repeat_is_idempotent(): void
    {
        $group = $this->makeGroup(CostAuthorityEnrollmentStatusEnum::Enrolled);
        $service = app(CostDeliveryModeOwnershipBootstrapService::class);

        $first = DB::transaction(fn () => $service->bootstrap($group->id, $this->actor->id));
        $second = DB::transaction(fn () => $service->bootstrap($group->id, (string) Str::ulid()));

        $this->assertSame($first->id, $second->id);
        $this->assertSame($this->actor->id, $second->established_by);
        $this->assertSame(CostDeliveryMode::Synchronous, $second->delivery_mode);
        $this->assertSame(1, $second->ownership_version);
        $this->assertDatabaseCount('cost_delivery_mode_ownerships', 1);
        $this->assertDatabaseCount('cost_delivery_pilot_properties', 0);
        $this->assertDatabaseCount('cost_delivery_cutovers', 0);
    }

    #[DataProvider('nonEnrolledStatusProvider')]
    public function test_non_enrolled_lifecycle_states_are_rejected(string $status): void
    {
        $group = $this->makeGroup(CostAuthorityEnrollmentStatusEnum::from($status));

        try {
            DB::transaction(fn () => app(CostDeliveryModeOwnershipBootstrapService::class)
                ->bootstrap($group->id, $this->actor->id));
            $this->fail("Status {$status} must be rejected.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires status=ENROLLED', $exception->getMessage());
        }

        $this->assertDatabaseMissing('cost_delivery_mode_ownerships', ['enrollment_group_id' => $group->id]);
    }

    public static function nonEnrolledStatusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'approved' => ['approved'],
            'rejected' => ['rejected'],
            'superseded' => ['superseded'],
        ];
    }

    public function test_incomplete_enrolled_snapshot_set_is_rejected(): void
    {
        $groupId = (string) Str::ulid();
        DB::table('cost_authority_enrollment_groups')->insert([
            'id' => $groupId,
            'property_id' => $this->property->id,
            'item_id' => $this->item->id,
            'status' => 'enrolled',
            'approved_by' => $this->actor->id,
            'approved_at' => now(),
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::transaction(fn () => app(CostDeliveryModeOwnershipBootstrapService::class)
                ->bootstrap($groupId, $this->actor->id));
            $this->fail('Missing scope snapshots must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires complete enrollment snapshots', $exception->getMessage());
        }

        $this->assertDatabaseMissing('cost_delivery_mode_ownerships', ['enrollment_group_id' => $groupId]);
    }

    public function test_deferred_existing_ownership_is_not_misreported_as_idempotent_bootstrap(): void
    {
        $group = $this->makeGroup(CostAuthorityEnrollmentStatusEnum::Enrolled);
        $ownership = DB::transaction(fn () => app(CostDeliveryModeOwnershipBootstrapService::class)
            ->bootstrap($group->id, $this->actor->id));
        $this->transitionToDeferred($group, $ownership->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cost delivery ownership bootstrap found mismatched existing ownership.');
        DB::transaction(fn () => app(CostDeliveryModeOwnershipBootstrapService::class)
            ->bootstrap($group->id, $this->actor->id));
    }

    public function test_adapter_distinguishes_missing_enrolled_ownership_from_true_not_enrolled(): void
    {
        $group = $this->makeGroup(CostAuthorityEnrollmentStatusEnum::Enrolled);
        $adapter = app(InventoryCostDeliveryModeAdapter::class);

        try {
            DB::transaction(fn () => $adapter->resolveForPosting(
                $this->property->id,
                $this->item->id,
                $this->location->id,
            ));
            $this->fail('An enrolled group without ownership must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ENROLLED_DELIVERY_OWNERSHIP_MISSING', $exception->getMessage());
        }

        $notEnrolledItem = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $this->item->category_id,
            'sku' => 'CCP01A-NOT-ENROLLED-'.Str::random(8),
            'name' => 'CC-P01A Not Enrolled Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);

        $decision = DB::transaction(fn () => $adapter->resolveForPosting(
            $this->property->id,
            $notEnrolledItem->id,
            $this->location->id,
        ));
        $this->assertSame(CostDeliveryPostingDecision::NOT_ENROLLED, $decision->outcome);
        $this->assertNull($decision->deliveryMode);
        $this->assertDatabaseMissing('cost_delivery_mode_ownerships', ['item_id' => $notEnrolledItem->id]);
    }

    public function test_two_postgresql_contexts_concurrently_bootstrap_exactly_one_ownership(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ivorq_ccp01a_'.strtolower(Str::random(12));
        mkdir($dir, 0700, true);
        $databaseName = 'ivorq_concurrency_ccp01a_'.strtolower(Str::random(10));
        $resultFile = $dir.DIRECTORY_SEPARATOR.'coordinator-result.json';
        $coordinator = <<<'PHP'
$base = $argv[1]; $database = $argv[2]; $dir = $argv[3]; $resultFile = $argv[4];
$host = getenv('DB_HOST') ?: '127.0.0.1'; $port = getenv('DB_PORT') ?: '5432';
$user = getenv('DB_USERNAME') ?: ''; $pass = getenv('DB_PASSWORD') ?: '';
$result = ['ok' => false, 'db_created' => false, 'db_dropped' => false, 'workers' => [], 'count' => null, 'row' => null, 'error' => null];
$quote = fn (string $name): string => '"' . preg_replace('/[^a-z0-9_]/', '', $name) . '"';
$admin = null;
try {
    $admin = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE ' . $quote($database)); $result['db_created'] = true;

    require $base . '/vendor/autoload.php';
    $app = require $base . '/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['database.connections.pgsql.database' => $database]);
    \Illuminate\Support\Facades\DB::purge('pgsql'); \Illuminate\Support\Facades\DB::reconnect('pgsql');
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

    $companyId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('companies')->insert(['id'=>$companyId,'name'=>'CCP01A Company','slug'=>'ccp01a-'.\Illuminate\Support\Str::random(8),'created_at'=>now(),'updated_at'=>now()]);
    $propertyId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('properties')->insert(['id'=>$propertyId,'company_id'=>$companyId,'name'=>'CCP01A Property','slug'=>'ccp01a-prop-'.\Illuminate\Support\Str::random(8),'code'=>'CP'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(5)),'currency'=>'USD','timezone'=>'UTC','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
    $categoryId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_categories')->insert(['id'=>$categoryId,'property_id'=>$propertyId,'name'=>'CCP01A Category','created_at'=>now(),'updated_at'=>now()]);
    $itemId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_items')->insert(['id'=>$itemId,'property_id'=>$propertyId,'category_id'=>$categoryId,'sku'=>'CCP01A-CONC','name'=>'CCP01A Item','inventory_type'=>'goods','weighted_average_cost'=>0,'is_active'=>true,'criticality'=>'low','reorder_point'=>0,'is_batch_tracked'=>false,'is_expiry_tracked'=>false,'created_at'=>now(),'updated_at'=>now()]);
    $locationId = (string) \Illuminate\Support\Str::ulid();
    $periodId = (string) \Illuminate\Support\Str::ulid();
    $actorId = (string) \Illuminate\Support\Str::ulid();
    $groupId = (string) \Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('cost_authority_enrollment_groups')->insert(['id'=>$groupId,'property_id'=>$propertyId,'item_id'=>$itemId,'status'=>'draft','created_at'=>now(),'updated_at'=>now()]);
    \Illuminate\Support\Facades\DB::table('cost_authority_enrollment_scope_snapshots')->insert(['id'=>(string)\Illuminate\Support\Str::ulid(),'enrollment_group_id'=>$groupId,'location_id'=>$locationId,'valuation_scope'=>"property:{$propertyId}:location:{$locationId}:item:{$itemId}",'opening_quantity'=>0,'opening_carrying_value'=>0,'currency_code'=>'USD','business_date'=>'2026-08-01','financial_period_id'=>$periodId,'source_reference'=>'CC-P01A-CONCURRENCY','evidence_timestamp'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    \Illuminate\Support\Facades\DB::table('cost_authority_enrollment_groups')->where('id',$groupId)->update(['status'=>'approved','approved_by'=>$actorId,'approved_at'=>now(),'updated_at'=>now()]);
    \Illuminate\Support\Facades\DB::table('cost_authority_enrollment_groups')->where('id',$groupId)->update(['status'=>'enrolled','enrolled_at'=>now(),'updated_at'=>now()]);

    $worker = <<<'WORKER'
$base=$argv[1]; $db=$argv[2]; $group=$argv[3]; $actor=$argv[4]; $dir=$argv[5]; $name=$argv[6];
require $base.'/vendor/autoload.php'; $app=require $base.'/bootstrap/app.php'; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.pgsql.database'=>$db]); \Illuminate\Support\Facades\DB::purge('pgsql'); \Illuminate\Support\Facades\DB::reconnect('pgsql');
$r=['ok'=>false,'id'=>null,'pid'=>null,'error'=>null];
try { $r['pid']=(int)\Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() pid')->pid; touch($dir.'/ready-'.$name); $until=microtime(true)+20; while(!file_exists($dir.'/start')&&microtime(true)<$until){usleep(10000);} $o=\Illuminate\Support\Facades\DB::transaction(function()use($group,$actor){\Illuminate\Support\Facades\DB::statement("SET LOCAL lock_timeout='10s'");return app(\Modules\Finance\CostControl\Services\CostDeliveryModeOwnershipBootstrapService::class)->bootstrap($group,$actor);}); $r['ok']=true; $r['id']=$o->id; } catch(\Throwable $e){$r['error']=get_class($e).':'.$e->getMessage();}
file_put_contents($dir.'/result-'.$name.'.json',json_encode($r),LOCK_EX);
WORKER;
    $workerFile=$dir.'/worker.php'; file_put_contents($workerFile,"<?php\n".$worker);
    $processes=[];
    foreach(['A','B'] as $name){$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($workerFile).' '.escapeshellarg($base).' '.escapeshellarg($database).' '.escapeshellarg($groupId).' '.escapeshellarg($actorId).' '.escapeshellarg($dir).' '.escapeshellarg($name);$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$base);fclose($pipes[0]);$processes[]=[$p,$pipes];}
    $until=microtime(true)+30; while((!file_exists($dir.'/ready-A')||!file_exists($dir.'/ready-B'))&&microtime(true)<$until){usleep(20000);} touch($dir.'/start');
    $until=microtime(true)+30; while((!file_exists($dir.'/result-A.json')||!file_exists($dir.'/result-B.json'))&&microtime(true)<$until){usleep(20000);}
    foreach($processes as [$p,$pipes]){stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);}
    $result['workers']=[json_decode((string)@file_get_contents($dir.'/result-A.json'),true),json_decode((string)@file_get_contents($dir.'/result-B.json'),true)];
    $result['count']=\Illuminate\Support\Facades\DB::table('cost_delivery_mode_ownerships')->where('enrollment_group_id',$groupId)->count();
    $result['row']=(array)\Illuminate\Support\Facades\DB::table('cost_delivery_mode_ownerships')->where('enrollment_group_id',$groupId)->first();
    $result['ok']=$result['workers'][0]['ok']&&$result['workers'][1]['ok']&&$result['workers'][0]['id']===$result['workers'][1]['id']&&$result['workers'][0]['pid']!==$result['workers'][1]['pid']&&$result['count']===1;
} catch(\Throwable $e){$result['error']=get_class($e).':'.$e->getMessage();}
try { if(class_exists(\Illuminate\Support\Facades\DB::class)){\Illuminate\Support\Facades\DB::disconnect();\Illuminate\Support\Facades\DB::purge('pgsql');} if(!$admin){$admin=new PDO("pgsql:host={$host};port={$port};dbname=postgres",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);} $stmt=$admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname=:db AND pid<>pg_backend_pid()');$stmt->execute(['db'=>$database]);$admin->exec('DROP DATABASE IF EXISTS '.$quote($database));$result['db_dropped']=true;}catch(\Throwable $e){$result['error']=($result['error']??'').' cleanup:'.$e->getMessage();}
file_put_contents($resultFile,json_encode($result),LOCK_EX);
PHP;

        $coordinatorFile = $dir.DIRECTORY_SEPARATOR.'coordinator.php';
        file_put_contents($coordinatorFile, "<?php\n".$coordinator);
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($coordinatorFile)
            .' '.escapeshellarg(base_path())
            .' '.escapeshellarg($databaseName)
            .' '.escapeshellarg($dir)
            .' '.escapeshellarg($resultFile);
        $process = null;
        try {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $deadline = microtime(true) + 120;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(50000);
            }
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $process = null;
            $this->assertSame(0, $exit, $stdout.$stderr);
            $this->assertFileExists($resultFile, $stdout.$stderr);
            $result = json_decode(file_get_contents($resultFile), true);
            $this->assertTrue($result['db_created'], (string) $result['error']);
            $this->assertTrue($result['db_dropped'], (string) $result['error']);
            $this->assertTrue($result['ok'], json_encode($result));
            $this->assertSame(1, $result['count']);
            $this->assertSame('SYNCHRONOUS', $result['row']['delivery_mode']);
            $this->assertSame(1, (int) $result['row']['ownership_version']);
            $this->assertNull($result['row']['activated_cutover_id']);
            $this->assertNotEmpty($result['row']['established_by']);
            $this->assertNotEmpty($result['row']['established_at']);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    private function makeGroup(CostAuthorityEnrollmentStatusEnum $status): CostAuthorityEnrollmentGroup
    {
        $scope = "property:{$this->property->id}:location:{$this->location->id}:item:{$this->item->id}";
        $group = $this->enrollmentRepository->createDraft(
            ['property_id' => $this->property->id, 'item_id' => $this->item->id],
            [[
                'location_id' => $this->location->id,
                'valuation_scope' => $scope,
                'opening_quantity' => '0.0000',
                'opening_carrying_value' => '0.0000',
                'currency_code' => 'USD',
                'business_date' => '2026-08-01',
                'financial_period_id' => $this->period->id,
                'source_reference' => 'CC-P01A-BOOTSTRAP-TEST',
                'evidence_timestamp' => now(),
            ]]
        );

        if ($status === CostAuthorityEnrollmentStatusEnum::Draft) {
            return $group;
        }

        if ($status === CostAuthorityEnrollmentStatusEnum::Rejected) {
            return DB::transaction(fn () => $this->enrollmentRepository->reject(
                $group->id,
                $this->actor->id,
                now(),
                'TEST_REJECTION',
            ));
        }

        DB::transaction(fn () => $this->enrollmentRepository->approve($group->id, $this->actor->id, now()));

        if ($status === CostAuthorityEnrollmentStatusEnum::Approved) {
            return $group->fresh();
        }

        if ($status === CostAuthorityEnrollmentStatusEnum::Superseded) {
            return DB::transaction(fn () => $this->enrollmentRepository->supersedeApproved(
                $group->id,
                $this->actor->id,
                now(),
                'TEST_SUPERSESSION',
            ));
        }

        DB::table('cost_authority_enrollment_groups')->where('id', $group->id)->update([
            'status' => 'enrolled',
            'enrolled_at' => now(),
            'updated_at' => now(),
        ]);

        return $group->fresh();
    }

    private function transitionToDeferred(CostAuthorityEnrollmentGroup $group, string $ownershipId): void
    {
        $snapshot = CostAuthorityEnrollmentScopeSnapshot::where('enrollment_group_id', $group->id)->firstOrFail();
        CostAvcoState::create([
            'property_id' => $group->property_id,
            'location_id' => $snapshot->location_id,
            'item_id' => $group->item_id,
            'valuation_scope' => $snapshot->valuation_scope,
            'on_hand_quantity' => '0.0000',
            'carrying_value' => '0.0000',
            'weighted_average_unit_cost' => null,
            'unresolved_provisional_quantity' => '0.0000',
            'last_valuation_sequence' => null,
            'last_valuation_business_date' => null,
            'enrollment_group_id' => $group->id,
            'enrollment_scope_snapshot_id' => $snapshot->id,
        ]);
        CostDeliveryPilotProperty::create([
            'pilot_slot' => 1,
            'property_id' => $group->property_id,
            'owner_approval_reference' => 'OWNER-CC-P01A-DEFERRED-TEST',
            'authorized_by' => $this->actor->id,
            'authorized_at' => now(),
        ]);

        DB::transaction(function () use ($group, $ownershipId, $snapshot) {
            $cutoverId = (string) Str::ulid();
            DB::table('cost_delivery_cutovers')->insert([
                'id' => $cutoverId,
                'ownership_id' => $ownershipId,
                'enrollment_group_id' => $group->id,
                'property_id' => $group->property_id,
                'item_id' => $group->item_id,
                'financial_period_id' => $this->period->id,
                'boundary_business_date' => '2026-08-31',
                'owner_approval_reference' => 'OWNER-CC-P01A-DEFERRED-TEST',
                'requested_by' => $this->actor->id,
                'requested_at' => now()->subMinutes(2),
                'approved_by' => $this->actor->id,
                'approved_at' => now()->subMinute(),
                'activated_by' => $this->actor->id,
                'activated_at' => now(),
                'created_at' => now(),
            ]);
            DB::table('cost_delivery_cutover_scopes')->insert([
                'id' => (string) Str::ulid(),
                'cutover_id' => $cutoverId,
                'enrollment_scope_snapshot_id' => $snapshot->id,
                'property_id' => $group->property_id,
                'location_id' => $snapshot->location_id,
                'item_id' => $group->item_id,
                'valuation_scope' => $snapshot->valuation_scope,
                'inventory_sequence_source' => 'ALLOCATOR_ABSENT',
                'inventory_valuation_sequence_id' => null,
                'inventory_allocator_last_sequence' => 0,
                'cost_avco_last_valuation_sequence' => null,
                'sequence_state_classification' => 'NO_PRIOR_APPLIED_VALUATION_SEQUENCE',
                'last_synchronously_owned_sequence' => 0,
                'first_deferred_owned_sequence' => 1,
                'created_at' => now(),
            ]);
            DB::table('cost_delivery_mode_ownerships')->where('id', $ownershipId)->update([
                'delivery_mode' => 'DEFERRED',
                'ownership_version' => 2,
                'activated_cutover_id' => $cutoverId,
                'changed_by' => $this->actor->id,
                'changed_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
