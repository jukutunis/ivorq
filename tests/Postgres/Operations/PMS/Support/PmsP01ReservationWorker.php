<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Modules\Operations\PMS\Services\ReservationService;
use Modules\Operations\PMS\Services\GuestService;
use Modules\Operations\PMS\Services\GuestLedgerFolioAggregateService;
use Modules\Operations\PMS\Services\RatePlanService;
use Modules\Operations\PMS\Services\RoomBlockService;
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
    $outcome = match ($config['action'] ?? 'create') {
        'guest_delete' => ['deleted' => app(GuestService::class)->delete($config['guest_id'])],
        'guest_associate' => (function () use ($config): array {
            app(GuestService::class)->addAccompanyingGuest($config['reservation_id'], $config['guest_id']);
            return ['associated' => true];
        })(),
        'folio_open' => (function () use ($actor, $config): array {
            $folio = app(GuestLedgerFolioAggregateService::class)->openWindow(
                $actor, $config['reservation_id'], $config['idempotency_key']);
            return ['folio_id' => $folio->id, 'guest_id' => $folio->guest_id];
        })(),
        'replace_primary' => (function () use ($service, $config): array {
            $reservation = $service->update($config['reservation_id'], ['primary_guest_id' => $config['guest_id']]);
            return ['guest_id' => $reservation->primary_guest_id];
        })(),
        'cancel' => $service->cancel($config['reservation_id'], 'Concurrent cancellation'),
        'no_show' => $service->noShow($config['reservation_id']),
        'room_block_create' => (function () use ($config): array {
            $block = app(RoomBlockService::class)->create([
                'room_id' => $config['room_id'], 'block_type' => 'out_of_order',
                'start_at' => '2026-12-20 10:00:00', 'end_at' => '2026-12-22 10:00:00',
            ]);
            return ['block_id' => $block->id];
        })(),
        'rate_plan_delete' => ['deleted' => app(RatePlanService::class)->delete($config['rate_plan_id'])],
        default => $service->create([
            'primary_guest_id' => $config['guest_id'],
            'rate_plan_id' => $config['rate_plan_id'] ?? null,
            'arrival_date' => '2026-12-10',
            'departure_date' => '2026-12-12',
            'reserved_room_type' => 'standard',
        ]),
    };
    $result = [
        'ok' => true, 'php_pid' => getmypid(), 'pg_pid' => $backendPid,
        ...($outcome instanceof \Modules\Operations\PMS\Models\Reservation
            ? ['id' => $outcome->id, 'number' => $outcome->reservation_number,
                'status' => $outcome->status->value]
            : $outcome),
    ];
} catch (Throwable $error) {
    $result = ['ok' => false, 'php_pid' => getmypid(), 'pg_pid' => $backendPid ?? null,
        'error' => $error::class.': '.$error->getMessage()];
}

file_put_contents($config['result'], json_encode($result, JSON_THROW_ON_ERROR));
