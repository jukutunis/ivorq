<?php

/**
 * Package 9 Checkout Execution Concurrency Worker.
 *
 * Bootstrap Laravel, connect to a disposable concurrency database,
 * resolve the real FrontDeskCheckoutExecutionService::execute(), and
 * output JSON result to stdout. Coordination happens via marker files.
 *
 * Usage: php P9CheckoutExecutionConcurrencyWorker.php <fixture.json> <scenario>
 *
 * Scenarios:
 *   lock_hold          — Hold stay row lock, signal a_locked, wait for release_a, then execute & commit.
 *   lock_hold_rollback — Hold stay row lock, signal a_locked, wait for release_a, then rollback.
 *   execute            — Signal b_before_execute, call execute(), output result.
 *   execute_blocked    — Signal b_ready, then call execute() (expected to block behind lock_hold).
 */

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecutionService;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Spatie\Permission\PermissionRegistrar;
use Shared\Services\CurrentPropertyService;

require __DIR__ . '/../../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fixturePath = $argv[1] ?? '';
$scenario    = $argv[2] ?? 'execute';

if (!file_exists($fixturePath)) {
    fwrite(STDERR, "P9_WORKER_FIXTURE_NOT_FOUND: {$fixturePath}\n");
    exit(1);
}

$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);

// ── Database connection ─────────────────────────────────────────────
if (isset($fixture['database'])) {
    config(['database.connections.pgsql.database' => $fixture['database']]);
    config(['database.default' => 'pgsql']);
    DB::setDefaultConnection('pgsql');
    DB::purge('pgsql');
    DB::reconnect('pgsql');
}

app(PermissionRegistrar::class)->forgetCachedPermissions();

$clearResult = static fn (string $fingerprint, string $reservationId, string $propertyId): array => [
    'status' => 'AVAILABLE_CLEAR',
    'code' => null,
    'source_fingerprint' => hash('sha256', $fingerprint . '|' . $propertyId . '|' . $reservationId),
    'source_identifiers' => [],
];

app()->singleton(GuestLedgerPostingCompletenessParticipationPort::class, fn () => new class($clearResult) implements GuestLedgerPostingCompletenessParticipationPort {
    public function __construct(private readonly \Closure $clearResult) {}
    public function participate(string $reservationId, string $propertyId): array { return ($this->clearResult)('p9-posting-completeness', $reservationId, $propertyId); }
});
app()->singleton(GuestLedgerSettlementHoldParticipationPort::class, fn () => new class($clearResult) implements GuestLedgerSettlementHoldParticipationPort {
    public function __construct(private readonly \Closure $clearResult) {}
    public function participate(string $reservationId, string $propertyId): array { return ($this->clearResult)('p9-settlement-hold', $reservationId, $propertyId); }
});
app()->singleton(GuestLedgerCompletedSettlementConflictParticipationPort::class, fn () => new class($clearResult) implements GuestLedgerCompletedSettlementConflictParticipationPort {
    public function __construct(private readonly \Closure $clearResult) {}
    public function participate(string $reservationId, string $propertyId): array { return ($this->clearResult)('p9-completed-settlement', $reservationId, $propertyId); }
});

// ── Actor & session ─────────────────────────────────────────────────
$actor = User::findOrFail($fixture['actor_id']);
Auth::login($actor);
session()->setId($fixture['session_id']);
app(CurrentPropertyService::class)->setPropertyId($fixture['property_id']);

session([
    'active_property_id'  => $fixture['property_id'],
    'current_property_id' => $fixture['property_id'],
    'active_company_id'   => $fixture['company_id'],
    CheckoutSensitiveConfirmationService::SESSION_KEY => [
        CheckoutSensitiveConfirmationService::INTENT => [
            'actor_id'                 => $fixture['actor_id'],
            'intent'                   => CheckoutSensitiveConfirmationService::INTENT,
            'company_id'               => $fixture['company_id'],
            'property_id'              => $fixture['property_id'],
            'front_desk_stay_id'       => $fixture['front_desk_stay_id'],
            'checkout_idempotency_key' => $fixture['checkout_idempotency_key'],
            'issuance_id'              => $fixture['issuance_id'],
            'confirmation_identity'    => $fixture['confirmation_identity'],
            'confirmation_fingerprint' => $fixture['confirmation_fingerprint'],
            'session_fingerprint'      => $fixture['session_fingerprint'],
            'confirmed_at'             => $fixture['confirmed_at'],
            'expires_at'               => $fixture['expires_at'],
        ],
    ],
]);

// Real owner-domain attestations are resolved from the Laravel container.
// ── Marker helpers (atomic write via tmp+rename) ──────────────────
$markerDir = $fixture['marker_dir'];

$writeMarker = function (string $name, array $payload) use ($markerDir): void {
    $path = $markerDir . DIRECTORY_SEPARATOR . $name . '.json';
    $tmp  = $path . '.tmp';
    file_put_contents($tmp, json_encode($payload, JSON_THROW_ON_ERROR));
    rename($tmp, $path);
};

$waitForMarker = function (string $name, int $timeoutMs = 30000) use ($markerDir): void {
    $path     = $markerDir . DIRECTORY_SEPARATOR . $name;
    $deadline = microtime(true) + ($timeoutMs / 1000);
    while (!file_exists($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('P9_WORKER_BARRIER_TIMEOUT_' . $name);
        }
        usleep(50_000);
    }
};

// ── Scenarios ───────────────────────────────────────────────────────
try {
    $backendPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;

    // ── lock_hold ──────────────────────────────────────────────────
    if ($scenario === 'lock_hold') {
        DB::beginTransaction();
        DB::table('front_desk_stays')
            ->where('id', $fixture['front_desk_stay_id'])
            ->lockForUpdate()
            ->first();
        $writeMarker('a_locked', [
            'php_pid'     => getmypid(),
            'backend_pid' => $backendPid,
        ]);
        $waitForMarker('release_a', 60000);

        $result = app(FrontDeskCheckoutExecutionService::class)
            ->execute($actor, $fixture['front_desk_stay_id'], $fixture['checkout_idempotency_key']);
        DB::commit();
        echo json_encode([
            'result'                => 'committed',
            'php_pid'              => getmypid(),
            'backend_pid'          => $backendPid,
            'property_id'           => $fixture['property_id'],
            'front_desk_stay_id'    => $fixture['front_desk_stay_id'],
            'checkout_execution_id' => $result->checkoutExecutionId,
            'replayed'             => $result->replayed,
        ], JSON_THROW_ON_ERROR);
        exit(0);
    }

    // ── lock_hold_rollback ─────────────────────────────────────────
    if ($scenario === 'lock_hold_rollback') {
        DB::beginTransaction();
        DB::table('front_desk_stays')
            ->where('id', $fixture['front_desk_stay_id'])
            ->lockForUpdate()
            ->first();
        $writeMarker('a_locked', [
            'php_pid'     => getmypid(),
            'backend_pid' => $backendPid,
        ]);
        $waitForMarker('release_a', 60000);
        DB::rollBack();
        echo json_encode([
            'result'             => 'rolled_back',
            'php_pid'            => getmypid(),
            'backend_pid'        => $backendPid,
            'property_id'         => $fixture['property_id'],
            'front_desk_stay_id'  => $fixture['front_desk_stay_id'],
        ], JSON_THROW_ON_ERROR);
        exit(0);
    }

    // ── execute ────────────────────────────────────────────────────
    if ($scenario === 'execute') {
        $writeMarker('b_before_execute', [
            'php_pid'     => getmypid(),
            'backend_pid' => $backendPid,
        ]);
        $result = app(FrontDeskCheckoutExecutionService::class)
            ->execute($actor, $fixture['front_desk_stay_id'], $fixture['checkout_idempotency_key']);
        echo json_encode([
            'result'                => 'executed',
            'php_pid'              => getmypid(),
            'backend_pid'          => $backendPid,
            'property_id'           => $fixture['property_id'],
            'front_desk_stay_id'    => $fixture['front_desk_stay_id'],
            'checkout_execution_id' => $result->checkoutExecutionId,
            'replayed'             => $result->replayed,
        ], JSON_THROW_ON_ERROR);
        exit(0);
    }

    // ── execute_blocked (signals ready, then blocks behind lock_hold) ─
    if ($scenario === 'execute_blocked') {
        $writeMarker('b_ready', [
            'php_pid'     => getmypid(),
            'backend_pid' => $backendPid,
        ]);
        $result = app(FrontDeskCheckoutExecutionService::class)
            ->execute($actor, $fixture['front_desk_stay_id'], $fixture['checkout_idempotency_key']);
        echo json_encode([
            'result'                => 'executed',
            'php_pid'              => getmypid(),
            'backend_pid'          => $backendPid,
            'property_id'           => $fixture['property_id'],
            'front_desk_stay_id'    => $fixture['front_desk_stay_id'],
            'checkout_execution_id' => $result->checkoutExecutionId,
            'replayed'             => $result->replayed,
        ], JSON_THROW_ON_ERROR);
        exit(0);
    }

    throw new RuntimeException('P9_UNKNOWN_WORKER_SCENARIO: ' . $scenario);

} catch (DomainException | Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    echo json_encode([
        'result'       => 'domain_error',
        'message'      => $exception->getMessage(),
        'class'        => $exception::class,
        'php_pid'     => getmypid(),
        'backend_pid' => $backendPid ?? null,
        'property_id' => $fixture['property_id'],
        'front_desk_stay_id' => $fixture['front_desk_stay_id'],
    ], JSON_THROW_ON_ERROR);
    exit(0);

} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    echo json_encode([
        'result'       => 'worker_error',
        'class'        => $exception::class,
        'message'      => $exception->getMessage(),
        'php_pid'     => getmypid(),
        'backend_pid' => $backendPid ?? null,
        'property_id' => $fixture['property_id'] ?? null,
        'front_desk_stay_id' => $fixture['front_desk_stay_id'] ?? null,
    ], JSON_THROW_ON_ERROR);
    exit(1);
}
