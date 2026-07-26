<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationContext;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;

require __DIR__ . '/../../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fixturePath = $argv[1] ?? '';
$scenario = $argv[2] ?? '';

$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);

$actor = User::findOrFail($fixture['actor_id']);
Auth::login($actor);
app(CurrentPropertyService::class)->setPropertyId($fixture['property_id']);
session([
    'active_property_id' => $fixture['property_id'],
    'current_property_id' => $fixture['property_id'],
    'active_company_id' => $fixture['company_id'],
    CheckoutSensitiveConfirmationService::SESSION_KEY => [
        CheckoutSensitiveConfirmationService::INTENT => [
            'actor_id' => $fixture['actor_id'],
            'intent' => CheckoutSensitiveConfirmationService::INTENT,
            'company_id' => $fixture['company_id'],
            'property_id' => $fixture['property_id'],
            'front_desk_stay_id' => $fixture['front_desk_stay_id'],
            'checkout_idempotency_key' => $fixture['checkout_idempotency_key'],
            'issuance_id' => $fixture['issuance_id'],
            'confirmation_identity' => $fixture['confirmation_identity'],
            'confirmation_fingerprint' => $fixture['confirmation_fingerprint'],
            'session_fingerprint' => $fixture['session_fingerprint'],
            'confirmed_at' => $fixture['confirmed_at'],
            'expires_at' => $fixture['expires_at'],
        ],
    ],
]);

$context = new CheckoutSensitiveConfirmationContext(
    actor: $actor,
    company: Company::findOrFail($fixture['company_id']),
    property: Property::findOrFail($fixture['property_id']),
    stay: FrontDeskStay::findOrFail($fixture['front_desk_stay_id']),
    checkoutIdempotencyKey: $fixture['checkout_idempotency_key'],
    sessionFingerprint: $fixture['session_fingerprint'],
);

$markerDir = $fixture['marker_dir'];

$writeMarker = function (string $name, array $payload) use ($markerDir): void {
    file_put_contents($markerDir . DIRECTORY_SEPARATOR . $name . '.json', json_encode($payload, JSON_THROW_ON_ERROR));
};

$waitFor = function (string $name, int $timeoutMs = 15000) use ($markerDir): void {
    $path = $markerDir . DIRECTORY_SEPARATOR . $name;
    $deadline = microtime(true) + ($timeoutMs / 1000);
    while (! file_exists($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('P8_WORKER_BARRIER_TIMEOUT_' . $name);
        }
        usleep(50_000);
    }
};

try {
    if ($scenario === 'hold_commit' || $scenario === 'hold_rollback') {
        DB::beginTransaction();
        $backend = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        DB::table('checkout_sensitive_confirmation_issuances')
            ->where('id', $fixture['issuance_id'])
            ->lockForUpdate()
            ->first();
        $writeMarker('a_locked', ['php_pid' => getmypid(), 'backend_pid' => $backend]);
        $waitFor('release_a');

        if ($scenario === 'hold_commit') {
            $result = app(CheckoutSensitiveConfirmationService::class)->claimCurrentSessionConfirmation($context);
            DB::commit();
            echo json_encode(['result' => 'committed', 'php_pid' => getmypid(), 'backend_pid' => $backend, 'consumption_id' => $result->consumptionId], JSON_THROW_ON_ERROR);
            exit(0);
        }

        DB::rollBack();
        echo json_encode(['result' => 'rolled_back', 'php_pid' => getmypid(), 'backend_pid' => $backend], JSON_THROW_ON_ERROR);
        exit(0);
    }

    if ($scenario === 'claim') {
        $backend = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $writeMarker('b_before_claim', ['php_pid' => getmypid(), 'backend_pid' => $backend]);
        try {
            $result = DB::transaction(fn () => app(CheckoutSensitiveConfirmationService::class)->claimCurrentSessionConfirmation($context));
            echo json_encode(['result' => 'claimed', 'php_pid' => getmypid(), 'backend_pid' => $backend, 'consumption_id' => $result->consumptionId], JSON_THROW_ON_ERROR);
            exit(0);
        } catch (DomainException $exception) {
            echo json_encode(['result' => 'domain_error', 'message' => $exception->getMessage(), 'php_pid' => getmypid(), 'backend_pid' => $backend], JSON_THROW_ON_ERROR);
            exit(0);
        }
    }

    throw new RuntimeException('P8_UNKNOWN_WORKER_SCENARIO');
} catch (Throwable $exception) {
    echo json_encode([
        'result' => 'worker_error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'php_pid' => getmypid(),
    ], JSON_THROW_ON_ERROR);
    exit(1);
}
