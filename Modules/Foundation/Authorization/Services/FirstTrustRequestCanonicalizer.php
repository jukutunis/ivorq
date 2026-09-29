<?php

namespace Modules\Foundation\Authorization\Services;

use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final class FirstTrustRequestCanonicalizer
{
    private const DOMAIN = "IVORQ-FIRST-TRUST-REQUEST-V1\0";

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws JsonException
     */
    public function canonicalBytes(array $request): string
    {
        return $this->encodeCanonical($this->normalize($request));
    }

    /**
     * @param  array<string, mixed>  $request
     *
     * @throws JsonException
     */
    public function fingerprint(array $request): string
    {
        return hash('sha256', self::DOMAIN.$this->canonicalBytes($request));
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function normalize(array $request): array
    {
        $this->assertExactKeys($request, [
            'protocol',
            'environment',
            'installation_id',
            'canonical_sha',
            'authority',
            'owner',
            'company',
            'property',
        ]);

        if ($request['protocol'] !== 1) {
            throw new InvalidArgumentException('FIRST_TRUST_REQUEST_PROTOCOL_INVALID');
        }

        $authority = $this->object($request['authority'], 'authority');
        $owner = $this->object($request['owner'], 'owner');
        $company = $this->object($request['company'], 'company');
        $property = $this->object($request['property'], 'property');

        $this->assertExactKeys($authority, ['reference', 'issuer', 'audience', 'verification_bundle_fingerprint']);
        $this->assertExactKeys($owner, ['name', 'email']);
        $this->assertExactKeys($company, ['name', 'slug']);
        $this->assertExactKeys($property, ['name', 'slug', 'code', 'timezone', 'currency']);

        $environment = strtoupper($this->text($request['environment'], 'environment', 20));
        if (! in_array($environment, ['OPERATIONAL', 'REHEARSAL'], true)) {
            throw new InvalidArgumentException('FIRST_TRUST_REQUEST_ENVIRONMENT_INVALID');
        }

        $installationId = $this->reference($request['installation_id'], 'installation_id');
        $canonicalSha = $this->text($request['canonical_sha'], 'canonical_sha', 40);
        if (preg_match('/\A[a-f0-9]{40}\z/', $canonicalSha) !== 1) {
            throw new InvalidArgumentException('FIRST_TRUST_REQUEST_CANONICAL_SHA_INVALID');
        }

        $verificationFingerprint = $this->text(
            $authority['verification_bundle_fingerprint'],
            'verification_bundle_fingerprint',
            64,
        );
        if (preg_match('/\A[a-f0-9]{64}\z/', $verificationFingerprint) !== 1) {
            throw new InvalidArgumentException('FIRST_TRUST_REQUEST_VERIFIER_FINGERPRINT_INVALID');
        }

        $ownerEmail = mb_strtolower($this->text($owner['email'], 'owner.email', 255));
        if (filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('FIRST_TRUST_REQUEST_OWNER_EMAIL_INVALID');
        }

        $companySlug = $this->slug($company['slug'], 'company.slug');
        $propertySlug = $this->slug($property['slug'], 'property.slug');

        $propertyCode = strtoupper($this->text($property['code'], 'property.code', 20));
        if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,19}\z/', $propertyCode) !== 1) {
            throw new InvalidArgumentException('FIRST_TRUST_REQUEST_PROPERTY_CODE_INVALID');
        }

        $currency = strtoupper($this->text($property['currency'], 'property.currency', 3));
        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException('FIRST_TRUST_REQUEST_PROPERTY_CURRENCY_INVALID');
        }

        $timezone = $this->text($property['timezone'], 'property.timezone', 100);
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('FIRST_TRUST_REQUEST_PROPERTY_TIMEZONE_INVALID');
        }

        return [
            'authority' => [
                'audience' => $this->text($authority['audience'], 'authority.audience', 200),
                'issuer' => $this->text($authority['issuer'], 'authority.issuer', 300),
                'reference' => $this->reference($authority['reference'], 'authority.reference'),
                'verification_bundle_fingerprint' => $verificationFingerprint,
            ],
            'canonical_sha' => $canonicalSha,
            'company' => [
                'name' => $this->text($company['name'], 'company.name', 255),
                'slug' => $companySlug,
            ],
            'environment' => $environment,
            'installation_id' => $installationId,
            'owner' => [
                'email' => $ownerEmail,
                'name' => $this->text($owner['name'], 'owner.name', 255),
            ],
            'property' => [
                'code' => $propertyCode,
                'currency' => $currency,
                'name' => $this->text($property['name'], 'property.name', 255),
                'slug' => $propertySlug,
                'timezone' => $timezone,
            ],
            'protocol' => 1,
        ];
    }

    /** @param array<string, mixed> $value */
    private function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new InvalidArgumentException('FIRST_TRUST_REQUEST_FIELDS_INVALID');
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $field): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("FIRST_TRUST_REQUEST_{$field}_INVALID");
        }

        return $value;
    }

    private function text(mixed $value, string $field, int $maximumBytes): string
    {
        if (! is_string($value)
            || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException("FIRST_TRUST_REQUEST_{$field}_INVALID");
        }

        $normalized = trim($value);
        if ($normalized === '' || strlen($normalized) > $maximumBytes) {
            throw new InvalidArgumentException("FIRST_TRUST_REQUEST_{$field}_INVALID");
        }

        return $normalized;
    }

    private function reference(mixed $value, string $field): string
    {
        $reference = $this->text($value, $field, 200);
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/#-]*\z/', $reference) !== 1) {
            throw new InvalidArgumentException("FIRST_TRUST_REQUEST_{$field}_INVALID");
        }

        return $reference;
    }

    private function slug(mixed $value, string $field): string
    {
        $slug = $this->text($value, $field, 255);
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
            throw new InvalidArgumentException("FIRST_TRUST_REQUEST_{$field}_INVALID");
        }

        return $slug;
    }

    /** @throws JsonException */
    private function encodeCanonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $encoded = array_map(fn (mixed $entry): string => $this->encodeCanonical($entry), $value);

                return '['.implode(',', $encoded).']';
            }

            ksort($value, SORT_STRING);
            $encoded = [];
            foreach ($value as $key => $entry) {
                $encoded[] = $this->encodeCanonical((string) $key).':'.$this->encodeCanonical($entry);
            }

            return '{'.implode(',', $encoded).'}';
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

        throw new InvalidArgumentException('FIRST_TRUST_REQUEST_CANONICAL_VALUE_INVALID');
    }
}
