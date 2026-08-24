<?php

namespace Tests\Postgres\Finance\CostControl;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Enums\CostAuthorityEnrollmentStatusEnum;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Models\CostDeliveryModeOwnership;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\CostControl\Repositories\CostDeliveryModeOwnershipRepository;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentActivationService;
use Modules\Finance\CostControl\Services\CostAuthorityEnrollmentBaselineSeedService;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\PostgresTestCase;

class CostAuthorityEnrollmentActivationTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;

    private User $actor;

    private InventoryItem $item;

    private InventoryLocation $location;

    private FinancialPeriod $period;

    private CostAuthorityEnrollmentRepository $enrollmentRepository;

    private CostAuthorityEnrollmentBaselineSeedService $baselineService;

    private CostAuthorityEnrollmentActivationService $activationService;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::where('currency', 'USD')->firstOrFail();
        $this->actor = User::firstOrFail();

        $category = InventoryCategory::firstOrCreate([
            'property_id' => $this->property->id,
            'name' => 'Cost Authority Enrollment Activation',
        ]);
        $this->item = InventoryItem::create([
            'property_id' => $this->property->id,
            'category_id' => $category->id,
            'sku' => 'CAEA-'.Str::random(10),
            'name' => 'Cost Authority Enrollment Activation Item',
            'inventory_type' => 'goods',
            'weighted_average_cost' => 0,
            'is_active' => true,
        ]);
        $this->location = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Cost Authority Activation '.Str::random(8),
            'type' => 'internal',
        ]);
        $this->period = FinancialPeriod::updateOrCreate(
            [
                'property_id' => $this->property->id,
                'period_year' => 2026,
                'period_month' => 8,
            ],
            ['status' => FinancialPeriodStatusEnum::Open],
        );

        $this->enrollmentRepository = app(CostAuthorityEnrollmentRepository::class);
        $this->baselineService = app(CostAuthorityEnrollmentBaselineSeedService::class);
        $this->activationService = app(CostAuthorityEnrollmentActivationService::class);
    }

    public function test_controlled_activation_commits_enrollment_and_exact_initial_ownership(): void
    {
        $group = $this->makeApprovedGroup();
        $this->seedBaseline($group);

        $ownership = $this->activationService->activate($group->id, $this->actor->id);

        $this->assertSame(CostAuthorityEnrollmentStatusEnum::Enrolled, $group->fresh()->status);
        $this->assertNotNull($group->fresh()->enrolled_at);
        $this->assertSame($group->property_id, $ownership->property_id);
        $this->assertSame($group->item_id, $ownership->item_id);
        $this->assertSame($group->id, $ownership->enrollment_group_id);
        $this->assertSame('SYNCHRONOUS', $ownership->delivery_mode->value);
        $this->assertSame(1, $ownership->ownership_version);
        $this->assertNull($ownership->activated_cutover_id);
        $this->assertSame($this->actor->id, $ownership->established_by);
        $this->assertNotNull($ownership->established_at);
        $this->assertNull($ownership->changed_by);
        $this->assertNull($ownership->changed_at);
        $this->assertSame(1, CostDeliveryModeOwnership::where('enrollment_group_id', $group->id)->count());
    }

    public function test_uncommitted_enrollment_is_not_visible_before_matching_ownership_commits(): void
    {
        $group = $this->makeApprovedGroup();
        $primary = DB::connection('pgsql');
        config(['database.connections.pgsql_visibility' => config('database.connections.pgsql')]);
        DB::purge('pgsql_visibility');
        $observer = DB::connection('pgsql_visibility');

        try {
            $primary->beginTransaction();
            DB::table('cost_authority_enrollment_groups')->where('id', $group->id)->update([
                'status' => 'enrolled',
                'enrolled_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertSame(
                'approved',
                $observer->table('cost_authority_enrollment_groups')->where('id', $group->id)->value('status'),
            );
            $this->assertSame(
                0,
                $observer->table('cost_delivery_mode_ownerships')->where('enrollment_group_id', $group->id)->count(),
            );

            app(CostDeliveryModeOwnershipRepository::class)->createInitialSynchronous(
                $group->fresh(),
                $this->actor->id,
            );
            $primary->commit();

            $this->assertSame(
                'enrolled',
                $observer->table('cost_authority_enrollment_groups')->where('id', $group->id)->value('status'),
            );
            $this->assertSame(
                1,
                $observer->table('cost_delivery_mode_ownerships')->where('enrollment_group_id', $group->id)->count(),
            );
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::disconnect('pgsql_visibility');
            DB::purge('pgsql_visibility');
        }
    }

    public function test_ownership_creation_failure_rolls_enrollment_back_to_approved(): void
    {
        $group = $this->makeApprovedGroup();
        $this->seedBaseline($group);

        try {
            $this->activationService->activate($group->id, str_repeat('A', 27));
            $this->fail('Invalid ownership provenance must roll the complete activation back.');
        } catch (\Throwable) {
            $this->assertSame(CostAuthorityEnrollmentStatusEnum::Approved, $group->fresh()->status);
            $this->assertSame(0, CostDeliveryModeOwnership::where('enrollment_group_id', $group->id)->count());
        }
    }

    public function test_direct_enrollment_without_ownership_fails_at_commit(): void
    {
        $group = $this->makeApprovedGroup();

        $this->assertRejectedTransaction($group, function () use ($group): void {
            DB::table('cost_authority_enrollment_groups')->where('id', $group->id)->update([
                'status' => 'enrolled',
                'enrolled_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'ENROLLED commit requires exactly one equivalent initial SYNCHRONOUS ownership');
    }

    public function test_direct_insert_already_enrolled_without_ownership_fails_at_commit(): void
    {
        $groupId = (string) Str::ulid();
        $rejection = null;

        try {
            DB::transaction(function () use ($groupId): void {
                DB::table('cost_authority_enrollment_groups')->insert([
                    'id' => $groupId,
                    'property_id' => $this->property->id,
                    'item_id' => $this->item->id,
                    'status' => 'enrolled',
                    'enrolled_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            $rejection = $exception;
        }

        $this->assertNotNull($rejection, 'Expected direct ENROLLED insert to be rejected at commit.');
        $this->assertStringContainsString(
            'ENROLLED commit requires exactly one equivalent initial SYNCHRONOUS ownership',
            $rejection->getMessage(),
        );
        $this->assertFalse(CostAuthorityEnrollmentGroup::whereKey($groupId)->exists());
    }

    public function test_direct_enrollment_with_malformed_ownership_fails(): void
    {
        $group = $this->makeApprovedGroup();

        $this->assertRejectedOwnership($group, ['established_by' => '']);
    }

    #[DataProvider('invalidInitialOwnershipProvider')]
    public function test_invalid_initial_ownership_is_rejected(string $scenario): void
    {
        $group = $this->makeApprovedGroup();
        $overrides = match ($scenario) {
            'property_mismatch' => ['property_id' => (string) Str::ulid()],
            'item_mismatch' => ['item_id' => (string) Str::ulid()],
            'enrollment_group_mismatch' => ['enrollment_group_id' => (string) Str::ulid()],
            'initial_deferred' => [
                'delivery_mode' => 'DEFERRED',
                'activated_cutover_id' => (string) Str::ulid(),
            ],
            'version_not_one' => ['ownership_version' => 2],
            'initial_cutover' => ['activated_cutover_id' => (string) Str::ulid()],
            'changed_provenance' => [
                'changed_by' => $this->actor->id,
                'changed_at' => now(),
            ],
            'blank_established_by' => ['established_by' => ''],
            'missing_established_by' => ['established_by' => null],
        };

        $this->assertRejectedOwnership($group, $overrides);
    }

    public static function invalidInitialOwnershipProvider(): array
    {
        return [
            'Property mismatch' => ['property_mismatch'],
            'Item mismatch' => ['item_mismatch'],
            'Enrollment group mismatch' => ['enrollment_group_mismatch'],
            'Initial DEFERRED' => ['initial_deferred'],
            'Version not one' => ['version_not_one'],
            'Initial cutover' => ['initial_cutover'],
            'Changed provenance' => ['changed_provenance'],
            'Blank established by' => ['blank_established_by'],
            'Missing established by' => ['missing_established_by'],
        ];
    }

    public function test_incomplete_canonical_snapshots_fail_before_enrollment(): void
    {
        $group = $this->makeApprovedGroup(['financial_period_id' => null]);

        try {
            $this->activationService->activate($group->id, $this->actor->id);
            $this->fail('Incomplete snapshot evidence must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('incomplete or non-canonical enrollment snapshots', $exception->getMessage());
        }

        $this->assertSame(CostAuthorityEnrollmentStatusEnum::Approved, $group->fresh()->status);
    }

    public function test_missing_required_cost_avco_baseline_fails_closed(): void
    {
        $group = $this->makeApprovedGroup();

        try {
            $this->activationService->activate($group->id, $this->actor->id);
            $this->fail('Missing baseline evidence must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('complete seeded CostAvcoState baseline is required', $exception->getMessage());
        }

        $this->assertSame(CostAuthorityEnrollmentStatusEnum::Approved, $group->fresh()->status);
        $this->assertSame(0, CostDeliveryModeOwnership::where('enrollment_group_id', $group->id)->count());
    }

    public function test_non_approved_lifecycle_is_rejected(): void
    {
        $group = $this->makeDraftGroup();

        try {
            $this->activationService->activate($group->id, $this->actor->id);
            $this->fail('Only APPROVED enrollment may activate.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('group must be APPROVED', $exception->getMessage());
        }

        $this->assertSame(CostAuthorityEnrollmentStatusEnum::Draft, $group->fresh()->status);
    }

    public function test_exact_retry_fails_closed_without_second_ownership(): void
    {
        $group = $this->makeApprovedGroup();
        $this->seedBaseline($group);
        $first = $this->activationService->activate($group->id, $this->actor->id);

        try {
            $this->activationService->activate($group->id, $this->actor->id);
            $this->fail('Committed activation retry must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('group must be APPROVED', $exception->getMessage());
        }

        $this->assertSame($first->id, CostDeliveryModeOwnership::where('enrollment_group_id', $group->id)->sole()->id);
        $this->assertSame(1, CostDeliveryModeOwnership::where('enrollment_group_id', $group->id)->count());
    }

    public function test_second_active_enrollment_for_property_and_item_remains_prohibited(): void
    {
        $first = $this->makeApprovedGroup();
        $this->seedBaseline($first);
        $this->activationService->activate($first->id, $this->actor->id);

        $secondLocation = InventoryLocation::create([
            'property_id' => $this->property->id,
            'name' => 'Second enrollment '.Str::random(8),
            'type' => 'internal',
        ]);
        $second = $this->makeApprovedGroup([], $secondLocation);
        $this->seedBaseline($second);

        try {
            $this->activationService->activate($second->id, $this->actor->id);
            $this->fail('A second ENROLLED group for one Property+Item must be rejected.');
        } catch (\Throwable) {
            $this->assertSame(CostAuthorityEnrollmentStatusEnum::Approved, $second->fresh()->status);
        }

        $this->assertSame(0, CostDeliveryModeOwnership::where('enrollment_group_id', $second->id)->count());
    }

    public function test_two_postgresql_contexts_produce_one_activation_and_one_controlled_loser(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ivorq_ccp01a_prea4_'.strtolower(Str::random(12));
        mkdir($dir, 0700, true);
        $databaseName = 'ivorq_concurrency_ccp01a_prea4_'.strtolower(Str::random(8));
        $resultFile = $dir.DIRECTORY_SEPARATOR.'coordinator-result.json';
        $coordinator = <<<'PHP'
$base=$argv[1]; $database=$argv[2]; $dir=$argv[3]; $resultFile=$argv[4];
$host=getenv('DB_HOST')?:'127.0.0.1'; $port=getenv('DB_PORT')?:'5432';
$user=getenv('DB_USERNAME')?:''; $pass=getenv('DB_PASSWORD')?:'';
$result=['ok'=>false,'db_created'=>false,'db_dropped'=>false,'workers'=>[],'count'=>null,'group_status'=>null,'row'=>null,'error'=>null];
$quote=fn(string $name):string=>'"'.preg_replace('/[^a-z0-9_]/','',$name).'"';
$admin=null;
try {
    $admin=new PDO("pgsql:host={$host};port={$port};dbname=postgres",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE '.$quote($database)); $result['db_created']=true;
    require $base.'/vendor/autoload.php'; $app=require $base.'/bootstrap/app.php'; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['database.connections.pgsql.database'=>$database]); \Illuminate\Support\Facades\DB::purge('pgsql'); \Illuminate\Support\Facades\DB::reconnect('pgsql');
    \Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
    $companyId=(string)\Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('companies')->insert(['id'=>$companyId,'name'=>'Pre A4 Company','slug'=>'pre-a4-'.\Illuminate\Support\Str::random(8),'created_at'=>now(),'updated_at'=>now()]);
    $propertyId=(string)\Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('properties')->insert(['id'=>$propertyId,'company_id'=>$companyId,'name'=>'Pre A4 Property','slug'=>'pre-a4-prop-'.\Illuminate\Support\Str::random(8),'code'=>'PA'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(5)),'currency'=>'USD','timezone'=>'UTC','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
    $categoryId=(string)\Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_categories')->insert(['id'=>$categoryId,'property_id'=>$propertyId,'name'=>'Pre A4 Category','created_at'=>now(),'updated_at'=>now()]);
    $itemId=(string)\Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_items')->insert(['id'=>$itemId,'property_id'=>$propertyId,'category_id'=>$categoryId,'sku'=>'PRE-A4-CONC','name'=>'Pre A4 Item','inventory_type'=>'goods','weighted_average_cost'=>0,'is_active'=>true,'criticality'=>'low','reorder_point'=>0,'is_batch_tracked'=>false,'is_expiry_tracked'=>false,'created_at'=>now(),'updated_at'=>now()]);
    $locationId=(string)\Illuminate\Support\Str::ulid();
    \Illuminate\Support\Facades\DB::table('inventory_locations')->insert(['id'=>$locationId,'property_id'=>$propertyId,'name'=>'Pre A4 Location','type'=>'internal','created_at'=>now(),'updated_at'=>now()]);
    $actorId=(string)\Illuminate\Support\Str::ulid(); $groupId=(string)\Illuminate\Support\Str::ulid(); $snapshotId=(string)\Illuminate\Support\Str::ulid();
    $scope="property:{$propertyId}:location:{$locationId}:item:{$itemId}";
    \Illuminate\Support\Facades\DB::table('cost_authority_enrollment_groups')->insert(['id'=>$groupId,'property_id'=>$propertyId,'item_id'=>$itemId,'status'=>'draft','created_at'=>now(),'updated_at'=>now()]);
    \Illuminate\Support\Facades\DB::table('cost_authority_enrollment_scope_snapshots')->insert(['id'=>$snapshotId,'enrollment_group_id'=>$groupId,'location_id'=>$locationId,'valuation_scope'=>$scope,'opening_quantity'=>0,'opening_carrying_value'=>0,'currency_code'=>'USD','business_date'=>'2026-08-01','financial_period_id'=>(string)\Illuminate\Support\Str::ulid(),'source_reference'=>'PRE-A4-CONCURRENCY','evidence_timestamp'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    \Illuminate\Support\Facades\DB::table('cost_authority_enrollment_groups')->where('id',$groupId)->update(['status'=>'approved','approved_by'=>$actorId,'approved_at'=>now(),'updated_at'=>now()]);
    \Illuminate\Support\Facades\DB::table('cost_avco_states')->insert(['id'=>(string)\Illuminate\Support\Str::ulid(),'property_id'=>$propertyId,'location_id'=>$locationId,'item_id'=>$itemId,'valuation_scope'=>$scope,'on_hand_quantity'=>0,'carrying_value'=>0,'weighted_average_unit_cost'=>null,'unresolved_provisional_quantity'=>0,'last_valuation_sequence'=>null,'last_valuation_business_date'=>null,'enrollment_group_id'=>$groupId,'enrollment_scope_snapshot_id'=>$snapshotId,'created_at'=>now(),'updated_at'=>now()]);
    $worker=<<<'WORKER'
$base=$argv[1];$db=$argv[2];$group=$argv[3];$actor=$argv[4];$dir=$argv[5];$name=$argv[6];
require $base.'/vendor/autoload.php';$app=require $base.'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.pgsql.database'=>$db]);\Illuminate\Support\Facades\DB::purge('pgsql');\Illuminate\Support\Facades\DB::reconnect('pgsql');
$r=['ok'=>false,'id'=>null,'pid'=>null,'error'=>null];
try{$r['pid']=(int)\Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() pid')->pid;touch($dir.'/ready-'.$name);$until=microtime(true)+20;while(!file_exists($dir.'/start')&&microtime(true)<$until){usleep(10000);}\Illuminate\Support\Facades\DB::statement("SET lock_timeout='10s'");$o=app(\Modules\Finance\CostControl\Services\CostAuthorityEnrollmentActivationService::class)->activate($group,$actor);$r['ok']=true;$r['id']=$o->id;}catch(\Throwable $e){$r['error']=get_class($e).':'.$e->getMessage();}
file_put_contents($dir.'/result-'.$name.'.json',json_encode($r),LOCK_EX);
WORKER;
    $workerFile=$dir.'/worker.php';file_put_contents($workerFile,"<?php\n".$worker);$processes=[];
    foreach(['A','B'] as $name){$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($workerFile).' '.escapeshellarg($base).' '.escapeshellarg($database).' '.escapeshellarg($groupId).' '.escapeshellarg($actorId).' '.escapeshellarg($dir).' '.escapeshellarg($name);$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$base);fclose($pipes[0]);$processes[]=[$p,$pipes];}
    $until=microtime(true)+30;while((!file_exists($dir.'/ready-A')||!file_exists($dir.'/ready-B'))&&microtime(true)<$until){usleep(20000);}touch($dir.'/start');
    $until=microtime(true)+30;while((!file_exists($dir.'/result-A.json')||!file_exists($dir.'/result-B.json'))&&microtime(true)<$until){usleep(20000);}
    foreach($processes as [$p,$pipes]){stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);}
    $result['workers']=[json_decode((string)@file_get_contents($dir.'/result-A.json'),true),json_decode((string)@file_get_contents($dir.'/result-B.json'),true)];
    $result['count']=\Illuminate\Support\Facades\DB::table('cost_delivery_mode_ownerships')->where('enrollment_group_id',$groupId)->count();
    $result['group_status']=\Illuminate\Support\Facades\DB::table('cost_authority_enrollment_groups')->where('id',$groupId)->value('status');
    $result['row']=(array)\Illuminate\Support\Facades\DB::table('cost_delivery_mode_ownerships')->where('enrollment_group_id',$groupId)->first();
    $wins=count(array_filter($result['workers'],fn($w)=>$w['ok']??false));$losses=count(array_filter($result['workers'],fn($w)=>!($w['ok']??false)&&str_contains((string)($w['error']??''),'group must be APPROVED')));
    $result['ok']=$wins===1&&$losses===1&&$result['count']===1&&$result['group_status']==='enrolled'&&$result['row']['delivery_mode']==='SYNCHRONOUS'&&(int)$result['row']['ownership_version']===1;
}catch(\Throwable $e){$result['error']=get_class($e).':'.$e->getMessage();}
try{if(class_exists(\Illuminate\Support\Facades\DB::class)){\Illuminate\Support\Facades\DB::disconnect();\Illuminate\Support\Facades\DB::purge('pgsql');}if(!$admin){$admin=new PDO("pgsql:host={$host};port={$port};dbname=postgres",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}$stmt=$admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname=:db AND pid<>pg_backend_pid()');$stmt->execute(['db'=>$database]);$admin->exec('DROP DATABASE IF EXISTS '.$quote($database));$result['db_dropped']=true;}catch(\Throwable $e){$result['error']=($result['error']??'').' cleanup:'.$e->getMessage();}
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
            $this->assertSame('enrolled', $result['group_status']);
            $this->assertSame('SYNCHRONOUS', $result['row']['delivery_mode']);
            $this->assertSame(1, (int) $result['row']['ownership_version']);
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

    public function test_migration_down_removes_only_new_guard_and_preserves_prior_triggers(): void
    {
        $migration = require base_path(
            'Modules/Finance/CostControl/database/migrations/'.
            '2026_08_21_000500_enforce_atomic_cost_delivery_ownership_on_cost_authority_enrollment.php'
        );

        try {
            $this->assertTrue($this->triggerExists('trg_caeg_enrolled_initial_ownership'));
            $migration->down();

            $this->assertFalse($this->triggerExists('trg_caeg_enrolled_initial_ownership'));
            $this->assertFalse($this->functionExists('enforce_caeg_enrolled_initial_ownership'));
            $this->assertTrue($this->triggerExists('trg_caeg_guard_lifecycle'));
            $this->assertTrue($this->triggerExists('trg_caeg_no_delete'));
            $this->assertTrue($this->triggerExists('trg_cdmo_insert'));
            $this->assertTrue($this->triggerExists('trg_cdmo_update'));
            $this->assertTrue($this->triggerExists('trg_cdmo_no_delete'));
        } finally {
            $migration->up();
        }

        $this->assertTrue($this->triggerExists('trg_caeg_enrolled_initial_ownership'));
    }

    private function makeDraftGroup(
        array $snapshotOverrides = [],
        ?InventoryLocation $location = null,
    ): CostAuthorityEnrollmentGroup {
        $location ??= $this->location;
        $scope = "property:{$this->property->id}:location:{$location->id}:item:{$this->item->id}";

        return $this->enrollmentRepository->createDraft(
            ['property_id' => $this->property->id, 'item_id' => $this->item->id],
            [[...[
                'location_id' => $location->id,
                'valuation_scope' => $scope,
                'opening_quantity' => '10.0000',
                'opening_carrying_value' => '125.0000',
                'currency_code' => 'USD',
                'business_date' => '2026-08-01',
                'financial_period_id' => $this->period->id,
                'source_reference' => 'CC-P01A-PRE-A4-TEST',
                'evidence_timestamp' => now(),
            ], ...$snapshotOverrides]],
        );
    }

    private function makeApprovedGroup(
        array $snapshotOverrides = [],
        ?InventoryLocation $location = null,
    ): CostAuthorityEnrollmentGroup {
        $group = $this->makeDraftGroup($snapshotOverrides, $location);
        DB::transaction(fn () => $this->enrollmentRepository->approve(
            $group->id,
            $this->actor->id,
            now(),
        ));

        return $group->fresh();
    }

    private function seedBaseline(CostAuthorityEnrollmentGroup $group): void
    {
        $this->baselineService->seedApprovedGroup($group->id, $this->actor->id);
    }

    private function assertRejectedTransaction(
        CostAuthorityEnrollmentGroup $group,
        callable $operation,
        string $expectedMessage,
    ): void {
        $rejection = null;

        try {
            DB::transaction($operation);
        } catch (\Throwable $exception) {
            $rejection = $exception;
        }

        $this->assertNotNull($rejection, 'Expected PostgreSQL transaction rejection.');

        if ($expectedMessage !== '') {
            $this->assertStringContainsString($expectedMessage, $rejection->getMessage());
        }

        $this->assertSame(CostAuthorityEnrollmentStatusEnum::Approved, $group->fresh()->status);
        $this->assertSame(0, CostDeliveryModeOwnership::where('enrollment_group_id', $group->id)->count());
    }

    private function assertRejectedOwnership(CostAuthorityEnrollmentGroup $group, array $overrides): void
    {
        $this->assertRejectedTransaction($group, function () use ($group, $overrides): void {
            DB::table('cost_authority_enrollment_groups')->where('id', $group->id)->update([
                'status' => 'enrolled',
                'enrolled_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('cost_delivery_mode_ownerships')->insert([...[
                'id' => (string) Str::ulid(),
                'property_id' => $group->property_id,
                'item_id' => $group->item_id,
                'enrollment_group_id' => $group->id,
                'delivery_mode' => 'SYNCHRONOUS',
                'ownership_version' => 1,
                'activated_cutover_id' => null,
                'established_by' => $this->actor->id,
                'established_at' => now(),
                'changed_by' => null,
                'changed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ], ...$overrides]);
        }, '');
    }

    private function triggerExists(string $name): bool
    {
        return DB::table('pg_trigger')->where('tgname', $name)->where('tgisinternal', false)->exists();
    }

    private function functionExists(string $name): bool
    {
        return DB::table('pg_proc')->where('proname', $name)->exists();
    }
}
