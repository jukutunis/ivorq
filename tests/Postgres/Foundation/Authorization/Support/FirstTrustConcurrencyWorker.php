<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Data\FirstTrustRequest;
use Modules\Foundation\Authorization\Services\FirstTrustOrchestrator;
use Tests\Fakes\FakeFirstTrustAuthority;

$root = $argv[1];
$arguments = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$app['config']->set([
    'first-trust.environment' => 'REHEARSAL',
    'first-trust.installation_id' => 'ivorq-concurrency-installation',
    'first-trust.release_sha' => $arguments['release_sha'],
    'first-trust.authority.reference' => 'authority/rehearsal/v1',
    'first-trust.authority.issuer' => 'https://authority.example.test/rehearsal',
    'first-trust.authority.audience' => 'ivorq-installation',
    'first-trust.authority.verification_bundle_path' => $arguments['bundle_path'],
    'mail.default' => 'array',
]);
$app->instance(FirstTrustAuthority::class, new FakeFirstTrustAuthority($arguments['authority_state_file']));
Notification::fake();
DB::select("SELECT set_config('application_name', ?, false)", [$arguments['application_name']]);
DB::unprepared("SET lock_timeout TO '20s'");
DB::unprepared("SET statement_timeout TO '30s'");

file_put_contents($arguments['ready_file'], 'ready', LOCK_EX);
$deadline = microtime(true) + 20;
while (! is_file($arguments['go_file']) && microtime(true) < $deadline) {
    usleep(20000);
}
if (! is_file($arguments['go_file'])) {
    file_put_contents($arguments['result_file'], json_encode(['status' => 'error', 'error' => 'WORKER_BARRIER_TIMEOUT'], JSON_THROW_ON_ERROR));
    exit(1);
}

try {
    $result = $app->make(FirstTrustOrchestrator::class)->establish(
        new FirstTrustRequest(
            'Concurrency Owner',
            'concurrency.owner@example.test',
            'Concurrency Company',
            'concurrency-company',
            'Concurrency Property',
            'concurrency-property',
            'CONC_01',
            'Asia/Makassar',
            'IDR',
        ),
        'B5A2B-CONCURRENCY-RAW-AUTHORIZATION-MARKER',
    );
    $payload = [
        'status' => 'success',
        'run_id' => $result->runId,
        'execution_id' => $result->executionId,
        'state' => $result->state,
        'delivery' => $result->activationDeliveryState,
    ];
} catch (Throwable $throwable) {
    $payload = ['status' => 'rejected', 'error' => $throwable->getMessage()];
}

file_put_contents($arguments['result_file'], json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);
exit($payload['status'] === 'error' ? 1 : 0);
