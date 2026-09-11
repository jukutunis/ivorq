<?php

namespace Tests\Postgres\Finance\CostControl;

use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Finance\CostControl\Services\CostDeliveryPilotAuthorizationService;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use RuntimeException;
use Tests\PostgresTestCase;

final class CostDeliveryPilotAuthorizationServiceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private Property $property;

    private Property $otherProperty;

    private User $actor;

    private User $otherActor;

    private CostDeliveryPilotAuthorizationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::where('currency', 'USD')->firstOrFail();
        $this->otherProperty = PropertyFactory::new()->create(['currency' => 'USD']);
        $this->actor = UserFactory::new()->create(['is_active' => true]);
        $this->otherActor = UserFactory::new()->create(['is_active' => true]);
        $this->service = app(CostDeliveryPilotAuthorizationService::class);
    }

    public function test_first_authorization_creates_exactly_one_immutable_slot_one_row(): void
    {
        $pilot = $this->service->authorize($this->property->id, '  OWNER-P02A-001  ', $this->actor->id);

        $this->assertSame(1, $pilot->pilot_slot);
        $this->assertSame($this->property->id, $pilot->property_id);
        $this->assertSame('OWNER-P02A-001', $pilot->owner_approval_reference);
        $this->assertSame($this->actor->id, $pilot->authorized_by);
        $this->assertNotNull($pilot->authorized_at);
        $this->assertDatabaseCount('cost_delivery_pilot_properties', 1);
    }

    public function test_exact_retry_returns_same_row_without_altering_authorized_at(): void
    {
        $first = $this->service->authorize($this->property->id, 'OWNER-P02A-002', $this->actor->id);
        $authorizedAt = DB::table('cost_delivery_pilot_properties')->where('id', $first->id)->value('authorized_at');
        $this->travel(10)->minutes();

        $retry = $this->service->authorize($this->property->id, ' OWNER-P02A-002 ', $this->actor->id);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($authorizedAt, DB::table('cost_delivery_pilot_properties')->where('id', $first->id)->value('authorized_at'));
        $this->assertDatabaseCount('cost_delivery_pilot_properties', 1);
    }

    public function test_different_property_conflicts_without_mutation(): void
    {
        $this->service->authorize($this->property->id, 'OWNER-P02A-003', $this->actor->id);

        $this->assertFailure(
            fn () => $this->service->authorize($this->otherProperty->id, 'OWNER-P02A-003', $this->actor->id),
            'PILOT_AUTHORIZATION_CONFLICT',
        );
    }

    public function test_different_owner_reference_conflicts_without_mutation(): void
    {
        $this->service->authorize($this->property->id, 'OWNER-P02A-004', $this->actor->id);

        $this->assertFailure(
            fn () => $this->service->authorize($this->property->id, 'OWNER-P02A-DIFFERENT', $this->actor->id),
            'PILOT_AUTHORIZATION_CONFLICT',
        );
    }

    public function test_different_authorized_actor_conflicts_without_mutation(): void
    {
        $this->service->authorize($this->property->id, 'OWNER-P02A-005', $this->actor->id);

        $this->assertFailure(
            fn () => $this->service->authorize($this->property->id, 'OWNER-P02A-005', $this->otherActor->id),
            'PILOT_AUTHORIZATION_CONFLICT',
        );
    }

    public function test_missing_property_fails_closed_without_a_row(): void
    {
        $this->assertFailure(
            fn () => $this->service->authorize((string) Str::ulid(), 'OWNER-P02A-006', $this->actor->id),
            'PILOT_AUTHORIZATION_PROPERTY_NOT_FOUND',
        );
    }

    public function test_missing_actor_fails_closed_without_a_row(): void
    {
        $this->assertFailure(
            fn () => $this->service->authorize($this->property->id, 'OWNER-P02A-007', (string) Str::ulid()),
            'PILOT_AUTHORIZATION_ACTOR_NOT_FOUND',
        );
    }

    public function test_inactive_actor_fails_closed_without_a_row(): void
    {
        $inactive = UserFactory::new()->create(['is_active' => false]);

        $this->assertFailure(
            fn () => $this->service->authorize($this->property->id, 'OWNER-P02A-008', $inactive->id),
            'PILOT_AUTHORIZATION_ACTOR_INACTIVE',
        );
    }

    public function test_blank_identities_and_owner_reference_fail_before_insert(): void
    {
        $this->assertFailure(
            fn () => $this->service->authorize('   ', 'OWNER-P02A-INVALID', $this->actor->id),
            'PILOT_AUTHORIZATION_PROPERTY_NOT_FOUND',
        );
        $this->assertFailure(
            fn () => $this->service->authorize($this->property->id, 'OWNER-P02A-INVALID', '   '),
            'PILOT_AUTHORIZATION_ACTOR_NOT_FOUND',
        );

        try {
            $this->service->authorize($this->property->id, '   ', $this->actor->id);
            $this->fail('Blank Owner approval reference must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Pilot owner approval reference cannot be blank.', $exception->getMessage());
        }
        $this->assertDatabaseCount('cost_delivery_pilot_properties', 0);
    }

    public function test_service_exposes_no_update_delete_or_replacement_operation(): void
    {
        $pilot = $this->service->authorize($this->property->id, 'OWNER-P02A-009', $this->actor->id);

        $publicMethods = array_values(array_filter(
            get_class_methods($this->service),
            fn (string $method): bool => $method !== '__construct',
        ));

        $this->assertSame(['authorize'], $publicMethods);
        $this->assertSame([
            'id' => $pilot->id,
            'property_id' => $this->property->id,
            'owner_approval_reference' => 'OWNER-P02A-009',
            'authorized_by' => $this->actor->id,
        ], (array) DB::table('cost_delivery_pilot_properties')->where('id', $pilot->id)
            ->first(['id', 'property_id', 'owner_approval_reference', 'authorized_by']));
    }

    public function test_concurrent_identical_and_conflicting_authorizations_are_serialized(): void
    {
        $result = $this->runIsolatedConcurrencyProof();

        $this->assertTrue($result['database_created'], (string) $result['error']);
        $this->assertTrue($result['database_dropped'], (string) $result['error']);
        $this->assertSame(1, $result['identical']['row_count'], json_encode($result));
        $this->assertSame(2, count(array_filter(
            $result['identical']['workers'],
            fn (array $worker): bool => $worker['ok'],
        )), json_encode($result));
        $this->assertCount(1, array_unique(array_column($result['identical']['workers'], 'pilot_id')));
        $this->assertSame(1, $result['conflicting']['row_count'], json_encode($result));
        $this->assertSame(1, count(array_filter(
            $result['conflicting']['workers'],
            fn (array $worker): bool => $worker['ok'],
        )), json_encode($result));
        $this->assertSame(1, count(array_filter(
            $result['conflicting']['workers'],
            fn (array $worker): bool => $worker['error'] === 'PILOT_AUTHORIZATION_CONFLICT',
        )), json_encode($result));
    }

    private function assertFailure(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail("Expected {$reason}.");
        } catch (RuntimeException $exception) {
            $this->assertSame($reason, $exception->getMessage());
        }

        $this->assertLessThanOrEqual(1, DB::table('cost_delivery_pilot_properties')->count());
    }

    /** @return array<string,mixed> */
    private function runIsolatedConcurrencyProof(): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cc-p02a-'.strtolower(Str::random(12));
        mkdir($directory, 0700, true);
        $resultFile = $directory.DIRECTORY_SEPARATOR.'result.json';
        $database = 'ivorq_concurrency_p02a_'.strtolower(Str::random(8));
        $coordinatorFile = $directory.DIRECTORY_SEPARATOR.'coordinator.php';
        file_put_contents($coordinatorFile, "<?php\n".$this->concurrencyCoordinatorSource());
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($coordinatorFile)
            .' '.escapeshellarg(base_path()).' '.escapeshellarg($database)
            .' '.escapeshellarg($directory).' '.escapeshellarg($resultFile);
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $result = is_file($resultFile) ? json_decode((string) file_get_contents($resultFile), true) : null;

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($directory);

        $this->assertSame(0, $exit, $stdout."\n".$stderr);
        $this->assertIsArray($result, $stdout."\n".$stderr);

        return $result;
    }

    private function concurrencyCoordinatorSource(): string
    {
        return <<<'PHP'
$base=$argv[1];$database=$argv[2];$directory=$argv[3];$resultFile=$argv[4];
$host=getenv('DB_HOST')?:'127.0.0.1';$port=getenv('DB_PORT')?:'5432';$user=getenv('DB_USERNAME')?:'';$pass=getenv('DB_PASSWORD')?:'';
$result=['database_created'=>false,'database_dropped'=>false,'identical'=>null,'conflicting'=>null,'error'=>null];
$quote=fn(string $name):string=>'"'.preg_replace('/[^a-z0-9_]/','',$name).'"';$admin=null;
try{
 $admin=new PDO("pgsql:host={$host};port={$port};dbname=postgres",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $admin->exec('CREATE DATABASE '.$quote($database));$result['database_created']=true;
 require $base.'/vendor/autoload.php';$app=require $base.'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 config(['database.connections.pgsql.database'=>$database]);\Illuminate\Support\Facades\DB::purge('pgsql');\Illuminate\Support\Facades\DB::reconnect('pgsql');
 $migrate=function(){\Illuminate\Support\Facades\Artisan::call('migrate:fresh',['--force'=>true]);};
 $seed=function(string $suffix){$now=now();$company=(string)\Illuminate\Support\Str::ulid();
  \Illuminate\Support\Facades\DB::table('companies')->insert(['id'=>$company,'name'=>'P02A Company '.$suffix,'slug'=>'p02a-company-'.strtolower(\Illuminate\Support\Str::random(8)),'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
  $properties=[];foreach([1,2] as $n){$id=(string)\Illuminate\Support\Str::ulid();\Illuminate\Support\Facades\DB::table('properties')->insert(['id'=>$id,'company_id'=>$company,'name'=>'P02A Property '.$n,'slug'=>'p02a-property-'.$n.'-'.strtolower(\Illuminate\Support\Str::random(8)),'code'=>'P2'.strtoupper(\Illuminate\Support\Str::random(5)),'timezone'=>'UTC','currency'=>'USD','is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);$properties[]=$id;}
  $actors=[];foreach([1,2] as $n){$id=(string)\Illuminate\Support\Str::ulid();\Illuminate\Support\Facades\DB::table('users')->insert(['id'=>$id,'name'=>'P02A Actor '.$n,'email'=>'p02a-'.$suffix.'-'.$n.'@example.test','password'=>'unused','is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);$actors[]=$id;}
  return [$properties,$actors];};
 $worker=<<<'WORKER'
$base=$argv[1];$database=$argv[2];$property=$argv[3];$reference=$argv[4];$actor=$argv[5];$directory=$argv[6];$name=$argv[7];
require $base.'/vendor/autoload.php';$app=require $base.'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(['database.connections.pgsql.database'=>$database]);\Illuminate\Support\Facades\DB::purge('pgsql');\Illuminate\Support\Facades\DB::reconnect('pgsql');
$answer=['ok'=>false,'pilot_id'=>null,'error'=>null];try{touch($directory.'/ready-'.$name);$until=microtime(true)+20;while(!file_exists($directory.'/start')&&microtime(true)<$until){usleep(10000);}\Illuminate\Support\Facades\DB::statement("SET lock_timeout='10s'");$pilot=app(\Modules\Finance\CostControl\Services\CostDeliveryPilotAuthorizationService::class)->authorize($property,$reference,$actor);$answer['ok']=true;$answer['pilot_id']=$pilot->id;}catch(Throwable $e){$answer['error']=$e->getMessage();}file_put_contents($directory.'/worker-'.$name.'.json',json_encode($answer),LOCK_EX);
WORKER;
 $workerFile=$directory.'/worker.php';file_put_contents($workerFile,"<?php\n".$worker);
 $race=function(array $requests,string $label)use($base,$database,$directory,$workerFile){$raceDir=$directory.'/'.$label;mkdir($raceDir,0700,true);$processes=[];foreach($requests as $index=>$request){$name=(string)$index;$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($workerFile).' '.escapeshellarg($base).' '.escapeshellarg($database).' '.escapeshellarg($request[0]).' '.escapeshellarg($request[1]).' '.escapeshellarg($request[2]).' '.escapeshellarg($raceDir).' '.escapeshellarg($name);$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$base);fclose($pipes[0]);$processes[]=[$process,$pipes];}$until=microtime(true)+30;while((!file_exists($raceDir.'/ready-0')||!file_exists($raceDir.'/ready-1'))&&microtime(true)<$until){usleep(10000);}touch($raceDir.'/start');$workers=[];foreach($processes as $index=>[$process,$pipes]){$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$file=$raceDir.'/worker-'.$index.'.json';$workers[]=is_file($file)?json_decode((string)file_get_contents($file),true):['ok'=>false,'pilot_id'=>null,'error'=>"worker exit {$exit}: {$stdout} {$stderr}"];}$row=(array)\Illuminate\Support\Facades\DB::table('cost_delivery_pilot_properties')->first();foreach(glob($raceDir.'/*')?:[] as $file){if(is_file($file))unlink($file);}rmdir($raceDir);return ['workers'=>$workers,'row_count'=>\Illuminate\Support\Facades\DB::table('cost_delivery_pilot_properties')->count(),'row'=>$row];};
 $migrate();[$properties,$actors]=$seed('identical');$result['identical']=$race([[$properties[0],'OWNER-P02A-CONCURRENT',$actors[0]],[$properties[0],'OWNER-P02A-CONCURRENT',$actors[0]]],'identical');
 $migrate();[$properties,$actors]=$seed('conflicting');$result['conflicting']=$race([[$properties[0],'OWNER-P02A-A',$actors[0]],[$properties[1],'OWNER-P02A-B',$actors[1]]],'conflicting');
}catch(Throwable $e){$result['error']=get_class($e).':'.$e->getMessage();}
try{if(class_exists(\Illuminate\Support\Facades\DB::class)){\Illuminate\Support\Facades\DB::disconnect();\Illuminate\Support\Facades\DB::purge('pgsql');}if(!$admin){$admin=new PDO("pgsql:host={$host};port={$port};dbname=postgres",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}$statement=$admin->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname=:database AND pid<>pg_backend_pid()');$statement->execute(['database'=>$database]);$admin->exec('DROP DATABASE IF EXISTS '.$quote($database));$result['database_dropped']=true;}catch(Throwable $e){$result['error']=($result['error']??'').' cleanup:'.$e->getMessage();}
file_put_contents($resultFile,json_encode($result),LOCK_EX);
PHP;
    }
}
