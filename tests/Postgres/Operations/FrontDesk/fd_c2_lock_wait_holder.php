<?php

/**
 * FD-C2 Lock-Wait Lock Holder
 *
 * Acquires a FOR UPDATE row lock on the specified handoff row,
 * emits a barrier signal, holds the lock for 5 seconds,
 * then releases and exits.
 *
 * Exit codes: 0 = success
 *
 * Usage:
 *   php fd_c2_lock_wait_holder.php <handoffId>
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$handoffId = $argv[1] ?? '';

if ($handoffId === '') {
    fwrite(STDERR, "LOCK_HOLDER:ERROR:Missing handoffId\n");
    exit(1);
}

$conn = DB::connection('pgsql');
$conn->beginTransaction();

$locked = $conn->table('front_desk_checkout_housekeeping_handoffs')
    ->where('id', $handoffId)
    ->lockForUpdate()
    ->first();

if (! $locked) {
    $conn->rollBack();
    fwrite(STDERR, "LOCK_HOLDER:ERROR:Row not found\n");
    exit(1);
}

// Emit barrier: lock acquired
echo "LOCK_ACQUIRED\n";
flush();

// Hold the lock for 5 seconds to allow the lease to expire
usleep(5_000_000);

// Release
$conn->commit();

echo "LOCK_RELEASED\n";
exit(0);
