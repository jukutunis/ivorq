<?php

namespace Tests\Unit\Infrastructure\FirstTrustAuthority;

use DateTimeImmutable;
use DateTimeZone;
use Infrastructure\FirstTrustAuthority\FirstTrustAuthorityException;
use Infrastructure\FirstTrustAuthority\FirstTrustEnvelopeVerifier;
use Infrastructure\FirstTrustAuthority\FirstTrustProtocolV1Parser;
use Infrastructure\FirstTrustAuthority\PinnedVerificationBundleLoader;
use Modules\Foundation\Authorization\Data\FirstTrustExpectedBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FirstTrustEnvelopeVerifierTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_it_verifies_authorization_offline_and_preserves_exact_bundle_fingerprint(): void
    {
        [$verifier, $bundleBytes] = $this->verifier();
        $result = $verifier->verifyAuthorization(FirstTrustTestFactory::fixture('authorization-envelope.json'), $this->binding());
        $this->assertSame('auth-1', $result->authorizationId);
        $this->assertSame(FirstTrustTestFactory::KEY_ID, $result->verifiedKeyId);
        $this->assertSame(hash('sha256', $bundleBytes), $result->verifierBundleFingerprint);
    }

    public function test_tampered_payload_signature_unknown_key_and_cross_kind_use_fail_closed(): void
    {
        [$verifier] = $this->verifier();
        $valid = FirstTrustTestFactory::fixture('authorization-envelope.json');
        $tampered = json_decode($valid, true);
        $payload = FirstTrustTestFactory::payload($valid);
        $payload['installation_id'] = 'tampered';
        $tampered['payload'] = $this->base64url(FirstTrustTestFactory::canonical($payload));
        $cases = [FirstTrustTestFactory::canonical($tampered)];
        $signature = json_decode($valid, true);
        $signature['signature'][5] = $signature['signature'][5] === 'A' ? 'B' : 'A';
        $cases[] = FirstTrustTestFactory::canonical($signature);
        $unknown = json_decode($valid, true);
        $unknownPayload = FirstTrustTestFactory::payload($valid);
        $unknown['key_id'] = $unknownPayload['key_id'] = 'arn:aws:kms:ap-southeast-1:123456789012:key/33333333-3333-4333-8333-333333333333';
        $unknown['payload'] = $this->base64url(FirstTrustTestFactory::canonical($unknownPayload));
        $cases[] = FirstTrustTestFactory::canonical($unknown);
        foreach ($cases as $case) {
            try {
                $verifier->verifyAuthorization($case, $this->binding());
                $this->fail('Invalid envelope was accepted.');
            } catch (FirstTrustAuthorityException $e) {
                $this->assertSame('AUTHORIZATION_INVALID', $e->getMessage());
            }
        }
        $this->expectExceptionMessage('AUTHORIZATION_INVALID');
        $verifier->verifyAuthorization(FirstTrustTestFactory::fixture('reservation-envelope.json'), $this->binding());
    }

    public function test_binding_and_time_failures_use_stable_codes(): void
    {
        [$verifier] = $this->verifier();
        $envelope = FirstTrustTestFactory::fixture('authorization-envelope.json');
        try {
            $verifier->verifyAuthorization($envelope, new FirstTrustExpectedBinding('OPERATIONAL', 'installation-1', str_repeat('a', 64), str_repeat('b', 40)));
            $this->fail();
        } catch (FirstTrustAuthorityException $e) {
            $this->assertSame('ENVIRONMENT_MISMATCH', $e->getMessage());
        }
        try {
            $verifier->verifyAuthorization($envelope, new FirstTrustExpectedBinding('REHEARSAL', 'other', str_repeat('a', 64), str_repeat('b', 40)));
            $this->fail();
        } catch (FirstTrustAuthorityException $e) {
            $this->assertSame('INSTALLATION_MISMATCH', $e->getMessage());
        }
        [$expired] = $this->verifier('verification-bundle.json', '2030-01-01T00:12:00.000Z');
        $this->expectExceptionMessage('AUTHORIZATION_EXPIRED');
        $expired->verifyAuthorization($envelope, $this->binding());
    }

    public function test_controlled_rotation_accepts_both_pinned_keys_and_rejects_a_third(): void
    {
        [$verifier] = $this->verifier('rotation-bundle.json');
        $this->assertSame(FirstTrustTestFactory::KEY_ID, $verifier->verifyAuthorization(FirstTrustTestFactory::fixture('authorization-envelope.json'), $this->binding())->verifiedKeyId);
        $this->assertSame(FirstTrustTestFactory::ROTATED_KEY_ID, $verifier->verifyAuthorization(FirstTrustTestFactory::fixture('authorization-envelope-rotated-key.json'), $this->binding())->verifiedKeyId);
        $unknown = json_decode(FirstTrustTestFactory::fixture('authorization-envelope.json'), true);
        $payload = FirstTrustTestFactory::payload(FirstTrustTestFactory::fixture('authorization-envelope.json'));
        $unknown['key_id'] = $payload['key_id'] = 'arn:aws:kms:ap-southeast-1:123456789012:key/33333333-3333-4333-8333-333333333333';
        $unknown['payload'] = $this->base64url(FirstTrustTestFactory::canonical($payload));
        $this->expectExceptionMessage('AUTHORIZATION_INVALID');
        $verifier->verifyAuthorization(FirstTrustTestFactory::canonical($unknown), $this->binding());
    }

    public function test_all_authority_states_and_exact_nested_evidence_are_verified(): void
    {
        [$verifier] = $this->verifier();
        foreach (['available' => 'AVAILABLE', 'reserved' => 'RESERVED', 'consumed' => 'CONSUMED', 'revoked' => 'REVOKED', 'expired' => 'EXPIRED'] as $fixture => $state) {
            $result = $verifier->verifyState(FirstTrustTestFactory::fixture('authority-state-'.$fixture.'.json'), 'auth-1', $this->binding());
            $this->assertSame($state, $result->state);
        }
        $consumed = $verifier->verifyState(FirstTrustTestFactory::fixture('authority-state-consumed.json'), 'auth-1', $this->binding());
        $this->assertSame(hash('sha256', FirstTrustTestFactory::fixture('consumption-receipt.json')), $consumed->consumptionFingerprint);
        $this->assertSame(hash('sha256', FirstTrustTestFactory::fixture('reservation-envelope.json')), $consumed->reservationFingerprint);
    }

    public function test_wrong_curve_and_private_key_bundles_are_rejected(): void
    {
        [$wrongCurve] = $this->verifier('wrong-curve-bundle.json');
        try {
            $wrongCurve->verifyAuthorization(FirstTrustTestFactory::fixture('authorization-envelope.json'), $this->binding());
            $this->fail();
        } catch (FirstTrustAuthorityException $e) {
            $this->assertSame('AUTHORITY_UNAVAILABLE', $e->getMessage());
        }

        $private = FirstTrustTestFactory::fixtureJson('verification-bundle.json');
        $private['spki_pem'] = "-----BEGIN PRIVATE KEY-----\nQUJD\n-----END PRIVATE KEY-----\n";
        $path = $this->temporaryFile(json_encode($private, JSON_THROW_ON_ERROR));
        $loader = new PinnedVerificationBundleLoader($path, 'REHEARSAL', 'https://issuer.test', 'ivorq.test');
        $this->expectExceptionMessage('AUTHORITY_UNAVAILABLE');
        $loader->load();
    }

    #[DataProvider('invalidStateEvidence')]
    public function test_nested_state_tampering_and_disagreement_fail_with_state_specific_codes(string $fixture, string $code, string $bundle = 'negative-verification-bundle.json'): void
    {
        [$verifier] = $this->verifier($bundle);
        $this->expectException(FirstTrustAuthorityException::class);
        $this->expectExceptionMessage($code);
        $verifier->verifyState(FirstTrustTestFactory::fixture($fixture), 'auth-1', $this->binding());
    }

    public static function invalidStateEvidence(): array
    {
        return [
            'nested reservation signature' => ['authority-state-nested-reservation-tampered.json', 'AUTHORITY_STATE_INVALID'],
            'nested receipt signature' => ['authority-state-nested-receipt-tampered.json', 'AUTHORITY_STATE_INVALID'],
            'reservation fingerprint' => ['authority-state-reservation-fingerprint-mismatch-v2.json', 'AUTHORITY_STATE_INVALID', 'mismatch-verification-bundle.json'],
            'consumption fingerprint' => ['authority-state-consumption-fingerprint-mismatch-v2.json', 'AUTHORITY_STATE_INVALID', 'mismatch-verification-bundle.json'],
            'outer nested execution' => ['authority-state-execution-mismatch-v2.json', 'AUTHORITY_STATE_BINDING_MISMATCH', 'mismatch-verification-bundle.json'],
            'outer nested authorization' => ['authority-state-authorization-mismatch.json', 'AUTHORITY_STATE_BINDING_MISMATCH'],
            'environment' => ['authority-state-environment-mismatch.json', 'AUTHORITY_STATE_BINDING_MISMATCH'],
            'issuer' => ['authority-state-issuer-mismatch.json', 'AUTHORITY_STATE_BINDING_MISMATCH'],
        ];
    }

    public function test_signed_state_authority_reference_mismatch_is_a_binding_mismatch(): void
    {
        [$verifier] = $this->verifier(expectedAuthorityReference: 'other-authority.test');
        $this->assertAuthorityCode('AUTHORITY_STATE_BINDING_MISMATCH', fn () => $verifier->verifyState(
            FirstTrustTestFactory::fixture('authority-state-available.json'),
            'auth-1',
            $this->binding(),
        ));
    }

    public function test_fixture_manifest_proves_exact_nonsecret_external_provenance(): void
    {
        $manifest = FirstTrustTestFactory::fixtureJson('fixture-manifest.json');
        $this->assertSame('658a2d694891bf667150779e6af965dd28a5f24e', $manifest['external_repository_sha']);
        $this->assertTrue($manifest['synthetic_nonsecret']);
        $this->assertFalse($manifest['private_key_persisted']);
        $directory = base_path('tests/Fixtures/first-trust-authority/v1');
        $actual = [];
        foreach (glob($directory.'/*') as $path) {
            if (basename($path) !== 'fixture-manifest.json') {
                $actual[basename($path)] = hash_file('sha256', $path);
            }
        }
        $declared = array_map(static fn (array $entry): string => $entry['sha256'], $manifest['fixtures']);
        ksort($actual);
        ksort($declared);
        $this->assertSame($actual, $declared);
    }

    public function test_authorization_claim_binding_time_and_key_windows_are_enforced(): void
    {
        $envelope = FirstTrustTestFactory::fixture('authorization-envelope.json');
        [$notYetValid] = $this->verifier('verification-bundle.json', '2029-12-31T23:58:00.000Z');
        $this->assertAuthorityCode('AUTHORIZATION_INVALID', fn () => $notYetValid->verifyAuthorization($envelope, $this->binding()));

        [$longLifetime] = $this->verifier('authorization-variant-bundle.json');
        $this->assertAuthorityCode('AUTHORIZATION_INVALID', fn () => $longLifetime->verifyAuthorization(FirstTrustTestFactory::fixture('authorization-long-lifetime.json'), $this->binding()));

        [$verifier] = $this->verifier();
        $this->assertAuthorityCode('REQUEST_FINGERPRINT_MISMATCH', fn () => $verifier->verifyAuthorization($envelope, new FirstTrustExpectedBinding('REHEARSAL', 'installation-1', str_repeat('c', 64), str_repeat('b', 40))));
        $this->assertAuthorityCode('CANONICAL_SHA_MISMATCH', fn () => $verifier->verifyAuthorization($envelope, new FirstTrustExpectedBinding('REHEARSAL', 'installation-1', str_repeat('a', 64), str_repeat('c', 40))));

        [$issuerMismatch] = $this->verifier(expectedIssuer: 'https://other.test');
        $this->assertAuthorityCode('AUTHORIZATION_INVALID', fn () => $issuerMismatch->verifyAuthorization($envelope, $this->binding()));
        [$audienceMismatch] = $this->verifier(expectedAudience: 'other-audience');
        $this->assertAuthorityCode('AUTHORIZATION_INVALID', fn () => $audienceMismatch->verifyAuthorization($envelope, $this->binding()));

        $futureBundle = FirstTrustTestFactory::fixtureJson('verification-bundle.json');
        $futureBundle['activated_at'] = '2030-01-01T00:01:00.000Z';
        $path = $this->temporaryFile(json_encode($futureBundle, JSON_THROW_ON_ERROR));
        $futureKey = $this->verifierFromPath($path);
        $this->assertAuthorityCode('AUTHORIZATION_INVALID', fn () => $futureKey->verifyAuthorization($envelope, $this->binding()));

        $rotation = FirstTrustTestFactory::fixtureJson('rotation-bundle.json');
        $path = $this->temporaryFile(json_encode($rotation[1], JSON_THROW_ON_ERROR));
        $wrongKey = $this->verifierFromPath($path);
        $this->assertAuthorityCode('AUTHORIZATION_INVALID', fn () => $wrongKey->verifyAuthorization($envelope, $this->binding()));
    }

    private function binding(): FirstTrustExpectedBinding
    {
        return new FirstTrustExpectedBinding('REHEARSAL', 'installation-1', str_repeat('a', 64), str_repeat('b', 40));
    }

    /** @return array{FirstTrustEnvelopeVerifier,string} */
    private function verifier(
        string $bundle = 'verification-bundle.json',
        string $now = '2030-01-01T00:05:00.000Z',
        string $expectedIssuer = 'https://issuer.test',
        string $expectedAudience = 'ivorq.test',
        string $expectedAuthorityReference = 'authority.test',
    ): array {
        $path = base_path('tests/Fixtures/first-trust-authority/v1/'.$bundle);
        $bytes = file_get_contents($path);
        $loader = new PinnedVerificationBundleLoader($path, 'REHEARSAL', 'https://issuer.test', 'ivorq.test');

        return [new FirstTrustEnvelopeVerifier(new FirstTrustProtocolV1Parser, $loader, $expectedAuthorityReference, $expectedIssuer, $expectedAudience, 60, static fn () => new DateTimeImmutable($now, new DateTimeZone('UTC'))), $bytes];
    }

    private function verifierFromPath(string $path): FirstTrustEnvelopeVerifier
    {
        return new FirstTrustEnvelopeVerifier(
            new FirstTrustProtocolV1Parser,
            new PinnedVerificationBundleLoader($path, 'REHEARSAL', 'https://issuer.test', 'ivorq.test'),
            'authority.test', 'https://issuer.test', 'ivorq.test', 60,
            static fn () => new DateTimeImmutable('2030-01-01T00:05:00.000Z', new DateTimeZone('UTC')),
        );
    }

    private function temporaryFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ivorq-bundle-');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function assertAuthorityCode(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected first-trust authority exception.');
        } catch (FirstTrustAuthorityException $exception) {
            $this->assertSame($code, $exception->getMessage());
        }
    }
}
