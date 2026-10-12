<?php

namespace Infrastructure\FirstTrustAuthority;

use Aws\Credentials\CredentialsInterface;
use Aws\Signature\SignatureV4;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use JsonException;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Data\FirstTrustExpectedBinding;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorityState;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorization;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustConsumption;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustReservation;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class AwsFirstTrustAuthority implements FirstTrustAuthority
{
    private const MAX_RESPONSE_BYTES = 262144;
    private const REMOTE_CODES = [
        'AUTHORIZATION_INVALID', 'AUTHORIZATION_EXPIRED', 'AUTHORIZATION_REVOKED',
        'ENVIRONMENT_MISMATCH', 'INSTALLATION_MISMATCH', 'CANONICAL_SHA_MISMATCH',
        'REQUEST_FINGERPRINT_MISMATCH', 'ALREADY_RESERVED_OTHER_EXECUTION',
        'RESERVATION_EXPIRED', 'ALREADY_CONSUMED_OTHER_EXECUTION', 'RECEIPT_INVALID',
        'AUTHORITY_RECOVERY_REQUIRED', 'AUTHORITY_STATE_INVALID', 'AUTHORITY_STATE_BINDING_MISMATCH',
    ];

    /** @param callable():mixed $credentialProvider @param callable():string $regionProvider */
    public function __construct(
        private readonly FirstTrustEnvelopeVerifier $verifier,
        private readonly ClientInterface $http,
        private readonly mixed $credentialProvider,
        private readonly mixed $regionProvider,
        private readonly string $baseUrl,
        private readonly float $connectTimeout = 2.0,
        private readonly float $requestTimeout = 5.0,
    ) {
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
        }
    }

    public function verifyAuthorization(string $serializedEnvelope, FirstTrustExpectedBinding $binding): VerifiedFirstTrustAuthorization
    {
        return $this->verifier->verifyAuthorization($serializedEnvelope, $binding);
    }

    public function reserve(string $serializedAuthorization, string $executionId, FirstTrustExpectedBinding $binding): VerifiedFirstTrustReservation
    {
        $authorization = $this->verifier->verifyAuthorization($serializedAuthorization, $binding);
        $response = $this->call('reserve', $authorization->authorizationId, [
            'authorization_envelope' => $serializedAuthorization,
            'execution_id' => $executionId,
            'expected_binding' => $this->binding($binding),
        ], false);
        return $this->verifier->verifyReservation($this->singleString($response, 'reservation_envelope', 'AUTHORIZATION_INVALID'), $authorization->authorizationId, $executionId, $binding);
    }

    public function consume(string $authorizationId, string $executionId, FirstTrustExpectedBinding $binding): VerifiedFirstTrustConsumption
    {
        $response = $this->call('consume', $authorizationId, [
            'execution_id' => $executionId,
            'expected_binding' => $this->binding($binding),
        ], false);
        return $this->verifier->verifyConsumption($this->singleString($response, 'consumption_receipt', 'RECEIPT_INVALID'), $authorizationId, $executionId, $binding);
    }

    public function state(string $authorizationId, FirstTrustExpectedBinding $binding): VerifiedFirstTrustAuthorityState
    {
        $response = $this->call('state', $authorizationId, ['expected_binding' => $this->binding($binding)], true);
        return $this->verifier->verifyState($this->singleString($response, 'authority_state_envelope', 'AUTHORITY_STATE_INVALID'), $authorizationId, $binding);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function call(string $operation, string $authorizationId, array $body, bool $state): array
    {
        try {
            $provided = ($this->credentialProvider)();
            $credentials = $provided instanceof PromiseInterface ? $provided->wait() : $provided;
            $region = ($this->regionProvider)();
            if (! $credentials instanceof CredentialsInterface || ! is_string($region) || preg_match('/\A[a-z]{2}(?:-gov)?-[a-z0-9-]+-[0-9]\z/D', $region) !== 1) {
                throw new FirstTrustAuthorityException('AUTHORITY_CREDENTIAL_REJECTED');
            }
        } catch (FirstTrustAuthorityException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new FirstTrustAuthorityException('AUTHORITY_CREDENTIAL_REJECTED', $e);
        }

        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $url = rtrim($this->baseUrl, '/').'/v1/authorizations/'.rawurlencode($authorizationId).'/'.$operation;
        $request = new Request('POST', $url, ['Content-Type' => 'application/json', 'Accept' => 'application/json'], $json);
        $signed = (new SignatureV4('execute-api', $region))->signRequest($request, $credentials);
        try {
            $response = $this->http->send($signed, [
                'http_errors' => false,
                'allow_redirects' => false,
                'connect_timeout' => $this->connectTimeout,
                'timeout' => $this->requestTimeout,
            ]);
        } catch (Throwable $e) {
            throw new FirstTrustAuthorityException($state ? 'AUTHORITY_STATE_UNAVAILABLE' : 'AUTHORITY_UNAVAILABLE', $e);
        }
        return $this->decodeResponse($response, $state);
    }

    /** @return array<string,mixed> */
    private function decodeResponse(ResponseInterface $response, bool $state): array
    {
        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) throw new FirstTrustAuthorityException($state ? 'AUTHORITY_STATE_UNAVAILABLE' : 'AUTHORITY_UNAVAILABLE');
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared !== '' && (! ctype_digit($declared) || (int) $declared > self::MAX_RESPONSE_BYTES)) throw new FirstTrustAuthorityException($state ? 'AUTHORITY_STATE_INVALID' : 'AUTHORIZATION_INVALID');
        $stream = $response->getBody();
        $body = '';
        while (! $stream->eof()) {
            $body .= $stream->read(min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
            if (strlen($body) > self::MAX_RESPONSE_BYTES) throw new FirstTrustAuthorityException($state ? 'AUTHORITY_STATE_INVALID' : 'AUTHORIZATION_INVALID');
        }
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new FirstTrustAuthorityException($status >= 200 && $status < 300 ? ($state ? 'AUTHORITY_STATE_INVALID' : 'AUTHORIZATION_INVALID') : ($state ? 'AUTHORITY_STATE_UNAVAILABLE' : 'AUTHORITY_UNAVAILABLE'));
        }
        if (! is_array($decoded) || array_is_list($decoded)) throw new FirstTrustAuthorityException($state ? 'AUTHORITY_STATE_INVALID' : 'AUTHORIZATION_INVALID');
        if ($status < 200 || $status >= 300) {
            $error = $decoded['error'] ?? null;
            $errorFields = is_array($error) ? array_keys($error) : [];
            sort($errorFields, SORT_STRING);
            if (array_keys($decoded) === ['error'] && is_array($error) && ! array_is_list($error)
                && $errorFields === ['code', 'message'] && is_string($error['code']) && is_string($error['message'])
                && in_array($error['code'], self::REMOTE_CODES, true)) throw new FirstTrustAuthorityException($error['code']);
            throw new FirstTrustAuthorityException($state ? 'AUTHORITY_STATE_UNAVAILABLE' : 'AUTHORITY_UNAVAILABLE');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $response */
    private function singleString(array $response, string $field, string $invalidCode): string
    {
        if (array_keys($response) !== [$field] || ! is_string($response[$field]) || $response[$field] === '') throw new FirstTrustAuthorityException($invalidCode);
        return $response[$field];
    }

    /** @return array{environment:string,installation_id:string,request_fingerprint:string,canonical_sha:string} */
    private function binding(FirstTrustExpectedBinding $binding): array
    {
        return ['environment' => $binding->environment, 'installation_id' => $binding->installationId, 'request_fingerprint' => $binding->requestFingerprint, 'canonical_sha' => $binding->canonicalSha];
    }

    public static function resolveExternalRegion(): string
    {
        foreach (['AWS_REGION', 'AWS_DEFAULT_REGION'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') return $value;
        }
        $profile = getenv('AWS_PROFILE');
        $profile = is_string($profile) && $profile !== '' ? $profile : 'default';
        $configured = getenv('AWS_CONFIG_FILE');
        $home = getenv('USERPROFILE');
        $path = is_string($configured) && $configured !== '' ? $configured : (is_string($home) ? $home.'/.aws/config' : '');
        if ($path !== '' && is_file($path) && is_readable($path)) {
            $profiles = parse_ini_file($path, true, INI_SCANNER_RAW);
            $section = $profile === 'default' ? 'default' : 'profile '.$profile;
            $region = is_array($profiles) ? ($profiles[$section]['region'] ?? null) : null;
            if (is_string($region) && $region !== '') return $region;
        }
        throw new FirstTrustAuthorityException('AUTHORITY_CREDENTIAL_REJECTED');
    }
}
