<?php

namespace Modules\Foundation\Authorization\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Services\OwnerActivationService;
use Modules\Foundation\Authorization\Contracts\FirstTrustAuthority;
use Modules\Foundation\Authorization\Data\FirstTrustExpectedBinding;
use Modules\Foundation\Authorization\Data\FirstTrustRequest;
use Modules\Foundation\Authorization\Data\FirstTrustResult;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorityState;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustAuthorization;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustConsumption;
use Modules\Foundation\Authorization\Data\VerifiedFirstTrustReservation;
use Modules\Foundation\Authorization\Enums\FirstTrustRunStatus;
use Modules\Foundation\Authorization\Models\FirstTrustRun;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Process\Process;
use Throwable;

final class FirstTrustOrchestrator
{
    private const MAXIMUM_VERIFICATION_BUNDLE_BYTES = 1048576;

    /** @var list<string> */
    private const STABLE_AUTHORITY_ERRORS = [
        'AUTHORITY_UNAVAILABLE',
        'AUTHORITY_CREDENTIAL_REJECTED',
        'AUTHORITY_PROTOCOL_UNSUPPORTED',
        'AUTHORIZATION_INVALID',
        'AUTHORIZATION_EXPIRED',
        'AUTHORIZATION_REVOKED',
        'ENVIRONMENT_MISMATCH',
        'INSTALLATION_MISMATCH',
        'CANONICAL_SHA_MISMATCH',
        'REQUEST_FINGERPRINT_MISMATCH',
        'ALREADY_RESERVED_OTHER_EXECUTION',
        'RESERVATION_EXPIRED',
        'ALREADY_CONSUMED_OTHER_EXECUTION',
        'RECEIPT_INVALID',
        'AUTHORITY_RECOVERY_REQUIRED',
        'AUTHORITY_STATE_UNAVAILABLE',
        'AUTHORITY_STATE_INVALID',
        'AUTHORITY_STATE_BINDING_MISMATCH',
        'FIRST_TRUST_AUTHORITY_UNCONFIGURED',
    ];

    public function __construct(
        private readonly FirstTrustAuthority $authority,
        private readonly FirstTrustRequestCanonicalizer $canonicalizer,
        private readonly FirstTrustEmptyStateGuard $emptyState,
        private readonly OwnerActivationService $activations,
        private readonly FirstTrustActivationDeliveryService $delivery,
    ) {}

    public function establish(
        FirstTrustRequest $request,
        #[SensitiveParameter] string $serializedAuthorization,
    ): FirstTrustResult {
        $context = $this->deploymentContext($request);
        $verified = $this->verifyAuthorization($serializedAuthorization, $context);
        $state = $this->authoritativeState($verified->authorizationId, $context['binding']);
        $this->assertState($state, $verified, $context);

        if (in_array($state->state, ['REVOKED', 'EXPIRED'], true)) {
            throw new RuntimeException('FIRST_TRUST_AUTHORIZATION_'.$state->state);
        }

        if ($state->state === 'AVAILABLE') {
            $executionId = 'ftx-'.Str::ulid();
            $reservation = $this->reserve(
                $serializedAuthorization,
                $executionId,
                $verified,
                $context,
            );
            $run = $this->ensureFoundation($reservation, $request, $context);

            return $this->consumeCompleteAndDeliver($run, $verified, $context);
        }

        if ($state->executionId === null) {
            throw new RuntimeException('FIRST_TRUST_AUTHORITY_STATE_INVALID');
        }

        $run = FirstTrustRun::query()
            ->where('environment', $context['local_environment'])
            ->where('installation_id', $context['installation_id'])
            ->first();

        if ($state->state === 'RESERVED') {
            $reservation = $this->reservationFromState($state, $verified, $context);
            if ($run === null) {
                $run = $this->ensureFoundation($reservation, $request, $context);
            } else {
                $this->assertRunIdentity($run, $verified, $context, $state->executionId);
                $this->assertReservationMatchesRun($reservation, $run);
                if ($run->status === FirstTrustRunStatus::Completed) {
                    $this->assertCompletedFoundationIdentity($run, $context);
                } else {
                    $this->assertPendingFoundationIntegrity($run, $request, $context);
                    $this->assertRecoveryDeadline($run);
                }
            }

            return $this->consumeCompleteAndDeliver($run, $verified, $context);
        }

        if ($state->state === 'CONSUMED') {
            if ($run === null) {
                throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
            }

            $this->assertRunIdentity($run, $verified, $context, $state->executionId);
            $this->assertReservationMatchesRun($state, $run);
            if ($run->status === FirstTrustRunStatus::Completed) {
                $this->assertCompletedFoundationIdentity($run, $context);
            } else {
                $this->assertPendingFoundationIntegrity($run, $request, $context);
            }
            $consumption = $this->consumptionFromState($state, $verified, $context, $run);
            [$completed, $newlyCompleted] = $this->complete($run, $consumption, $context);

            return $this->result(
                $completed,
                $newlyCompleted ? $this->delivery->deliver($completed) : 'not_attempted',
            );
        }

        throw new RuntimeException('FIRST_TRUST_AUTHORITY_STATE_INVALID');
    }

    /**
     * @return array{
     *   environment:string,
     *   local_environment:string,
     *   installation_id:string,
     *   release_sha:string,
     *   authority_reference:string,
     *   authority_issuer:string,
     *   authority_audience:string,
     *   verifier_bundle_fingerprint:string,
     *   request_fingerprint:string,
     *   normalized:array<string,mixed>,
     *   binding:FirstTrustExpectedBinding
     * }
     */
    private function deploymentContext(FirstTrustRequest $request): array
    {
        $environment = config('first-trust.environment');
        $installationId = config('first-trust.installation_id');
        $releaseSha = config('first-trust.release_sha');
        $authorityReference = config('first-trust.authority.reference');
        $authorityIssuer = config('first-trust.authority.issuer');
        $authorityAudience = config('first-trust.authority.audience');
        $bundlePath = config('first-trust.authority.verification_bundle_path');

        foreach ([$environment, $installationId, $releaseSha, $authorityReference, $authorityIssuer, $authorityAudience, $bundlePath] as $value) {
            if (! is_string($value) || $value === '') {
                throw new RuntimeException('FIRST_TRUST_DEPLOYMENT_CONFIG_INVALID');
            }
        }

        if (! in_array($environment, ['OPERATIONAL', 'REHEARSAL'], true)) {
            throw new RuntimeException('FIRST_TRUST_DEPLOYMENT_CONFIG_INVALID');
        }
        if (preg_match('/\A[a-f0-9]{40}\z/', $releaseSha) !== 1) {
            throw new RuntimeException('FIRST_TRUST_DEPLOYMENT_CONFIG_INVALID');
        }

        $this->assertReleaseSha($releaseSha);
        $bundleFingerprint = $this->verificationBundleFingerprint($bundlePath);

        $canonicalInput = [
            'protocol' => 1,
            'environment' => $environment,
            'installation_id' => $installationId,
            'canonical_sha' => $releaseSha,
            'authority' => [
                'reference' => $authorityReference,
                'issuer' => $authorityIssuer,
                'audience' => $authorityAudience,
                'verification_bundle_fingerprint' => $bundleFingerprint,
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
        ];

        try {
            $normalized = $this->canonicalizer->normalize($canonicalInput);
            $fingerprint = $this->canonicalizer->fingerprint($canonicalInput);
            $binding = new FirstTrustExpectedBinding(
                $normalized['environment'],
                $normalized['installation_id'],
                $fingerprint,
                $normalized['canonical_sha'],
            );
        } catch (Throwable) {
            throw new RuntimeException('FIRST_TRUST_REQUEST_INVALID');
        }

        return [
            'environment' => $normalized['environment'],
            'local_environment' => strtolower($normalized['environment']),
            'installation_id' => $normalized['installation_id'],
            'release_sha' => $normalized['canonical_sha'],
            'authority_reference' => $normalized['authority']['reference'],
            'authority_issuer' => $normalized['authority']['issuer'],
            'authority_audience' => $normalized['authority']['audience'],
            'verifier_bundle_fingerprint' => $normalized['authority']['verification_bundle_fingerprint'],
            'request_fingerprint' => $fingerprint,
            'normalized' => $normalized,
            'binding' => $binding,
        ];
    }

    private function assertReleaseSha(string $configuredSha): void
    {
        if (! is_dir(base_path('.git')) && ! is_file(base_path('.git'))) {
            return;
        }

        try {
            $process = new Process(['git', '-C', base_path(), 'rev-parse', 'HEAD']);
            $process->setTimeout(5);
            $process->run();
        } catch (Throwable) {
            throw new RuntimeException('FIRST_TRUST_RELEASE_SHA_UNAVAILABLE');
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('FIRST_TRUST_RELEASE_SHA_UNAVAILABLE');
        }

        if (trim($process->getOutput()) !== $configuredSha) {
            throw new RuntimeException('FIRST_TRUST_RELEASE_SHA_MISMATCH');
        }
    }

    private function verificationBundleFingerprint(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('FIRST_TRUST_VERIFICATION_BUNDLE_INVALID');
        }

        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('FIRST_TRUST_VERIFICATION_BUNDLE_INVALID');
        }

        try {
            $stat = fstat($handle);
            if (! is_array($stat)
                || (($stat['mode'] ?? 0) & 0170000) !== 0100000
                || ($stat['size'] ?? 0) < 1
                || $stat['size'] > self::MAXIMUM_VERIFICATION_BUNDLE_BYTES) {
                throw new RuntimeException('FIRST_TRUST_VERIFICATION_BUNDLE_INVALID');
            }

            $bytes = stream_get_contents($handle, self::MAXIMUM_VERIFICATION_BUNDLE_BYTES + 1);
            if (! is_string($bytes) || $bytes === '' || strlen($bytes) !== $stat['size']) {
                throw new RuntimeException('FIRST_TRUST_VERIFICATION_BUNDLE_INVALID');
            }

            return hash('sha256', $bytes);
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $context */
    private function verifyAuthorization(string $serializedAuthorization, array $context): VerifiedFirstTrustAuthorization
    {
        try {
            $verified = $this->authority->verifyAuthorization($serializedAuthorization, $context['binding']);
        } catch (Throwable $throwable) {
            $stable = $this->stableAuthorityError($throwable, [
                'AUTHORITY_UNAVAILABLE',
                'AUTHORITY_CREDENTIAL_REJECTED',
                'AUTHORITY_PROTOCOL_UNSUPPORTED',
                'AUTHORIZATION_INVALID',
                'AUTHORIZATION_EXPIRED',
                'AUTHORIZATION_REVOKED',
                'ENVIRONMENT_MISMATCH',
                'INSTALLATION_MISMATCH',
                'CANONICAL_SHA_MISMATCH',
                'REQUEST_FINGERPRINT_MISMATCH',
                'AUTHORITY_STATE_BINDING_MISMATCH',
                'FIRST_TRUST_AUTHORITY_UNCONFIGURED',
            ]);
            if ($stable !== null) {
                throw new RuntimeException($stable);
            }

            throw new RuntimeException('FIRST_TRUST_AUTHORIZATION_VERIFICATION_FAILED');
        }

        $this->assertAuthorization($verified, $context);

        return $verified;
    }

    /** @param array<string,mixed> $context */
    private function assertAuthorization(VerifiedFirstTrustAuthorization $verified, array $context): void
    {
        if ($verified->authorityReference !== $context['authority_reference']
            || $verified->authorityIssuer !== $context['authority_issuer']
            || $verified->authorityAudience !== $context['authority_audience']
            || $verified->verifierBundleFingerprint !== $context['verifier_bundle_fingerprint']
            || ! $this->sameBinding($verified->binding, $context['binding'])
            || ! $this->validReference($verified->authorizationId, 200)
            || ! $this->validReference($verified->verifiedKeyId, 300)) {
            throw new RuntimeException('FIRST_TRUST_AUTHORIZATION_PROJECTION_INVALID');
        }

        $now = $this->now()->getTimestamp();
        if ($verified->issuedAt > $verified->notBefore
            || $verified->notBefore > $verified->expiresAt
            || $now < $verified->notBefore->getTimestamp()
            || $now >= $verified->expiresAt->getTimestamp()) {
            throw new RuntimeException('FIRST_TRUST_AUTHORIZATION_PROJECTION_INVALID');
        }
    }

    private function authoritativeState(
        string $authorizationId,
        FirstTrustExpectedBinding $binding,
    ): VerifiedFirstTrustAuthorityState {
        try {
            return $this->authority->state($authorizationId, $binding);
        } catch (Throwable $throwable) {
            $stable = $this->stableAuthorityError($throwable, [
                'AUTHORITY_UNAVAILABLE',
                'AUTHORITY_CREDENTIAL_REJECTED',
                'AUTHORITY_PROTOCOL_UNSUPPORTED',
                'AUTHORITY_STATE_UNAVAILABLE',
                'AUTHORITY_STATE_INVALID',
                'AUTHORITY_STATE_BINDING_MISMATCH',
                'AUTHORIZATION_INVALID',
                'AUTHORIZATION_EXPIRED',
                'AUTHORIZATION_REVOKED',
                'ENVIRONMENT_MISMATCH',
                'INSTALLATION_MISMATCH',
                'CANONICAL_SHA_MISMATCH',
                'REQUEST_FINGERPRINT_MISMATCH',
            ]);
            if ($stable !== null) {
                throw new RuntimeException($stable);
            }

            throw new RuntimeException('AUTHORITY_STATE_UNAVAILABLE');
        }
    }

    /** @param array<string,mixed> $context */
    private function assertState(
        VerifiedFirstTrustAuthorityState $state,
        VerifiedFirstTrustAuthorization $verified,
        array $context,
    ): void {
        if ($state->authorizationId !== $verified->authorizationId
            || $state->authorityReference !== $verified->authorityReference
            || $state->authorityIssuer !== $verified->authorityIssuer
            || $state->verifiedKeyId !== $verified->verifiedKeyId
            || $state->verifierBundleFingerprint !== $context['verifier_bundle_fingerprint']
            || ! $this->sameBinding($state->binding, $context['binding'])) {
            throw new RuntimeException('FIRST_TRUST_AUTHORITY_STATE_INVALID');
        }
    }

    /** @param array<string,mixed> $context */
    private function reserve(
        string $serializedAuthorization,
        string $executionId,
        VerifiedFirstTrustAuthorization $verified,
        array $context,
    ): VerifiedFirstTrustReservation {
        try {
            $reservation = $this->authority->reserve(
                $serializedAuthorization,
                $executionId,
                $context['binding'],
            );
        } catch (Throwable $throwable) {
            $stable = $this->stableAuthorityError($throwable);
            if ($stable !== null && $stable !== 'AUTHORITY_UNAVAILABLE') {
                throw new RuntimeException($stable);
            }

            $state = $this->authoritativeState($verified->authorizationId, $context['binding']);
            $this->assertState($state, $verified, $context);
            if ($state->state !== 'RESERVED') {
                throw new RuntimeException('AUTHORITY_RECOVERY_REQUIRED');
            }
            if ($state->executionId !== $executionId) {
                throw new RuntimeException('ALREADY_RESERVED_OTHER_EXECUTION');
            }

            return $this->reservationFromState($state, $verified, $context);
        }

        if ($reservation->executionId !== $executionId) {
            throw new RuntimeException('ALREADY_RESERVED_OTHER_EXECUTION');
        }
        $this->assertReservation($reservation, $verified, $context, true);

        return $reservation;
    }

    /** @param array<string,mixed> $context */
    private function reservationFromState(
        VerifiedFirstTrustAuthorityState $state,
        VerifiedFirstTrustAuthorization $verified,
        array $context,
    ): VerifiedFirstTrustReservation {
        if ($state->state !== 'RESERVED'
            || $state->executionId === null
            || $state->reservationReference === null
            || $state->reservationFingerprint === null
            || $state->reservedAt === null
            || $state->commitDeadline === null
            || $state->recoveryDeadline === null) {
            throw new RuntimeException('FIRST_TRUST_AUTHORITY_STATE_INVALID');
        }

        $reservation = new VerifiedFirstTrustReservation(
            $state->authorizationId,
            $state->authorityReference,
            $state->authorityIssuer,
            $state->executionId,
            $state->binding,
            $state->reservationReference,
            $state->reservationFingerprint,
            $state->reservedAt,
            $state->commitDeadline,
            $state->recoveryDeadline,
            $state->verifiedKeyId,
            $state->verifierBundleFingerprint,
        );
        $this->assertReservation($reservation, $verified, $context, false);

        return $reservation;
    }

    /** @param array<string,mixed> $context */
    private function assertReservation(
        VerifiedFirstTrustReservation $reservation,
        VerifiedFirstTrustAuthorization $verified,
        array $context,
        bool $requireCommitLease,
    ): void {
        if ($reservation->authorizationId !== $verified->authorizationId
            || $reservation->authorityReference !== $verified->authorityReference
            || $reservation->authorityIssuer !== $verified->authorityIssuer
            || $reservation->verifiedKeyId !== $verified->verifiedKeyId
            || $reservation->verifierBundleFingerprint !== $context['verifier_bundle_fingerprint']
            || ! $this->sameBinding($reservation->binding, $context['binding'])
            || ! $this->validReference($reservation->executionId, 200)
            || ! $this->validReference($reservation->reservationReference, 200)
            || preg_match('/\A[a-f0-9]{64}\z/', $reservation->reservationFingerprint) !== 1
            || $reservation->reservedAt >= $reservation->commitDeadline
            || $reservation->commitDeadline > $reservation->recoveryDeadline) {
            throw new RuntimeException('FIRST_TRUST_RESERVATION_INVALID');
        }

        $now = $this->now()->getTimestamp();
        if (($requireCommitLease && $now >= $reservation->commitDeadline->getTimestamp())
            || $now >= $reservation->recoveryDeadline->getTimestamp()) {
            throw new RuntimeException($requireCommitLease
                ? 'FIRST_TRUST_COMMIT_LEASE_EXPIRED'
                : 'AUTHORITY_RECOVERY_REQUIRED');
        }
    }

    /** @param array<string,mixed> $context */
    private function ensureFoundation(
        VerifiedFirstTrustReservation $reservation,
        FirstTrustRequest $request,
        array $context,
    ): FirstTrustRun {
        $this->assertReservationDeadlineForFoundation($reservation);

        return DB::transaction(function () use ($reservation, $request, $context): FirstTrustRun {
            $this->advisoryLock($context);
            $this->emptyState->lockProtectedTables();

            $existing = FirstTrustRun::query()
                ->where('environment', $context['local_environment'])
                ->where('installation_id', $context['installation_id'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $this->assertRunIdentity($existing, null, $context, $reservation->executionId, $reservation->authorizationId);
                $this->assertReservationMatchesRun($reservation, $existing);
                if ($existing->status === FirstTrustRunStatus::Completed) {
                    $this->assertCompletedFoundationIdentity($existing, $context);
                } else {
                    $this->assertPendingFoundationIntegrity($existing, $request, $context);
                }

                return $existing;
            }

            $this->assertReservationDeadlineForFoundation($reservation);
            $run = new FirstTrustRun;
            $run->forceFill([
                'environment' => $context['local_environment'],
                'installation_id' => $context['installation_id'],
                'status' => FirstTrustRunStatus::InProgress,
                'execution_id' => $reservation->executionId,
                'authorization_id' => $reservation->authorizationId,
                'authority_reference' => $reservation->authorityReference,
                'authority_issuer' => $reservation->authorityIssuer,
                'request_fingerprint' => $context['request_fingerprint'],
                'canonical_sha' => $context['release_sha'],
                'verifier_key_id' => $reservation->verifiedKeyId,
                'verifier_bundle_fingerprint' => $reservation->verifierBundleFingerprint,
                'reservation_reference' => $reservation->reservationReference,
                'reservation_fingerprint' => $reservation->reservationFingerprint,
                'reservation_reserved_at' => $reservation->reservedAt,
                'reservation_commit_deadline' => $reservation->commitDeadline,
                'reservation_recovery_deadline' => $reservation->recoveryDeadline,
                'started_at' => $this->now(),
            ])->save();

            $this->emptyState->assertEmptyExceptCurrentRun($run->id);

            $normalized = $context['normalized'];
            $company = new Company;
            $company->forceFill([
                'name' => $normalized['company']['name'],
                'slug' => $normalized['company']['slug'],
                'is_active' => true,
            ])->save();

            $property = new Property;
            $property->forceFill([
                'company_id' => $company->id,
                'name' => $normalized['property']['name'],
                'slug' => $normalized['property']['slug'],
                'code' => $normalized['property']['code'],
                'timezone' => $normalized['property']['timezone'],
                'currency' => $normalized['property']['currency'],
                'is_active' => true,
            ])->save();

            $user = new User;
            $user->forceFill([
                'name' => $normalized['owner']['name'],
                'email' => $normalized['owner']['email'],
                'password' => null,
                'is_active' => false,
                'is_system_admin' => false,
                'email_verified_at' => null,
                'department_id' => null,
                'position_id' => null,
                'employee_id' => null,
            ])->save();

            $joinedAt = $this->now();
            DB::table('property_user')->insert([
                'property_id' => $property->id,
                'user_id' => $user->id,
                'status' => 'active',
                'is_default' => true,
                'joined_at' => $joinedAt,
                'created_at' => $joinedAt,
                'updated_at' => $joinedAt,
            ]);

            $activation = $this->activations->inviteExistingOwner(
                $user,
                $run->id,
                $context['local_environment'],
                $context['installation_id'],
                $company->id,
                $property->id,
            );
            if ($activation->status !== OwnerActivationStatus::Invited
                || $activation->pending_role_name !== 'installation-owner') {
                throw new RuntimeException('FIRST_TRUST_OWNER_ACTIVATION_INVALID');
            }

            $foundationCommittedAt = $this->now();
            $run->forceFill([
                'user_id' => $user->id,
                'company_id' => $company->id,
                'property_id' => $property->id,
                'membership_user_id' => $user->id,
                'membership_property_id' => $property->id,
                'membership_fingerprint' => $this->membershipFingerprint($user->id, $property->id, $joinedAt),
                'owner_activation_id' => $activation->id,
                'foundation_committed_at' => $foundationCommittedAt,
            ])->save();

            return $run->fresh();
        }, 1);
    }

    private function assertReservationDeadlineForFoundation(VerifiedFirstTrustReservation $reservation): void
    {
        $now = $this->now()->getTimestamp();
        if ($now >= $reservation->commitDeadline->getTimestamp()) {
            throw new RuntimeException('FIRST_TRUST_COMMIT_LEASE_EXPIRED');
        }
        if ($now >= $reservation->recoveryDeadline->getTimestamp()) {
            throw new RuntimeException('AUTHORITY_RECOVERY_REQUIRED');
        }
    }

    /** @param array<string,mixed> $context */
    private function consumeCompleteAndDeliver(
        FirstTrustRun $run,
        VerifiedFirstTrustAuthorization $verified,
        array $context,
    ): FirstTrustResult {
        $run->refresh();
        if ($run->status === FirstTrustRunStatus::Completed) {
            $state = $this->authoritativeState($verified->authorizationId, $context['binding']);
            $this->assertState($state, $verified, $context);
            if ($state->state !== 'CONSUMED' || $state->executionId !== $run->execution_id) {
                throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
            }
            $this->assertReservationMatchesRun($state, $run);
            $this->assertCompletedFoundationIdentity($run, $context);
            $this->consumptionFromState($state, $verified, $context, $run);

            return $this->result($run, 'not_attempted');
        }

        if ($run->status === FirstTrustRunStatus::Failed) {
            throw new RuntimeException('FIRST_TRUST_LOCAL_FAILED_TERMINAL');
        }

        $this->assertRecoveryDeadline($run);
        $consumption = $this->consumeWithReconciliation($run, $verified, $context);
        [$completed, $newlyCompleted] = $this->complete($run, $consumption, $context);

        return $this->result(
            $completed,
            $newlyCompleted ? $this->delivery->deliver($completed) : 'not_attempted',
        );
    }

    /** @param array<string,mixed> $context */
    private function consumeWithReconciliation(
        FirstTrustRun $run,
        VerifiedFirstTrustAuthorization $verified,
        array $context,
    ): VerifiedFirstTrustConsumption {
        try {
            $consumption = $this->authority->consume(
                $run->authorization_id,
                $run->execution_id,
                $context['binding'],
            );
        } catch (Throwable $throwable) {
            $stable = $this->stableAuthorityError($throwable);
            if ($stable !== null && $stable !== 'AUTHORITY_UNAVAILABLE') {
                throw new RuntimeException($stable);
            }

            $consumption = null;
        }

        if ($consumption !== null) {
            $this->assertConsumption($consumption, $run, $context);

            return $consumption;
        }

        $state = $this->authoritativeState($run->authorization_id, $context['binding']);
        $this->assertState($state, $verified, $context);
        if ($state->state === 'CONSUMED') {
            $consumption = $this->consumptionFromState($state, $verified, $context, $run);
            $this->assertConsumption($consumption, $run, $context);

            return $consumption;
        }

        if ($state->state !== 'RESERVED' || $state->executionId !== $run->execution_id) {
            throw new RuntimeException('AUTHORITY_RECOVERY_REQUIRED');
        }
        $this->assertReservationMatchesRun($state, $run);
        $this->assertRecoveryDeadline($run);

        try {
            $consumption = $this->authority->consume(
                $run->authorization_id,
                $run->execution_id,
                $context['binding'],
            );
        } catch (Throwable $throwable) {
            $stable = $this->stableAuthorityError($throwable);
            if ($stable !== null && $stable !== 'AUTHORITY_UNAVAILABLE') {
                throw new RuntimeException($stable);
            }

            $state = $this->authoritativeState($run->authorization_id, $context['binding']);
            $this->assertState($state, $verified, $context);
            if ($state->state === 'CONSUMED') {
                $consumption = $this->consumptionFromState($state, $verified, $context, $run);
                $this->assertConsumption($consumption, $run, $context);

                return $consumption;
            }

            throw new RuntimeException('AUTHORITY_RECOVERY_REQUIRED');
        }

        $this->assertConsumption($consumption, $run, $context);

        return $consumption;
    }

    /** @param array<string,mixed> $context */
    private function consumptionFromState(
        VerifiedFirstTrustAuthorityState $state,
        VerifiedFirstTrustAuthorization $verified,
        array $context,
        FirstTrustRun $run,
    ): VerifiedFirstTrustConsumption {
        if ($state->state !== 'CONSUMED'
            || $state->executionId === null
            || $state->reservationReference === null
            || $state->reservedAt === null
            || $state->consumptionReference === null
            || $state->consumptionFingerprint === null
            || $state->consumedAt === null) {
            throw new RuntimeException('FIRST_TRUST_AUTHORITY_STATE_INVALID');
        }

        $this->assertReservationMatchesRun($state, $run);

        return new VerifiedFirstTrustConsumption(
            $state->authorizationId,
            $state->authorityReference,
            $state->authorityIssuer,
            $state->executionId,
            $state->binding,
            $state->reservationReference,
            $state->reservedAt,
            $state->consumptionReference,
            $state->consumptionFingerprint,
            $state->consumedAt,
            $state->verifiedKeyId,
            $state->verifierBundleFingerprint,
        );
    }

    /** @param array<string,mixed> $context */
    private function assertConsumption(
        VerifiedFirstTrustConsumption $consumption,
        FirstTrustRun $run,
        array $context,
    ): void {
        if ($consumption->authorizationId !== $run->authorization_id
            || $consumption->authorityReference !== $run->authority_reference
            || $consumption->authorityIssuer !== $run->authority_issuer
            || $consumption->executionId !== $run->execution_id
            || $consumption->reservationReference !== $run->reservation_reference
            || $consumption->verifiedKeyId !== $run->verifier_key_id
            || $consumption->verifierBundleFingerprint !== $run->verifier_bundle_fingerprint
            || ! $this->sameBinding($consumption->binding, $context['binding'])
            || ! $this->sameInstant($consumption->reservedAt, $run->reservation_reserved_at)
            || ! $this->validReference($consumption->consumptionReference, 200)
            || preg_match('/\A[a-f0-9]{64}\z/', $consumption->consumptionFingerprint) !== 1
            || $consumption->consumedAt < $consumption->reservedAt) {
            throw new RuntimeException('FIRST_TRUST_CONSUMPTION_INVALID');
        }
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{FirstTrustRun,bool}
     */
    private function complete(
        FirstTrustRun $run,
        VerifiedFirstTrustConsumption $consumption,
        array $context,
    ): array {
        return DB::transaction(function () use ($run, $consumption, $context): array {
            $this->advisoryLock($context);
            $locked = FirstTrustRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
            }
            $this->assertRunIdentity($locked, null, $context, $consumption->executionId, $consumption->authorizationId);
            $this->assertConsumption($consumption, $locked, $context);

            if ($locked->status === FirstTrustRunStatus::Completed) {
                $this->assertCompletedFoundationIdentity($locked, $context);
                $this->assertCompletedConsumption($locked, $consumption);

                return [$locked, false];
            }
            if ($locked->status === FirstTrustRunStatus::Failed) {
                throw new RuntimeException('FIRST_TRUST_LOCAL_FAILED_TERMINAL');
            }
            $this->assertPendingFoundationIntegrity($locked, null, $context);

            $externalConsumedAt = CarbonImmutable::instance($consumption->consumedAt)->utc()->startOfSecond();
            $completionFingerprint = $this->completionFingerprint($locked, $consumption, $externalConsumedAt);
            $locked->forceFill([
                'consumption_reference' => $consumption->consumptionReference,
                'consumption_fingerprint' => $consumption->consumptionFingerprint,
                'external_consumed_at' => $externalConsumedAt,
                'completion_evidence_fingerprint' => $completionFingerprint,
                'completed_at' => $this->now(),
                'status' => FirstTrustRunStatus::Completed,
            ])->save();

            return [$locked->fresh(), true];
        }, 1);
    }

    private function assertCompletedConsumption(
        FirstTrustRun $run,
        VerifiedFirstTrustConsumption $consumption,
    ): void {
        if ($run->consumption_reference !== $consumption->consumptionReference
            || $run->consumption_fingerprint !== $consumption->consumptionFingerprint
            || ! $this->sameInstant($consumption->consumedAt, $run->external_consumed_at)
            || ! is_string($run->completion_evidence_fingerprint)
            || $run->completion_evidence_fingerprint !== $this->completionFingerprint(
                $run,
                $consumption,
                $run->external_consumed_at,
            )) {
            throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
        }
    }

    /** @param array<string,mixed> $context */
    private function assertRunIdentity(
        FirstTrustRun $run,
        ?VerifiedFirstTrustAuthorization $verified,
        array $context,
        string $executionId,
        ?string $authorizationId = null,
    ): void {
        if ($run->request_fingerprint !== $context['request_fingerprint']) {
            throw new RuntimeException('REQUEST_FINGERPRINT_MISMATCH');
        }

        if ($run->environment !== $context['local_environment']
            || $run->installation_id !== $context['installation_id']
            || $run->canonical_sha !== $context['release_sha']
            || $run->authority_reference !== $context['authority_reference']
            || $run->authority_issuer !== $context['authority_issuer']
            || $run->verifier_bundle_fingerprint !== $context['verifier_bundle_fingerprint']
            || $run->execution_id !== $executionId
            || ($authorizationId !== null && $run->authorization_id !== $authorizationId)
            || ($verified !== null && ($run->authorization_id !== $verified->authorizationId
                || $run->verifier_key_id !== $verified->verifiedKeyId))) {
            throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
        }

        if ($run->status === FirstTrustRunStatus::Failed) {
            throw new RuntimeException('FIRST_TRUST_LOCAL_FAILED_TERMINAL');
        }
    }

    /** @param array<string,mixed> $context */
    private function assertPendingFoundationIntegrity(
        FirstTrustRun $run,
        ?FirstTrustRequest $request,
        array $context,
    ): void {
        foreach (['user_id', 'company_id', 'property_id', 'membership_user_id', 'membership_property_id', 'membership_fingerprint', 'owner_activation_id', 'foundation_committed_at'] as $field) {
            if ($run->{$field} === null) {
                throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
            }
        }

        if ($run->membership_user_id !== $run->user_id
            || $run->membership_property_id !== $run->property_id) {
            throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
        }

        $user = User::withTrashed()->find($run->user_id);
        $company = Company::withTrashed()->find($run->company_id);
        $property = Property::withTrashed()->find($run->property_id);
        $activation = OwnerActivation::query()->find($run->owner_activation_id);
        $membership = DB::table('property_user')
            ->where('user_id', $run->user_id)
            ->where('property_id', $run->property_id)
            ->first();

        if ($user === null || $company === null || $property === null || $activation === null || $membership === null
            || $user->trashed() || $user->is_active || $user->is_system_admin || $user->password !== null || $user->email_verified_at !== null
            || ! $company->is_active || $company->trashed()
            || ! $property->is_active || $property->trashed() || $property->company_id !== $company->id
            || $membership->status !== 'active' || ! (bool) $membership->is_default
            || $activation->status !== OwnerActivationStatus::Invited
            || $activation->pending_role_name !== 'installation-owner'
            || $activation->first_trust_run_id !== $run->id
            || $activation->user_id !== $run->user_id
            || $activation->company_id !== $run->company_id
            || $activation->property_id !== $run->property_id
            || $activation->environment !== $context['local_environment']
            || $activation->installation_id !== $context['installation_id']) {
            throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
        }

        if ($request !== null) {
            $normalized = $context['normalized'];
            if ($user->name !== $normalized['owner']['name']
                || $user->email !== $normalized['owner']['email']
                || $company->name !== $normalized['company']['name']
                || $company->slug !== $normalized['company']['slug']
                || $property->name !== $normalized['property']['name']
                || $property->slug !== $normalized['property']['slug']
                || $property->code !== $normalized['property']['code']
                || $property->timezone !== $normalized['property']['timezone']
                || $property->currency !== $normalized['property']['currency']) {
                throw new RuntimeException('REQUEST_FINGERPRINT_MISMATCH');
            }
        }

        $joinedAt = CarbonImmutable::parse($membership->joined_at, 'UTC');
        if ($run->membership_fingerprint !== $this->membershipFingerprint(
            $run->user_id,
            $run->property_id,
            $joinedAt,
        )) {
            throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
        }
    }

    /** @param array<string,mixed> $context */
    private function assertCompletedFoundationIdentity(FirstTrustRun $run, array $context): void
    {
        foreach ([
            'user_id',
            'company_id',
            'property_id',
            'membership_user_id',
            'membership_property_id',
            'membership_fingerprint',
            'owner_activation_id',
            'foundation_committed_at',
            'consumption_reference',
            'consumption_fingerprint',
            'external_consumed_at',
            'completion_evidence_fingerprint',
            'completed_at',
        ] as $field) {
            if ($run->{$field} === null) {
                throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
            }
        }

        if ($run->status !== FirstTrustRunStatus::Completed
            || $run->membership_user_id !== $run->user_id
            || $run->membership_property_id !== $run->property_id
            || preg_match('/\A[a-f0-9]{64}\z/', $run->membership_fingerprint) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $run->consumption_fingerprint) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $run->completion_evidence_fingerprint) !== 1) {
            throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
        }

        $user = User::withTrashed()->find($run->user_id);
        $company = Company::withTrashed()->find($run->company_id);
        $property = Property::withTrashed()->find($run->property_id);
        $activation = OwnerActivation::query()->find($run->owner_activation_id);
        $membership = DB::table('property_user')
            ->where('user_id', $run->membership_user_id)
            ->where('property_id', $run->membership_property_id)
            ->first();

        if ($user === null || $user->trashed() || $user->is_system_admin
            || $company === null || $company->trashed()
            || $property === null || $property->trashed() || $property->company_id !== $run->company_id
            || $membership === null
            || $activation === null
            || $activation->first_trust_run_id !== $run->id
            || $activation->user_id !== $run->user_id
            || $activation->company_id !== $run->company_id
            || $activation->property_id !== $run->property_id
            || $activation->environment !== $context['local_environment']
            || $activation->installation_id !== $context['installation_id']) {
            throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
        }

        $joinedAt = CarbonImmutable::parse($membership->joined_at, 'UTC');
        if ($run->membership_fingerprint !== $this->membershipFingerprint(
            $run->membership_user_id,
            $run->membership_property_id,
            $joinedAt,
        )) {
            throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
        }
    }

    private function assertReservationMatchesRun(
        VerifiedFirstTrustReservation|VerifiedFirstTrustAuthorityState $reservation,
        FirstTrustRun $run,
    ): void {
        if ($reservation->executionId === null
            || $reservation->reservationReference === null
            || $reservation->reservationFingerprint === null
            || $reservation->reservedAt === null
            || $reservation->commitDeadline === null
            || $reservation->recoveryDeadline === null
            || $reservation->authorizationId !== $run->authorization_id
            || $reservation->executionId !== $run->execution_id
            || $reservation->authorityReference !== $run->authority_reference
            || $reservation->authorityIssuer !== $run->authority_issuer
            || $reservation->binding->environment !== strtoupper($run->environment)
            || $reservation->binding->installationId !== $run->installation_id
            || $reservation->binding->requestFingerprint !== $run->request_fingerprint
            || $reservation->binding->canonicalSha !== $run->canonical_sha
            || $reservation->verifiedKeyId !== $run->verifier_key_id
            || $reservation->verifierBundleFingerprint !== $run->verifier_bundle_fingerprint
            || $reservation->reservationReference !== $run->reservation_reference
            || $reservation->reservationFingerprint !== $run->reservation_fingerprint
            || ! $this->sameInstant($reservation->reservedAt, $run->reservation_reserved_at)
            || ! $this->sameInstant($reservation->commitDeadline, $run->reservation_commit_deadline)
            || ! $this->sameInstant($reservation->recoveryDeadline, $run->reservation_recovery_deadline)) {
            throw new RuntimeException('FIRST_TRUST_EXTERNAL_LOCAL_DIVERGENCE');
        }
    }

    private function assertRecoveryDeadline(FirstTrustRun $run): void
    {
        if ($run->reservation_recovery_deadline === null
            || $this->now()->getTimestamp() >= $run->reservation_recovery_deadline->getTimestamp()) {
            throw new RuntimeException('AUTHORITY_RECOVERY_REQUIRED');
        }
    }

    /** @param array<string,mixed> $context */
    private function advisoryLock(array $context): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('FIRST_TRUST_POSTGRESQL_REQUIRED');
        }
        DB::select(
            'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
            ['ivorq:first-trust:'.$context['local_environment'], $context['installation_id']],
        );
    }

    private function membershipFingerprint(string $userId, string $propertyId, DateTimeInterface $joinedAt): string
    {
        return hash('sha256', "IVORQ-FIRST-TRUST-MEMBERSHIP-V1\0".$this->canonicalJson([
            'is_default' => true,
            'joined_at' => $this->timestamp($joinedAt),
            'property_id' => $propertyId,
            'status' => 'active',
            'user_id' => $userId,
        ]));
    }

    private function completionFingerprint(
        FirstTrustRun $run,
        VerifiedFirstTrustConsumption $consumption,
        DateTimeInterface $externalConsumedAt,
    ): string {
        return hash('sha256', "IVORQ-FIRST-TRUST-COMPLETION-V1\0".$this->canonicalJson([
            'authorization_id' => $run->authorization_id,
            'authority_issuer' => $run->authority_issuer,
            'authority_reference' => $run->authority_reference,
            'canonical_sha' => $run->canonical_sha,
            'company_id' => $run->company_id,
            'consumption_fingerprint' => $consumption->consumptionFingerprint,
            'consumption_reference' => $consumption->consumptionReference,
            'environment' => $run->environment,
            'execution_id' => $run->execution_id,
            'external_consumed_at' => $this->timestamp($externalConsumedAt),
            'foundation_committed_at' => $this->timestamp($run->foundation_committed_at),
            'installation_id' => $run->installation_id,
            'membership_fingerprint' => $run->membership_fingerprint,
            'membership_property_id' => $run->membership_property_id,
            'membership_user_id' => $run->membership_user_id,
            'owner_activation_id' => $run->owner_activation_id,
            'property_id' => $run->property_id,
            'request_fingerprint' => $run->request_fingerprint,
            'reservation_commit_deadline' => $this->timestamp($run->reservation_commit_deadline),
            'reservation_fingerprint' => $run->reservation_fingerprint,
            'reservation_recovery_deadline' => $this->timestamp($run->reservation_recovery_deadline),
            'reservation_reference' => $run->reservation_reference,
            'reservation_reserved_at' => $this->timestamp($run->reservation_reserved_at),
            'run_id' => $run->id,
            'user_id' => $run->user_id,
            'verifier_bundle_fingerprint' => $run->verifier_bundle_fingerprint,
            'verifier_key_id' => $run->verifier_key_id,
        ]));
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(fn (mixed $item): string => $this->canonicalJson($item), $value)).']';
            }
            ksort($value, SORT_STRING);

            return '{'.implode(',', array_map(
                fn (string $key): string => $this->canonicalJson($key).':'.$this->canonicalJson($value[$key]),
                array_keys($value),
            )).'}';
        }
        if (is_string($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }

        throw new RuntimeException('FIRST_TRUST_CANONICAL_EVIDENCE_INVALID');
    }

    private function timestamp(DateTimeInterface $value): string
    {
        return CarbonImmutable::instance(DateTimeImmutable::createFromInterface($value))
            ->utc()
            ->startOfSecond()
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function sameBinding(FirstTrustExpectedBinding $left, FirstTrustExpectedBinding $right): bool
    {
        return $left->environment === $right->environment
            && $left->installationId === $right->installationId
            && $left->requestFingerprint === $right->requestFingerprint
            && $left->canonicalSha === $right->canonicalSha;
    }

    private function sameInstant(DateTimeInterface $left, DateTimeInterface $right): bool
    {
        return $this->timestamp($left) === $this->timestamp($right);
    }

    private function validReference(string $value, int $maximumBytes): bool
    {
        return $value !== ''
            && strlen($value) <= $maximumBytes
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/#-]*\z/', $value) === 1;
    }

    /** @param null|list<string> $permitted */
    private function stableAuthorityError(Throwable $throwable, ?array $permitted = null): ?string
    {
        $message = $throwable->getMessage();
        if (! in_array($message, self::STABLE_AUTHORITY_ERRORS, true)
            || ($permitted !== null && ! in_array($message, $permitted, true))) {
            return null;
        }

        return $message;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(new DateTimeZone('UTC'))->startOfSecond();
    }

    private function result(FirstTrustRun $run, string $deliveryState): FirstTrustResult
    {
        return new FirstTrustResult(
            $run->id,
            $run->execution_id,
            $run->status->value,
            $run->user_id,
            $run->company_id,
            $run->property_id,
            $run->owner_activation_id,
            $run->completion_evidence_fingerprint,
            $deliveryState,
        );
    }
}
