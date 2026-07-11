<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Str;
use Tests\TestCase;

class GuestDepositRefundArTransferConcurrencyProofTest extends TestCase
{
    public function test_all_glf_c_races_use_distinct_workers_and_preserve_bounded_exact_evidence():void
    {
        $result=$this->runCoordinator('glfc'.strtolower(Str::random(6)));
        $this->assertTrue($result['db_created']??false);$this->assertTrue($result['migrations_ok']??false);$this->assertSame('ivorq_testing',$result['protected_database']??null);
        $this->assertNull($result['error']??null,'Coordinator error: '.($result['error']??'none'));
        foreach(['deposit_recording_replay','deposit_number_safety','deposit_application_replay','deposit_over_application','deposit_split','deposit_double_reversal','deposit_application_vs_refund','payment_allocation_vs_refund','ar_accept_reject','ar_double_accept','ar_accept_vs_deposit','ar_double_reversal','duplicate_source_effect']as$name)$this->assertWorkers($result[$name]??[],$name);
        $this->assertSame(['DEPOSIT_RECORDED','DEPOSIT_RECORDED'],$result['deposit_recording_replay']['outcomes']);$this->assertSame(1,$result['deposit_recording_replay']['rows']);$this->assertSame($result['deposit_recording_replay']['worker_a']['id'],$result['deposit_recording_replay']['worker_b']['id']);
        $this->assertSame(2,$result['deposit_number_safety']['rows']);$this->assertSame(2,$result['deposit_number_safety']['numbers']);
        $this->assertSame(1,$result['deposit_application_replay']['applications']);$this->assertSame(1,$result['deposit_application_replay']['items']);
        $this->assertSame(['BOUNDED_REJECT','DEPOSIT_APPLIED'],$result['deposit_over_application']['outcomes']);$this->assertSame(1,$result['deposit_over_application']['applications']);
        $this->assertSame(['DEPOSIT_APPLIED','DEPOSIT_APPLIED'],$result['deposit_split']['outcomes']);$this->assertSame(2,$result['deposit_split']['applications']);$this->assertSame('RESOLVED',$result['deposit_split']['status']);
        $this->assertSame(1,$result['deposit_double_reversal']['reversals']);$this->assertSame(1,$result['deposit_double_reversal']['items']);
        foreach(['deposit_application_vs_refund','payment_allocation_vs_refund']as$name){$this->assertContains('BOUNDED_REJECT',$result[$name]['outcomes'],"{$name}: ".json_encode($result[$name]));$this->assertLessThanOrEqual(100,$result[$name]['resolved']);}
        $this->assertSame(1,$result['ar_accept_reject']['terminal']);$this->assertContains($result['ar_accept_reject']['items'],[0,1]);
        $this->assertSame(1,$result['ar_double_accept']['terminal']);$this->assertSame(1,$result['ar_double_accept']['items']);
        $this->assertContains('DEPOSIT_APPLIED',$result['ar_accept_vs_deposit']['outcomes']);$this->assertSame(1,$result['ar_double_reversal']['reversals']);$this->assertSame(1,$result['ar_double_reversal']['items']);
        $this->assertWorkers($result['duplicate_source_effect']??[],'duplicate_source_effect');$se=$result['duplicate_source_effect'];$this->assertContains('FOLIO_ITEM_INSERTED',$se['outcomes']);$this->assertContains('DUPLICATE_KEY_VIOLATION',$se['outcomes']);$this->assertSame(1,$se['items']);$this->assertSame(1,$se['request_ok']);$this->assertSame(1,$se['decision_ok']);
        $this->assertTrue($result['db_dropped']??false,'Disposable database cleanup failed: '.($result['drop_error']??'none'));
    }
    private function assertWorkers(array $s,string $name):void{$this->assertTrue($s['pid_different']??false,"{$name}: PHP PIDs must differ");$this->assertTrue($s['pg_different']??false,"{$name}: PG PIDs must differ");$this->assertNull($s['worker_a']['hidden_error']??null,"{$name} A: ".json_encode($s));$this->assertNull($s['worker_b']['hidden_error']??null,"{$name} B: ".json_encode($s));}
    private function runCoordinator(string $runId):array
    {
        $db='ivorq_concurrency_glf_c_'.$runId.'_'.strtolower(Str::random(4));$dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ivorq-glfc-conc-'.$runId.'-'.Str::random(4);@mkdir($dir,0700,true);
        try{$cfg=$dir.DIRECTORY_SEPARATOR.'cfg.json';$out=$dir.DIRECTORY_SEPARATOR.'result.json';$pg=config('database.connections.pgsql');file_put_contents($cfg,json_encode(['db_name'=>$db,'barrier_dir'=>$dir,'base_path'=>base_path(),'db_host'=>$pg['host']??'127.0.0.1','db_port'=>(string)($pg['port']??'5432'),'db_user'=>$pg['username'],'db_pass'=>$pg['password'],'result_file'=>$out]));$script=__DIR__.'/Support/GuestDepositRefundArConcurrencyCoordinator.php';$p=proc_open(PHP_BINARY.' '.escapeshellarg($script).' '.escapeshellarg($cfg),[0=>['pipe','r'],1=>['pipe','w']],$pipes,base_path());if(!is_resource($p))return['error'=>'FAILED_TO_START'];fclose($pipes[0]);fclose($pipes[1]);$end=time()+600;while(time()<$end&&!file_exists($out)){usleep(100000);} $r=file_exists($out)?(json_decode(file_get_contents($out),true)?:['error'=>'PARSE_ERROR']):['error'=>'TIMEOUT'];@proc_close($p);return$r;}finally{foreach(glob($dir.DIRECTORY_SEPARATOR.'*')?:[]as$f)if(is_file($f))@unlink($f);@rmdir($dir);}}
}
