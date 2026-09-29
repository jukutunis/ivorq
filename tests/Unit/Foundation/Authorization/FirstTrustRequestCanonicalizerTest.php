<?php

namespace Tests\Unit\Foundation\Authorization;

use InvalidArgumentException;
use Modules\Foundation\Authorization\Services\FirstTrustRequestCanonicalizer;
use PHPUnit\Framework\TestCase;

class FirstTrustRequestCanonicalizerTest extends TestCase
{
    public function test_it_freezes_exact_canonical_bytes_and_fingerprint(): void
    {
        $canonicalizer = new FirstTrustRequestCanonicalizer;

        $expected = '{"authority":{"audience":"ivorq-installation","issuer":"https://authority.example.test/operational","reference":"authority/operational/v1","verification_bundle_fingerprint":"'.str_repeat('b', 64).'"},"canonical_sha":"'.str_repeat('a', 40).'","company":{"name":"PT Élan Hospitality","slug":"elan-hospitality"},"environment":"OPERATIONAL","installation_id":"installation/alpha-01","owner":{"email":"owner@example.com","name":"Édi Owner"},"property":{"code":"BALI_01","currency":"IDR","name":"IVORQ Bali","slug":"ivorq-bali","timezone":"Asia/Makassar"},"protocol":1}';

        $this->assertSame($expected, $canonicalizer->canonicalBytes($this->request()));
        $this->assertSame('55705cb89d3d6e740e36b719c37dc4c4c646846d8b840ff81aa30bc9700c7128', $canonicalizer->fingerprint($this->request()));
        $this->assertStringNotContainsString("\n", $canonicalizer->canonicalBytes($this->request()));
    }

    public function test_key_order_does_not_change_bytes_or_fingerprint(): void
    {
        $canonicalizer = new FirstTrustRequestCanonicalizer;
        $request = $this->request();
        $reordered = array_reverse($request, true);
        $reordered['authority'] = array_reverse($request['authority'], true);
        $reordered['property'] = array_reverse($request['property'], true);

        $this->assertSame($canonicalizer->canonicalBytes($request), $canonicalizer->canonicalBytes($reordered));
        $this->assertSame($canonicalizer->fingerprint($request), $canonicalizer->fingerprint($reordered));
    }

    public function test_normalization_is_bounded_and_preserves_internal_unicode_bytes(): void
    {
        $canonicalizer = new FirstTrustRequestCanonicalizer;
        $request = $this->request();
        $request['environment'] = ' operational ';
        $request['owner']['name'] = '  Édi  Owner  ';
        $request['owner']['email'] = ' Owner@Example.COM ';
        $request['company']['name'] = ' PT Élan Hospitality ';
        $request['property']['code'] = ' bali_01 ';
        $request['property']['currency'] = ' idr ';

        $normalized = $canonicalizer->normalize($request);

        $this->assertSame('OPERATIONAL', $normalized['environment']);
        $this->assertSame('Édi  Owner', $normalized['owner']['name']);
        $this->assertSame('owner@example.com', $normalized['owner']['email']);
        $this->assertSame('PT Élan Hospitality', $normalized['company']['name']);
        $this->assertSame('BALI_01', $normalized['property']['code']);
        $this->assertSame('IDR', $normalized['property']['currency']);
        $this->assertStringContainsString('Édi  Owner', $canonicalizer->canonicalBytes($request));
    }

    public function test_invalid_identity_and_encoding_values_fail_closed(): void
    {
        $invalid = [
            'control character' => function (array &$request): void {
                $request['owner']['name'] = "Owner\nName";
            },
            'invalid utf8' => function (array &$request): void {
                $request['company']['name'] = "\xC3\x28";
            },
            'bad company slug' => function (array &$request): void {
                $request['company']['slug'] = 'Not Canonical';
            },
            'bad property slug' => function (array &$request): void {
                $request['property']['slug'] = 'two--hyphens';
            },
            'bad sha' => function (array &$request): void {
                $request['canonical_sha'] = str_repeat('A', 40);
            },
            'bad fingerprint' => function (array &$request): void {
                $request['authority']['verification_bundle_fingerprint'] = str_repeat('G', 64);
            },
            'bad timezone' => function (array &$request): void {
                $request['property']['timezone'] = 'Makassar/Asia';
            },
            'empty name' => function (array &$request): void {
                $request['property']['name'] = '   ';
            },
        ];

        foreach ($invalid as $label => $mutate) {
            $request = $this->request();
            $mutate($request);

            try {
                (new FirstTrustRequestCanonicalizer)->canonicalBytes($request);
                $this->fail("{$label} should fail closed.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_missing_added_and_raw_authorization_fields_are_rejected(): void
    {
        $canonicalizer = new FirstTrustRequestCanonicalizer;

        $missing = $this->request();
        unset($missing['company']);
        $added = $this->request();
        $added['generated_at'] = '2026-09-27T00:00:00.000Z';
        $rawAuthorization = $this->request();
        $rawAuthorization['authorization_envelope'] = 'fake-but-forbidden';

        foreach ([$missing, $added, $rawAuthorization] as $request) {
            try {
                $canonicalizer->canonicalBytes($request);
                $this->fail('An inexact canonical request field set should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('FIRST_TRUST_REQUEST_FIELDS_INVALID', $exception->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'protocol' => 1,
            'environment' => 'OPERATIONAL',
            'installation_id' => 'installation/alpha-01',
            'canonical_sha' => str_repeat('a', 40),
            'authority' => [
                'reference' => 'authority/operational/v1',
                'issuer' => 'https://authority.example.test/operational',
                'audience' => 'ivorq-installation',
                'verification_bundle_fingerprint' => str_repeat('b', 64),
            ],
            'owner' => [
                'name' => 'Édi Owner',
                'email' => 'owner@example.com',
            ],
            'company' => [
                'name' => 'PT Élan Hospitality',
                'slug' => 'elan-hospitality',
            ],
            'property' => [
                'name' => 'IVORQ Bali',
                'slug' => 'ivorq-bali',
                'code' => 'BALI_01',
                'timezone' => 'Asia/Makassar',
                'currency' => 'IDR',
            ],
        ];
    }
}
