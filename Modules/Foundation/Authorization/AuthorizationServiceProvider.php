<?php

namespace Modules\Foundation\Authorization;

use Aws\Credentials\CredentialProvider;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Infrastructure\FirstTrustAuthority\AwsFirstTrustAuthority;
use Infrastructure\FirstTrustAuthority\FirstTrustEnvelopeVerifier;
use Infrastructure\FirstTrustAuthority\FirstTrustProtocolV1Parser;
use Infrastructure\FirstTrustAuthority\PinnedVerificationBundleLoader;
use Infrastructure\FirstTrustAuthority\UnconfiguredFirstTrustAuthority;
use Modules\Foundation\Authorization\Console\EstablishFirstTrustCommand;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Models\Role;
use Modules\Foundation\Authorization\Policies\RolePolicy;

class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FirstTrustAuthority::class, function ($app): FirstTrustAuthority {
            $values = [
                config('first-trust.environment'), config('first-trust.installation_id'), config('first-trust.release_sha'),
                config('first-trust.authority.reference'), config('first-trust.authority.issuer'), config('first-trust.authority.audience'),
                config('first-trust.authority.base_url'), config('first-trust.authority.verification_bundle_path'),
            ];
            if (count(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '')) !== count($values)) {
                return $app->make(UnconfiguredFirstTrustAuthority::class);
            }
            [$environment, , , $reference, $issuer, $audience, $baseUrl, $bundlePath] = $values;
            $loader = new PinnedVerificationBundleLoader($bundlePath, $environment, $issuer, $audience);
            $verifier = new FirstTrustEnvelopeVerifier(new FirstTrustProtocolV1Parser, $loader, $reference, $issuer, $audience);

            return new AwsFirstTrustAuthority(
                $verifier,
                new Client,
                CredentialProvider::defaultProvider(),
                static fn (): string => AwsFirstTrustAuthority::resolveExternalRegion(),
                $baseUrl,
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                EstablishFirstTrustCommand::class,
            ]);
        }

        Gate::policy(Role::class, RolePolicy::class);

        // Super admin bypasses all gates
        Gate::before(function ($user, $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }
        });
    }
}
