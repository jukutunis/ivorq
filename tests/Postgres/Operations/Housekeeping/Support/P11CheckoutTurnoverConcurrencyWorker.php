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

$emit = static function (array $data, int $exitCode = 0): never {
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    exit($exitCode);
};

$waitForRelease = static function (array $payload): void {
    $readyMarker = (string) ($payload['ready_marker'] ?? '');
    $releasePath = (string) ($payload['release_path'] ?? '');

    if ($readyMarker !== '') {
        file_put_contents($readyMarker, json_encode([
            'php_pid' => getmypid(),
            'postgres_backend_pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            'ready_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_THROW_ON_ERROR));
    }

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

    $waitForRelease($payload);

    $delivery = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);
    $intake = app(HousekeepingCheckoutTurnoverIntakeService::class);
    $leaseSeconds = (int) ($payload['lease_seconds'] ?? 60);
    $handoffId = (string) ($payload['handoff_id'] ?? '');
    $claimToken = (string) ($payload['claim_token'] ?? '');

    $common = [
        'php_pid' => $phpPid,
        'postgres_backend_pid' => $pgBackendPid,
        'mode' => $mode,
        'started_at' => $startedAt,
        'completed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
    ];

    switch ($mode) {
        case 'claim_next':
            $claim = $delivery->claimNextAvailable($propertyId, $leaseSeconds);
            $emit($common + [
                'outcome' => $claim === null ? 'no_available' : 'claimed',
                'claim' => $claim,
            ]);

        case 'consume_next':
            $result = $intake->consumeNextAvailable($propertyId, $leaseSeconds);
            $emit($common + [
                'outcome' => $result === null ? 'no_available' : 'consumed',
                'result' => $result?->toSafeArray(),
            ]);

        case 'consume_claimed':
            $result = $intake->consumeClaimed($propertyId, $handoffId, $claimToken);
            if ((bool) ($payload['mark_delivered'] ?? false)) {
                $delivery->markDelivered($propertyId, $handoffId, $claimToken);
            }
            $emit($common + [
                'outcome' => 'consumed',
                'result' => $result->toSafeArray(),
            ]);

        case 'mark_delivered':
            $delivery->markDelivered($propertyId, $handoffId, $claimToken);
            $emit($common + ['outcome' => 'delivered']);

        case 'mark_failed':
            $retryAt = DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' + interval '5 minutes' AS retry_at")->retry_at;
            $delivery->markFailed($propertyId, $handoffId, $claimToken, (string) ($payload['error_code'] ?? 'HK_P11_INTERNAL_RETRYABLE_FAILURE'), new DateTimeImmutable((string) $retryAt, new DateTimeZone('UTC')));
            $emit($common + ['outcome' => 'failed']);

        default:
            throw new RuntimeException('Unknown mode: ' . $mode);
    }
} catch (DomainException $exception) {
    $emit([
        'php_pid' => $phpPid,
        'postgres_backend_pid' => $pgBackendPid,
        'mode' => $mode,
        'started_at' => $startedAt,
        'completed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
        'outcome' => 'domain_error',
        'domain_error' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    fwrite(STDERR, 'P11_WORKER_CRASH: ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    $emit([
        'php_pid' => $phpPid,
        'postgres_backend_pid' => $pgBackendPid,
        'mode' => $mode,
        'started_at' => $startedAt,
        'completed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
        'outcome' => 'crash',
        'exception_class' => get_class($exception),
        'database_message' => $exception->getMessage(),
    ], 2);
}
