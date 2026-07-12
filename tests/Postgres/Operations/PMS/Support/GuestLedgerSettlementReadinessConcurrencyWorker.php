<?php

/**
 * GLF-D Concurrency Worker — standalone PHP process.
 *
 * Bootstraps Laravel on a disposable PostgreSQL database, binds CLEAR
 * test ports, and either invokes the production projection service or
 * executes a real production lifecycle mutation (payment allocation,
 * deposit application, refund, AR accept/reverse).
 *
 * Credentials come from proc_open environment variables — never from
 * command-line arguments. Result JSON excludes all secrets.
 */

// Read args from file (args file path is the first argument)
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
$workerId   = (string) ($args['IVORQ_WORKER_ID'] ?? 'unknown');
$scenario   = (string) ($args['IVORQ_SCENARIO'] ?? 'project');
$resultFile = (string) ($args['IVORQ_RESULT_FILE'] ?? '');
$barrier    = (string) ($args['IVORQ_BARRIER'] ?? '');
$index      = (int) ($args['IVORQ_WORKER_INDEX'] ?? 0);

if ($resultFile === '' || $barrier === '') { fwrite(STDERR, "Missing result_file or barrier\n"); exit(1); }

// DB credentials from environment (proc_open env) — NOT from command args.
// The coordinator sets DB_DATABASE to the disposable DB name in the process env.
// Host/port/username/password are inherited from the parent process.
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

$phpPid = getmypid();

try {
    // Load autoloader before bootstrapping Laravel
    require __DIR__ . '/../../../../../vendor/autoload.php';
    $app = require __DIR__ . '/../../../../../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    // Bind CLEAR test ports
    $app->singleton(Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort::class, function () {
        return new class implements Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessReadPort {
            public function evaluate(string $rid, string $pid): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
            }
        };
    });
    $app->singleton(Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort::class, function () {
        return new class implements Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldReadPort {
            public function evaluate(string $rid, string $pid): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
            }
        };
    });
    $app->singleton(Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort::class, function () {
        return new class implements Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictReadPort {
            public function evaluate(string $rid, string $pid): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'message' => null];
            }
        };
    });

    // Test double: bypass sensitive-action confirmation in worker processes.
    // Production services (refund, AR accept/reverse) require confirmation;
    // the worker process has no interactive session, so we bind a pass-through.
    $app->singleton(Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService::class, function () use ($app) {
        $auditSvc = $app->make(Modules\Foundation\Audit\Services\AuditService::class);
        return new class($auditSvc) extends Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService {
            public function __construct($auditService) { parent::__construct($auditService); }
            public function requireValidConfirmation($actor, string $intent, $companyId, string $propertyId): void {
                // Pass-through: worker process confirmation is pre-authorized
            }
        };
    });

    $pgPid = DB::select('SELECT pg_backend_pid() as pid')[0]->pid;

    // Resolve IDs from args (non-secret identifiers only — credentials come from proc_open env)
    $stayId         = (string) ($args['IVORQ_STAY_ID'] ?? '');
    $propId         = (string) ($args['IVORQ_PROPERTY_ID'] ?? '');
    $actorId        = (string) ($args['IVORQ_ACTOR_ID'] ?? '');
    $mutatorCmd     = (string) ($args['IVORQ_MUTATOR'] ?? '');
    $paymentId      = (string) ($args['IVORQ_PAYMENT_ID'] ?? '');
    $folioId        = (string) ($args['IVORQ_FOLIO_ID'] ?? '');
    $depositId      = (string) ($args['IVORQ_DEPOSIT_ID'] ?? '');
    $arRequestId    = (string) ($args['IVORQ_AR_REQUEST_ID'] ?? '');
    $cashierSessionId = (string) ($args['IVORQ_CASHIER_SESSION_ID'] ?? '');
    $refundSourceType = (string) ($args['IVORQ_REFUND_SOURCE_TYPE'] ?? 'GUEST_PAYMENT');

    // Authenticate and set property context
    $actor = Modules\Foundation\User\Models\User::whereKey($actorId)->where('is_active', true)->first();
    if ($actor) {
        auth()->login($actor);
        app(Shared\Services\CurrentPropertyService::class)->setPropertyId($propId);
    }

    // ── Barrier synchronisation ─────────────────────────────────────────
    $readyFile = $barrier . '-' . $workerId;
    file_put_contents($readyFile, (string) $phpPid);
    $maxWait = 60; $waited = 0;
    while ($waited < $maxWait) {
        $readyCount = count(glob($barrier . '-*'));
        if ($readyCount >= 2) break;
        usleep(300000); $waited += 0.3;
    }

    $result = [
        'worker_id' => $workerId, 'php_pid' => $phpPid, 'pg_backend_pid' => $pgPid,
        'scenario' => $scenario, 'index' => $index,
    ];

    if ($index === 0 || $mutatorCmd === '') {
        // ── Projection worker ───────────────────────────────────────────
        $service = $app->make(
            Modules\Operations\PMS\Services\GuestLedgerCheckoutSettlementReadinessProjectionService::class
        );
        if ($stayId && $actor) {
            $proj = $service->project($actor, $stayId);
            $result['status'] = $proj->status->value;
            $result['source_fingerprint'] = $proj->source_fingerprint;
            $result['canonical_balance'] = $proj->canonical_aggregate_balance;
            $result['property_id'] = $proj->property_id;
            $result['markers'] = $proj->markers;
            $result['folio_count'] = $proj->folio_count;
            $result['blocker_codes'] = $proj->blocker_codes;
            $result['folio_count_after'] = DB::table('folios')->count();
            $result['folio_item_count_after'] = DB::table('folio_items')->count();
        } else {
            $result['error'] = 'Missing stay/actor IDs for projection';
        }
    } elseif ($index === 1 && $mutatorCmd !== '') {
        // ── Mutator worker — real production lifecycle service ──────────
        // Small delay to let projection worker establish its snapshot
        usleep(250000);
        $mutationResult = null;

        switch ($mutatorCmd) {
            case 'allocate':
                if ($paymentId && $folioId && $actor) {
                    $svc = $app->make(Modules\Operations\PMS\Services\GuestPaymentLifecycleService::class);
                    $alloc = $svc->allocatePayment($actor, $paymentId, $folioId, '100.00',
                        'glf-d-conc-alloc-' . uniqid());
                    $mutationResult = ['type' => 'allocation', 'id' => $alloc->id, 'amount' => (string) $alloc->amount];
                }
                break;

            case 'apply_deposit':
                if ($depositId && $folioId && $actor) {
                    $svc = $app->make(Modules\Operations\PMS\Services\GuestDepositLifecycleService::class);
                    $app = $svc->applyDeposit($actor, $depositId, $folioId, '200.00',
                        'glf-d-conc-dep-' . uniqid());
                    $mutationResult = ['type' => 'deposit_application', 'id' => $app->id];
                }
                break;

            case 'refund':
                if ($paymentId && $actor && $cashierSessionId) {
                    $svc = $app->make(Modules\Operations\PMS\Services\GuestRefundLifecycleService::class);
                    $sourceId = $refundSourceType === 'GUEST_DEPOSIT' ? $depositId : $paymentId;
                    $ref = $svc->recordCashRefund($actor, $refundSourceType, $sourceId,
                        $cashierSessionId, '50.00', 'CONC_TEST', 'glf-d-conc-ref-' . uniqid());
                    $mutationResult = ['type' => 'refund', 'id' => $ref->id, 'amount' => (string) $ref->amount];
                }
                break;

            case 'accept_ar':
                if ($arRequestId && $actor) {
                    $svc = $app->make(Modules\Finance\AccountsReceivable\Services\GuestArTransferDecisionService::class);
                    $dec = $svc->acceptTransfer($actor, $arRequestId, 'CONC_TEST',
                        'glf-d-conc-ar-' . uniqid());
                    $mutationResult = ['type' => 'ar_accept', 'id' => $dec->id];
                }
                break;

            case 'reverse_ar':
                if ($arRequestId && $actor) {
                    $svc = $app->make(Modules\Finance\AccountsReceivable\Services\GuestArTransferDecisionService::class);
                    $dec = $svc->reverseAcceptedTransfer($actor, $arRequestId, 'CONC_TEST',
                        'glf-d-conc-ar-rev-' . uniqid());
                    $mutationResult = ['type' => 'ar_reverse', 'id' => $dec->id];
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
    }

    file_put_contents($resultFile, json_encode($result));

} catch (Throwable $e) {
    file_put_contents($resultFile, json_encode([
        'worker_id' => $workerId, 'php_pid' => $phpPid,
        'error' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine(),
    ]));
    exit(1);
}
