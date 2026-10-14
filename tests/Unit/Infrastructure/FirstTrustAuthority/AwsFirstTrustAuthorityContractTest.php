<?php

namespace Tests\Unit\Infrastructure\FirstTrustAuthority;

use Aws\Credentials\Credentials;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Infrastructure\FirstTrustAuthority\AwsFirstTrustAuthority;
use Infrastructure\FirstTrustAuthority\FirstTrustAuthorityException;
use Infrastructure\FirstTrustAuthority\FirstTrustEnvelopeVerifier;
use Infrastructure\FirstTrustAuthority\FirstTrustProtocolV1Parser;
use Infrastructure\FirstTrustAuthority\PinnedVerificationBundleLoader;
use Infrastructure\FirstTrustAuthority\UnconfiguredFirstTrustAuthority;
use Modules\Foundation\Authorization\AuthorizationServiceProvider;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Data\FirstTrustExpectedBinding;
use Tests\TestCase;

final class AwsFirstTrustAuthorityContractTest extends TestCase
{
    public function test_verify_authorization_is_fully_offline(): void
    {
        $credentialsResolved = 0;
        [$authority, $history] = $this->authority([], function () use (&$credentialsResolved) {
            $credentialsResolved++;
            throw new \RuntimeException('must not resolve');
        });
        $verified = $authority->verifyAuthorization(FirstTrustTestFactory::fixture('authorization-envelope.json'), $this->binding());
        $this->assertSame('auth-1', $verified->authorizationId);
        $this->assertSame(0, $credentialsResolved);
        $this->assertCount(0, $history);
    }

    public function test_reserve_uses_exact_route_body_and_execute_api_session_sigv4(): void
    {
        [$authority, $history] = $this->authority([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['reservation_envelope' => FirstTrustTestFactory::fixture('reservation-envelope.json')], JSON_THROW_ON_ERROR)),
        ]);
        $result = $authority->reserve(FirstTrustTestFactory::fixture('authorization-envelope.json'), 'execution-1', $this->binding());
        $this->assertSame('reservation-1', $result->reservationReference);
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/authorizations/auth-1/reserve', $request->getUri()->getPath());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $body = json_decode((string) $request->getBody(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame(['authorization_envelope', 'execution_id', 'expected_binding'], array_keys($body));
        $this->assertSame('execution-1', $body['execution_id']);
        $this->assertSame($this->expectedBindingArray(), $body['expected_binding']);
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 ', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('/ap-southeast-1/execute-api/aws4_request', $request->getHeaderLine('Authorization'));
        $this->assertNotSame('', $request->getHeaderLine('X-Amz-Date'));
        $this->assertSame('synthetic-session-token', $request->getHeaderLine('X-Amz-Security-Token'));
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertSame(2.0, $history[0]['options']['connect_timeout']);
        $this->assertSame(5.0, $history[0]['options']['timeout']);
    }

    public function test_consume_and_state_use_exact_protocol_contracts(): void
    {
        [$authority, $history] = $this->authority([
            new Response(200, [], json_encode(['consumption_receipt' => FirstTrustTestFactory::fixture('consumption-receipt.json')], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['authority_state_envelope' => FirstTrustTestFactory::fixture('authority-state-consumed.json')], JSON_THROW_ON_ERROR)),
        ]);
        $consumption = $authority->consume('auth-1', 'execution-1', $this->binding());
        $state = $authority->state('auth-1', $this->binding());
        $this->assertSame('consumption-1', $consumption->consumptionReference);
        $this->assertSame('CONSUMED', $state->state);
        $this->assertSame('/v1/authorizations/auth-1/consume', $history[0]['request']->getUri()->getPath());
        $this->assertSame(['execution_id', 'expected_binding'], array_keys(json_decode((string) $history[0]['request']->getBody(), true)));
        $this->assertSame('/v1/authorizations/auth-1/state', $history[1]['request']->getUri()->getPath());
        $this->assertSame(['expected_binding'], array_keys(json_decode((string) $history[1]['request']->getBody(), true)));
    }

    public function test_all_signed_state_variants_map_to_the_exact_dto(): void
    {
        foreach (['available' => 'AVAILABLE', 'reserved' => 'RESERVED', 'consumed' => 'CONSUMED', 'revoked' => 'REVOKED', 'expired' => 'EXPIRED'] as $fixture => $expected) {
            [$authority, $history] = $this->authority([
                new Response(200, [], json_encode(['authority_state_envelope' => FirstTrustTestFactory::fixture("authority-state-{$fixture}.json")], JSON_THROW_ON_ERROR)),
            ]);
            $this->assertSame($expected, $authority->state('auth-1', $this->binding())->state);
            $this->assertCount(1, $history);
        }
    }

    public function test_reserve_rejects_server_failure_tampering_and_binding_mismatch(): void
    {
        [$serverFailure] = $this->authority([new Response(503, [], 'AWS_REMOTE_SECRET_MARKER')]);
        $this->assertAuthorityCode('AUTHORITY_UNAVAILABLE', fn () => $serverFailure->reserve(FirstTrustTestFactory::fixture('authorization-envelope.json'), 'execution-1', $this->binding()));

        $tampered = $this->tamperSignature('reservation-envelope.json');
        [$invalidSignature] = $this->authority([new Response(200, [], json_encode(['reservation_envelope' => $tampered], JSON_THROW_ON_ERROR))]);
        $this->assertAuthorityCode('AUTHORIZATION_INVALID', fn () => $invalidSignature->reserve(FirstTrustTestFactory::fixture('authorization-envelope.json'), 'execution-1', $this->binding()));

        [$bindingMismatch] = $this->authority([new Response(200, [], json_encode(['reservation_envelope' => FirstTrustTestFactory::fixture('reservation-envelope.json')], JSON_THROW_ON_ERROR))]);
        $wrongBinding = new FirstTrustExpectedBinding('REHEARSAL', 'installation-1', str_repeat('c', 64), str_repeat('b', 40));
        $this->assertAuthorityCode('REQUEST_FINGERPRINT_MISMATCH', fn () => $bindingMismatch->reserve(FirstTrustTestFactory::fixture('authorization-envelope.json'), 'execution-1', $wrongBinding));
    }

    public function test_consume_rejects_server_failure_conflict_invalid_receipt_and_tampering(): void
    {
        [$serverFailure] = $this->authority([new Response(500, [], 'AWS_REMOTE_SECRET_MARKER')]);
        $this->assertAuthorityCode('AUTHORITY_UNAVAILABLE', fn () => $serverFailure->consume('auth-1', 'execution-1', $this->binding()));

        [$conflict] = $this->authority([new Response(409, [], '{"error":{"code":"ALREADY_CONSUMED_OTHER_EXECUTION","message":"conflict"}}')]);
        $this->assertAuthorityCode('ALREADY_CONSUMED_OTHER_EXECUTION', fn () => $conflict->consume('auth-1', 'execution-1', $this->binding()));

        [$invalid] = $this->authority([new Response(422, [], '{"error":{"code":"RECEIPT_INVALID","message":"invalid"}}')]);
        $this->assertAuthorityCode('RECEIPT_INVALID', fn () => $invalid->consume('auth-1', 'execution-1', $this->binding()));

        [$tampered] = $this->authority([new Response(200, [], json_encode(['consumption_receipt' => $this->tamperSignature('consumption-receipt.json')], JSON_THROW_ON_ERROR))]);
        $this->assertAuthorityCode('RECEIPT_INVALID', fn () => $tampered->consume('auth-1', 'execution-1', $this->binding()));
    }

    public function test_transport_status_redirect_and_stable_remote_errors_map_without_retry(): void
    {
        $marker = 'AWS_PROVIDER_PRIVATE_DETAIL_DO_NOT_LEAK';
        [$timeout, $timeoutHistory] = $this->authority([
            new ConnectException($marker, new Request('POST', 'https://authority.test')),
        ]);
        try {
            $timeout->reserve(FirstTrustTestFactory::fixture('authorization-envelope.json'), 'execution-1', $this->binding());
            $this->fail();
        } catch (FirstTrustAuthorityException $e) {
            $this->assertSame('AUTHORITY_UNAVAILABLE', $e->getMessage());
            $this->assertStringNotContainsString($marker, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertCount(1, $timeoutHistory);

        [$redirect, $redirectHistory] = $this->authority([new Response(302, ['Location' => 'https://evil.test'], '')]);
        try {
            $redirect->consume('auth-1', 'execution-1', $this->binding());
            $this->fail();
        } catch (FirstTrustAuthorityException $e) {
            $this->assertSame('AUTHORITY_UNAVAILABLE', $e->getMessage());
        }
        $this->assertCount(1, $redirectHistory);

        [$stable] = $this->authority([new Response(409, [], '{"error":{"message":"safe","code":"ALREADY_RESERVED_OTHER_EXECUTION"}}')]);
        $this->expectExceptionMessage('ALREADY_RESERVED_OTHER_EXECUTION');
        $stable->reserve(FirstTrustTestFactory::fixture('authorization-envelope.json'), 'execution-1', $this->binding());
    }

    public function test_state_timeout_and_malformed_success_use_state_specific_errors(): void
    {
        [$timeout] = $this->authority([new ConnectException('private', new Request('POST', 'https://authority.test'))]);
        try {
            $timeout->state('auth-1', $this->binding());
            $this->fail();
        } catch (FirstTrustAuthorityException $e) {
            $this->assertSame('AUTHORITY_STATE_UNAVAILABLE', $e->getMessage());
        }

        [$malformed] = $this->authority([new Response(200, [], '{"authority_state_envelope":"not-an-envelope"}')]);
        $this->expectExceptionMessage('AUTHORITY_STATE_INVALID');
        $malformed->state('auth-1', $this->binding());
    }

    public function test_state_server_failure_and_binding_mismatch_use_state_specific_errors(): void
    {
        [$serverFailure] = $this->authority([new Response(500, [], 'AWS_REMOTE_SECRET_MARKER')]);
        $this->assertAuthorityCode('AUTHORITY_STATE_UNAVAILABLE', fn () => $serverFailure->state('auth-1', $this->binding()));

        [$bindingMismatch] = $this->authority([new Response(200, [], json_encode(['authority_state_envelope' => FirstTrustTestFactory::fixture('authority-state-available.json')], JSON_THROW_ON_ERROR))]);
        $wrongBinding = new FirstTrustExpectedBinding('OPERATIONAL', 'installation-1', str_repeat('a', 64), str_repeat('b', 40));
        $this->assertAuthorityCode('AUTHORITY_STATE_BINDING_MISMATCH', fn () => $bindingMismatch->state('auth-1', $wrongBinding));
    }

    public function test_credential_failure_never_issues_an_unsigned_request(): void
    {
        [$authority, $history] = $this->authority([], static fn () => throw new \RuntimeException('AWS_PROVIDER_PRIVATE_DETAIL_DO_NOT_LEAK'));
        try {
            $authority->consume('auth-1', 'execution-1', $this->binding());
            $this->fail();
        } catch (FirstTrustAuthorityException $e) {
            $this->assertSame('AUTHORITY_CREDENTIAL_REJECTED', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertCount(0, $history);
    }

    public function test_credential_status_oversized_response_and_duplicate_json_fail_closed(): void
    {
        [$rejected] = $this->authority([new Response(403, [], 'AWS_REMOTE_SECRET_MARKER')]);
        try {
            $rejected->consume('auth-1', 'execution-1', $this->binding());
            $this->fail();
        } catch (FirstTrustAuthorityException $e) {
            $this->assertSame('AUTHORITY_CREDENTIAL_REJECTED', $e->getMessage());
            $this->assertStringNotContainsString('AWS_REMOTE_SECRET_MARKER', $e->getMessage());
        }

        [$oversized] = $this->authority([new Response(200, ['Content-Length' => '262145'], '')]);
        try {
            $oversized->consume('auth-1', 'execution-1', $this->binding());
            $this->fail();
        } catch (FirstTrustAuthorityException $e) {
            $this->assertSame('RECEIPT_INVALID', $e->getMessage());
        }

        [$streamedOversized] = $this->authority([new Response(200, [], str_repeat('x', 262145))]);
        $this->assertAuthorityCode('RECEIPT_INVALID', fn () => $streamedOversized->consume('auth-1', 'execution-1', $this->binding()));

        [$duplicate] = $this->authority([new Response(200, [], '{"consumption_receipt":"a","consumption_receipt":"b"}')]);
        $this->expectExceptionMessage('RECEIPT_INVALID');
        $duplicate->consume('auth-1', 'execution-1', $this->binding());
    }

    public function test_official_sdk_region_resolver_uses_environment_and_shared_profile(): void
    {
        $originals = [];
        foreach (['AWS_REGION', 'AWS_PROFILE', 'AWS_CONFIG_FILE'] as $name) {
            $originals[$name] = getenv($name);
        }
        $config = tempnam(sys_get_temp_dir(), 'aws-config-');
        file_put_contents($config, "[profile review]\nregion = ap-southeast-2\n");
        try {
            putenv('AWS_REGION=ap-southeast-1');
            $this->assertSame('ap-southeast-1', AwsFirstTrustAuthority::resolveExternalRegion());
            putenv('AWS_REGION');
            putenv('AWS_PROFILE=review');
            putenv('AWS_CONFIG_FILE='.$config);
            $this->assertSame('ap-southeast-2', AwsFirstTrustAuthority::resolveExternalRegion());
        } finally {
            @unlink($config);
            foreach ($originals as $name => $value) {
                putenv($value === false ? $name : $name.'='.$value);
            }
        }
    }

    public function test_service_provider_is_fail_closed_and_complete_resolution_has_no_external_side_effect(): void
    {
        $original = config('first-trust');
        try {
            config(['first-trust' => [
                'environment' => null, 'installation_id' => null, 'release_sha' => null,
                'authority' => ['reference' => null, 'issuer' => null, 'audience' => null, 'base_url' => null, 'verification_bundle_path' => null],
            ]]);
            app()->forgetInstance(FirstTrustAuthority::class);
            (new AuthorizationServiceProvider(app()))->register();
            $this->assertInstanceOf(UnconfiguredFirstTrustAuthority::class, app()->make(FirstTrustAuthority::class));

            config(['first-trust' => [
                'environment' => 'REHEARSAL', 'installation_id' => 'installation-1', 'release_sha' => str_repeat('b', 40),
                'authority' => [
                    'reference' => 'authority.test', 'issuer' => 'https://issuer.test', 'audience' => 'ivorq.test',
                    'base_url' => 'https://authority.test',
                    'verification_bundle_path' => base_path('tests/Fixtures/first-trust-authority/v1/verification-bundle.json'),
                ],
            ]]);
            app()->forgetInstance(FirstTrustAuthority::class);
            (new AuthorizationServiceProvider(app()))->register();
            $this->assertInstanceOf(AwsFirstTrustAuthority::class, app()->make(FirstTrustAuthority::class));
        } finally {
            config(['first-trust' => $original]);
            app()->forgetInstance(FirstTrustAuthority::class);
            (new AuthorizationServiceProvider(app()))->register();
        }
    }

    /** @param list<mixed> $queue @return array{AwsFirstTrustAuthority,\ArrayObject<int,array<string,mixed>>} */
    private function authority(array $queue, ?callable $credentials = null): array
    {
        $history = new \ArrayObject;
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        $bundle = base_path('tests/Fixtures/first-trust-authority/v1/verification-bundle.json');
        $verifier = new FirstTrustEnvelopeVerifier(
            new FirstTrustProtocolV1Parser,
            new PinnedVerificationBundleLoader($bundle, 'REHEARSAL', 'https://issuer.test', 'ivorq.test'),
            'authority.test', 'https://issuer.test', 'ivorq.test', 60,
            static fn () => new DateTimeImmutable('2030-01-01T00:05:00.000Z', new DateTimeZone('UTC')),
        );
        $credentials ??= static fn () => new Credentials('ASIASYNTHETICTEST', 'synthetic-secret-not-real', 'synthetic-session-token');

        return [new AwsFirstTrustAuthority($verifier, new Client(['handler' => $stack]), $credentials, static fn () => 'ap-southeast-1', 'https://authority.test'), $history];
    }

    private function binding(): FirstTrustExpectedBinding
    {
        return new FirstTrustExpectedBinding('REHEARSAL', 'installation-1', str_repeat('a', 64), str_repeat('b', 40));
    }

    /** @return array<string,string> */
    private function expectedBindingArray(): array
    {
        return ['environment' => 'REHEARSAL', 'installation_id' => 'installation-1', 'request_fingerprint' => str_repeat('a', 64), 'canonical_sha' => str_repeat('b', 40)];
    }

    private function tamperSignature(string $fixture): string
    {
        $envelope = json_decode(FirstTrustTestFactory::fixture($fixture), true, 16, JSON_THROW_ON_ERROR);
        $envelope['signature'][0] = $envelope['signature'][0] === 'A' ? 'B' : 'A';

        return FirstTrustTestFactory::canonical($envelope);
    }

    private function assertAuthorityCode(string $expected, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected first-trust authority exception.');
        } catch (FirstTrustAuthorityException $exception) {
            $this->assertSame($expected, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }
}
