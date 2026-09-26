<?php

namespace Modules\Foundation\Authorization\Services;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Audit\Services\AuditService;
use Modules\Foundation\Authorization\Models\CheckoutSensitiveConfirmationConsumption;
use Modules\Foundation\Authorization\Models\CheckoutSensitiveConfirmationIssuance;
use Modules\Foundation\Authorization\ValueObjects\CheckoutSensitiveConfirmationPreflightResult;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService;

class CheckoutSensitiveConfirmationService
{
    public const INTENT = SensitiveActionConfirmationService::CHECKOUT_EXECUTION_INTENT;

    public const SESSION_KEY = 'sensitive_action_confirmation';

    public const ERROR_CONTEXT_REQUIRED = 'P8_CHECKOUT_CONFIRMATION_CONTEXT_REQUIRED';

    public const ERROR_MALFORMED_CONFIRMATION = 'P8_CHECKOUT_CONFIRMATION_MALFORMED';

    public const ERROR_CONTEXT_CONFLICT = 'P8_CHECKOUT_CONFIRMATION_CONTEXT_CONFLICT';

    public const ERROR_SESSION_MISMATCH = 'P8_CHECKOUT_CONFIRMATION_SESSION_MISMATCH';

    public const ERROR_EXPIRED = 'P8_CHECKOUT_CONFIRMATION_EXPIRED';

    public const ERROR_ALREADY_CONSUMED = 'P8_CHECKOUT_CONFIRMATION_ALREADY_CONSUMED';

    public const ERROR_CHECKOUT_IDENTITY_CONSUMED = 'P8_CHECKOUT_IDENTITY_ALREADY_CONSUMED';

    public const ERROR_ACTIVE_TRANSACTION_REQUIRED = 'P8_CHECKOUT_CONFIRMATION_ACTIVE_TRANSACTION_REQUIRED';

    public const ERROR_POSTGRESQL_REQUIRED = 'P8_CHECKOUT_CONFIRMATION_POSTGRESQL_REQUIRED';

    public const ERROR_DATABASE_INTEGRITY = 'P8_CHECKOUT_CONFIRMATION_DATABASE_INTEGRITY_FAILURE';

    public const ERROR_INVALID_IDEMPOTENCY = 'P8_CHECKOUT_CONFIRMATION_INVALID_IDEMPOTENCY_KEY';

    public function __construct(
        private readonly AuditService $auditService,
        private readonly FrontDeskCheckoutExecuteAuthorizationService $checkoutAuthorization,
    ) {}

    public static function fingerprintSession(string $sessionId): string
    {
        return hash('sha256', 'ivorq-checkout-session|'.$sessionId);
    }

    public function issueForCurrentSession(User $actor, string $frontDeskStayId, string $checkoutIdempotencyKey, string $password): CheckoutSensitiveConfirmationIssuance
    {
        $resolved = $this->checkoutAuthorization->resolveAuthorizedContext($actor, $frontDeskStayId);

        return $this->issue(new CheckoutSensitiveConfirmationContext(
            actor: $resolved['actor'],
            company: $resolved['company'],
            property: $resolved['property'],
            stay: $resolved['stay'],
            checkoutIdempotencyKey: $checkoutIdempotencyKey,
            sessionFingerprint: self::fingerprintSession(session()->getId()),
        ), $password);
    }

    private function issue(CheckoutSensitiveConfirmationContext $context, string $password): CheckoutSensitiveConfirmationIssuance
    {
        [$issuance, $authEpoch] = DB::transaction(function () use ($context, $password): array {
            $lockedActor = $this->authoritativeActor($context, 'update');
            if ($lockedActor->password === null || ! Hash::check($password, $lockedActor->password)) {
                throw new DomainException('The password is incorrect.');
            }

            $lockedContext = $this->contextWithActor($context, $lockedActor);
            $this->assertAuthoritativeContext($lockedContext);

            $idempotencyKey = $this->normalizeIdempotencyKey($lockedContext->checkoutIdempotencyKey);
            $authEpoch = (int) $lockedActor->auth_epoch;
            $now = Carbon::now()->startOfSecond();

            $existing = CheckoutSensitiveConfirmationIssuance::query()
                ->where('intent', self::INTENT)
                ->where('actor_id', $lockedActor->id)
                ->where('company_id', $lockedContext->company->id)
                ->where('property_id', $lockedContext->property->id)
                ->where('front_desk_stay_id', $lockedContext->stay->id)
                ->where('checkout_idempotency_key', $idempotencyKey)
                ->where('session_fingerprint', $lockedContext->sessionFingerprint)
                ->where('expires_at', '>', $now)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('checkout_sensitive_confirmation_consumptions as c')
                        ->whereColumn('c.issuance_id', 'checkout_sensitive_confirmation_issuances.id');
                })
                ->orderByDesc('created_at')
                ->first();

            if ($existing instanceof CheckoutSensitiveConfirmationIssuance) {
                $reference = $this->sessionReference();
                if ($reference === null) {
                    throw new DomainException(self::ERROR_MALFORMED_CONFIRMATION);
                }
                $this->assertIssuanceMatchesContext($existing, $lockedContext, $idempotencyKey, $reference, $now, $authEpoch);

                return [$existing, $authEpoch];
            }

            $confirmedAt = $now;
            $expiresAt = $now->copy()->addMinutes(SensitiveActionConfirmationService::CONFIRMATION_TTL_MINUTES);
            $confirmationIdentity = (string) Str::ulid();
            $confirmationFingerprint = $this->confirmationFingerprint(
                $confirmationIdentity,
                $lockedContext,
                $idempotencyKey,
                $confirmedAt,
                $expiresAt,
                $authEpoch,
            );

            try {
                $issuance = new CheckoutSensitiveConfirmationIssuance;
                $issuance->forceFill([
                    'confirmation_identity' => $confirmationIdentity,
                    'intent' => self::INTENT,
                    'actor_id' => $lockedActor->id,
                    'company_id' => $lockedContext->company->id,
                    'property_id' => $lockedContext->property->id,
                    'front_desk_stay_id' => $lockedContext->stay->id,
                    'checkout_idempotency_key' => $idempotencyKey,
                    'session_fingerprint' => $lockedContext->sessionFingerprint,
                    'confirmation_fingerprint' => $confirmationFingerprint,
                    'confirmed_at' => $confirmedAt,
                    'expires_at' => $expiresAt,
                    'created_at' => $confirmedAt,
                ])->save();
            } catch (QueryException $exception) {
                $this->mapPersistenceQueryException($exception);
            }

            $this->auditService->log(
                'checkout_sensitive_action_confirmed',
                $lockedActor,
                [],
                [
                    'intent' => self::INTENT,
                    'company_id' => $lockedContext->company->id,
                    'property_id' => $lockedContext->property->id,
                    'front_desk_stay_id' => $lockedContext->stay->id,
                    'checkout_idempotency_fingerprint' => hash('sha256', $idempotencyKey),
                    'confirmation_fingerprint' => $confirmationFingerprint,
                    'confirmed_at' => $confirmedAt->toISOString(),
                    'expires_at' => $expiresAt->toISOString(),
                    'correlation' => request()?->headers->get('X-Request-Id') ?? request()?->headers->get('X-Correlation-Id'),
                ],
                ['checkout-sensitive-confirmation', $lockedContext->property->id, $lockedContext->stay->id]
            );

            return [$issuance, $authEpoch];
        });

        $this->storeSessionReference($issuance, $authEpoch);

        return $issuance;
    }

    public function claimCurrentSessionConfirmationFor(User $actor, string $frontDeskStayId, string $checkoutIdempotencyKey): CheckoutSensitiveConfirmationClaimResult
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException(self::ERROR_POSTGRESQL_REQUIRED);
        }

        if (DB::transactionLevel() < 1) {
            throw new DomainException(self::ERROR_ACTIVE_TRANSACTION_REQUIRED);
        }

        $resolved = $this->checkoutAuthorization->resolveAuthorizedContext($actor, $frontDeskStayId);

        return $this->claimCurrentSessionConfirmation(new CheckoutSensitiveConfirmationContext(
            actor: $resolved['actor'],
            company: $resolved['company'],
            property: $resolved['property'],
            stay: $resolved['stay'],
            checkoutIdempotencyKey: $checkoutIdempotencyKey,
            sessionFingerprint: self::fingerprintSession(session()->getId()),
        ));
    }

    public function validateCurrentSessionConfirmationFor(User $actor, string $frontDeskStayId, string $checkoutIdempotencyKey): CheckoutSensitiveConfirmationPreflightResult
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException(self::ERROR_POSTGRESQL_REQUIRED);
        }

        $resolved = $this->checkoutAuthorization->resolveAuthorizedContext($actor, $frontDeskStayId);
        $context = new CheckoutSensitiveConfirmationContext(
            actor: $resolved['actor'],
            company: $resolved['company'],
            property: $resolved['property'],
            stay: $resolved['stay'],
            checkoutIdempotencyKey: $checkoutIdempotencyKey,
            sessionFingerprint: self::fingerprintSession(session()->getId()),
        );

        $authoritativeActor = $this->authoritativeActor($context);
        $context = $this->contextWithActor($context, $authoritativeActor);
        $this->assertAuthoritativeContext($context);

        $reference = $this->sessionReference();
        if ($reference === null) {
            throw new DomainException(self::ERROR_MALFORMED_CONFIRMATION);
        }

        $idempotencyKey = $this->normalizeIdempotencyKey($context->checkoutIdempotencyKey);
        $issuanceId = (string) ($reference['issuance_id'] ?? '');
        if ($issuanceId === '') {
            throw new DomainException(self::ERROR_MALFORMED_CONFIRMATION);
        }

        /** @var CheckoutSensitiveConfirmationIssuance|null $issuance */
        $issuance = CheckoutSensitiveConfirmationIssuance::query()
            ->whereKey($issuanceId)
            ->first();

        if (! $issuance) {
            throw new DomainException(self::ERROR_MALFORMED_CONFIRMATION);
        }

        $dbNow = $this->postgresWallClockUtc();
        $this->assertIssuanceMatchesContext($issuance, $context, $idempotencyKey, $reference, $dbNow, (int) $authoritativeActor->auth_epoch);

        $consumed = CheckoutSensitiveConfirmationConsumption::query()
            ->where('issuance_id', $issuance->id)
            ->exists();

        if ($consumed) {
            throw new DomainException(self::ERROR_ALREADY_CONSUMED);
        }

        return new CheckoutSensitiveConfirmationPreflightResult(
            issuanceId: $issuance->id,
            confirmationIdentity: $issuance->confirmation_identity,
            confirmationFingerprint: $issuance->confirmation_fingerprint,
            actorId: $issuance->actor_id,
            companyId: $issuance->company_id,
            propertyId: $issuance->property_id,
            frontDeskStayId: $issuance->front_desk_stay_id,
            checkoutIdempotencyKey: $issuance->checkout_idempotency_key,
            sessionFingerprint: $issuance->session_fingerprint,
            confirmedAt: Carbon::parse($issuance->confirmed_at),
            expiresAt: Carbon::parse($issuance->expires_at),
        );
    }

    private function claimCurrentSessionConfirmation(CheckoutSensitiveConfirmationContext $context): CheckoutSensitiveConfirmationClaimResult
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException(self::ERROR_POSTGRESQL_REQUIRED);
        }

        if (DB::transactionLevel() < 1) {
            throw new DomainException(self::ERROR_ACTIVE_TRANSACTION_REQUIRED);
        }

        $reference = $this->sessionReference();
        if ($reference === null) {
            throw new DomainException(self::ERROR_MALFORMED_CONFIRMATION);
        }

        $idempotencyKey = $this->normalizeIdempotencyKey($context->checkoutIdempotencyKey);
        $issuanceId = (string) ($reference['issuance_id'] ?? '');
        if ($issuanceId === '') {
            throw new DomainException(self::ERROR_MALFORMED_CONFIRMATION);
        }

        /** @var CheckoutSensitiveConfirmationIssuance|null $issuance */
        $issuance = CheckoutSensitiveConfirmationIssuance::query()
            ->whereKey($issuanceId)
            ->lockForUpdate()
            ->first();

        if (! $issuance) {
            throw new DomainException(self::ERROR_MALFORMED_CONFIRMATION);
        }

        $authoritativeActor = $this->authoritativeActor($context, 'share');
        $context = $this->contextWithActor($context, $authoritativeActor);
        $this->assertAuthoritativeContext($context);

        $dbNow = $this->postgresWallClockUtc();
        $this->assertIssuanceMatchesContext($issuance, $context, $idempotencyKey, $reference, $dbNow, (int) $authoritativeActor->auth_epoch);

        try {
            $consumption = new CheckoutSensitiveConfirmationConsumption;
            $consumption->forceFill([
                'issuance_id' => $issuance->id,
                'confirmation_identity' => $issuance->confirmation_identity,
                'confirmation_fingerprint' => $issuance->confirmation_fingerprint,
                'actor_id' => $issuance->actor_id,
                'company_id' => $issuance->company_id,
                'property_id' => $issuance->property_id,
                'front_desk_stay_id' => $issuance->front_desk_stay_id,
                'checkout_idempotency_key' => $issuance->checkout_idempotency_key,
                'consumed_at' => $dbNow,
                'created_at' => $dbNow,
            ])->save();
            $consumption->refresh();
        } catch (QueryException $exception) {
            $this->mapPersistenceQueryException($exception);
        }

        return new CheckoutSensitiveConfirmationClaimResult(
            consumptionId: $consumption->id,
            issuanceId: $issuance->id,
            confirmationIdentity: $issuance->confirmation_identity,
            confirmationFingerprint: $issuance->confirmation_fingerprint,
            actorId: $issuance->actor_id,
            companyId: $issuance->company_id,
            propertyId: $issuance->property_id,
            frontDeskStayId: $issuance->front_desk_stay_id,
            checkoutIdempotencyKey: $issuance->checkout_idempotency_key,
            confirmedAt: Carbon::parse($issuance->confirmed_at),
            expiresAt: Carbon::parse($issuance->expires_at),
            consumedAt: $consumption->consumed_at,
        );
    }

    public function cleanupCurrentSessionReference(): void
    {
        $confirmations = session()->get(self::SESSION_KEY, []);
        if (! is_array($confirmations)) {
            session()->put(self::SESSION_KEY, []);

            return;
        }

        unset($confirmations[self::INTENT]);
        session()->put(self::SESSION_KEY, $confirmations);
    }

    public function normalizeIdempotencyKey(string $key): string
    {
        $normalized = trim($key);

        if ($normalized === ''
            || strlen($normalized) > 120
            || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1) {
            throw new DomainException(self::ERROR_INVALID_IDEMPOTENCY);
        }

        return $normalized;
    }

    private function storeSessionReference(CheckoutSensitiveConfirmationIssuance $issuance, int $authEpoch): void
    {
        $confirmations = session()->get(self::SESSION_KEY, []);
        if (! is_array($confirmations)) {
            $confirmations = [];
        }

        $confirmations[self::INTENT] = [
            'actor_id' => $issuance->actor_id,
            'auth_epoch' => $authEpoch,
            'intent' => self::INTENT,
            'company_id' => $issuance->company_id,
            'property_id' => $issuance->property_id,
            'front_desk_stay_id' => $issuance->front_desk_stay_id,
            'checkout_idempotency_key' => $issuance->checkout_idempotency_key,
            'issuance_id' => $issuance->id,
            'confirmation_identity' => $issuance->confirmation_identity,
            'confirmation_fingerprint' => $issuance->confirmation_fingerprint,
            'session_fingerprint' => $issuance->session_fingerprint,
            'confirmed_at' => $issuance->confirmed_at?->toISOString(),
            'expires_at' => $issuance->expires_at?->toISOString(),
        ];

        session()->put(self::SESSION_KEY, $confirmations);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sessionReference(): ?array
    {
        $confirmations = session()->get(self::SESSION_KEY, []);

        if (! is_array($confirmations)
            || ! isset($confirmations[self::INTENT])
            || ! is_array($confirmations[self::INTENT])) {
            return null;
        }

        return $confirmations[self::INTENT];
    }

    /**
     * @param  array<string, mixed>  $reference
     */
    private function assertIssuanceMatchesContext(
        CheckoutSensitiveConfirmationIssuance $issuance,
        CheckoutSensitiveConfirmationContext $context,
        string $idempotencyKey,
        array $reference,
        Carbon $dbNow,
        int $currentAuthEpoch,
    ): void {
        if ($issuance->intent !== self::INTENT) {
            throw new DomainException(self::ERROR_CONTEXT_REQUIRED);
        }

        if ($issuance->actor_id !== $context->actor->id
            || $issuance->company_id !== $context->company->id
            || $issuance->property_id !== $context->property->id
            || $issuance->front_desk_stay_id !== $context->stay->id
            || $issuance->checkout_idempotency_key !== $idempotencyKey) {
            throw new DomainException(self::ERROR_CONTEXT_CONFLICT);
        }

        if ($issuance->session_fingerprint !== $context->sessionFingerprint) {
            throw new DomainException(self::ERROR_SESSION_MISMATCH);
        }

        foreach (['confirmation_identity', 'confirmation_fingerprint', 'session_fingerprint'] as $field) {
            if (($reference[$field] ?? null) !== $issuance->{$field}) {
                throw new DomainException($field === 'session_fingerprint' ? self::ERROR_SESSION_MISMATCH : self::ERROR_MALFORMED_CONFIRMATION);
            }
        }

        if (! $dbNow->lt(Carbon::parse($issuance->expires_at))) {
            throw new DomainException(self::ERROR_EXPIRED);
        }

        $hasExplicitAuthEpoch = array_key_exists('auth_epoch', $reference);
        if ($hasExplicitAuthEpoch && ! is_int($reference['auth_epoch'])) {
            throw new DomainException(self::ERROR_CONTEXT_REQUIRED);
        }
        $issuanceAuthEpoch = $hasExplicitAuthEpoch ? $reference['auth_epoch'] : 0;
        if ($issuanceAuthEpoch !== $currentAuthEpoch) {
            throw new DomainException(self::ERROR_CONTEXT_REQUIRED);
        }

        if ($hasExplicitAuthEpoch) {
            $expectedFingerprint = $this->confirmationFingerprint(
                $issuance->confirmation_identity,
                $context,
                $idempotencyKey,
                Carbon::parse($issuance->confirmed_at),
                Carbon::parse($issuance->expires_at),
                $issuanceAuthEpoch,
            );
            if (! hash_equals($expectedFingerprint, $issuance->confirmation_fingerprint)) {
                throw new DomainException(self::ERROR_MALFORMED_CONFIRMATION);
            }
        }

    }

    private function assertAuthoritativeContext(CheckoutSensitiveConfirmationContext $context): void
    {
        $resolved = $this->checkoutAuthorization->authorize($context->actor);

        if ($resolved['actor']->id !== $context->actor->id
            || $resolved['company']->id !== $context->company->id
            || $resolved['property']->id !== $context->property->id) {
            throw new DomainException(self::ERROR_CONTEXT_CONFLICT);
        }

        $stay = FrontDeskStay::withoutGlobalScopes()
            ->whereKey($context->stay->id)
            ->where('property_id', $resolved['property']->id)
            ->first();

        if (! $stay) {
            throw new DomainException(self::ERROR_CONTEXT_CONFLICT);
        }

        if ($context->sessionFingerprint !== self::fingerprintSession(session()->getId())) {
            throw new DomainException(self::ERROR_SESSION_MISMATCH);
        }
    }

    private function authoritativeActor(CheckoutSensitiveConfirmationContext $context, ?string $lockMode = null): User
    {
        $query = User::query()->whereKey($context->actor->getKey());
        if ($lockMode === 'update') {
            $query->lockForUpdate();
        } elseif ($lockMode === 'share') {
            $query->sharedLock();
        }

        $actor = $query->first();
        if (! $actor || ! $actor->is_active) {
            throw new DomainException(self::ERROR_CONTEXT_REQUIRED);
        }

        return $actor;
    }

    private function contextWithActor(CheckoutSensitiveConfirmationContext $context, User $actor): CheckoutSensitiveConfirmationContext
    {
        return new CheckoutSensitiveConfirmationContext(
            actor: $actor,
            company: $context->company,
            property: $context->property,
            stay: $context->stay,
            checkoutIdempotencyKey: $context->checkoutIdempotencyKey,
            sessionFingerprint: $context->sessionFingerprint,
        );
    }

    private function confirmationFingerprint(
        string $confirmationIdentity,
        CheckoutSensitiveConfirmationContext $context,
        string $idempotencyKey,
        Carbon $confirmedAt,
        Carbon $expiresAt,
        int $authEpoch,
    ): string {
        return hash('sha256', implode('|', [
            self::INTENT,
            $confirmationIdentity,
            $context->actor->id,
            $context->company->id,
            $context->property->id,
            $context->stay->id,
            $idempotencyKey,
            $context->sessionFingerprint,
            $confirmedAt->toISOString(),
            $expiresAt->toISOString(),
            $authEpoch,
        ]));
    }

    private function postgresWallClockUtc(): Carbon
    {
        $row = DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' AS wall_clock_utc");

        return Carbon::parse($row->wall_clock_utc);
    }

    private function mapPersistenceQueryException(QueryException $exception): void
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'p8_csc_consume_issuance_unique')) {
            throw new DomainException(self::ERROR_ALREADY_CONSUMED, previous: $exception);
        }

        if (str_contains($message, 'p8_csc_consume_checkout_unique')) {
            throw new DomainException(self::ERROR_CHECKOUT_IDENTITY_CONSUMED, previous: $exception);
        }

        if (str_contains($message, 'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_EXPIRED')) {
            throw new DomainException(self::ERROR_EXPIRED, previous: $exception);
        }

        if (str_contains($message, 'P8_CHECKOUT_CONFIRMATION_ISSUANCE_SOURCE_MISMATCH')
            || str_contains($message, 'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_CONTEXT_MISMATCH')
            || str_contains($message, 'P8_CHECKOUT_EXECUTION_CONFIRMATION_SOURCE_MISMATCH')) {
            throw new DomainException(self::ERROR_CONTEXT_CONFLICT, previous: $exception);
        }

        throw new DomainException(self::ERROR_DATABASE_INTEGRITY, previous: $exception);
    }
}
