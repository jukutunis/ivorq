<?php

namespace Tests\Fakes;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Data\FirstTrustExpectedBinding;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorityState;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorization;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustConsumption;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustReservation;
use RuntimeException;
use SensitiveParameter;

final class FakeFirstTrustAuthority implements FirstTrustAuthority
{
    /** @var array<string,mixed> */
    private array $memory;

    public function __construct(
        private readonly ?string $stateFile = null,
        array $initial = [],
    ) {
        $this->memory = array_merge($this->defaults(), $initial);

        if ($stateFile !== null && ! is_file($stateFile)) {
            self::initializeStateFile($stateFile, $initial);
        }
    }

    public static function initializeStateFile(string $path, array $initial = []): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('FAKE_FIRST_TRUST_STATE_DIRECTORY_FAILED');
        }

        $fake = new self;
        $bytes = json_encode(array_merge($fake->defaults(), $initial), JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('FAKE_FIRST_TRUST_STATE_WRITE_FAILED');
        }
    }

    public function configure(array $changes): void
    {
        $this->mutate(static fn (array $state): array => array_merge($state, $changes));
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return $this->read();
    }

    public function verifyAuthorization(
        #[SensitiveParameter] string $serializedEnvelope,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustAuthorization {
        $state = $this->read();
        if (isset($state['verify_error']) && is_string($state['verify_error'])) {
            throw new RuntimeException($state['verify_error']);
        }
        if (($state['verify_throws'] ?? false) === true) {
            throw new RuntimeException('TEST_PROVIDER_SECRET_BODY_MUST_NOT_ESCAPE');
        }

        $projectionBinding = $this->projectedBinding($binding, $state);
        $now = $this->now();

        return new VerifiedFirstTrustAuthorization(
            (string) $state['authorization_id'],
            (string) ($state['authority_reference'] ?? config('first-trust.authority.reference')),
            (string) ($state['authority_issuer'] ?? config('first-trust.authority.issuer')),
            (string) ($state['authority_audience'] ?? config('first-trust.authority.audience')),
            $projectionBinding,
            $now->modify('-2 minutes'),
            $now->modify('-1 minute'),
            isset($state['authorization_expires_at'])
                ? new DateTimeImmutable((string) $state['authorization_expires_at'])
                : (($state['authorization_expired'] ?? false) ? $now->modify('-1 second') : $now->modify('+1 hour')),
            (string) $state['verified_key_id'],
            (string) ($state['verifier_bundle_fingerprint'] ?? $this->bundleFingerprint()),
        );
    }

    public function state(
        string $authorizationId,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustAuthorityState {
        $state = $this->read();
        if (isset($state['state_error']) && is_string($state['state_error'])) {
            throw new RuntimeException($state['state_error']);
        }
        if (($state['state_unavailable'] ?? false) === true) {
            throw new RuntimeException('TEST_AUTHORITY_STATE_UNAVAILABLE_SECRET');
        }

        $authorityState = (string) $state['state'];

        return new VerifiedFirstTrustAuthorityState(
            (string) $state['authorization_id'],
            (string) ($state['authority_reference'] ?? config('first-trust.authority.reference')),
            (string) ($state['authority_issuer'] ?? config('first-trust.authority.issuer')),
            $authorityState,
            $this->projectedBinding($binding, $state),
            $this->now(),
            (string) $state['verified_key_id'],
            (string) ($state['verifier_bundle_fingerprint'] ?? $this->bundleFingerprint()),
            $state['execution_id'] ?? null,
            $state['reservation_reference'] ?? null,
            $state['reservation_fingerprint'] ?? null,
            isset($state['reserved_at']) ? new DateTimeImmutable($state['reserved_at']) : null,
            isset($state['commit_deadline']) ? new DateTimeImmutable($state['commit_deadline']) : null,
            isset($state['recovery_deadline']) ? new DateTimeImmutable($state['recovery_deadline']) : null,
            $state['consumption_reference'] ?? null,
            $state['consumption_fingerprint'] ?? null,
            isset($state['consumed_at']) ? new DateTimeImmutable($state['consumed_at']) : null,
        );
    }

    public function reserve(
        #[SensitiveParameter] string $serializedAuthorization,
        string $executionId,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustReservation {
        $before = $this->read();
        if (isset($before['reserve_error']) && is_string($before['reserve_error'])) {
            throw new RuntimeException($before['reserve_error']);
        }
        if (isset($before['reserve_barrier_directory']) && is_string($before['reserve_barrier_directory'])) {
            $this->awaitReserveBarrier($before['reserve_barrier_directory'], $executionId);
        }

        $throw = null;
        $state = $this->mutate(function (array $state) use ($executionId, &$throw): array {
            if (($state['reserve_timeout_before_mutation_once'] ?? false) === true) {
                $state['reserve_timeout_before_mutation_once'] = false;
                $throw = 'reserve_before';

                return $state;
            }

            if ($state['state'] === 'AVAILABLE') {
                $reservedAt = $this->now();
                $winningExecution = ($state['reserve_other_execution_once'] ?? false)
                    ? (string) ($state['other_execution_id'] ?? 'ftx-01JTESTOTHEREXECUTION0000')
                    : $executionId;
                $state = array_merge($state, [
                    'state' => 'RESERVED',
                    'execution_id' => $winningExecution,
                    'reservation_reference' => 'reservation-'.substr(hash('sha256', $winningExecution), 0, 24),
                    'reservation_fingerprint' => hash('sha256', 'reservation:'.$winningExecution),
                    'reserved_at' => $reservedAt->format(DATE_ATOM),
                    'commit_deadline' => $reservedAt->modify('+15 minutes')->format(DATE_ATOM),
                    'recovery_deadline' => $reservedAt->modify('+1 day')->format(DATE_ATOM),
                ]);
                if (($state['reserve_other_execution_once'] ?? false) === true) {
                    $state['reserve_other_execution_once'] = false;
                    $throw = 'reserve_conflict';

                    return $state;
                }
                if (($state['reserve_timeout_after_mutation_once'] ?? false) === true) {
                    $state['reserve_timeout_after_mutation_once'] = false;
                    $throw = 'reserve_after';
                }

                return $state;
            }

            if ($state['state'] === 'RESERVED' && $state['execution_id'] === $executionId) {
                return $state;
            }

            $throw = 'reserve_conflict';

            return $state;
        });

        if ($throw !== null) {
            throw new RuntimeException('TEST_RESERVE_'.$throw.'_SECRET');
        }

        return $this->reservation($state, $binding);
    }

    public function consume(
        string $authorizationId,
        string $executionId,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustConsumption {
        $before = $this->read();
        if (isset($before['consume_error']) && is_string($before['consume_error'])) {
            throw new RuntimeException($before['consume_error']);
        }

        $throw = null;
        $state = $this->mutate(function (array $state) use ($executionId, &$throw): array {
            if (($state['consume_timeout_before_mutation_once'] ?? false) === true) {
                $state['consume_timeout_before_mutation_once'] = false;
                $throw = 'consume_before';
                if (($state['state_unavailable_after_consume_failure'] ?? false) === true) {
                    $state['state_unavailable'] = true;
                }

                return $state;
            }

            if ($state['state'] === 'CONSUMED' && $state['execution_id'] === $executionId) {
                return $state;
            }
            if ($state['state'] !== 'RESERVED' || $state['execution_id'] !== $executionId) {
                $throw = 'consume_conflict';

                return $state;
            }
            if (($state['keep_reserved_once'] ?? false) === true) {
                $state['keep_reserved_once'] = false;
                $throw = 'consume_reserved';

                return $state;
            }

            $consumedAt = $this->now();
            $state = array_merge($state, [
                'state' => 'CONSUMED',
                'consumption_reference' => 'consumption-'.substr(hash('sha256', $executionId), 0, 24),
                'consumption_fingerprint' => hash('sha256', 'consumption:'.$executionId),
                'consumed_at' => $consumedAt->format(DATE_ATOM),
            ]);
            if (($state['consume_timeout_after_mutation_once'] ?? false) === true) {
                $state['consume_timeout_after_mutation_once'] = false;
                $throw = 'consume_after';
                if (($state['state_unavailable_after_consume_failure'] ?? false) === true) {
                    $state['state_unavailable'] = true;
                }
            }

            return $state;
        });

        if ($throw !== null) {
            throw new RuntimeException('TEST_CONSUME_'.$throw.'_SECRET');
        }

        return $this->consumption($state, $binding);
    }

    /** @return array<string,mixed> */
    private function defaults(): array
    {
        return [
            'authorization_id' => 'authorization-test-001',
            'verified_key_id' => 'test-key-001',
            'state' => 'AVAILABLE',
        ];
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        if ($this->stateFile === null) {
            return $this->memory;
        }

        $handle = fopen($this->stateFile, 'c+b');
        if (! is_resource($handle) || ! flock($handle, LOCK_SH)) {
            throw new RuntimeException('FAKE_FIRST_TRUST_STATE_READ_FAILED');
        }
        try {
            rewind($handle);
            $decoded = json_decode((string) stream_get_contents($handle), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : throw new RuntimeException('FAKE_FIRST_TRUST_STATE_INVALID');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    private function mutate(callable $mutation): array
    {
        if ($this->stateFile === null) {
            $this->memory = $mutation($this->memory);

            return $this->memory;
        }

        $handle = fopen($this->stateFile, 'c+b');
        if (! is_resource($handle) || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('FAKE_FIRST_TRUST_STATE_WRITE_FAILED');
        }
        try {
            rewind($handle);
            $contents = (string) stream_get_contents($handle);
            $state = $contents === '' ? $this->defaults() : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($state)) {
                throw new RuntimeException('FAKE_FIRST_TRUST_STATE_INVALID');
            }
            $state = $mutation($state);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $state;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $state */
    private function projectedBinding(FirstTrustExpectedBinding $binding, array $state): FirstTrustExpectedBinding
    {
        return new FirstTrustExpectedBinding(
            (string) ($state['binding_environment'] ?? $binding->environment),
            (string) ($state['binding_installation_id'] ?? $binding->installationId),
            (string) ($state['binding_request_fingerprint'] ?? $binding->requestFingerprint),
            (string) ($state['binding_canonical_sha'] ?? $binding->canonicalSha),
        );
    }

    /** @param array<string,mixed> $state */
    private function reservation(array $state, FirstTrustExpectedBinding $binding): VerifiedFirstTrustReservation
    {
        if ($state['state'] !== 'RESERVED') {
            throw new RuntimeException('FAKE_FIRST_TRUST_RESERVATION_UNAVAILABLE');
        }

        return new VerifiedFirstTrustReservation(
            (string) $state['authorization_id'],
            (string) ($state['authority_reference'] ?? config('first-trust.authority.reference')),
            (string) ($state['authority_issuer'] ?? config('first-trust.authority.issuer')),
            (string) $state['execution_id'],
            $this->projectedBinding($binding, $state),
            (string) $state['reservation_reference'],
            (string) $state['reservation_fingerprint'],
            new DateTimeImmutable($state['reserved_at']),
            new DateTimeImmutable($state['commit_deadline']),
            new DateTimeImmutable($state['recovery_deadline']),
            (string) $state['verified_key_id'],
            (string) ($state['verifier_bundle_fingerprint'] ?? $this->bundleFingerprint()),
        );
    }

    /** @param array<string,mixed> $state */
    private function consumption(array $state, FirstTrustExpectedBinding $binding): VerifiedFirstTrustConsumption
    {
        if ($state['state'] !== 'CONSUMED') {
            throw new RuntimeException('FAKE_FIRST_TRUST_CONSUMPTION_UNAVAILABLE');
        }

        return new VerifiedFirstTrustConsumption(
            (string) $state['authorization_id'],
            (string) ($state['authority_reference'] ?? config('first-trust.authority.reference')),
            (string) ($state['authority_issuer'] ?? config('first-trust.authority.issuer')),
            (string) $state['execution_id'],
            $this->projectedBinding($binding, $state),
            (string) $state['reservation_reference'],
            new DateTimeImmutable($state['reserved_at']),
            (string) $state['consumption_reference'],
            (string) $state['consumption_fingerprint'],
            new DateTimeImmutable($state['consumed_at']),
            (string) $state['verified_key_id'],
            (string) ($state['verifier_bundle_fingerprint'] ?? $this->bundleFingerprint()),
        );
    }

    private function bundleFingerprint(): string
    {
        $path = config('first-trust.authority.verification_bundle_path');

        return is_string($path) && is_file($path) ? hash_file('sha256', $path) : str_repeat('0', 64);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function awaitReserveBarrier(string $directory, string $executionId): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('FAKE_FIRST_TRUST_RESERVE_BARRIER_FAILED');
        }
        file_put_contents($directory.DIRECTORY_SEPARATOR.'ready-'.hash('sha256', $executionId), 'ready', LOCK_EX);
        $deadline = microtime(true) + 15;
        do {
            if (count(glob($directory.DIRECTORY_SEPARATOR.'ready-*') ?: []) >= 2) {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('FAKE_FIRST_TRUST_RESERVE_BARRIER_TIMEOUT');
    }
}
