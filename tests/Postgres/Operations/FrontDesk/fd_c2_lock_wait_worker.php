<?php

/**
 * FD-C2 Lock-Wait Concurrency Worker
 *
 * This worker receives handoff claim parameters via CLI arguments and
 * attempts markDelivered(). It is designed to be started as a child
 * process while another process holds a FOR UPDATE row lock.
 *
 * The raw claim token is passed as a CLI argument (not in process
 * command line visible to other users via ps). In production, use
 * an environment variable or protected temporary file.
 *
 * Exit codes:
 *   0 — delivery succeeded (UNEXPECTED in lock-wait expiry test)
 *   1 — rejected with expected FD-C2 domain exception
 *   2 — unexpected error
 *
 * Usage:
 *   php fd_c2_lock_wait_worker.php <propertyId> <handoffId> <claimToken>
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutHousekeepingHandoffDeliveryService;
use Shared\Services\CurrentPropertyService;

$propertyId = $argv[1] ?? '';
$handoffId = $argv[2] ?? '';
$claimToken = $argv[3] ?? '';

if ($propertyId === '' || $handoffId === '' || $claimToken === '') {
    echo "WORKER_RESULT:ERROR:InvalidArgumentException:Missing required arguments\n";
    exit(2);
}

$currentProperty = app(CurrentPropertyService::class);
$currentProperty->setPropertyId($propertyId);

$deliveryService = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);

try {
    $result = $deliveryService->markDelivered($propertyId, $handoffId, $claimToken);
    echo "WORKER_RESULT:DELIVERED\n";
    exit(0);
} catch (DomainException $e) {
    echo "WORKER_RESULT:REJECTED:" . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo "WORKER_RESULT:ERROR:" . get_class($e) . ":" . $e->getMessage() . "\n";
    exit(2);
}
