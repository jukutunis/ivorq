<?php

namespace Tests\Postgres\Foundation\Authorization\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Data\FirstTrustRequest;
use Modules\Foundation\Authorization\Data\FirstTrustResult;
use Modules\Foundation\Authorization\Services\FirstTrustOrchestrator;
use Modules\Foundation\Authorization\Services\FirstTrustRequestCanonicalizer;
use Symfony\Component\Process\Process;
use Tests\Fakes\FakeFirstTrustAuthority;
use Tests\PostgresTestCase;

abstract class FirstTrustTestCase extends PostgresTestCase
{
    use DatabaseMigrations;

    protected const RAW_AUTHORIZATION = 'B5A2B-RAW-AUTHORIZATION-MARKER-DO-NOT-PERSIST';

    protected string $bundlePath;

    protected string $releaseSha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bundlePath = tempnam(sys_get_temp_dir(), 'ivorq-first-trust-bundle-');
        file_put_contents($this->bundlePath, "B5A2B deterministic verification bundle\n");

        $process = new Process(['git', '-C', base_path(), 'rev-parse', 'HEAD']);
        $process->mustRun();
        $this->releaseSha = trim($process->getOutput());

        config([
            'first-trust.environment' => 'REHEARSAL',
            'first-trust.installation_id' => 'ivorq-test-installation',
            'first-trust.release_sha' => $this->releaseSha,
            'first-trust.authority.reference' => 'authority/rehearsal/v1',
            'first-trust.authority.issuer' => 'https://authority.example.test/rehearsal',
            'first-trust.authority.audience' => 'ivorq-installation',
            'first-trust.authority.verification_bundle_path' => $this->bundlePath,
            'mail.default' => 'array',
        ]);

        Notification::fake();
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('first_trust_runs')) {
            DB::statement('TRUNCATE TABLE first_trust_runs, owner_activations CASCADE');
        }
        if (isset($this->bundlePath) && is_file($this->bundlePath)) {
            @unlink($this->bundlePath);
        }

        parent::tearDown();
    }

    protected function request(array $changes = []): FirstTrustRequest
    {
        $values = array_merge([
            'ownerName' => 'Edi Owner',
            'ownerEmail' => 'OWNER@EXAMPLE.TEST',
            'companyName' => 'IVORQ Test Company',
            'companySlug' => 'ivorq-test-company',
            'propertyName' => 'IVORQ Test Property',
            'propertySlug' => 'ivorq-test-property',
            'propertyCode' => 'tst_01',
            'propertyTimezone' => 'Asia/Makassar',
            'propertyCurrency' => 'idr',
        ], $changes);

        return new FirstTrustRequest(...$values);
    }

    protected function fake(array $initial = [], ?string $stateFile = null): FakeFirstTrustAuthority
    {
        $fake = new FakeFirstTrustAuthority($stateFile, $initial);
        $this->app->instance(FirstTrustAuthority::class, $fake);

        return $fake;
    }

    protected function establish(?FirstTrustRequest $request = null): FirstTrustResult
    {
        return app(FirstTrustOrchestrator::class)->establish(
            $request ?? $this->request(),
            self::RAW_AUTHORIZATION,
        );
    }

    protected function requestFingerprint(?FirstTrustRequest $request = null): string
    {
        $request ??= $this->request();

        return app(FirstTrustRequestCanonicalizer::class)->fingerprint([
            'protocol' => 1,
            'environment' => config('first-trust.environment'),
            'installation_id' => config('first-trust.installation_id'),
            'canonical_sha' => config('first-trust.release_sha'),
            'authority' => [
                'reference' => config('first-trust.authority.reference'),
                'issuer' => config('first-trust.authority.issuer'),
                'audience' => config('first-trust.authority.audience'),
                'verification_bundle_fingerprint' => hash_file('sha256', $this->bundlePath),
            ],
            'owner' => [
                'name' => $request->ownerName,
                'email' => $request->ownerEmail,
            ],
            'company' => [
                'name' => $request->companyName,
                'slug' => $request->companySlug,
            ],
            'property' => [
                'name' => $request->propertyName,
                'slug' => $request->propertySlug,
                'code' => $request->propertyCode,
                'timezone' => $request->propertyTimezone,
                'currency' => $request->propertyCurrency,
            ],
        ]);
    }

    protected function assertFoundationCounts(int $expected): void
    {
        foreach (['users', 'companies', 'properties', 'property_user', 'owner_activations', 'first_trust_runs'] as $table) {
            $this->assertSame($expected, DB::table($table)->count(), $table);
        }
    }

    protected function assertNoFoundationMutation(): void
    {
        $this->assertFoundationCounts(0);
        $this->assertSame(0, DB::table('identity_security_events')->count());
        $this->assertSame(0, DB::table('owner_activation_tokens')->count());
        $this->assertSame(0, DB::table('activity_log')->count());
    }
}
