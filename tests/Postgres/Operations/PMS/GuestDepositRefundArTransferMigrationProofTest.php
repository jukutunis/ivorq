<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Str;
use Tests\TestCase;

class GuestDepositRefundArTransferMigrationProofTest extends TestCase
{
    public function test_glf_c_up_down_reapply_and_legacy_blocker_use_disposable_databases():void
    {
        $r=$this->runProof('glfc'.strtolower(Str::random(6)));$this->assertNull($r['error']??null,'Migration proof error: '.($r['error']??'none'));
        foreach(['proof_db_created','ambiguous_db_created','pre_glfc_ok','up_ok','up_tables','up_columns','up_constraints','up_triggers','valid_rows','invalid_sql_blocked','down_ok','down_tables_removed','down_columns_removed','glfa_glfb_preserved','reapply_ok','ambiguous_blocked','ambiguous_no_partial','ambiguous_row_preserved','proof_db_dropped','ambiguous_db_dropped']as$key)$this->assertTrue($r[$key]??false,"Migration proof failed: {$key}; ".json_encode($r));
        $this->assertStringContainsString('GLF_C_BLOCKED_LEGACY_DEPOSIT_ITEMS',$r['ambiguous_error']??'');
    }
    private function runProof(string $runId):array{$dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ivorq-glfc-mig-'.$runId.'-'.Str::random(4);@mkdir($dir,0700,true);try{$cfg=$dir.DIRECTORY_SEPARATOR.'cfg.json';$out=$dir.DIRECTORY_SEPARATOR.'out.json';$pg=config('database.connections.pgsql');file_put_contents($cfg,json_encode(['run_id'=>$runId,'base_path'=>base_path(),'db_host'=>$pg['host']??'127.0.0.1','db_port'=>(string)($pg['port']??'5432'),'db_user'=>$pg['username'],'db_pass'=>$pg['password'],'result_file'=>$out]));$script=__DIR__.'/Support/GuestDepositRefundArMigrationProofRunner.php';$proc=proc_open(PHP_BINARY.' '.escapeshellarg($script).' '.escapeshellarg($cfg),[0=>['pipe','r'],1=>['pipe','w']],$pipes,base_path());if(!is_resource($proc))return['error'=>'FAILED_TO_START'];fclose($pipes[0]);fclose($pipes[1]);$end=time()+600;while(time()<$end&&!file_exists($out))usleep(100000);$r=file_exists($out)?(json_decode(file_get_contents($out),true)?:['error'=>'PARSE_ERROR']):['error'=>'TIMEOUT'];@proc_close($proc);return$r;}finally{foreach(glob($dir.DIRECTORY_SEPARATOR.'*')?:[]as$f)if(is_file($f))@unlink($f);@rmdir($dir);}}
}
