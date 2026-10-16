<?php

namespace Infrastructure\FirstTrustAuthority;

use JsonException;

final class FirstTrustProtocolV1Parser
{
    public const MAX_ENVELOPE_BYTES = 32768;

    private const ENVELOPE_FIELDS = ['key_id', 'kind', 'payload', 'protocol', 'signature', 'signature_encoding'];

    private const KINDS = ['authorization', 'reservation', 'authority_state', 'consumption_receipt'];

    private const REFERENCE = '/\A[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,199}\z/D';

    private const KEY_ARN = '/\Aarn:aws:kms:[a-z0-9-]+:[0-9]{12}:key\/[A-Za-z0-9-]+\z/D';

    private const TIMESTAMP = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z\z/D';

    /** @return array{envelope: array<string,mixed>, payload: array<string,mixed>, payload_bytes: string, signature: string} */
    public function parse(string $serialized, string $expectedKind, string $invalidCode): array
    {
        if ($serialized === '' || strlen($serialized) > self::MAX_ENVELOPE_BYTES || ! mb_check_encoding($serialized, 'UTF-8')) {
            throw new FirstTrustAuthorityException($invalidCode);
        }

        $envelope = $this->decodeStrictObject($serialized, $invalidCode);
        $fields = array_keys($envelope);
        sort($fields, SORT_STRING);
        if ($fields !== self::ENVELOPE_FIELDS) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        if (($envelope['protocol'] ?? null) !== 1) {
            throw new FirstTrustAuthorityException('AUTHORITY_PROTOCOL_UNSUPPORTED');
        }
        if (! is_string($envelope['kind']) || ! in_array($envelope['kind'], self::KINDS, true)) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        if ($envelope['kind'] !== $expectedKind || ! is_string($envelope['key_id'])
            || preg_match(self::KEY_ARN, $envelope['key_id']) !== 1
            || $envelope['signature_encoding'] !== 'DER'
            || ! is_string($envelope['payload']) || ! is_string($envelope['signature'])) {
            throw new FirstTrustAuthorityException($invalidCode);
        }

        $payloadBytes = $this->decodeBase64Url($envelope['payload'], $invalidCode);
        $signature = $this->decodeBase64Url($envelope['signature'], $invalidCode);
        if ($payloadBytes === '' || $signature === '' || ! mb_check_encoding($payloadBytes, 'UTF-8')) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        $payload = $this->decodeStrictObject($payloadBytes, $invalidCode);
        if ($this->canonicalize($payload, $invalidCode) !== $payloadBytes) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        $this->validatePayload($payload, $expectedKind, $invalidCode);
        if ($payload['key_id'] !== $envelope['key_id']) {
            throw new FirstTrustAuthorityException($invalidCode);
        }

        return ['envelope' => $envelope, 'payload' => $payload, 'payload_bytes' => $payloadBytes, 'signature' => $signature];
    }

    /** @return array<string,mixed> */
    public function decodeStrictObject(string $json, string $invalidCode): array
    {
        $decoded = $this->decodeStrict($json, $invalidCode);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new FirstTrustAuthorityException($invalidCode);
        }

        return $decoded;
    }

    public function decodeStrict(string $json, string $invalidCode): mixed
    {
        if ($json === '' || ! mb_check_encoding($json, 'UTF-8') || $this->hasDuplicateObjectKey($json)) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        try {
            return json_decode($json, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
    }

    private function decodeBase64Url(string $value, string $invalidCode): string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
        if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $value) {
            throw new FirstTrustAuthorityException($invalidCode);
        }

        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function validatePayload(array $payload, string $kind, string $invalidCode): void
    {
        $common = ['protocol', 'kind', 'authority_reference', 'issuer', 'authorization_id', 'environment', 'installation_id', 'request_fingerprint', 'canonical_sha', 'key_id'];
        $specific = match ($kind) {
            'authorization' => ['audience', 'issued_at', 'not_before', 'expires_at'],
            'reservation' => ['execution_id', 'reservation_reference', 'reserved_at', 'commit_deadline', 'recovery_deadline'],
            'consumption_receipt' => ['execution_id', 'reservation_reference', 'reserved_at', 'consumption_reference', 'consumed_at'],
            'authority_state' => ['state', 'state_generated_at'],
            default => throw new FirstTrustAuthorityException($invalidCode),
        };
        $optional = $kind === 'authority_state' ? ['execution_id', 'reservation_reference', 'reserved_at', 'commit_deadline', 'recovery_deadline', 'consumption_reference', 'consumed_at', 'reservation_fingerprint', 'consumption_fingerprint', 'reservation_envelope', 'consumption_receipt'] : [];
        $required = array_merge($common, $specific);
        $allowed = array_merge($required, $optional);
        $actual = array_keys($payload);
        if (array_diff($required, $actual) !== [] || array_diff($actual, $allowed) !== []) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        if ($payload['protocol'] !== 1) {
            throw new FirstTrustAuthorityException('AUTHORITY_PROTOCOL_UNSUPPORTED');
        }
        if ($payload['kind'] !== $kind) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        foreach (['authority_reference', 'authorization_id', 'installation_id'] as $field) {
            $this->assertReference($payload[$field], $invalidCode);
        }
        if (! is_string($payload['issuer']) || $payload['issuer'] === '' || strlen($payload['issuer']) > 300
            || ! in_array($payload['environment'], ['OPERATIONAL', 'REHEARSAL'], true)
            || ! is_string($payload['request_fingerprint']) || preg_match('/\A[a-f0-9]{64}\z/D', $payload['request_fingerprint']) !== 1
            || ! is_string($payload['canonical_sha']) || preg_match('/\A[a-f0-9]{40}\z/D', $payload['canonical_sha']) !== 1
            || ! is_string($payload['key_id']) || preg_match(self::KEY_ARN, $payload['key_id']) !== 1) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        if ($kind === 'authorization') {
            if (! is_string($payload['audience']) || $payload['audience'] === '' || strlen($payload['audience']) > 200) {
                throw new FirstTrustAuthorityException($invalidCode);
            }
            $this->assertTimes($payload, ['issued_at', 'not_before', 'expires_at'], $invalidCode);

            return;
        }
        if ($kind === 'reservation') {
            $this->assertReference($payload['execution_id'], $invalidCode);
            $this->assertReference($payload['reservation_reference'], $invalidCode);
            $this->assertTimes($payload, ['reserved_at', 'commit_deadline', 'recovery_deadline'], $invalidCode);

            return;
        }
        if ($kind === 'consumption_receipt') {
            foreach (['execution_id', 'reservation_reference', 'consumption_reference'] as $field) {
                $this->assertReference($payload[$field], $invalidCode);
            }
            $this->assertTimes($payload, ['reserved_at', 'consumed_at'], $invalidCode);

            return;
        }
        $this->validateState($payload, $invalidCode);
    }

    /** @param array<string,mixed> $payload */
    private function validateState(array $payload, string $invalidCode): void
    {
        $states = ['AVAILABLE', 'RESERVED', 'CONSUMED', 'REVOKED', 'EXPIRED'];
        if (! in_array($payload['state'], $states, true)) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
        $this->assertTimes($payload, ['state_generated_at'], $invalidCode);
        $reservation = ['execution_id', 'reservation_reference', 'reserved_at', 'commit_deadline', 'recovery_deadline', 'reservation_fingerprint', 'reservation_envelope'];
        $consumption = ['consumption_reference', 'consumed_at', 'consumption_fingerprint', 'consumption_receipt'];
        $required = $payload['state'] === 'CONSUMED' ? array_merge($reservation, $consumption) : ($payload['state'] === 'RESERVED' ? $reservation : []);
        foreach (array_merge($reservation, $consumption) as $field) {
            $present = array_key_exists($field, $payload);
            if (in_array($field, $required, true) !== $present) {
                throw new FirstTrustAuthorityException($invalidCode);
            }
            if (! $present) {
                continue;
            }
            if (str_ends_with($field, '_at') || str_ends_with($field, '_deadline')) {
                $this->assertTimes($payload, [$field], $invalidCode);
            } elseif (str_ends_with($field, '_fingerprint')) {
                if (! is_string($payload[$field]) || preg_match('/\A[a-f0-9]{64}\z/D', $payload[$field]) !== 1) {
                    throw new FirstTrustAuthorityException($invalidCode);
                }
            } elseif (str_ends_with($field, '_envelope') || $field === 'consumption_receipt') {
                if (! is_string($payload[$field]) || $payload[$field] === '' || strlen($payload[$field]) > 32768) {
                    throw new FirstTrustAuthorityException($invalidCode);
                }
            } else {
                $this->assertReference($payload[$field], $invalidCode);
            }
        }
    }

    private function assertReference(mixed $value, string $invalidCode): void
    {
        if (! is_string($value) || preg_match(self::REFERENCE, $value) !== 1) {
            throw new FirstTrustAuthorityException($invalidCode);
        }
    }

    /** @param array<string,mixed> $payload @param list<string> $fields */
    private function assertTimes(array $payload, array $fields, string $invalidCode): void
    {
        foreach ($fields as $field) {
            if (! is_string($payload[$field] ?? null) || preg_match(self::TIMESTAMP, $payload[$field]) !== 1) {
                throw new FirstTrustAuthorityException($invalidCode);
            }
            $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $payload[$field], new \DateTimeZone('UTC'));
            if (! $time || $time->format('Y-m-d\TH:i:s.v\Z') !== $payload[$field]) {
                throw new FirstTrustAuthorityException($invalidCode);
            }
        }
    }

    private function canonicalize(mixed $value, string $invalidCode): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(fn ($v) => $this->canonicalize($v, $invalidCode), $value)).']';
            }
            ksort($value, SORT_STRING);
            $parts = [];
            foreach ($value as $key => $entry) {
                $parts[] = $this->canonicalize((string) $key, $invalidCode).':'.$this->canonicalize($entry, $invalidCode);
            }

            return '{'.implode(',', $parts).'}';
        }
        if (is_string($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        throw new FirstTrustAuthorityException($invalidCode);
    }

    private function hasDuplicateObjectKey(string $json): bool
    {
        $stack = [];
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            if ($json[$i] === '"') {
                $start = $i++;
                while ($i < $length) {
                    if ($json[$i] === '\\') {
                        $i += 2;

                        continue;
                    }
                    if ($json[$i] === '"') {
                        break;
                    }
                    $i++;
                }
                $raw = substr($json, $start, $i - $start + 1);
                $j = $i + 1;
                while ($j < $length && str_contains(" \t\r\n", $json[$j])) {
                    $j++;
                }
                if ($j < $length && $json[$j] === ':' && $stack !== []) {
                    try {
                        $key = json_decode($raw, true, 2, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        return true;
                    }
                    $top = count($stack) - 1;
                    if (($stack[$top]['type'] ?? null) === '{') {
                        if (isset($stack[$top]['keys'][$key])) {
                            return true;
                        }
                        $stack[$top]['keys'][$key] = true;
                    }
                }
            } elseif ($json[$i] === '{') {
                $stack[] = ['type' => '{', 'keys' => []];
            } elseif ($json[$i] === '[') {
                $stack[] = ['type' => '[', 'keys' => []];
            } elseif (($json[$i] === '}' || $json[$i] === ']') && $stack !== []) {
                array_pop($stack);
            }
        }

        return false;
    }
}
