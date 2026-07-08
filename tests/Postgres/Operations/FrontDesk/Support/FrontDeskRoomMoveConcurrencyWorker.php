<?php

declare(strict_types=1);

$config = json_decode(file_get_contents($argv[1] ?? ''), true);
require $config['base_path'] . '/vendor/autoload.php';
$app = require_once $config['base_path'] . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.pgsql.database' => $config['db_name']]);
\Illuminate\Support\Facades\DB::purge('pgsql');
\Illuminate\Support\Facades\DB::reconnect('pgsql');

$workerId = $config['worker_id'];
$scenario = $config['scenario'];
$fixture = $config['fixture'];
$barrierDir = $config['barrier_dir'];
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
    session(['active_property_id' => $fixture['property_id'], 'active_company_id' => $fixture['company_id'], 'current_property_id' => $fixture['property_id']]);
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

    $service = app(\Modules\Operations\FrontDesk\Services\FrontDeskRoomMoveService::class);
    $stayId = $scenario === 'same_target' && $workerId === 'B' ? $fixture['stay_b_id'] : $fixture['stay_a_id'];
    $targetRoom = $fixture['target_room_id'];
    $reason = $fixture['move_reason'];
    $context = 'room-move-' . $workerId . '-' . \Illuminate\Support\Str::ulid();
    $hash = $service->prepareConfirmation($actor, $stayId, $targetRoom, $reason, $context);
    app(\Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService::class)->confirm(
        $actor,
        \Modules\Operations\FrontDesk\Services\FrontDeskRoomMoveService::INTENT,
        'password',
        $fixture['company_id'],
        $fixture['property_id'],
        $hash
    );
    $service->move($actor, $stayId, $targetRoom, $reason, 'move-' . $workerId . '-' . \Illuminate\Support\Str::ulid(), $context);
    $result['outcome'] = 'ROOM_MOVED';
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
