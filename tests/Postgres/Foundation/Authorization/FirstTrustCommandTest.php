<?php

namespace Tests\Postgres\Foundation\Authorization;

use Modules\Foundation\Authorization\Console\EstablishFirstTrustCommand;
use RuntimeException;
use Tests\Postgres\Foundation\Authorization\Support\FirstTrustTestCase;

class FirstTrustCommandTest extends FirstTrustTestCase
{
    public function test_authorization_stdin_preserves_signed_bytes_and_accepts_only_one_transport_line_ending(): void
    {
        $command = new EstablishFirstTrustCommand;
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "signed bytes  \r\n");
        rewind($stream);

        $this->assertSame('signed bytes  ', $command->readAuthorizationStream($stream));
        fclose($stream);

        $maximum = fopen('php://temp', 'w+b');
        fwrite($maximum, str_repeat('a', 65536)."\n");
        rewind($maximum);
        $this->assertSame(str_repeat('a', 65536), $command->readAuthorizationStream($maximum));
        fclose($maximum);
    }

    public function test_empty_nul_and_oversized_authorization_stdin_are_rejected(): void
    {
        $command = new EstablishFirstTrustCommand;
        foreach ([
            '' => 'FIRST_TRUST_AUTHORIZATION_INPUT_INVALID',
            "abc\0def" => 'FIRST_TRUST_AUTHORIZATION_INPUT_INVALID',
            str_repeat('a', 65537) => 'FIRST_TRUST_AUTHORIZATION_INPUT_TOO_LARGE',
        ] as $input => $expected) {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $input);
            rewind($stream);
            try {
                $command->readAuthorizationStream($stream);
                $this->fail('Invalid authorization stdin must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame($expected, $exception->getMessage());
            } finally {
                fclose($stream);
            }
        }
    }

    public function test_command_exposes_only_the_approved_business_options(): void
    {
        $command = new EstablishFirstTrustCommand;
        $options = array_keys($command->getDefinition()->getOptions());
        foreach ([
            'owner-name', 'owner-email', 'company-name', 'company-slug', 'property-name',
            'property-slug', 'property-code', 'property-timezone', 'property-currency',
            'authorization-stdin',
        ] as $approved) {
            $this->assertContains($approved, $options);
        }
        foreach ([
            'force', 'reset', 'adopt', 'offline', 'skip-authority', 'fake-authority',
            'canonical-sha', 'environment', 'installation-id', 'execution-id',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $options);
        }
    }

    public function test_noninteractive_command_requires_stdin_and_never_echoes_authorization(): void
    {
        $withoutStdin = $this->runCommandProcess('', false);
        $this->assertNotSame(0, $withoutStdin['exit']);
        $this->assertStringContainsString('FIRST_TRUST_AUTHORIZATION_STDIN_REQUIRED', $withoutStdin['output']);

        $withStdin = $this->runCommandProcess(self::RAW_AUTHORIZATION."\n", true);
        $this->assertNotSame(0, $withStdin['exit']);
        $this->assertStringContainsString('FIRST_TRUST_AUTHORITY_UNCONFIGURED', $withStdin['output']);
        $this->assertStringNotContainsString(self::RAW_AUTHORIZATION, $withStdin['output']);
        $this->assertStringNotContainsString('TEST_PROVIDER_SECRET', $withStdin['output']);
        $this->assertNoFoundationMutation();
    }

    public function test_command_process_rejects_empty_and_oversized_stdin_without_echoing_it(): void
    {
        $empty = $this->runCommandProcess('', true);
        $this->assertNotSame(0, $empty['exit']);
        $this->assertStringContainsString('FIRST_TRUST_AUTHORIZATION_INPUT_INVALID', $empty['output']);

        $marker = 'OVERSIZED-SECRET-MARKER-';
        $oversized = $this->runCommandProcess($marker.str_repeat('x', 65537), true);
        $this->assertNotSame(0, $oversized['exit']);
        $this->assertStringContainsString('FIRST_TRUST_AUTHORIZATION_INPUT_TOO_LARGE', $oversized['output']);
        $this->assertStringNotContainsString($marker, $oversized['output']);
        $this->assertNoFoundationMutation();
    }

    /** @return array{exit:int,output:string} */
    private function runCommandProcess(string $stdin, bool $authorizationStdin): array
    {
        $arguments = [
            PHP_BINARY,
            'artisan',
            'ivorq:first-trust:establish',
            '--owner-name=Edi Owner',
            '--owner-email=owner@example.test',
            '--company-name=IVORQ Test Company',
            '--company-slug=ivorq-test-company',
            '--property-name=IVORQ Test Property',
            '--property-slug=ivorq-test-property',
            '--property-code=TST_01',
            '--property-timezone=Asia/Makassar',
            '--property-currency=IDR',
            '--no-interaction',
        ];
        if ($authorizationStdin) {
            $arguments[] = '--authorization-stdin';
        }

        $process = proc_open(
            $arguments,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path(),
            array_merge(getenv(), [
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => 'ivorq_testing',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'FIRST_TRUST_ENVIRONMENT' => 'REHEARSAL',
                'FIRST_TRUST_INSTALLATION_ID' => 'ivorq-test-installation',
                'FIRST_TRUST_RELEASE_SHA' => $this->releaseSha,
                'FIRST_TRUST_AUTHORITY_REFERENCE' => 'authority/rehearsal/v1',
                'FIRST_TRUST_AUTHORITY_ISSUER' => 'https://authority.example.test/rehearsal',
                'FIRST_TRUST_AUTHORITY_AUDIENCE' => 'ivorq-installation',
                'FIRST_TRUST_VERIFICATION_BUNDLE_PATH' => $this->bundlePath,
            ]),
        );
        if (! is_resource($process)) {
            $this->fail('Unable to spawn the first-trust command process.');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => $output];
    }
}
