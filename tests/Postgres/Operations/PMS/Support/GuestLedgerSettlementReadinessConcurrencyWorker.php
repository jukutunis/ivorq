<?php

/**
 * GLF-D Concurrency Worker - standalone PHP process.
 *
 * Credentials come from proc_open environment variables, never from command
 * arguments. Result JSON is scoped to this worker and excludes secrets.
 */

$argsFile = $argv[1] ?? '';
if ($argsFile === '' || ! file_exists($argsFile)) {
    fwrite(STDERR, "Missing or unreadable args file\n");
    exit(1);
}

$args = json_decode(file_get_contents($argsFile), true);
if (! is_array($args)) {
    fwrite(STDERR, "Invalid args JSON in {$argsFile}\n");
    exit(1);
}

$workerId = (string) ($args['IVORQ_WORKER_ID'] ?? 'unknown');
$scenario = (string) ($args['IVORQ_SCENARIO'] ?? 'project');
$resultFile = (string) ($args['IVORQ_RESULT_FILE'] ?? '');
$barrier = (string) ($args['IVORQ_BARRIER'] ?? '');
$index = (int) ($args['IVORQ_WORKER_INDEX'] ?? 0);
$expectedWorkers = max(1, (int) ($args['IVORQ_EXPECTED_WORKERS'] ?? 2));
$hasMutatorPeer = (string) ($args['IVORQ_HAS_MUTATOR_PEER'] ?? '0') === '1';

if ($resultFile === '' || $barrier === '') {
    fwrite(STDERR, "Missing result_file or barrier\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

$phpPid = getmypid();
$pgPid = null;
$barrierPhase = 'bootstrap';
$mutationStarted = false;
$mutationCommitted = false;

$barrierFiles = function () use ($barrier): array {
    $files = glob($barrier . '*') ?: [];
    sort($files);

    return array_map('basename', $files);
};

$writeResult = function (array $payload) use (
    $resultFile,
    $workerId,
    $phpPid,
    &$pgPid,
    $scenario,
    $index,
    &$barrierPhase,
    &$mutationStarted,
    &$mutationCommitted,
    $barrierFiles
): void {
    $payload += [
        'worker_id' => $workerId,
        'php_pid' => $phpPid,
        'pg_backend_pid' => $pgPid,
        'scenario' => $scenario,
        'index' => $index,
        'barrier_phase' => $barrierPhase,
        'observed_barrier_files' => $barrierFiles(),
        'mutation_started' => $mutationStarted,
        'mutation_committed' => $mutationCommitted,
    ];

    file_put_contents($resultFile, json_encode($payload));
};

$waitForBarrier = function (
    callable $predicate,
    string $phase,
    float $seconds,
    ?callable $diagnostics = null
) use (&$barrierPhase, $writeResult): void {
    $barrierPhase = $phase;
    $started = microtime(true);

    while ((microtime(true) - $started) < $seconds) {
        if ($predicate()) {
            return;
        }
        usleep(50000);
    }

    $writeResult(($diagnostics ? $diagnostics() : []) + [
        'error' => 'GLF_D_BARRIER_TIMEOUT:' . $phase,
    ]);

    throw new RuntimeException('GLF_D_BARRIER_TIMEOUT:' . $phase);
};

try {
    require __DIR__ . '/../../../../../vendor/autoload.php';
    $app = require __DIR__ . '/../../../../../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $app->singleton(Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort::class, function () {
        return new class implements Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort {
            public function evaluate(string $rid, string $pid): array
            {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
            }
        };
    });

    $app->singleton(Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort::class, function () {
        return new class implements Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort {
            public function evaluate(string $rid, string $pid): array
            {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
            }
        };
    });

    $requiresMutationBarrier = $index === 0 && $hasMutatorPeer;
    if ($requiresMutationBarrier) {
        $app->singleton(Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort::class, function () use ($barrier, $waitForBarrier) {
            return new class($barrier, $waitForBarrier) implements Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort {
                private string $barrier;
                private $waitForBarrier;

                public function __construct(string $barrier, callable $waitForBarrier)
                {
                    $this->barrier = $barrier;
                    $this->waitForBarrier = $waitForBarrier;
                }

                public function evaluate(string $rid, string $pid): array
                {
                    file_put_contents($this->barrier . '-snapshot', (string) getmypid());
                    ($this->waitForBarrier)(
                        fn (): bool => file_exists($this->barrier . '-mutated'),
                        'mutation-committed',
                        30.0
                    );

                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });
    } else {
        $app->singleton(Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort::class, function () {
            return new class implements Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort {
                public function evaluate(string $rid, string $pid): array
                {
                    return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
                }
            };
        });
    }

    $app->singleton(Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService::class, function () use ($app) {
        $auditSvc = $app->make(Modules\Foundation\Audit\Services\AuditService::class);

        return new class($auditSvc) extends Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService {
            public function __construct($auditService)
            {
                parent::__construct($auditService);
            }

            public function requireValidConfirmation($actor, string $intent, $companyId, string $propertyId): void
            {
                // Worker process confirmation is pre-authorized by the test fixture.
            }
        };
    });

    $pgPid = \Illuminate\Support\Facades\DB::select('SELECT pg_backend_pid() as pid')[0]->pid;

    $stayId = (string) ($args['IVORQ_STAY_ID'] ?? '');
    $propId = (string) ($args['IVORQ_PROPERTY_ID'] ?? '');
    $actorId = (string) ($args['IVORQ_ACTOR_ID'] ?? '');
    $mutatorCmd = (string) ($args['IVORQ_MUTATOR'] ?? '');
    $paymentId = (string) ($args['IVORQ_PAYMENT_ID'] ?? '');
    $folioId = (string) ($args['IVORQ_FOLIO_ID'] ?? '');
    $depositId = (string) ($args['IVORQ_DEPOSIT_ID'] ?? '');
    $arRequestId = (string) ($args['IVORQ_AR_REQUEST_ID'] ?? '');
    $cashierSessionId = (string) ($args['IVORQ_CASHIER_SESSION_ID'] ?? '');
    $refundSourceType = (string) ($args['IVORQ_REFUND_SOURCE_TYPE'] ?? 'GUEST_PAYMENT');

    $actor = Modules\Foundation\User\Models\User::whereKey($actorId)
        ->where('is_active', true)
        ->first();

    if ($actor) {
        auth()->login($actor);
        app(Shared\Services\CurrentPropertyService::class)->setPropertyId($propId);
    }

    $readyFile = $barrier . '-ready-' . $workerId;
    file_put_contents($readyFile, (string) $phpPid);
    $waitForBarrier(
        fn (): bool => count(glob($barrier . '-ready-*') ?: []) >= $expectedWorkers,
        'workers-ready',
        15.0,
        fn (): array => [
            'expected_workers' => $expectedWorkers,
            'observed_workers' => count(glob($barrier . '-ready-*') ?: []),
        ]
    );

    $result = [
        'worker_id' => $workerId,
        'php_pid' => $phpPid,
        'pg_backend_pid' => $pgPid,
        'scenario' => $scenario,
        'index' => $index,
    ];

    $isMutator = $index === 1 && $mutatorCmd !== '';

    if (! $isMutator) {
        $barrierPhase = 'projection';
        $service = $app->make(Modules\Operations\PMS\Services\GuestLedgerCheckoutSettlementReadinessProjectionService::class);

        if ($stayId && $actor) {
            $proj = $service->project($actor, $stayId);
            $result['status'] = $proj->status->value;
            $result['source_fingerprint'] = $proj->source_fingerprint;
            $result['canonical_balance'] = $proj->canonical_aggregate_balance;
            $result['property_id'] = $proj->property_id;
            $result['markers'] = $proj->markers;
            $result['folio_count'] = $proj->folio_count;
            $result['blocker_codes'] = $proj->blocker_codes;
            $result['review_reasons'] = $proj->review_reasons;
            $result['evidence_unavailable_codes'] = $proj->evidence_unavailable_codes;
            $result['folio_ids'] = $proj->folio_ids;
            $result['zero_write'] = [
                'folios' => \Illuminate\Support\Facades\DB::table('folios')->count(),
                'folio_items' => \Illuminate\Support\Facades\DB::table('folio_items')->count(),
                'guest_payment_transactions' => \Illuminate\Support\Facades\DB::table('guest_payment_transactions')->count(),
                'guest_payment_allocations' => \Illuminate\Support\Facades\DB::table('guest_payment_allocations')->count(),
                'guest_payment_reversals' => \Illuminate\Support\Facades\DB::table('guest_payment_reversals')->count(),
                'guest_deposit_transactions' => \Illuminate\Support\Facades\DB::table('guest_deposit_transactions')->count(),
                'guest_deposit_applications' => \Illuminate\Support\Facades\DB::table('guest_deposit_applications')->count(),
                'guest_deposit_reversals' => \Illuminate\Support\Facades\DB::table('guest_deposit_reversals')->count(),
                'guest_refund_transactions' => \Illuminate\Support\Facades\DB::table('guest_refund_transactions')->count(),
                'guest_ar_transfer_requests' => \Illuminate\Support\Facades\DB::table('guest_ar_transfer_requests')->count(),
                'guest_ar_transfer_decisions' => \Illuminate\Support\Facades\DB::table('guest_ar_transfer_decisions')->count(),
                'front_desk_stays' => \Illuminate\Support\Facades\DB::table('front_desk_stays')->count(),
            ];
        } else {
            $result['error'] = 'Missing stay/actor IDs for projection';
        }
    } else {
        $waitForBarrier(
            fn (): bool => file_exists($barrier . '-snapshot'),
            'projection-snapshot',
            30.0
        );

        $mutationResult = null;

        switch ($mutatorCmd) {
            case 'allocate':
                if ($paymentId && $folioId && $actor) {
                    $mutationStarted = true;
                    $svc = $app->make(Modules\Operations\PMS\Services\GuestPaymentLifecycleService::class);
                    $alloc = $svc->allocatePayment($actor, $paymentId, $folioId, '100.00', 'glf-d-conc-alloc-' . uniqid());
                    $mutationCommitted = true;
                    $mutationResult = ['type' => 'allocation', 'id' => $alloc->id, 'amount' => (string) $alloc->amount];
                }
                break;

            case 'apply_deposit':
                if ($depositId && $folioId && $actor) {
                    $mutationStarted = true;
                    $svc = $app->make(Modules\Operations\PMS\Services\GuestDepositLifecycleService::class);
                    $application = $svc->applyDeposit($actor, $depositId, $folioId, '200.00', 'glf-d-conc-dep-' . uniqid());
                    $mutationCommitted = true;
                    $mutationResult = ['type' => 'deposit_application', 'id' => $application->id];
                }
                break;

            case 'refund':
                if ($paymentId && $actor && $cashierSessionId) {
                    $mutationStarted = true;
                    $svc = $app->make(Modules\Operations\PMS\Services\GuestRefundLifecycleService::class);
                    $sourceId = $refundSourceType === 'GUEST_DEPOSIT' ? $depositId : $paymentId;
                    $refund = $svc->recordCashRefund($actor, $refundSourceType, $sourceId, $cashierSessionId, '50.00', 'CONC_TEST', 'glf-d-conc-ref-' . uniqid());
                    $mutationCommitted = true;
                    $mutationResult = ['type' => 'refund', 'id' => $refund->id, 'amount' => (string) $refund->amount];
                }
                break;

            case 'accept_ar':
                if ($arRequestId && $actor) {
                    $mutationStarted = true;
                    $svc = $app->make(Modules\Finance\AccountsReceivable\Services\GuestArTransferDecisionService::class);
                    $decision = $svc->acceptTransfer($actor, $arRequestId, 'CONC_TEST', 'glf-d-conc-ar-' . uniqid());
                    $mutationCommitted = true;
                    $mutationResult = ['type' => 'ar_accept', 'id' => $decision->id];
                }
                break;

            case 'reverse_ar':
                if ($arRequestId && $actor) {
                    $mutationStarted = true;
                    $svc = $app->make(Modules\Finance\AccountsReceivable\Services\GuestArTransferDecisionService::class);
                    $decision = $svc->reverseAcceptedTransfer($actor, $arRequestId, 'CONC_TEST', 'glf-d-conc-ar-rev-' . uniqid());
                    $mutationCommitted = true;
                    $mutationResult = ['type' => 'ar_reverse', 'id' => $decision->id];
                }
                break;

            default:
                $mutationResult = ['error' => 'Unknown mutator: ' . $mutatorCmd];
        }

        if ($mutationResult && ! isset($mutationResult['error'])) {
            $result['mutator_executed'] = true;
            $result['mutation'] = $mutationResult;
        } else {
            $result['mutator_executed'] = false;
            $result['mutation_error'] = $mutationResult['error'] ?? 'Mutation failed';
        }

        file_put_contents($barrier . '-mutated', '1');
    }

    $writeResult($result);
} catch (Throwable $e) {
    $writeResult([
        'error' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    exit(1);
}
