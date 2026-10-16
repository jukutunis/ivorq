<?php

namespace Infrastructure\FirstTrustAuthority;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Foundation\Authorization\Data\FirstTrustExpectedBinding;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorityState;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorization;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustConsumption;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustReservation;

final class FirstTrustEnvelopeVerifier
{
    private const DOMAINS = [
        'authorization' => "IVORQ-FIRST-TRUST-AUTHORIZATION-V1\0",
        'reservation' => "IVORQ-FIRST-TRUST-RESERVATION-V1\0",
        'authority_state' => "IVORQ-FIRST-TRUST-AUTHORITY-STATE-V1\0",
        'consumption_receipt' => "IVORQ-FIRST-TRUST-CONSUMPTION-V1\0",
    ];

    public function __construct(
        private readonly FirstTrustProtocolV1Parser $parser,
        private readonly PinnedVerificationBundleLoader $bundleLoader,
        private readonly string $authorityReference,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $clockToleranceSeconds = 60,
        private readonly mixed $clock = null,
    ) {
        if ($clockToleranceSeconds < 0 || $clockToleranceSeconds > 60) {
            throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
        }
    }

    public function verifyAuthorization(string $serialized, FirstTrustExpectedBinding $binding): VerifiedFirstTrustAuthorization
    {
        $verified = $this->verify($serialized, 'authorization', 'AUTHORIZATION_INVALID');
        $p = $verified['payload'];
        $this->assertCommon($p, $binding, 'AUTHORIZATION_INVALID');
        if ($p['audience'] !== $this->audience) {
            throw new FirstTrustAuthorityException('AUTHORIZATION_INVALID');
        }
        $issued = $this->time($p['issued_at'], 'AUTHORIZATION_INVALID');
        $notBefore = $this->time($p['not_before'], 'AUTHORIZATION_INVALID');
        $expires = $this->time($p['expires_at'], 'AUTHORIZATION_INVALID');
        $now = $this->now();
        $issuedMilliseconds = $this->milliseconds($issued);
        $notBeforeMilliseconds = $this->milliseconds($notBefore);
        $expiresMilliseconds = $this->milliseconds($expires);
        $nowMilliseconds = $this->milliseconds($now);
        $toleranceMilliseconds = $this->clockToleranceSeconds * 1000;
        if ($notBeforeMilliseconds < $issuedMilliseconds || $expiresMilliseconds <= $notBeforeMilliseconds || $expiresMilliseconds - $issuedMilliseconds > 600000) {
            throw new FirstTrustAuthorityException('AUTHORIZATION_INVALID');
        }
        if ($nowMilliseconds + $toleranceMilliseconds < $notBeforeMilliseconds) {
            throw new FirstTrustAuthorityException('AUTHORIZATION_INVALID');
        }
        if ($nowMilliseconds - $toleranceMilliseconds >= $expiresMilliseconds) {
            throw new FirstTrustAuthorityException('AUTHORIZATION_EXPIRED');
        }

        return new VerifiedFirstTrustAuthorization($p['authorization_id'], $p['authority_reference'], $p['issuer'], $p['audience'], $binding, $issued, $notBefore, $expires, $verified['key_id'], $verified['bundle_fingerprint']);
    }

    public function verifyReservation(string $serialized, string $authorizationId, string $executionId, FirstTrustExpectedBinding $binding): VerifiedFirstTrustReservation
    {
        return $this->verifyReservationInContext($serialized, $authorizationId, $executionId, $binding, false);
    }

    private function verifyReservationInContext(string $serialized, string $authorizationId, string $executionId, FirstTrustExpectedBinding $binding, bool $stateContext): VerifiedFirstTrustReservation
    {
        $verified = $this->verify($serialized, 'reservation', 'AUTHORIZATION_INVALID');
        $p = $verified['payload'];
        $this->assertCommon($p, $binding, 'AUTHORIZATION_INVALID', $stateContext);
        if ($p['authorization_id'] !== $authorizationId || $p['execution_id'] !== $executionId) {
            throw new FirstTrustAuthorityException('AUTHORITY_STATE_BINDING_MISMATCH');
        }
        $reserved = $this->time($p['reserved_at'], 'AUTHORIZATION_INVALID');
        $commit = $this->time($p['commit_deadline'], 'AUTHORIZATION_INVALID');
        $recovery = $this->time($p['recovery_deadline'], 'AUTHORIZATION_INVALID');
        if ($commit <= $reserved || $recovery < $commit) {
            throw new FirstTrustAuthorityException('AUTHORIZATION_INVALID');
        }

        return new VerifiedFirstTrustReservation($p['authorization_id'], $p['authority_reference'], $p['issuer'], $p['execution_id'], $binding, $p['reservation_reference'], hash('sha256', $serialized), $reserved, $commit, $recovery, $verified['key_id'], $verified['bundle_fingerprint']);
    }

    public function verifyConsumption(string $serialized, string $authorizationId, string $executionId, FirstTrustExpectedBinding $binding): VerifiedFirstTrustConsumption
    {
        return $this->verifyConsumptionInContext($serialized, $authorizationId, $executionId, $binding, false);
    }

    private function verifyConsumptionInContext(string $serialized, string $authorizationId, string $executionId, FirstTrustExpectedBinding $binding, bool $stateContext): VerifiedFirstTrustConsumption
    {
        $verified = $this->verify($serialized, 'consumption_receipt', 'RECEIPT_INVALID');
        $p = $verified['payload'];
        $this->assertCommon($p, $binding, 'RECEIPT_INVALID', $stateContext);
        if ($p['authorization_id'] !== $authorizationId || $p['execution_id'] !== $executionId) {
            throw new FirstTrustAuthorityException('AUTHORITY_STATE_BINDING_MISMATCH');
        }
        $reserved = $this->time($p['reserved_at'], 'RECEIPT_INVALID');
        $consumed = $this->time($p['consumed_at'], 'RECEIPT_INVALID');
        if ($consumed < $reserved) {
            throw new FirstTrustAuthorityException('RECEIPT_INVALID');
        }

        return new VerifiedFirstTrustConsumption($p['authorization_id'], $p['authority_reference'], $p['issuer'], $p['execution_id'], $binding, $p['reservation_reference'], $reserved, $p['consumption_reference'], hash('sha256', $serialized), $consumed, $verified['key_id'], $verified['bundle_fingerprint']);
    }

    public function verifyState(string $serialized, string $authorizationId, FirstTrustExpectedBinding $binding): VerifiedFirstTrustAuthorityState
    {
        $verified = $this->verify($serialized, 'authority_state', 'AUTHORITY_STATE_INVALID');
        $p = $verified['payload'];
        $this->assertCommon($p, $binding, 'AUTHORITY_STATE_INVALID', true);
        if ($p['authorization_id'] !== $authorizationId) {
            throw new FirstTrustAuthorityException('AUTHORITY_STATE_BINDING_MISMATCH');
        }
        $args = [$p['authorization_id'], $p['authority_reference'], $p['issuer'], $p['state'], $binding, $this->time($p['state_generated_at'], 'AUTHORITY_STATE_INVALID'), $verified['key_id'], $verified['bundle_fingerprint']];
        if ($p['state'] === 'RESERVED' || $p['state'] === 'CONSUMED') {
            if (! hash_equals($p['reservation_fingerprint'], hash('sha256', $p['reservation_envelope']))) {
                throw new FirstTrustAuthorityException('AUTHORITY_STATE_INVALID');
            }
            try {
                $reservation = $this->verifyReservationInContext($p['reservation_envelope'], $authorizationId, $p['execution_id'], $binding, true);
            } catch (FirstTrustAuthorityException $exception) {
                $this->throwNestedStateFailure($exception);
            }
            if ($reservation->reservationReference !== $p['reservation_reference'] || $reservation->reservedAt != $this->time($p['reserved_at'], 'AUTHORITY_STATE_INVALID')
                || $reservation->commitDeadline != $this->time($p['commit_deadline'], 'AUTHORITY_STATE_INVALID')
                || $reservation->recoveryDeadline != $this->time($p['recovery_deadline'], 'AUTHORITY_STATE_INVALID')) {
                throw new FirstTrustAuthorityException('AUTHORITY_STATE_BINDING_MISMATCH');
            }
            array_push($args, $p['execution_id'], $p['reservation_reference'], $p['reservation_fingerprint'], $reservation->reservedAt, $reservation->commitDeadline, $reservation->recoveryDeadline);
            if ($p['state'] === 'CONSUMED') {
                if (! hash_equals($p['consumption_fingerprint'], hash('sha256', $p['consumption_receipt']))) {
                    throw new FirstTrustAuthorityException('AUTHORITY_STATE_INVALID');
                }
                try {
                    $receipt = $this->verifyConsumptionInContext($p['consumption_receipt'], $authorizationId, $p['execution_id'], $binding, true);
                } catch (FirstTrustAuthorityException $exception) {
                    $this->throwNestedStateFailure($exception);
                }
                if ($receipt->reservationReference !== $p['reservation_reference'] || $receipt->reservedAt != $reservation->reservedAt
                    || $receipt->consumptionReference !== $p['consumption_reference'] || $receipt->consumedAt != $this->time($p['consumed_at'], 'AUTHORITY_STATE_INVALID')) {
                    throw new FirstTrustAuthorityException('AUTHORITY_STATE_BINDING_MISMATCH');
                }
                array_push($args, $p['consumption_reference'], $p['consumption_fingerprint'], $receipt->consumedAt);
            }
        }

        return new VerifiedFirstTrustAuthorityState(...$args);
    }

    /** @return array{payload:array<string,mixed>,key_id:string,bundle_fingerprint:string} */
    private function verify(string $serialized, string $kind, string $invalidCode): array
    {
        $parsed = $this->parser->parse($serialized, $kind, $invalidCode);
        $bundle = $this->bundleLoader->load();
        $keyId = $parsed['envelope']['key_id'];
        $key = $bundle['keys'][$keyId] ?? null;
        if ($key === null) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        $signatureTime = $this->payloadEvidenceTime($parsed['payload'], $kind, $invalidCode);
        if ($signatureTime < $key['activated_at'] || ($key['retired_at'] !== null && $signatureTime >= $key['retired_at'])) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        $result = openssl_verify(self::DOMAINS[$kind].$parsed['payload_bytes'], $parsed['signature'], $key['pem'], OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new FirstTrustAuthorityException($invalidCode);
        }

        return ['payload' => $parsed['payload'], 'key_id' => $keyId, 'bundle_fingerprint' => $bundle['fingerprint']];
    }

    /** @param array<string,mixed> $p */
    private function assertCommon(array $p, FirstTrustExpectedBinding $binding, string $invalidCode, bool $state = false): void
    {
        if ($p['authority_reference'] !== $this->authorityReference || $p['issuer'] !== $this->issuer) {
            throw new FirstTrustAuthorityException($state ? 'AUTHORITY_STATE_BINDING_MISMATCH' : $invalidCode);
        }
        if ($p['environment'] !== $binding->environment) {
            throw new FirstTrustAuthorityException($state ? 'AUTHORITY_STATE_BINDING_MISMATCH' : 'ENVIRONMENT_MISMATCH');
        }
        if ($p['installation_id'] !== $binding->installationId) {
            throw new FirstTrustAuthorityException($state ? 'AUTHORITY_STATE_BINDING_MISMATCH' : 'INSTALLATION_MISMATCH');
        }
        if ($p['request_fingerprint'] !== $binding->requestFingerprint) {
            throw new FirstTrustAuthorityException($state ? 'AUTHORITY_STATE_BINDING_MISMATCH' : 'REQUEST_FINGERPRINT_MISMATCH');
        }
        if ($p['canonical_sha'] !== $binding->canonicalSha) {
            throw new FirstTrustAuthorityException($state ? 'AUTHORITY_STATE_BINDING_MISMATCH' : 'CANONICAL_SHA_MISMATCH');
        }
    }

    /** @param array<string,mixed> $payload */
    private function payloadEvidenceTime(array $payload, string $kind, string $invalidCode): DateTimeImmutable
    {
        return $this->time($payload[match ($kind) {
            'authorization' => 'issued_at', 'reservation' => 'reserved_at', 'consumption_receipt' => 'consumed_at', default => 'state_generated_at'
        }], $invalidCode);
    }

    private function time(string $value, string $invalidCode): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value, new DateTimeZone('UTC'));
        if (! $time || $time->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            throw new FirstTrustAuthorityException($invalidCode);
        }

        return $time;
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock === null ? new DateTimeImmutable('now', new DateTimeZone('UTC')) : ($this->clock)();
        if (! $now instanceof DateTimeImmutable) {
            throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
        }

        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    private function milliseconds(DateTimeImmutable $time): int
    {
        return (int) $time->format('Uv');
    }

    private function throwNestedStateFailure(FirstTrustAuthorityException $exception): never
    {
        if (in_array($exception->getMessage(), [
            'ENVIRONMENT_MISMATCH', 'INSTALLATION_MISMATCH', 'CANONICAL_SHA_MISMATCH',
            'REQUEST_FINGERPRINT_MISMATCH', 'AUTHORITY_STATE_BINDING_MISMATCH',
        ], true)) {
            throw new FirstTrustAuthorityException('AUTHORITY_STATE_BINDING_MISMATCH');
        }
        throw new FirstTrustAuthorityException('AUTHORITY_STATE_INVALID');
    }
}
