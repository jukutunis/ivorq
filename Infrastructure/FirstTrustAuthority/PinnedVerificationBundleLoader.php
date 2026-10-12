<?php

namespace Infrastructure\FirstTrustAuthority;

use DateTimeImmutable;
use JsonException;
use OpenSSLAsymmetricKey;

final class PinnedVerificationBundleLoader
{
    private const MAX_BUNDLE_BYTES = 262144;
    private const FIELDS = ['activated_at', 'audience', 'environment', 'issuer', 'kms_key_arn', 'protocol', 'retired_at', 'sha256_fingerprint', 'spki_pem'];

    /** @var array{fingerprint:string,keys:array<string,array{pem:string,activated_at:DateTimeImmutable,retired_at:?DateTimeImmutable}>}|null */
    private ?array $loaded = null;

    public function __construct(
        private readonly string $path,
        private readonly string $environment,
        private readonly string $issuer,
        private readonly string $audience,
    ) {}

    /** @return array{fingerprint:string,keys:array<string,array{pem:string,activated_at:DateTimeImmutable,retired_at:?DateTimeImmutable}>} */
    public function load(): array
    {
        if ($this->loaded !== null) return $this->loaded;
        if ($this->path === '' || ! is_file($this->path) || ! is_readable($this->path)) {
            throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
        }
        $size = filesize($this->path);
        if (! is_int($size) || $size < 1 || $size > self::MAX_BUNDLE_BYTES) {
            throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
        }
        $bytes = file_get_contents($this->path);
        if (! is_string($bytes) || strlen($bytes) !== $size || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
        }
        try {
            $decoded = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
        }
        $entries = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        if ($entries === []) throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');

        $keys = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || array_is_list($entry)) throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
            $fields = array_keys($entry);
            sort($fields, SORT_STRING);
            if ($fields !== self::FIELDS || $entry['protocol'] !== 1
                || $entry['environment'] !== $this->environment
                || $entry['issuer'] !== $this->issuer || $entry['audience'] !== $this->audience
                || ! is_string($entry['kms_key_arn'])
                || preg_match('/\Aarn:aws:kms:[a-z0-9-]+:[0-9]{12}:key\/[A-Za-z0-9-]+\z/D', $entry['kms_key_arn']) !== 1
                || isset($keys[$entry['kms_key_arn']])) {
                throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
            }
            $pem = $entry['spki_pem'];
            if (! is_string($pem) || str_contains($pem, 'PRIVATE KEY')
                || preg_match('/\A-----BEGIN PUBLIC KEY-----\n[A-Za-z0-9+\/=\n]+-----END PUBLIC KEY-----\n\z/D', $pem) !== 1) {
                throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
            }
            $key = openssl_pkey_get_public($pem);
            if (! $key instanceof OpenSSLAsymmetricKey) throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
            $details = openssl_pkey_get_details($key);
            if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['bits'] ?? null) !== 256
                || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
                throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
            }
            $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '', true);
            if (! is_string($der) || ! is_string($entry['sha256_fingerprint'])
                || ! hash_equals(hash('sha256', $der), $entry['sha256_fingerprint'])) {
                throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
            }
            $activated = $this->time($entry['activated_at']);
            $retired = $entry['retired_at'] === null ? null : $this->time($entry['retired_at']);
            if ($retired !== null && $retired <= $activated) throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
            $keys[$entry['kms_key_arn']] = ['pem' => $pem, 'activated_at' => $activated, 'retired_at' => $retired];
        }

        return $this->loaded = ['fingerprint' => hash('sha256', $bytes), 'keys' => $keys];
    }

    private function time(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z\z/D', $value) !== 1) {
            throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value);
        if (! $time || $time->format('Y-m-d\TH:i:s.v\Z') !== $value) throw new FirstTrustAuthorityException('AUTHORITY_UNAVAILABLE');
        return $time;
    }
}
