<?php

namespace Tests\Postgres\Operations\PMS\Support;

class GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyCoordinator
{
    /** @var array<int, array{process: resource, pipes: array, data_file: string, mode: string}> */
    private array $workers = [];
    private string $basePath;
    private string $workerScript;

    public function __construct()
    {
        $this->basePath = base_path();
        $this->workerScript = __DIR__ . '/GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyWorker.php';
    }

    public function spawnWorker(string $mode, array $payload, array $environment = []): int
    {
        $dataFile = tempnam(sys_get_temp_dir(), 'glfe_w_');
        file_put_contents($dataFile, json_encode(array_merge($payload, ['mode'=>$mode]), JSON_UNESCAPED_SLASHES));
        $cmd = sprintf('%s %s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg($this->workerScript), escapeshellarg($this->basePath), escapeshellarg($dataFile));
        $desc = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $env = array_merge(getenv(), $environment);
        $proc = proc_open($cmd, $desc, $pipes, null, $env);
        if (!is_resource($proc)) { @unlink($dataFile); throw new \RuntimeException("spawnWorker failed: {$mode}"); }
        fclose($pipes[0]);
        $this->workers[] = ['process'=>$proc,'pipes'=>$pipes,'data_file'=>$dataFile,'mode'=>$mode];
        return count($this->workers) - 1;
    }

    public function waitForMarker(string $path, int $timeoutS): array
    {
        $deadline = time() + $timeoutS;
        while (time() < $deadline) { if (file_exists($path)) { $raw=file_get_contents($path); @unlink($path); $d=json_decode($raw,true); if (!is_array($d)) throw new \RuntimeException("Marker not valid JSON: {$raw}"); return $d; } usleep(100000); }
        throw new \RuntimeException("Marker timeout after {$timeoutS}s: {$path}");
    }

    public function isWorkerRunning(int $idx): bool { return isset($this->workers[$idx]) && (proc_get_status($this->workers[$idx]['process'])['running'] ?? false); }

    public function releaseWorker(string $path): void { file_put_contents($path, 'release'); }

    private function collect(int $idx, int $timeoutS): array
    {
        $w = $this->workers[$idx]; $out=''; $err=''; $exited=false; $dl=time()+$timeoutS;
        while (time() < $dl) {
            $r=[$w['pipes'][1],$w['pipes'][2]]; @stream_select($r,$nv,$nv,1,0);
            $out.=stream_get_contents($w['pipes'][1]); $err.=stream_get_contents($w['pipes'][2]);
            $s=proc_get_status($w['process']); if(!$s['running']){$out.=stream_get_contents($w['pipes'][1]);$err.=stream_get_contents($w['pipes'][2]);$exited=true;break;}
        }
        return ['exited'=>$exited,'stdout'=>$out,'stderr'=>$err,'timed_out'=>time()>=$dl];
    }

    public function waitForWorker(int $idx, int $timeoutS): array
    {
        if (!isset($this->workers[$idx])) throw new \InvalidArgumentException("Worker {$idx} not found.");
        $w = $this->workers[$idx]; $c = $this->collect($idx, $timeoutS);
        @fclose($w['pipes'][1]); @fclose($w['pipes'][2]);

        if (!$c['exited']) {
            @proc_terminate($w['process'], 9); usleep(100000); @proc_close($w['process']);
            @unlink($w['data_file']); unset($this->workers[$idx]);
            throw new \RuntimeException("Worker {$idx} ({$w['mode']}) timed out after {$timeoutS}s. Stderr: {$c['stderr']}");
        }

        $exit = proc_close($w['process']); @unlink($w['data_file']); unset($this->workers[$idx]);
        $d = json_decode(trim($c['stdout']), true);
        if (!is_array($d) || json_last_error() !== JSON_ERROR_NONE) throw new \RuntimeException("Worker {$idx} ({$w['mode']}) malformed JSON. Exit:{$exit}. Stdout:{$c['stdout']}");

        // Validate required fields
        $required = ['php_pid','mode'];
        foreach ($required as $f) { if (empty($d[$f])) throw new \RuntimeException("Worker {$idx} missing {$f}"); }

        if ($exit !== 0) {
            $msg = "Worker {$idx} ({$w['mode']}) failed exit:{$exit}.";
            foreach (['domain_error','sqlstate','database_message','previous_exception_class'] as $f) { if (!empty($d[$f])) $msg .= " {$f}:{$d[$f]}"; }
            throw new \RuntimeException($msg);
        }

        // Transaction workers must have PG evidence
        $txModes = ['attest','mutate_and_hold','hold_source','attest_and_rollback','attest_other'];
        if (in_array($d['mode'] ?? '', $txModes, true)) {
            foreach (['postgres_backend_pid','postgres_transaction_id','started_at','completed_at'] as $f) { if (empty($d[$f])) throw new \RuntimeException("Worker {$idx} missing {$f}"); }
        }

        return $d;
    }

    public function terminateWorker(int $idx): void
    {
        if (!isset($this->workers[$idx])) return;
        @fclose($this->workers[$idx]['pipes'][1]); @fclose($this->workers[$idx]['pipes'][2]);
        @proc_terminate($this->workers[$idx]['process'], 9); usleep(100000); @proc_close($this->workers[$idx]['process']);
        @unlink($this->workers[$idx]['data_file']); unset($this->workers[$idx]);
    }

    public function cleanup(): void { foreach (array_keys($this->workers) as $i) $this->terminateWorker($i); }
    public function __destruct() { $this->cleanup(); }
}
