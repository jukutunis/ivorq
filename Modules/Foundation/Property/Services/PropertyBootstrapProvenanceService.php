<?php

namespace Modules\Foundation\Property\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningEnvironmentEnum;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBootstrapProvisioningRun;
use Modules\Foundation\User\Models\User;
use RuntimeException;

class PropertyBootstrapProvenanceService
{
    public const AUTHORIZATION_FAILURE = 'Bootstrap provisioning provenance action is not authorized.';

    public const ERROR_IDEMPOTENCY_CONFLICT = 'B4C_BOOTSTRAP_IDEMPOTENCY_CONFLICT';

    public const ERROR_FAILED_RUN_TERMINAL = 'B4C_BOOTSTRAP_FAILED_RUN_TERMINAL';

    public const ERROR_RUN_NOT_IN_PROGRESS = 'B4C_BOOTSTRAP_RUN_NOT_IN_PROGRESS';

    public const ERROR_COMPANY_REBIND = 'B4C_BOOTSTRAP_COMPANY_REBIND_REJECTED';

    public const ERROR_PROPERTY_REBIND = 'B4C_BOOTSTRAP_PROPERTY_REBIND_REJECTED';

    public const ERROR_PROPERTY_ALREADY_BOUND_TO_ANOTHER_RUN = 'B4C_BOOTSTRAP_PROPERTY_ALREADY_BOUND_TO_ANOTHER_RUN';

    public const ERROR_PROPERTY_COMPANY_MISMATCH = 'B4C_BOOTSTRAP_PROPERTY_COMPANY_MISMATCH';

    public const ERROR_COMPLETION_BINDINGS_REQUIRED = 'B4C_BOOTSTRAP_COMPLETION_BINDINGS_REQUIRED';

    public function start(
        User $actor,
        PropertyBootstrapProvisioningEnvironmentEnum $environment,
        string $idempotencyKey,
        string $requestFingerprint,
        string $canonicalSha,
        string $source,
    ): PropertyBootstrapProvisioningRun {
        $this->assertAuthenticatedActiveActor($actor);
        $this->assertBoundedNonBlank($idempotencyKey, 255, 'idempotency key');
        $this->assertSha256($requestFingerprint, 'request fingerprint');
        $this->assertGitSha($canonicalSha);
        $this->assertBoundedNonBlank($source, 255, 'source');

        return DB::transaction(function () use (
            $actor,
            $environment,
            $idempotencyKey,
            $requestFingerprint,
            $canonicalSha,
            $source,
        ): PropertyBootstrapProvisioningRun {
            $freshActor = $this->assertAuthenticatedActiveActor($actor);
            $this->lockStartIdentity($environment, $idempotencyKey);

            $existing = PropertyBootstrapProvisioningRun::query()
                ->where('environment', $environment->value)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExactRunIdentity(
                    $existing,
                    $freshActor,
                    $environment,
                    $requestFingerprint,
                    $canonicalSha,
                    $source,
                );

                if ($existing->status === PropertyBootstrapProvisioningStatusEnum::Failed) {
                    throw new RuntimeException(self::ERROR_FAILED_RUN_TERMINAL);
                }

                return $existing;
            }

            $run = new PropertyBootstrapProvisioningRun;
            $run->forceFill([
                'environment' => $environment,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $requestFingerprint,
                'status' => PropertyBootstrapProvisioningStatusEnum::InProgress,
                'canonical_sha' => $canonicalSha,
                'source' => $source,
                'initiated_by' => $freshActor->id,
                'started_at' => now(),
                'evidence' => [],
            ]);
            $run->save();

            return $run->fresh();
        }, 1);
    }

    public function bindCompany(
        PropertyBootstrapProvisioningRun $run,
        User $actor,
        string $companyId,
    ): PropertyBootstrapProvisioningRun {
        $this->assertAuthenticatedActiveActor($actor);

        return DB::transaction(function () use ($run, $actor, $companyId): PropertyBootstrapProvisioningRun {
            $locked = $this->lockMutableRun($run, $actor);
            $company = Company::query()->whereKey($companyId)->firstOrFail();

            if ($locked->company_id !== null) {
                if ((string) $locked->company_id !== (string) $company->id) {
                    throw new RuntimeException(self::ERROR_COMPANY_REBIND);
                }

                return $locked;
            }

            if ($locked->property_id !== null) {
                $property = Property::query()->whereKey($locked->property_id)->firstOrFail();
                if ((string) $property->company_id !== (string) $company->id) {
                    throw new RuntimeException(self::ERROR_PROPERTY_COMPANY_MISMATCH);
                }
            }

            $locked->forceFill(['company_id' => $company->id]);
            $locked->save();

            return $locked->fresh();
        }, 1);
    }

    public function bindProperty(
        PropertyBootstrapProvisioningRun $run,
        User $actor,
        string $propertyId,
    ): PropertyBootstrapProvisioningRun {
        $this->assertAuthenticatedActiveActor($actor);

        return DB::transaction(function () use ($run, $actor, $propertyId): PropertyBootstrapProvisioningRun {
            $locked = $this->lockMutableRun($run, $actor);
            $property = Property::query()->whereKey($propertyId)->firstOrFail();

            if ($locked->property_id !== null) {
                if ((string) $locked->property_id !== (string) $property->id) {
                    throw new RuntimeException(self::ERROR_PROPERTY_REBIND);
                }

                return $locked;
            }

            if ($locked->company_id !== null && (string) $property->company_id !== (string) $locked->company_id) {
                throw new RuntimeException(self::ERROR_PROPERTY_COMPANY_MISMATCH);
            }

            $this->lockPropertyBindingIdentity($locked->environment, $property->id);

            $alreadyBound = PropertyBootstrapProvisioningRun::query()
                ->where('environment', $locked->environment->value)
                ->where('property_id', $property->id)
                ->where('id', '<>', $locked->id)
                ->exists();

            if ($alreadyBound) {
                throw new RuntimeException(self::ERROR_PROPERTY_ALREADY_BOUND_TO_ANOTHER_RUN);
            }

            $locked->forceFill(['property_id' => $property->id]);
            $locked->save();

            return $locked->fresh();
        }, 1);
    }

    public function recordEvidence(
        PropertyBootstrapProvisioningRun $run,
        User $actor,
        array $evidence,
    ): PropertyBootstrapProvisioningRun {
        $this->assertAuthenticatedActiveActor($actor);
        $this->assertSafeEvidence($evidence);

        return DB::transaction(function () use ($run, $actor, $evidence): PropertyBootstrapProvisioningRun {
            $locked = $this->lockMutableRun($run, $actor);

            if ($locked->evidence === $evidence) {
                return $locked;
            }

            $locked->forceFill(['evidence' => $evidence]);
            $locked->save();

            return $locked->fresh();
        }, 1);
    }

    public function complete(
        PropertyBootstrapProvisioningRun $run,
        User $actor,
        array $evidence,
        string $evidenceFingerprint,
    ): PropertyBootstrapProvisioningRun {
        $this->assertAuthenticatedActiveActor($actor);
        $this->assertSafeEvidence($evidence);
        $this->assertSha256($evidenceFingerprint, 'evidence fingerprint');

        return DB::transaction(function () use ($run, $actor, $evidence, $evidenceFingerprint): PropertyBootstrapProvisioningRun {
            $locked = $this->lockMutableRun($run, $actor);

            if ($locked->company_id === null || $locked->property_id === null) {
                throw new RuntimeException(self::ERROR_COMPLETION_BINDINGS_REQUIRED);
            }

            $property = Property::query()->whereKey($locked->property_id)->firstOrFail();
            if ((string) $property->company_id !== (string) $locked->company_id) {
                throw new RuntimeException(self::ERROR_PROPERTY_COMPANY_MISMATCH);
            }

            $locked->forceFill([
                'evidence' => $evidence,
                'evidence_fingerprint' => $evidenceFingerprint,
                'status' => PropertyBootstrapProvisioningStatusEnum::Completed,
                'completed_at' => now(),
                'failed_at' => null,
                'failure_code' => null,
            ]);
            $locked->save();

            return $locked->fresh();
        }, 1);
    }

    public function fail(
        PropertyBootstrapProvisioningRun $run,
        User $actor,
        string $failureCode,
        array $evidence = [],
    ): PropertyBootstrapProvisioningRun {
        $this->assertAuthenticatedActiveActor($actor);
        $this->assertFailureCode($failureCode);
        $this->assertSafeEvidence($evidence, true);

        return DB::transaction(function () use ($run, $actor, $failureCode, $evidence): PropertyBootstrapProvisioningRun {
            $locked = $this->lockMutableRun($run, $actor);
            $locked->forceFill([
                'evidence' => $evidence,
                'status' => PropertyBootstrapProvisioningStatusEnum::Failed,
                'failed_at' => now(),
                'completed_at' => null,
                'failure_code' => $failureCode,
                'evidence_fingerprint' => null,
            ]);
            $locked->save();

            return $locked->fresh();
        }, 1);
    }

    private function assertAuthenticatedActiveActor(User $actor): User
    {
        if (! auth()->check() || (string) auth()->id() !== (string) $actor->id) {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE);
        }

        $fresh = User::query()
            ->whereKey($actor->id)
            ->where('is_active', true)
            ->first();

        if (! $fresh) {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE);
        }

        return $fresh;
    }

    private function lockStartIdentity(
        PropertyBootstrapProvisioningEnvironmentEnum $environment,
        string $idempotencyKey,
    ): void {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select(
            'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
            [$environment->value, $idempotencyKey],
        );
    }

    private function lockPropertyBindingIdentity(
        PropertyBootstrapProvisioningEnvironmentEnum $environment,
        string $propertyId,
    ): void {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select(
            'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
            [$environment->value, $propertyId],
        );
    }

    private function assertExactRunIdentity(
        PropertyBootstrapProvisioningRun $run,
        User $actor,
        PropertyBootstrapProvisioningEnvironmentEnum $environment,
        string $requestFingerprint,
        string $canonicalSha,
        string $source,
    ): void {
        if ($run->environment !== $environment
            || ! hash_equals((string) $run->request_fingerprint, $requestFingerprint)
            || ! hash_equals((string) $run->canonical_sha, $canonicalSha)
            || (string) $run->source !== $source
            || (string) $run->initiated_by !== (string) $actor->id
        ) {
            throw new RuntimeException(self::ERROR_IDEMPOTENCY_CONFLICT);
        }
    }

    private function lockMutableRun(
        PropertyBootstrapProvisioningRun $run,
        User $actor,
    ): PropertyBootstrapProvisioningRun {
        $freshActor = $this->assertAuthenticatedActiveActor($actor);
        $locked = PropertyBootstrapProvisioningRun::query()
            ->whereKey($run->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ((string) $locked->initiated_by !== (string) $freshActor->id) {
            throw new AuthorizationException(self::AUTHORIZATION_FAILURE);
        }

        if ($locked->status !== PropertyBootstrapProvisioningStatusEnum::InProgress) {
            throw new RuntimeException(self::ERROR_RUN_NOT_IN_PROGRESS);
        }

        return $locked;
    }

    private function assertSha256(string $value, string $label): void
    {
        if (strlen($value) !== 64 || ! ctype_xdigit($value)) {
            throw new InvalidArgumentException("The {$label} must be a 64-character hexadecimal SHA-256 value.");
        }
    }

    private function assertGitSha(string $value): void
    {
        if (strlen($value) !== 40 || ! ctype_xdigit($value)) {
            throw new InvalidArgumentException('The canonical SHA must be a 40-character hexadecimal Git SHA.');
        }
    }

    private function assertBoundedNonBlank(string $value, int $maximum, string $label): void
    {
        if (trim($value) === '' || mb_strlen($value) > $maximum) {
            throw new InvalidArgumentException("The {$label} must be non-blank and at most {$maximum} characters.");
        }
    }

    private function assertFailureCode(string $failureCode): void
    {
        if (strlen($failureCode) > 100
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/', $failureCode) !== 1
        ) {
            throw new InvalidArgumentException('The failure code must be a non-sensitive code of at most 100 characters.');
        }
    }

    private function assertSafeEvidence(array $evidence, bool $failureEvidence = false): void
    {
        try {
            json_encode($evidence, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Evidence must be JSON-serializable.', previous: $exception);
        }

        $forbidden = [
            'password',
            'secret',
            'credential',
            'api_key',
            'apikey',
            'session_token',
            'access_token',
            'refresh_token',
        ];

        if ($failureEvidence) {
            array_push($forbidden, 'stack_trace', 'stacktrace', 'exception_payload', 'raw_exception', 'sql', 'query');
        }

        $this->assertEvidenceKeysSafe($evidence, $forbidden);
    }

    private function assertEvidenceKeysSafe(array $evidence, array $forbidden): void
    {
        foreach ($evidence as $key => $value) {
            if (is_string($key)) {
                $normalized = strtolower(str_replace(['-', ' '], '_', $key));
                if (in_array($normalized, $forbidden, true)) {
                    throw new InvalidArgumentException('Evidence contains a prohibited sensitive field.');
                }
            }

            if (is_array($value)) {
                $this->assertEvidenceKeysSafe($value, $forbidden);
            }
        }
    }
}
