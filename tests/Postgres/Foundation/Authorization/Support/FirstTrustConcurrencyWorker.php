<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Data\FirstTrustRequest;
use Modules\Foundation\Authorization\Services\FirstTrustOrchestrator;
use Tests\Fakes\FakeFirstTrustAuthority;

$timing = ['worker_started_ns' => hrtime(true)];
$root = $argv[1];
$arguments = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$timing['application_booted_ns'] = hrtime(true);

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
$app->instance(FirstTrustAuthority::class, new FakeFirstTrustAuthority(
    $arguments['authority_state_file'],
    [],
    $arguments['reserve_barrier_directory'],
));
Notification::fake();
DB::select("SELECT set_config('application_name', ?, false)", [$arguments['application_name']]);
DB::unprepared("SET lock_timeout TO '20s'");
DB::unprepared("SET statement_timeout TO '30s'");
$session = (array) DB::selectOne(<<<'SQL'
    SELECT pg_backend_pid() AS pid,
           current_setting('application_name') AS application_name,
           current_setting('lock_timeout') AS lock_timeout,
           current_setting('statement_timeout') AS statement_timeout,
           txid_current_if_assigned() IS NULL AS transaction_id_unassigned,
           (SELECT count(*) FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'advisory') AS advisory_lock_count
    SQL);

$timing['ready_ns'] = hrtime(true);
file_put_contents($arguments['ready_file'], 'ready', LOCK_EX);
$deadline = microtime(true) + 20;
while (! is_file($arguments['go_file']) && microtime(true) < $deadline) {
    usleep(20000);
}
if (! is_file($arguments['go_file'])) {
    $timing['finished_ns'] = hrtime(true);
    file_put_contents($arguments['result_file'], json_encode([
        'status' => 'test_harness_error',
        'error' => 'WORKER_BARRIER_TIMEOUT',
        'timing' => $timing,
        'database_session' => $session,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
$timing['go_observed_ns'] = hrtime(true);

try {
    $timing['establish_started_ns'] = hrtime(true);
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
    $error = $throwable->getMessage();
    $harnessError = latestHarnessError($arguments['reserve_barrier_directory']);
    if ($harnessError !== null || str_starts_with($error, 'FAKE_FIRST_TRUST_')) {
        $payload = [
            'status' => 'test_harness_error',
            'error' => $harnessError ?? $error,
            'reported_error' => $error,
        ];
    } elseif ($error === 'ALREADY_RESERVED_OTHER_EXECUTION') {
        $payload = ['status' => 'expected_authority_rejection', 'error' => $error];
    } else {
        $payload = ['status' => 'unexpected_error', 'error' => $error];
    }
}

$timing['finished_ns'] = hrtime(true);
$payload['timing'] = $timing;
$payload['database_session'] = $session;
file_put_contents($arguments['result_file'], json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX);
exit(in_array($payload['status'], ['success', 'expected_authority_rejection'], true) ? 0 : 1);

function latestHarnessError(string $directory): ?string
{
    $files = glob($directory.DIRECTORY_SEPARATOR.'test-harness-error-*.json') ?: [];
    rsort($files, SORT_STRING);
    foreach ($files as $file) {
        try {
            $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 'FAKE_FIRST_TRUST_HARNESS_ERROR_INVALID';
        }
        if (is_array($payload) && isset($payload['error']) && is_string($payload['error'])) {
            return $payload['error'];
        }
    }

    return null;
}
