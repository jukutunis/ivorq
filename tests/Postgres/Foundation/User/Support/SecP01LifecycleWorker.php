<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Services\UserLifecycleService;
use Shared\Exceptions\NotFoundException;

$root = $argv[1];
$arguments = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::select("SELECT set_config('application_name', ?, false)", [$arguments['application_name']]);
DB::unprepared("SET lock_timeout TO '20s'");
DB::unprepared("SET statement_timeout TO '30s'");
file_put_contents($arguments['ready_file'], 'ready', LOCK_EX);
$deadline = microtime(true) + 20;
while (! is_file($arguments['go_file']) && microtime(true) < $deadline) {
    usleep(20000);
}

try {
    if (! is_file($arguments['go_file'])) {
        throw new RuntimeException('SEC_P01_CONCURRENCY_BARRIER_TIMEOUT');
    }

    $actor = User::query()->findOrFail($arguments['actor_id']);
    $service = $app->make(UserLifecycleService::class);
    $result = match ($arguments['action']) {
        'set_default' => $service->setDefaultProperty(
            $actor,
            $arguments['target_id'],
            $arguments['property_id'],
            $arguments['default_property_id'],
        ),
        'deactivate' => $service->deactivate($actor, $arguments['target_id'], $arguments['property_id']),
        'offboard' => $service->offboard($actor, $arguments['target_id'], $arguments['property_id']),
        default => throw new RuntimeException('SEC_P01_UNKNOWN_WORKER_ACTION'),
    };
    $payload = ['status' => 'success', 'user_id' => $result->id];
} catch (Throwable $throwable) {
    $payload = [
        'status' => $throwable instanceof DomainException || $throwable instanceof NotFoundException
            ? 'controlled_rejection'
            : 'unexpected_error',
        'error' => $throwable->getMessage(),
    ];
}

file_put_contents($arguments['result_file'], json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);
exit($payload['status'] === 'unexpected_error' ? 1 : 0);
