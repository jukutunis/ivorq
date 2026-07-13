<?php

use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutObligationProjectionService;
use Modules\Operations\GeneralCashier\Services\Ports\GeneralCashierCheckoutObligationSnapshotProbe;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

$argsFile = $argv[1] ?? '';
if ($argsFile === '' || ! file_exists($argsFile)) {
    fwrite(STDERR, "Missing args file\n");
    exit(1);
}

$args = json_decode(file_get_contents($argsFile), true);
if (! is_array($args)) {
    fwrite(STDERR, "Invalid args JSON\n");
    exit(1);
}

$resultFile = (string) ($args['result_file'] ?? '');
$barrier = (string) ($args['barrier'] ?? '');
$workerId = (string) ($args['worker_id'] ?? 'worker');
$index = (int) ($args['index'] ?? 0);
$propertyId = (string) ($args['property_id'] ?? '');
$companyId = (string) ($args['company_id'] ?? '');
$stayId = (string) ($args['stay_id'] ?? '');
$actorId = (string) ($args['actor_id'] ?? '');
$cashierSessionId = (string) ($args['cashier_session_id'] ?? '');
$runId = (string) ($args['run_id'] ?? '');

if ($resultFile === '' || $barrier === '' || $runId === '') {
    fwrite(STDERR, "Missing result file or barrier\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

$phpPid = getmypid();
$handshake = [];

function gcA1Signal(string $barrier, string $name, string $runId, array $payload = []): void
{
    $payload = $payload + [
        'run_id' => $runId,
        'name' => $name,
        'pid' => getmypid(),
        'at' => microtime(true),
    ];

    file_put_contents($barrier . '-' . $name . '.json', json_encode($payload));
}

function gcA1WaitFor(string $barrier, string $name, string $runId, int $timeoutMs = 6000): array
{
    $path = $barrier . '-' . $name . '.json';
    $deadline = microtime(true) + ($timeoutMs / 1000);

    while (microtime(true) < $deadline) {
        if (is_file($path)) {
            $payload = json_decode((string) file_get_contents($path), true);
            if (is_array($payload) && ($payload['run_id'] ?? null) === $runId) {
                return $payload;
            }
        }
        usleep(50000);
    }

    throw new RuntimeException("GC_A1_BARRIER_TIMEOUT:{$name}");
}

try {
    require __DIR__ . '/../../../../../vendor/autoload.php';
    $app = require __DIR__ . '/../../../../../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    if ($index === 0) {
        $app->singleton(GeneralCashierCheckoutObligationSnapshotProbe::class, function () use ($barrier, $runId, &$handshake) {
            return new class($barrier, $runId, $handshake) implements GeneralCashierCheckoutObligationSnapshotProbe {
                private array $handshake;

                public function __construct(
                    private readonly string $barrier,
                    private readonly string $runId,
                    array &$handshake
                ) {
                    $this->handshake =& $handshake;
                }

                public function afterCashSourceRead(string $propertyId, string $frontDeskStayId): void
                {
                    $settings = \Illuminate\Support\Facades\DB::selectOne(
                        "select current_setting('transaction_isolation') as isolation, current_setting('transaction_read_only') as read_only"
                    );
                    $this->handshake['projection_transaction_entered'] = true;
                    $this->handshake['projection_transaction_isolation'] = $settings->isolation;
                    $this->handshake['projection_transaction_read_only'] = $settings->read_only;
                    $this->handshake['projection_first_source_read_completed'] = true;
                    gcA1Signal($this->barrier, 'first-source-read-completed', $this->runId, [
                        'property_id' => $propertyId,
                        'front_desk_stay_id' => $frontDeskStayId,
                        'isolation' => $settings->isolation,
                        'read_only' => $settings->read_only,
                    ]);
                    gcA1WaitFor($this->barrier, 'mutation-committed', $this->runId);
                    $this->handshake['projection_observed_mutation_committed_barrier'] = true;
                }
            };
        });
    }

    $actor = User::whereKey($actorId)->where('is_active', true)->first();
    if (! $actor) {
        throw new RuntimeException('Actor unavailable.');
    }

    auth()->login($actor);
    app(CurrentPropertyService::class)->setPropertyId($propertyId);
    session([
        'active_property_id' => $propertyId,
        'current_property_id' => $propertyId,
        'active_company_id' => $companyId,
    ]);

    $pgPid = DB::select('SELECT pg_backend_pid() as pid')[0]->pid;
    gcA1Signal($barrier, 'ready-' . $workerId, $runId, [
        'worker_id' => $workerId,
        'php_pid' => $phpPid,
        'pg_backend_pid' => $pgPid,
    ]);

    gcA1WaitFor($barrier, 'ready-w0', $runId);
    gcA1WaitFor($barrier, 'ready-w1', $runId);
    $handshake['both_workers_started'] = true;

    $result = [
        'worker_id' => $workerId,
        'php_pid' => $phpPid,
        'pg_backend_pid' => $pgPid,
        'index' => $index,
        'run_id' => $runId,
    ];

    if ($index === 0) {
        $projection = app(GeneralCashierCheckoutObligationProjectionService::class)->project($actor, $stayId);
        $result['status'] = $projection->status->value;
        $result['source_fingerprint'] = $projection->source_fingerprint;
        $result['blocker_codes'] = $projection->blocker_codes;
        $result['evidence_unavailable_codes'] = $projection->evidence_unavailable_codes;
        $result['session_ids'] = $projection->related_cashier_session_ids;
        $result['handshake'] = $handshake;
    } else {
        gcA1WaitFor($barrier, 'first-source-read-completed', $runId);
        $handshake['mutator_observed_first_source_read_completed'] = true;

        DB::transaction(function () use ($cashierSessionId, $actor): void {
            $session = CashierSession::whereKey($cashierSessionId)->lockForUpdate()->firstOrFail();
            $session->forceFill([
                'status' => CashierSessionStatusEnum::CLOSED->value,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();
        });

        $session = CashierSession::whereKey($cashierSessionId)->firstOrFail();
        gcA1Signal($barrier, 'mutation-committed', $runId, [
            'cashier_session_id' => $session->id,
            'cashier_session_status' => $session->status->value,
        ]);
        $handshake['mutation_committed'] = true;
        $result['mutator_executed'] = true;
        $result['cashier_session_status'] = $session->fresh()->status->value;
        $result['handshake'] = $handshake;
    }

    file_put_contents($resultFile, json_encode($result));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'worker_id' => $workerId,
        'php_pid' => $phpPid,
        'run_id' => $runId,
        'handshake' => $handshake,
        'error' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]));
    exit(1);
}
