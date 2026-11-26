<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Services\ReservationService;
use Shared\Services\CurrentPropertyService;

$config = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
putenv('DB_DATABASE='.$config['database']);
$_ENV['DB_DATABASE'] = $config['database'];
$_SERVER['DB_DATABASE'] = $config['database'];

require $config['base_path'].'/vendor/autoload.php';
$app = require $config['base_path'].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

file_put_contents($config['ready'], (string) getmypid());
$deadline = microtime(true) + 30;
while (! file_exists($config['go']) && microtime(true) < $deadline) {
    usleep(10000);
}

try {
    if (! file_exists($config['go'])) {
        throw new RuntimeException('BARRIER_TIMEOUT');
    }
    $actor = User::query()->findOrFail($config['actor_id']);
    Auth::setUser($actor);
    app(CurrentPropertyService::class)->setId($config['property_id']);
    $backendPid = DB::selectOne('select pg_backend_pid() as pid')->pid;
    $service = app(ReservationService::class);
    $reservation = match ($config['action'] ?? 'create') {
        'cancel' => $service->cancel($config['reservation_id'], 'Concurrent cancellation'),
        'no_show' => $service->noShow($config['reservation_id']),
        default => $service->create([
            'primary_guest_id' => $config['guest_id'],
            'arrival_date' => '2026-12-10',
            'departure_date' => '2026-12-12',
            'reserved_room_type' => 'standard',
        ]),
    };
    $result = [
        'ok' => true, 'php_pid' => getmypid(), 'pg_pid' => $backendPid,
        'id' => $reservation->id, 'number' => $reservation->reservation_number,
        'status' => $reservation->status->value,
    ];
} catch (Throwable $error) {
    $result = ['ok' => false, 'php_pid' => getmypid(), 'pg_pid' => $backendPid ?? null,
        'error' => $error::class.': '.$error->getMessage()];
}

file_put_contents($config['result'], json_encode($result, JSON_THROW_ON_ERROR));
