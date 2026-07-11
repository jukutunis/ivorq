<?php

declare(strict_types=1);

$cfgPath = $argv[1] ?? '';
if ($cfgPath === '' || !file_exists($cfgPath)) {
    exit(1);
}

$cfg = json_decode(file_get_contents($cfgPath), true);
$workerId = $cfg['worker_id'];
$scenario = $cfg['scenario'];
$barrierDir = $cfg['barrier_dir'];
$resultFile = $cfg['result_file'];
$dbName = $cfg['db_name'];
$basePath = $cfg['base_path'];
$fixture = $cfg['fixture'];

require $basePath . '/vendor/autoload.php';
$app = require_once $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['database.connections.pgsql.database' => $dbName]);
\Illuminate\Support\Facades\DB::purge('pgsql');
\Illuminate\Support\Facades\DB::reconnect('pgsql');

$actor = \Modules\Foundation\User\Models\User::findOrFail($fixture['actor_id']);
\Illuminate\Support\Facades\Auth::login($actor);
app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($fixture['property_id']);
session([
    'active_property_id' => $fixture['property_id'],
    'current_property_id' => $fixture['property_id'],
    'active_company_id' => $fixture['company_id'],
]);

$result = [
    'worker_id' => $workerId,
    'pid' => getmypid(),
    'pg_backend_pid' => -1,
    'outcome' => 'UNKNOWN',
    'hidden_error' => null,
    'payment_id' => null,
    'payment_number' => null,
    'allocation_id' => null,
    'reversal_id' => null,
];

touch($barrierDir . "/ready-{$workerId}");
$startFile = $barrierDir . "/start-{$scenario}.signal";
$end = time() + 90;
while (time() < $end && !file_exists($startFile)) {
    usleep(20000);
}

if (!file_exists($startFile)) {
    $result['outcome'] = 'TIMEOUT_NO_START';
    file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT));
    touch($barrierDir . "/result-{$workerId}-ready");
    exit(1);
}

try {
    $result['pg_backend_pid'] = (int) \Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() as pid')->pid;
    $service = app(\Modules\Operations\PMS\Services\GuestPaymentLifecycleService::class);

    if ($scenario === 'recording_replay') {
        $payment = $service->recordCashPayment(
            $actor,
            $fixture['reservation_id'],
            $fixture['cashier_session_id'],
            '25.00',
            'recording-replay-key'
        );
        $result['payment_id'] = $payment->id;
        $result['payment_number'] = $payment->payment_number;
        $result['outcome'] = 'PAYMENT_RECORDED';
    } elseif ($scenario === 'payment_number_safety') {
        $reservationId = $workerId === 'A' ? $fixture['reservation_id_a'] : $fixture['reservation_id_b'];
        $payment = $service->recordCashPayment(
            $actor,
            $reservationId,
            $fixture['cashier_session_id'],
            '30.00',
            'payment-number-' . $workerId
        );
        $result['payment_id'] = $payment->id;
        $result['payment_number'] = $payment->payment_number;
        $result['outcome'] = 'PAYMENT_RECORDED';
    } elseif ($scenario === 'allocation_replay') {
        $allocation = $service->allocatePayment(
            $actor,
            $fixture['payment_id'],
            $fixture['folio_id'],
            '50.00',
            'allocation-replay-key'
        );
        $result['allocation_id'] = $allocation->id;
        $result['outcome'] = 'PAYMENT_ALLOCATED';
    } elseif ($scenario === 'over_allocation_race') {
        try {
            $allocation = $service->allocatePayment(
                $actor,
                $fixture['payment_id'],
                $fixture['folio_id'],
                '60.00',
                'over-allocation-' . $workerId
            );
            $result['allocation_id'] = $allocation->id;
            $result['outcome'] = 'PAYMENT_ALLOCATED';
        } catch (\DomainException $exception) {
            if (str_contains($exception->getMessage(), 'GUEST_PAYMENT_OVER_ALLOCATION')) {
                $result['outcome'] = 'OVER_ALLOCATION';
            } else {
                throw $exception;
            }
        }
    } elseif ($scenario === 'valid_split_race') {
        $folioId = $workerId === 'A' ? $fixture['folio_id_a'] : $fixture['folio_id_b'];
        $amount = $workerId === 'A' ? '60.00' : '40.00';
        $allocation = $service->allocatePayment(
            $actor,
            $fixture['payment_id'],
            $folioId,
            $amount,
            'valid-split-' . $workerId
        );
        $result['allocation_id'] = $allocation->id;
        $result['outcome'] = 'PAYMENT_ALLOCATED';
    } elseif ($scenario === 'double_reversal_race') {
        session()->put('sensitive_action_confirmation', [
            \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::REVERSAL_CONFIRMATION_INTENT => [
                'actor_id' => $actor->id,
                'intent' => \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::REVERSAL_CONFIRMATION_INTENT,
                'company_id' => $fixture['company_id'],
                'property_id' => $fixture['property_id'],
                'confirmed_at' => now()->toISOString(),
                'expires_at' => now()->addMinutes(15)->toISOString(),
            ],
        ]);
        $reversal = $service->reverseAllocation(
            $actor,
            $fixture['allocation_id'],
            'DOUBLE_REVERSAL',
            'double-reversal-key'
        );
        $result['reversal_id'] = $reversal->id;
        $result['outcome'] = 'ALLOCATION_REVERSED';
    } elseif ($scenario === 'allocation_versus_reversal') {
        if ($workerId === 'A') {
            $allocation = $service->allocatePayment(
                $actor,
                $fixture['payment_id'],
                $fixture['new_folio_id'],
                '30.00',
                'alloc-vs-rev-new'
            );
            $result['allocation_id'] = $allocation->id;
            $result['outcome'] = 'PAYMENT_ALLOCATED';
        } else {
            session()->put('sensitive_action_confirmation', [
                \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::REVERSAL_CONFIRMATION_INTENT => [
                    'actor_id' => $actor->id,
                    'intent' => \Modules\Operations\PMS\Services\GuestPaymentLifecycleService::REVERSAL_CONFIRMATION_INTENT,
                    'company_id' => $fixture['company_id'],
                    'property_id' => $fixture['property_id'],
                    'confirmed_at' => now()->toISOString(),
                    'expires_at' => now()->addMinutes(15)->toISOString(),
                ],
            ]);
            $reversal = $service->reverseAllocation(
                $actor,
                $fixture['existing_allocation_id'],
                'ALLOC_VS_REV',
                'alloc-vs-rev-reversal'
            );
            $result['reversal_id'] = $reversal->id;
            $result['outcome'] = 'ALLOCATION_REVERSED';
        }
    }
} catch (Throwable $exception) {
    $result['outcome'] = 'CONTROLLED_FAILURE';
    $result['hidden_error'] = $exception::class . ': ' . $exception->getMessage();
}

file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT));
touch($barrierDir . "/result-{$workerId}-ready");
exit(0);
