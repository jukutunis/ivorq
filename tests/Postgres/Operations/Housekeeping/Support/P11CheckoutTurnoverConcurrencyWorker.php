<?php

require_once __DIR__ . '/../../../../../vendor/autoload.php';

$basePath = $argv[1] ?? '';
$payloadFile = $argv[2] ?? '';

if ($basePath === '' || $payloadFile === '' || ! is_file($payloadFile)) {
    fwrite(STDERR, "Usage: php P11CheckoutTurnoverConcurrencyWorker.php <basePath> <payloadFile>\n");
    exit(2);
}

$app = require $basePath . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecutionService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutHousekeepingHandoffDeliveryService;
use Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService;
use Shared\Services\CurrentPropertyService;

$payload = json_decode((string) file_get_contents($payloadFile), true);
if (! is_array($payload)) {
    fwrite(STDERR, "Invalid payload JSON\n");
    exit(2);
}

$mode = (string) ($payload['mode'] ?? '');
$phpPid = getmypid();
$startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
$pgBackendPid = null;

$safeNow = static fn (): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');

$emit = static function (array $data, int $exitCode = 0): never {
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    exit($exitCode);
};

$tokenPayload = static function (string $handoffId, string $claimToken): array {
    return [
        'handoff_id' => $handoffId,
        'claim_token' => $claimToken,
    ];
};

$writeSecret = static function (array $payload, array $claim) use ($tokenPayload): void {
    $tokenPath = (string) ($payload['token_path'] ?? '');
    if ($tokenPath === '') {
        return;
    }

    file_put_contents(
        $tokenPath,
        json_encode($tokenPayload((string) $claim['handoff_id'], (string) $claim['claim_token']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
    );
    @chmod($tokenPath, 0600);
};

$readSecret = static function (array $payload): array {
    $tokenPath = (string) ($payload['token_path'] ?? '');
    if ($tokenPath === '' || ! is_file($tokenPath)) {
        throw new RuntimeException('P11_WORKER_SECRET_MISSING');
    }

    $secret = json_decode((string) file_get_contents($tokenPath), true);
    if (! is_array($secret) || ! isset($secret['handoff_id'], $secret['claim_token'])) {
        throw new RuntimeException('P11_WORKER_SECRET_INVALID');
    }

    return [
        'handoff_id' => (string) $secret['handoff_id'],
        'claim_token' => (string) $secret['claim_token'],
    ];
};

$safeClaim = static function (?array $claim): array {
    if ($claim === null) {
        return ['outcome' => 'no_available'];
    }

    return [
        'outcome' => 'claimed',
        'handoff_id' => (string) $claim['handoff_id'],
        'delivery_status' => 'CLAIMED',
        'attempts' => (int) $claim['attempts'],
    ];
};

$safeHandoff = static function (string $handoffId): array {
    $row = DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $handoffId)->first();

    return [
        'handoff_id' => $handoffId,
        'delivery_status' => (string) ($row->delivery_status ?? ''),
        'attempts' => (int) ($row->attempts ?? 0),
        'last_error_code' => $row->last_error_code ?? null,
    ];
};

$waitForReleasePath = static function (string $releasePath): void {
    if ($releasePath === '') {
        return;
    }

    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline) {
        if (is_file($releasePath)) {
            return;
        }
        usleep(50_000);
    }

    throw new RuntimeException('P11_WORKER_RELEASE_TIMEOUT');
};

try {
    $pgBackendPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $propertyId = (string) ($payload['property_id'] ?? '');
    if ($propertyId !== '') {
        app(CurrentPropertyService::class)->setPropertyId($propertyId);
    }

    if ((bool) ($payload['guard_checkout_execution'] ?? false)) {
        $app->bind(FrontDeskCheckoutExecutionService::class, static fn () => new class
        {
            public function execute(...$args): never
            {
                throw new RuntimeException('P11_CHECKOUT_EXECUTION_RERUN_GUARD');
            }
        });
    }

    $delivery = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);
    $intake = app(HousekeepingCheckoutTurnoverIntakeService::class);
    $leaseSeconds = (int) ($payload['lease_seconds'] ?? 60);
    $handoffId = (string) ($payload['handoff_id'] ?? '');
    $claimToken = (string) ($payload['claim_token'] ?? '');

    $insideReadyMarker = (string) ($payload['inside_tx_ready_marker'] ?? '');
    if ($insideReadyMarker !== '') {
        $insideReleasePath = (string) ($payload['inside_tx_release_path'] ?? '');
        $intake->setInsideTransactionTestingHookForTesting(static function (array $evidence) use ($insideReadyMarker, $insideReleasePath, $phpPid, $waitForReleasePath, $safeNow): void {
            $lockRows = DB::select(
                "
                SELECT locktype, mode, granted
                FROM pg_locks
                WHERE pid = pg_backend_pid()
                  AND granted
                  AND locktype IN ('relation', 'transactionid', 'virtualxid')
                ORDER BY locktype, mode
                "
            );
            file_put_contents($insideReadyMarker, json_encode([
                'marker' => 'P11_INSIDE_TRANSACTION_LOCKS_HELD',
                'php_pid' => $phpPid,
                'postgres_backend_pid' => (int) $evidence['postgres_backend_pid'],
                'property_id' => (string) $evidence['property_id'],
                'handoff_id' => (string) $evidence['handoff_id'],
                'room_id' => (string) $evidence['room_id'],
                'transaction_level' => DB::transactionLevel(),
                'xact_start_present' => DB::selectOne('SELECT xact_start IS NOT NULL AS active FROM pg_stat_activity WHERE pid = pg_backend_pid()')->active,
                'granted_lock_count' => count($lockRows),
                'ready_at' => $safeNow(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $waitForReleasePath($insideReleasePath);
        });
    }

    $postCommitExitCode = (int) ($payload['post_commit_exit_code'] ?? 0);
    if ($postCommitExitCode > 0) {
        $intake->setPostCommitTestingHookForTesting(static function (string $committedHandoffId) use ($emit, $phpPid, $pgBackendPid, $mode, $startedAt, $safeNow, $postCommitExitCode): never {
            $emit([
                'php_pid' => $phpPid,
                'postgres_backend_pid' => $pgBackendPid,
                'mode' => $mode,
                'started_at' => $startedAt,
                'completed_at' => $safeNow(),
                'outcome' => 'post_commit_terminated',
                'marker' => 'P11_WORKER_POST_COMMIT_TERMINATED',
                'handoff_id' => $committedHandoffId,
            ], $postCommitExitCode);
        });
    }

    $common = [
        'php_pid' => $phpPid,
        'postgres_backend_pid' => $pgBackendPid,
        'mode' => $mode,
        'started_at' => $startedAt,
    ];

    switch ($mode) {
        case 'claim_next':
            $claim = $delivery->claimNextAvailable($propertyId, $leaseSeconds);
            if ($claim !== null) {
                $writeSecret($payload, $claim);
            }
            $emit($common + ['completed_at' => $safeNow()] + $safeClaim($claim));

        case 'claim_available':
            $claim = $delivery->claimAvailable($propertyId, $handoffId, $leaseSeconds);
            $writeSecret($payload, $claim);
            $emit($common + ['completed_at' => $safeNow()] + $safeClaim($claim));

        case 'consume_next':
            $result = $intake->consumeNextAvailable($propertyId, $leaseSeconds);
            $emit($common + [
                'completed_at' => $safeNow(),
                'outcome' => $result === null ? 'no_available' : 'consumed',
                'result' => $result?->toSafeArray(),
            ]);

        case 'consume_next_store_secret':
            $claim = $delivery->claimNextAvailable($propertyId, $leaseSeconds);
            if ($claim === null) {
                $emit($common + ['completed_at' => $safeNow(), 'outcome' => 'no_available', 'result' => null]);
            }
            $writeSecret($payload, $claim);
            $result = $intake->consumeClaimed($propertyId, (string) $claim['handoff_id'], (string) $claim['claim_token']);
            $delivered = $delivery->markDelivered($propertyId, (string) $claim['handoff_id'], (string) $claim['claim_token']);
            $emit($common + [
                'completed_at' => $safeNow(),
                'outcome' => 'consumed',
                'result' => $result->toSafeArray(),
                'delivery_status' => $delivered->delivery_status?->value ?? 'DELIVERED',
                'attempts' => (int) $delivered->attempts,
            ]);

        case 'consume_claimed_from_secret':
            $secret = $readSecret($payload);
            $result = $intake->consumeClaimed($propertyId, $secret['handoff_id'], $secret['claim_token']);
            $handoff = null;
            if ((bool) ($payload['mark_delivered'] ?? false)) {
                $handoff = $delivery->markDelivered($propertyId, $secret['handoff_id'], $secret['claim_token']);
            }
            $emit($common + [
                'completed_at' => $safeNow(),
                'outcome' => 'consumed',
                'result' => $result->toSafeArray(),
                'delivery_status' => $handoff?->delivery_status?->value,
                'attempts' => $handoff ? (int) $handoff->attempts : null,
            ]);

        case 'mark_delivered_from_secret':
            $secret = $readSecret($payload);
            $handoff = $delivery->markDelivered($propertyId, $secret['handoff_id'], $secret['claim_token']);
            $emit($common + ['completed_at' => $safeNow(), 'outcome' => 'delivered'] + $safeHandoff($handoff->id));

        case 'mark_failed_from_secret':
            $secret = $readSecret($payload);
            $retryAt = DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' + interval '5 minutes' AS retry_at")->retry_at;
            $handoff = $delivery->markFailed(
                $propertyId,
                $secret['handoff_id'],
                $secret['claim_token'],
                (string) ($payload['error_code'] ?? HousekeepingCheckoutTurnoverIntakeService::ERROR_INTERNAL_RETRYABLE_FAILURE),
                new DateTimeImmutable((string) $retryAt, new DateTimeZone('UTC'))
            );
            $emit($common + ['completed_at' => $safeNow(), 'outcome' => 'failed'] + $safeHandoff($handoff->id));

        default:
            throw new RuntimeException('P11_WORKER_UNKNOWN_MODE');
    }
} catch (DomainException $exception) {
    $emit([
        'php_pid' => $phpPid,
        'postgres_backend_pid' => $pgBackendPid,
        'mode' => $mode,
        'started_at' => $startedAt,
        'completed_at' => $safeNow(),
        'outcome' => 'domain_error',
        'domain_error' => $exception->getMessage(),
    ]);
} catch (Throwable) {
    $emit([
        'php_pid' => $phpPid,
        'postgres_backend_pid' => $pgBackendPid,
        'mode' => $mode,
        'started_at' => $startedAt,
        'completed_at' => $safeNow(),
        'outcome' => 'internal_failure',
        'marker' => 'P11_WORKER_INTERNAL_FAILURE',
    ], 2);
}
