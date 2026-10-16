<?php

namespace Tests\Unit\Infrastructure\FirstTrustAuthority;

use Infrastructure\FirstTrustAuthority\FirstTrustAuthorityException;
use Infrastructure\FirstTrustAuthority\FirstTrustProtocolV1Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FirstTrustProtocolV1ParserTest extends TestCase
{
    #[DataProvider('validEnvelopes')]
    public function test_it_parses_each_external_canonical_json_envelope(string $fixture, string $kind, string $identityField, string $identity): void
    {
        $parsed = (new FirstTrustProtocolV1Parser)->parse(FirstTrustTestFactory::fixture($fixture), $kind, 'AUTHORIZATION_INVALID');
        $this->assertSame($identity, $parsed['payload'][$identityField]);
        $this->assertSame(FirstTrustTestFactory::KEY_ID, $parsed['envelope']['key_id']);
    }

    public static function validEnvelopes(): array
    {
        return [
            'authorization' => ['authorization-envelope.json', 'authorization', 'authorization_id', 'auth-1'],
            'reservation' => ['reservation-envelope.json', 'reservation', 'reservation_reference', 'reservation-1'],
            'consumption' => ['consumption-receipt.json', 'consumption_receipt', 'consumption_reference', 'consumption-1'],
            'authority state' => ['authority-state-available.json', 'authority_state', 'state', 'AVAILABLE'],
        ];
    }

    #[DataProvider('malformedEnvelopes')]
    public function test_it_rejects_malformed_or_noncanonical_envelopes(callable $mutate): void
    {
        $valid = FirstTrustTestFactory::fixture('authorization-envelope.json');
        $this->expectException(FirstTrustAuthorityException::class);
        $this->expectExceptionMessage('AUTHORIZATION_INVALID');
        (new FirstTrustProtocolV1Parser)->parse($mutate($valid), 'authorization', 'AUTHORIZATION_INVALID');
    }

    public static function malformedEnvelopes(): array
    {
        return [
            'empty' => [static fn () => ''],
            'invalid JSON' => [static fn () => '{'],
            'trailing bytes' => [static fn ($v) => $v.'x'],
            'duplicate key' => [static fn ($v) => preg_replace('/\{"key_id":/', '{"key_id":"duplicate","key_id":', $v, 1)],
            'padded base64url' => [static function ($v) {
                $a = json_decode($v, true);
                $a['payload'] .= '=';

                return FirstTrustTestFactory::canonical($a);
            }],
            'empty payload segment' => [static function ($v) {
                $a = json_decode($v, true);
                $a['payload'] = '';

                return FirstTrustTestFactory::canonical($a);
            }],
            'invalid base64url' => [static function ($v) {
                $a = json_decode($v, true);
                $a['payload'] = '*';

                return FirstTrustTestFactory::canonical($a);
            }],
            'unknown field' => [static function ($v) {
                $a = json_decode($v, true);
                $a['extra'] = true;

                return FirstTrustTestFactory::canonical($a);
            }],
            'kind substitution' => [static function ($v) {
                $a = json_decode($v, true);
                $a['kind'] = 'reservation';

                return FirstTrustTestFactory::canonical($a);
            }],
            'noncanonical payload' => [static function ($v) {
                $a = json_decode($v, true);
                $p = base64_decode(strtr($a['payload'], '-_', '+/').'==');
                $a['payload'] = rtrim(strtr(base64_encode(" \n".$p), '+/', '-_'), '=');

                return FirstTrustTestFactory::canonical($a);
            }],
            'oversized' => [static fn () => str_repeat('x', FirstTrustProtocolV1Parser::MAX_ENVELOPE_BYTES + 1)],
            'invalid UTF-8' => [static fn () => "\xC3\x28"],
            'missing field' => [static function ($v) {
                $a = json_decode($v, true);
                unset($a['signature']);

                return FirstTrustTestFactory::canonical($a);
            }],
            'wrong field type' => [static function ($v) {
                $a = json_decode($v, true);
                $a['signature'] = [];

                return FirstTrustTestFactory::canonical($a);
            }],
            'unknown kind' => [static function ($v) {
                $a = json_decode($v, true);
                $a['kind'] = 'unknown';

                return FirstTrustTestFactory::canonical($a);
            }],
            'payload unknown field' => [static function ($v) {
                $a = json_decode($v, true);
                $p = FirstTrustTestFactory::payload($v);
                $p['unknown'] = true;
                $a['payload'] = rtrim(strtr(base64_encode(FirstTrustTestFactory::canonical($p)), '+/', '-_'), '=');

                return FirstTrustTestFactory::canonical($a);
            }],
            'payload duplicate field' => [static function ($v) {
                $a = json_decode($v, true);
                $p = FirstTrustTestFactory::canonical(FirstTrustTestFactory::payload($v));
                $p = preg_replace('/\{"audience":/', '{"audience":"duplicate","audience":', $p, 1);
                $a['payload'] = rtrim(strtr(base64_encode($p), '+/', '-_'), '=');

                return FirstTrustTestFactory::canonical($a);
            }],
        ];
    }

    public function test_it_uses_the_protocol_unsupported_code(): void
    {
        $envelope = json_decode(FirstTrustTestFactory::fixture('authorization-envelope.json'), true);
        $envelope['protocol'] = 2;
        $this->expectExceptionMessage('AUTHORITY_PROTOCOL_UNSUPPORTED');
        (new FirstTrustProtocolV1Parser)->parse(FirstTrustTestFactory::canonical($envelope), 'authorization', 'AUTHORIZATION_INVALID');
    }

    public function test_serialized_envelope_enforces_the_exact_32_kib_boundary(): void
    {
        $valid = FirstTrustTestFactory::fixture('authorization-envelope.json');
        $atLimit = $valid.str_repeat(' ', FirstTrustProtocolV1Parser::MAX_ENVELOPE_BYTES - strlen($valid));
        $parsed = (new FirstTrustProtocolV1Parser)->parse($atLimit, 'authorization', 'AUTHORIZATION_INVALID');
        $this->assertSame('auth-1', $parsed['payload']['authorization_id']);
        $this->assertSame(32768, strlen($atLimit));

        try {
            (new FirstTrustProtocolV1Parser)->parse($atLimit.' ', 'authorization', 'AUTHORIZATION_INVALID');
            $this->fail('Expected oversized envelope rejection.');
        } catch (FirstTrustAuthorityException $exception) {
            $this->assertSame('AUTHORIZATION_INVALID', $exception->getMessage());
        }
    }
}
