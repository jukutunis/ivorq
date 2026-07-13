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

if ($resultFile === '' || $barrier === '') {
    fwrite(STDERR, "Missing result file or barrier\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

$phpPid = getmypid();

try {
    require __DIR__ . '/../../../../../vendor/autoload.php';
    $app = require __DIR__ . '/../../../../../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    if ($index === 0) {
        $app->singleton(GeneralCashierCheckoutObligationSnapshotProbe::class, function () use ($barrier) {
            return new class($barrier) implements GeneralCashierCheckoutObligationSnapshotProbe {
                public function __construct(private readonly string $barrier) {}

                public function afterCashSourceRead(string $propertyId, string $frontDeskStayId): void
                {
                    file_put_contents($this->barrier . '-snapshot', getmypid() . '|' . $propertyId . '|' . $frontDeskStayId);
                    $waited = 0;
                    while ($waited < 120) {
                        if (file_exists($this->barrier . '-mutated')) {
                            return;
                        }
                        usleep(50000);
                        $waited++;
                    }
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
    $readyFile = $barrier . '-ready-' . $workerId;
    file_put_contents($readyFile, (string) $phpPid);

    $waited = 0;
    while ($waited < 120) {
        if (count(glob($barrier . '-ready-*')) >= 2) {
            break;
        }
        usleep(50000);
        $waited++;
    }

    $result = [
        'worker_id' => $workerId,
        'php_pid' => $phpPid,
        'pg_backend_pid' => $pgPid,
        'index' => $index,
    ];

    if ($index === 0) {
        $projection = app(GeneralCashierCheckoutObligationProjectionService::class)->project($actor, $stayId);
        $result['status'] = $projection->status->value;
        $result['source_fingerprint'] = $projection->source_fingerprint;
        $result['blocker_codes'] = $projection->blocker_codes;
        $result['evidence_unavailable_codes'] = $projection->evidence_unavailable_codes;
        $result['session_ids'] = $projection->related_cashier_session_ids;
    } else {
        $waited = 0;
        while ($waited < 120) {
            if (file_exists($barrier . '-snapshot')) {
                break;
            }
            usleep(50000);
            $waited++;
        }

        $session = CashierSession::whereKey($cashierSessionId)->firstOrFail();
        $session->forceFill([
            'status' => CashierSessionStatusEnum::CLOSED->value,
            'closed_at' => now(),
            'closed_by' => $actor->id,
        ])->save();

        file_put_contents($barrier . '-mutated', $session->id);
        $result['mutator_executed'] = true;
        $result['cashier_session_status'] = $session->fresh()->status->value;
    }

    file_put_contents($resultFile, json_encode($result));
} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'worker_id' => $workerId,
        'php_pid' => $phpPid,
        'error' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]));
    exit(1);
}
