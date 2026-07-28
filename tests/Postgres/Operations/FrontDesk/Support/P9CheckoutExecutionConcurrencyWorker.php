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
use Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutTerminalObligationAttestationStatusEnum;
use Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService;
use Modules\Operations\GeneralCashier\ValueObjects\GeneralCashierCheckoutTerminalObligationAttestation;
use Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService;
use Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation;
use Modules\Operations\PMS\Enums\GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation;
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

// ── Mock attestations ───────────────────────────────────────────────
$nightAuditActive = $fixture['night_audit_active'] ?? false;

app()->bind(NightAuditCheckoutConcurrencyGuardService::class, function () use ($fixture, $nightAuditActive) {
    $mock = Mockery::mock(NightAuditCheckoutConcurrencyGuardService::class);
    $status = $nightAuditActive
        ? NightAuditCheckoutConcurrencyAttestation::STATUS_ACTIVE
        : NightAuditCheckoutConcurrencyAttestation::STATUS_CLEAR;

    $closeLock = $nightAuditActive;

    $mock->shouldReceive('attest')->andReturn(new NightAuditCheckoutConcurrencyAttestation(
        NightAuditCheckoutConcurrencyAttestation::VERSION,
        $status,
        NightAuditCheckoutConcurrencyAttestation::OWNER,
        !$closeLock,
        $closeLock,
        $fixture['property_id'],
        'date-id',
        '2099-01-01',
        'UTC',
        hash('sha256', 'na-' . ($nightAuditActive ? 'active' : 'clear')),
        now()->toISOString(),
        []
    ));
    return $mock;
});

app()->bind(GuestLedgerCheckoutTerminalFinancialAttestationService::class, function () use ($fixture) {
    $mock = Mockery::mock(GuestLedgerCheckoutTerminalFinancialAttestationService::class);
    $mock->shouldReceive('attest')->andReturn(GuestLedgerCheckoutTerminalFinancialAttestation::create(
        GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialReady,
        $fixture['property_id'],
        'date-id',
        '2099-01-01',
        $fixture['front_desk_stay_id'],
        'reservation-id',
        0, '0.00', 'USD',
        [], [], [], [], [],
        hash('sha256', 'fin'),
        now()->toISOString(),
        []
    ));
    $mock->shouldReceive('assertIssuedForCurrentTransaction');
    return $mock;
});

app()->bind(GeneralCashierCheckoutTerminalObligationAttestationService::class, function () use ($fixture) {
    $mock = Mockery::mock(GeneralCashierCheckoutTerminalObligationAttestationService::class);
    $mock->shouldReceive('attest')->andReturn(GeneralCashierCheckoutTerminalObligationAttestation::create(
        GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationClear,
        $fixture['property_id'],
        'date-id',
        '2099-01-01',
        $fixture['front_desk_stay_id'],
        'reservation-id',
        GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialReady->value,
        hash('sha256', 'fin'),
        [], 0, [], [], [],
        hash('sha256', 'cash'),
        now()->toISOString(),
        []
    ));
    $mock->shouldReceive('assertIssuedForCurrentTransaction');
    return $mock;
});

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
            'result'       => 'rolled_back',
            'php_pid'     => getmypid(),
            'backend_pid' => $backendPid,
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
    ], JSON_THROW_ON_ERROR);
    exit(1);
}
