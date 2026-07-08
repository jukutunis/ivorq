<?php

declare(strict_types=1);

$configPath = $argv[1] ?? '';
if ($configPath === '' || ! file_exists($configPath)) {
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
$workerId = $config['worker_id'];
$scenario = $config['scenario'];
$barrierDir = $config['barrier_dir'];
$fixture = $config['fixture'];

require $config['base_path'] . '/vendor/autoload.php';
$app = require_once $config['base_path'] . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.pgsql.database' => $config['db_name']]);
\Illuminate\Support\Facades\DB::purge('pgsql');
\Illuminate\Support\Facades\DB::reconnect('pgsql');

$result = [
    'worker_id' => $workerId,
    'scenario' => $scenario,
    'pid' => getmypid(),
    'pg_backend_pid' => null,
    'outcome' => 'UNKNOWN',
    'error_class' => null,
    'error_message' => null,
    'lock_attempted' => false,
];

try {
    $pid = \Illuminate\Support\Facades\DB::select('SELECT pg_backend_pid() AS pid');
    $result['pg_backend_pid'] = $pid[0]->pid ?? null;
} catch (Throwable) {
}

try {
    $actor = \Modules\Foundation\User\Models\User::findOrFail($fixture['actor_id']);
    \Illuminate\Support\Facades\Auth::login($actor);
    app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($fixture['property_id']);
    session([
        'active_property_id' => $fixture['property_id'],
        'active_company_id' => $fixture['company_id'],
        'current_property_id' => $fixture['property_id'],
    ]);
    setPermissionsTeamId($fixture['property_id']);

    touch($barrierDir . '/ready-' . $workerId);
    $startFile = $barrierDir . '/start-' . $scenario . '.signal';
    for ($i = 0; $i < 6000; $i++) {
        if (file_exists($startFile)) {
            break;
        }
        usleep(10000);
    }

    touch($barrierDir . '/locking-' . $workerId);
    $result['lock_attempted'] = true;

    if ($scenario === 'assign') {
        $reservationId = $workerId === 'A' ? $fixture['reservation_a_id'] : $fixture['reservation_b_id'];
        app(\Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService::class)->assign(
            $actor,
            $reservationId,
            $fixture['room_id'],
            null,
            'fd-a2-assign-' . $workerId . '-' . \Illuminate\Support\Str::ulid()
        );
        $result['outcome'] = 'ROOM_ASSIGNED';
    } elseif ($scenario === 'check_in') {
        $service = app(\Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::class);
        $context = 'fd-a2-check-in-' . $workerId . '-' . \Illuminate\Support\Str::ulid();
        $hash = $service->prepareConfirmation($actor, $fixture['stay_id'], $context);
        app(\Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService::class)->confirm(
            $actor,
            \Modules\Operations\FrontDesk\Services\FrontDeskCheckInService::INTENT,
            'password',
            $fixture['company_id'],
            $fixture['property_id'],
            $hash
        );
        $service->checkIn($actor, $fixture['stay_id'], $context);
        $result['outcome'] = 'IN_HOUSE';
    } else {
        throw new RuntimeException('UNKNOWN_SCENARIO');
    }
} catch (DomainException|RuntimeException $exception) {
    $result['outcome'] = 'CONTROLLED_FAILURE';
    $result['error_class'] = get_class($exception);
    $result['error_message'] = substr($exception->getMessage(), 0, 300);
} catch (Throwable $exception) {
    $result['outcome'] = 'ERROR';
    $result['error_class'] = get_class($exception);
    $result['error_message'] = substr($exception->getMessage(), 0, 500);
}

touch($barrierDir . '/posted-' . $workerId);
file_put_contents($config['result_file'], json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
exit(0);
