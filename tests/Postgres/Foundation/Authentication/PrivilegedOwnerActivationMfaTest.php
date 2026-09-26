<?php

namespace Tests\Postgres\Foundation\Authentication;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Modules\Foundation\Authentication\Models\IdentityChallenge;
use Modules\Foundation\Authentication\Models\IdentitySecurityEvent;
use Modules\Foundation\Authentication\Models\OwnerActivationToken;
use Modules\Foundation\Authentication\Models\OwnerMfaFactor;
use Modules\Foundation\Authentication\Models\OwnerRecoveryCode;
use Modules\Foundation\Authentication\Services\AuthService;
use Modules\Foundation\Authentication\Services\IdentityChallengeService;
use Modules\Foundation\Authentication\Services\IdentitySecurityEventService;
use Modules\Foundation\Authentication\Services\OwnerActivationService;
use Modules\Foundation\Authentication\Services\OwnerAuthenticationService;
use Modules\Foundation\Authentication\Services\OwnerRecoveryCodeService;
use Modules\Foundation\Authentication\Services\OwnerTotpService;
use Modules\Foundation\Authentication\Services\PasswordService;
use Modules\Foundation\Authentication\Services\SessionRevocationService;
use Modules\Foundation\Authentication\Services\TokenService;
use Modules\Foundation\Authorization\Models\CheckoutSensitiveConfirmationConsumption;
use Modules\Foundation\Authorization\Models\CheckoutSensitiveConfirmationIssuance;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningEnvironmentEnum;
use Modules\Foundation\Property\Enums\PropertyBootstrapProvisioningStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBootstrapProvisioningRun;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Models\UserSession;
use Modules\Foundation\User\Services\ProfileService;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class PrivilegedOwnerActivationMfaTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private const PASSWORD = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('unused');
    }

    public function test_complete_activation_happy_path_materializes_only_empty_property_role_and_ten_codes(): void
    {
        [$activation, $owner, $company, $property, $challenge] = $this->emailVerifiedActivation();
        app(OwnerActivationService::class)->establishPassword($challenge, self::PASSWORD);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_sessions', 0);
        $this->assertFalse($owner->fresh()->is_active);
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $owner->id]);

        $uri = app(OwnerActivationService::class)->startMfa($challenge);
        $factor = OwnerMfaFactor::query()->sole();
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringNotContainsString(Crypt::decryptString($factor->encrypted_secret), $factor->encrypted_secret);
        $this->assertArrayNotHasKey('encrypted_secret', $factor->toArray());

        $previousCounter = intdiv(time(), 30) - 1;
        app(OwnerActivationService::class)->confirmMfa($challenge, app(OwnerTotpService::class)->currentCode($factor, $previousCounter));
        $codes = app(OwnerActivationService::class)->complete($challenge, app(OwnerTotpService::class)->currentCode($factor->fresh(), $previousCounter + 1));

        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        $this->assertSame(OwnerActivationStatus::Active, $activation->fresh()->status);
        $this->assertTrue($owner->fresh()->is_active);
        $role = DB::table('roles')->where('name', 'installation-owner')->where('property_id', $property->id)->sole();
        $this->assertSame(0, DB::table('role_has_permissions')->where('role_id', $role->id)->count());
        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $role->id,
            'model_id' => $owner->id,
            'model_type' => User::class,
            'property_id' => $property->id,
        ]);
        $this->assertDatabaseHas('identity_security_events', ['event_type' => 'OWNER_ACTIVATED', 'property_id' => $property->id]);
        $this->assertSame($company->id, $activation->company_id);
    }

    public function test_database_rejects_skipped_backward_same_state_and_delete_transitions(): void
    {
        [$activation] = $this->invitedActivation();

        foreach (['MFA_ENROLLING', 'ACTIVE', 'INVITED'] as $status) {
            try {
                DB::table('owner_activations')->where('id', $activation->id)->update(['status' => $status, 'version' => 2]);
                $this->fail('Invalid activation transition should fail.');
            } catch (QueryException) {
                $this->assertSame('INVITED', DB::table('owner_activations')->where('id', $activation->id)->value('status'));
            }
        }

        [$verifiedActivation] = $this->emailVerifiedActivation();
        try {
            DB::table('owner_activations')->where('id', $verifiedActivation->id)->update([
                'status' => 'INVITED',
                'version' => $verifiedActivation->version + 1,
            ]);
            $this->fail('Backward activation transition should fail.');
        } catch (QueryException) {
            $this->assertSame('EMAIL_VERIFIED', DB::table('owner_activations')->where('id', $verifiedActivation->id)->value('status'));
        }

        $this->expectException(QueryException::class);
        DB::table('owner_activations')->where('id', $activation->id)->delete();
    }

    public function test_activation_tokens_are_digest_only_strict_single_use_expiring_and_reissued(): void
    {
        [$activation] = $this->invitedActivation();
        $service = app(OwnerActivationService::class);
        $first = $service->issueToken($activation, 'verify_email');
        $row = OwnerActivationToken::query()->sole();

        $this->assertNotSame($first, $row->digest);
        $this->assertFalse(str_contains(json_encode($row->getAttributes(), JSON_THROW_ON_ERROR), $first));

        $second = $service->issueToken($activation, 'verify_email');
        $this->assertNotSame($first, $second);
        $this->assertNotNull($row->fresh()->revoked_at);
        $service->redeemEmail($second, 'verify_email');
        $this->assertNotNull(OwnerActivationToken::query()->where('digest', '<>', $row->digest)->sole()->consumed_at);
        try {
            $service->redeemEmail($second, 'verify_email');
            $this->fail('Consumed activation token should not replay.');
        } catch (ValidationException) {
            $this->assertSame(OwnerActivationStatus::EmailVerified, $activation->fresh()->status);
        }
        $this->expectException(ValidationException::class);
        $service->redeemEmail($first, 'verify_email');
    }

    public function test_activation_token_strict_encoding_expiry_and_identity_binding_fail_closed(): void
    {
        [$activation] = $this->invitedActivation();
        $service = app(OwnerActivationService::class);
        $token = $service->issueToken($activation, 'verify_email');

        foreach ([$token.'=', strtolower($token).'!'] as $malformed) {
            try {
                $service->redeemEmail($malformed, 'verify_email');
                $this->fail('Malformed token should fail.');
            } catch (ValidationException) {
                $this->assertSame(OwnerActivationStatus::Invited, $activation->fresh()->status);
            }
        }

        $otherCompany = CompanyFactory::new()->create();
        OwnerActivationToken::query()->update(['company_id' => $otherCompany->id]);
        try {
            $service->redeemEmail($token, 'verify_email');
            $this->fail('Activation token binding mismatch should fail.');
        } catch (ValidationException) {
            $this->assertSame(OwnerActivationStatus::Invited, $activation->fresh()->status);
        }
        OwnerActivationToken::query()->update(['company_id' => $activation->company_id]);

        OwnerActivationToken::query()->update(['expires_at' => now()->subSecond()]);
        $this->expectException(ValidationException::class);
        $service->redeemEmail($token, 'verify_email');
    }

    public function test_email_is_normalized_and_case_insensitive_unique_including_soft_deleted_users(): void
    {
        $user = UserFactory::new()->create(['email' => '  Owner.Mixed@Example.COM  ']);
        $this->assertSame('owner.mixed@example.com', $user->email);
        $user->delete();

        $this->expectException(QueryException::class);
        UserFactory::new()->create(['email' => 'OWNER.MIXED@EXAMPLE.COM']);
    }

    public function test_nullable_password_and_privileged_password_policy_fail_closed(): void
    {
        [$activation, $owner, $company, , $challenge] = $this->emailVerifiedActivation();

        $this->postJson('/auth/login', ['company_id' => $company->id, 'email' => $owner->email, 'password' => 'anything'])
            ->assertStatus(422);

        foreach (['too-short', 'passwordpassword'] as $invalid) {
            try {
                app(OwnerActivationService::class)->establishPassword($challenge, $invalid);
                $this->fail('Weak owner password should fail.');
            } catch (ValidationException) {
                $this->assertSame(OwnerActivationStatus::EmailVerified, $activation->fresh()->status);
            }
        }

        app(OwnerActivationService::class)->establishPassword($challenge, self::PASSWORD);
        $this->assertTrue(Hash::check(self::PASSWORD, $owner->fresh()->password));
    }

    public function test_totp_accepts_plus_minus_one_window_and_rejects_replay(): void
    {
        [, , , , $challenge] = $this->emailVerifiedActivation();
        $activationService = app(OwnerActivationService::class);
        $activationService->establishPassword($challenge, self::PASSWORD);
        $activationService->startMfa($challenge);
        $factor = OwnerMfaFactor::query()->sole();
        $counter = intdiv(time(), 30);
        $code = app(OwnerTotpService::class)->currentCode($factor, $counter - 1);

        $activationService->confirmMfa($challenge, $code);
        $this->assertSame($counter - 1, $factor->fresh()->last_accepted_counter);

        $this->expectException(ValidationException::class);
        app(OwnerTotpService::class)->verifyActive($factor->user_id, $code);
    }

    public function test_restarting_unconfirmed_mfa_enrollment_revokes_pending_factor_without_exposing_seed(): void
    {
        [, , , , $challenge] = $this->emailVerifiedActivation();
        $service = app(OwnerActivationService::class);
        $service->establishPassword($challenge, self::PASSWORD);
        $firstUri = $service->startMfa($challenge);
        $first = OwnerMfaFactor::query()->sole();
        $secondUri = $service->startMfa($challenge);

        $this->assertNotSame($firstUri, $secondUri);
        $this->assertSame('REVOKED', $first->fresh()->state);
        $this->assertSame(1, OwnerMfaFactor::query()->where('state', 'PENDING')->count());
        $this->assertArrayNotHasKey('encrypted_secret', OwnerMfaFactor::query()->where('state', 'PENDING')->sole()->toArray());
    }

    public function test_same_totp_counter_has_exactly_one_success_under_locked_claim(): void
    {
        [$activation, , , , $challenge] = $this->emailVerifiedActivation();
        $service = app(OwnerActivationService::class);
        $service->establishPassword($challenge, self::PASSWORD);
        $service->startMfa($challenge);
        $factor = OwnerMfaFactor::query()->sole();
        $counter = intdiv(time(), 30) - 1;
        $code = app(OwnerTotpService::class)->currentCode($factor, $counter);
        $service->confirmMfa($challenge, $code);

        $successes = 0;
        foreach ([1, 2] as $_) {
            try {
                app(OwnerTotpService::class)->verifyActive($factor->user_id, app(OwnerTotpService::class)->currentCode($factor, $counter + 1));
                $successes++;
            } catch (ValidationException) {
            }
        }

        $this->assertSame(1, $successes);
        $this->assertSame(OwnerActivationStatus::MfaEnrolled, $activation->fresh()->status);
    }

    public function test_recovery_codes_are_one_use_and_regeneration_revokes_previous_generation(): void
    {
        [$activation, $owner, , , , $codes] = $this->activeOwner();
        $service = app(OwnerRecoveryCodeService::class);
        $first = $codes[0];
        $service->claim($owner->id, $first);

        try {
            $service->claim($owner->id, $first);
            $this->fail('Recovery code replay should fail.');
        } catch (ValidationException) {
            $this->assertSame(1, OwnerRecoveryCode::query()->whereNotNull('used_at')->count());
        }

        $factor = OwnerMfaFactor::query()->where('user_id', $owner->id)->sole();
        $newCodes = $service->regenerate($factor);
        $this->assertCount(10, $newCodes);
        $this->assertSame(2, OwnerRecoveryCode::query()->max('generation'));
        $this->assertSame(9, OwnerRecoveryCode::query()->where('generation', 1)->whereNotNull('revoked_at')->count());
        $this->assertSame(OwnerActivationStatus::Active, $activation->fresh()->status);
    }

    public function test_final_activation_rolls_back_when_property_membership_is_not_valid(): void
    {
        [$activation, $owner, , $property, $challenge] = $this->emailVerifiedActivation();
        $service = app(OwnerActivationService::class);
        $service->establishPassword($challenge, self::PASSWORD);
        $service->startMfa($challenge);
        $factor = OwnerMfaFactor::query()->sole();
        $counter = intdiv(time(), 30) - 1;
        $service->confirmMfa($challenge, app(OwnerTotpService::class)->currentCode($factor, $counter));
        DB::table('property_user')->where('user_id', $owner->id)->where('property_id', $property->id)->update(['status' => 'suspended']);

        try {
            $service->complete($challenge, app(OwnerTotpService::class)->currentCode($factor, $counter + 1));
            $this->fail('Invalid property binding should roll back completion.');
        } catch (ValidationException) {
            $this->assertSame(OwnerActivationStatus::MfaEnrolled, $activation->fresh()->status);
            $this->assertFalse($owner->fresh()->is_active);
            $this->assertDatabaseCount('owner_recovery_codes', 0);
            $this->assertDatabaseMissing('model_has_roles', ['model_id' => $owner->id]);
        }
    }

    public function test_installation_owner_role_resolves_only_in_intended_property_team(): void
    {
        [, $owner, $company, $property] = $this->activeOwner();
        $other = PropertyFactory::new()->create(['company_id' => $company->id]);

        setPermissionsTeamId($property->id);
        $owner->unsetRelation('roles');
        $this->assertTrue($owner->hasRole('installation-owner'));
        setPermissionsTeamId($other->id);
        $owner->unsetRelation('roles');
        $this->assertFalse($owner->hasRole('installation-owner'));
        $this->assertFalse($owner->can('generalledger.period.manage'));
        $this->assertFalse($owner->can('property.view'));
    }

    public function test_api_password_step_issues_only_preauth_then_mfa_issues_eight_hour_token(): void
    {
        [, $owner, $company, $property, $factor] = $this->activeOwner();
        $response = $this->postJson('/auth/login', [
            'company_id' => $company->id,
            'email' => strtoupper($owner->email),
            'password' => self::PASSWORD,
            'device_name' => 'test-api',
        ])->assertStatus(202)->assertJson(['mfa_required' => true]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_sessions', 0);
        $challenge = $response->json('challenge');
        $challengeRow = IdentityChallenge::query()->where('purpose', 'LOGIN_MFA')->sole();
        $this->assertSame((int) $owner->auth_epoch, $challengeRow->auth_epoch);
        $activationChallenge = IdentityChallenge::query()->where('purpose', 'ACTIVATION')->sole();
        $this->assertNull($activationChallenge->auth_epoch);
        foreach ([[$challengeRow->id, null], [$activationChallenge->id, 0]] as [$challengeId, $invalidEpoch]) {
            try {
                DB::table('identity_challenges')->where('id', $challengeId)->update(['auth_epoch' => $invalidEpoch]);
                $this->fail('The challenge purpose/auth_epoch database contract must reject invalid combinations.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $code = app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1);
        $result = $this->postJson('/auth/mfa/totp', [
            'challenge' => $challenge,
            'code' => $code,
            'channel' => 'api',
            'device_name' => 'test-api',
        ])->assertOk()->assertJsonStructure(['token', 'user', 'expires_in']);

        $token = $owner->tokens()->sole();
        $this->assertSame($owner->auth_epoch, $token->auth_epoch);
        $this->assertSame($property->id, $token->property_id);
        $this->assertEqualsWithDelta(now()->addHours(8)->timestamp, $token->expires_at->timestamp, 5);
        $this->assertSame($owner->auth_epoch, UserSession::query()->sole()->auth_epoch);
        $this->assertNotNull($challengeRow->fresh()->credential_issued_at);
        $this->withToken($result->json('token'))->getJson('/api/user')->assertOk();
    }

    public function test_web_password_step_does_not_authenticate_and_final_mfa_regenerates_authenticated_session(): void
    {
        [, $owner, $company, $property, $factor] = $this->activeOwner();
        $response = $this->withSession(['login.tenant_id' => $company->id])->post('/login', [
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ]);
        $response->assertStatus(202);
        $this->assertGuest();
        $this->assertDatabaseCount('user_sessions', 0);

        $sessionCookie = $response->getCookie(config('session.cookie'));
        $this->assertNotNull($sessionCookie);
        $mfaResponse = $this->withCredentials()->withCookie($sessionCookie->getName(), $sessionCookie->getValue())->postJson('/auth/mfa/totp', [
            'challenge' => $response->json('challenge'),
            'code' => app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
            'channel' => 'web',
        ]);
        $this->assertSame(200, $mfaResponse->status(), (string) IdentitySecurityEvent::query()->where('event_type', 'LOGIN_MFA_FAILED')->latest('occurred_at')->value('reason_code'));
        $mfaResponse->assertJsonPath('redirect', '/frontdesk');

        $this->assertAuthenticatedAs($owner);
        $this->assertSame($property->id, session('active_property_id'));
        $this->assertSame($owner->auth_epoch, session('auth_epoch'));
        $this->assertNotNull(UserSession::query()->sole()->web_session_digest);

        $owner->increment('auth_epoch');
        $authenticatedCookie = $mfaResponse->getCookie(config('session.cookie'));
        $this->assertNotNull($authenticatedCookie);
        $this->withCredentials()->withCookie($authenticatedCookie->getName(), $authenticatedCookie->getValue())
            ->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_challenge_expiry_replay_and_five_attempt_exhaustion_are_fail_closed_and_generic(): void
    {
        [, $owner, $company, , $factor] = $this->activeOwner();
        $challenge = $this->beginApiLogin($owner, $company);
        IdentityChallenge::query()->where('purpose', 'LOGIN_MFA')->update(['expires_at' => now()->subSecond()]);
        $this->postJson('/auth/mfa/totp', ['challenge' => $challenge, 'code' => '000000', 'channel' => 'api'])
            ->assertStatus(422)->assertJsonValidationErrors('challenge');

        $challenge = $this->beginApiLogin($owner, $company);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/auth/mfa/totp', ['challenge' => $challenge, 'code' => '000000', 'channel' => 'api'])
                ->assertStatus(422)->assertJsonValidationErrors('code');
        }
        $this->postJson('/auth/mfa/totp', [
            'challenge' => $challenge,
            'code' => app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
            'channel' => 'api',
        ])->assertStatus(422)->assertJsonValidationErrors('challenge');
        $this->assertSame(5, (int) IdentityChallenge::query()->max('failed_attempts'));
    }

    public function test_stale_epoch_inactive_owner_and_wrong_property_bearers_are_denied(): void
    {
        [, $owner, $company, , $factor] = $this->activeOwner();
        $plain = $this->finishApiLogin($owner, $company, $factor);
        $this->withToken($plain)->getJson('/api/user')->assertOk();

        $owner->increment('auth_epoch');
        $this->withToken($plain)->getJson('/api/user')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $owner->forceFill(['auth_epoch' => 0, 'is_active' => true])->save();
        OwnerMfaFactor::query()->whereKey($factor->id)->update(['last_accepted_counter' => intdiv(time(), 30)]);
        $plain = $this->finishApiLogin($owner, $company, $factor->fresh(), 1);
        $owner->forceFill(['is_active' => false])->save();
        $this->withToken($plain)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_central_revocation_bumps_epoch_and_removes_every_credential_record(): void
    {
        [, $owner, $company, , $factor] = $this->activeOwner();
        $plain = $this->finishApiLogin($owner, $company, $factor);
        DB::table('sessions')->insert([
            'id' => 'web-session-to-revoke',
            'user_id' => $owner->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('test'),
            'last_activity' => time(),
        ]);
        $oldEpoch = $owner->auth_epoch;

        app(SessionRevocationService::class)->revokeAll($owner, 'IDENTITY_COMPROMISE');

        $this->assertSame($oldEpoch + 1, $owner->fresh()->auth_epoch);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_sessions', 0);
        $this->assertDatabaseMissing('sessions', ['user_id' => $owner->id]);
        $this->assertDatabaseHas('identity_security_events', ['event_type' => 'SESSIONS_REVOKED', 'reason_code' => 'IDENTITY_COMPROMISE']);
        $this->withToken($plain)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_recovery_code_final_login_is_single_use_and_challenge_cannot_replay(): void
    {
        [, $owner, $company, , , $codes] = $this->activeOwner();
        $challenge = $this->beginApiLogin($owner, $company);
        $result = $this->postJson('/auth/mfa/recovery-code', [
            'challenge' => $challenge,
            'code' => $codes[0],
            'channel' => 'api',
        ])->assertOk()->assertJsonStructure(['token']);

        $this->assertDatabaseHas('identity_security_events', ['event_type' => 'RECOVERY_CODE_USED', 'subject_user_id' => $owner->id]);
        $this->postJson('/auth/mfa/recovery-code', [
            'challenge' => $challenge,
            'code' => $codes[0],
            'channel' => 'api',
        ])->assertStatus(422)->assertJsonValidationErrors('challenge');
        $this->withToken($result->json('token'))->getJson('/api/user')->assertOk();
    }

    public function test_recovery_regeneration_requires_password_and_totp_then_revokes_all_credentials(): void
    {
        [, $owner, $company, , $factor, $initialCodes] = $this->activeOwner();
        $this->postJson('/auth/mfa/recovery-code', [
            'challenge' => $this->beginApiLogin($owner, $company),
            'code' => $initialCodes[0],
            'channel' => 'api',
        ])->assertOk();
        $oldEpoch = $owner->auth_epoch;
        $codes = app(OwnerAuthenticationService::class)->regenerateRecoveryCodes(
            $owner,
            self::PASSWORD,
            app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
        );

        $this->assertCount(10, $codes);
        $this->assertSame($oldEpoch + 1, $owner->fresh()->auth_epoch);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_sessions', 0);
        $this->assertDatabaseHas('identity_security_events', ['event_type' => 'RECOVERY_CODES_REGENERATED']);
        $this->assertDatabaseHas('identity_security_events', ['event_type' => 'SESSIONS_REVOKED', 'reason_code' => 'RECOVERY_CODES_REGENERATED']);
    }

    public function test_active_owner_password_reset_applies_policy_and_revokes_all_credentials_without_removing_mfa(): void
    {
        [, $owner, $company, , $factor] = $this->activeOwner();
        $this->finishApiLogin($owner, $company, $factor);
        $resetToken = Password::getRepository()->create($owner);
        $status = app(PasswordService::class)->reset([
            'email' => strtoupper($owner->email),
            'token' => $resetToken,
            'password' => 'a new sufficiently long passphrase',
            'password_confirmation' => 'a new sufficiently long passphrase',
        ]);

        $this->assertSame(Password::PASSWORD_RESET, $status);
        $this->assertTrue(Hash::check('a new sufficiently long passphrase', $owner->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_sessions', 0);
        $this->assertDatabaseHas('owner_mfa_factors', ['user_id' => $owner->id, 'state' => 'ACTIVE']);
        $this->assertDatabaseHas('identity_security_events', ['event_type' => 'PASSWORD_RESET']);
    }

    public function test_active_owner_password_change_revokes_current_and_all_other_credentials(): void
    {
        [, $owner, $company, , $factor] = $this->activeOwner();
        $plain = $this->finishApiLogin($owner, $company, $factor);
        $oldEpoch = $owner->auth_epoch;

        $this->assertTrue(app(ProfileService::class)->changePassword($owner, self::PASSWORD, 'another long owner passphrase'));
        $this->assertSame($oldEpoch + 1, $owner->fresh()->auth_epoch);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_sessions', 0);
        $this->withToken($plain)->getJson('/api/user')->assertUnauthorized();
        $this->assertDatabaseHas('identity_security_events', ['event_type' => 'SESSIONS_REVOKED', 'reason_code' => 'PASSWORD_CHANGE']);
    }

    public function test_password_change_winning_first_rejects_stale_recovery_regeneration_after_user_lock_wait(): void
    {
        [, $owner, , , $factor] = $this->activeOwner();
        $oldEpoch = $owner->auth_epoch;
        $profileWorker = null;
        $recoveryWorker = null;
        $staleProfileWorker = null;

        $this->dropAtomicityTrigger('b5a1b1_hold_password_change');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION b5a1b1_hold_password_change() RETURNS trigger AS $$
            BEGIN
                PERFORM pg_sleep(8);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER trg_b5a1b1_hold_password_change
            BEFORE INSERT ON identity_security_events
            FOR EACH ROW WHEN (NEW.event_type = 'PASSWORD_RESET')
            EXECUTE FUNCTION b5a1b1_hold_password_change();
        SQL);

        try {
            $profileWorker = $this->spawnAtomicityWorker([
                'operation' => 'profile_password_change',
                'application_name' => 'b5a1b1_case_a_password_change',
                'user_id' => $owner->id,
                'current_password' => self::PASSWORD,
                'new_password' => 'case A replacement owner passphrase',
            ]);
            $this->waitForBackendWait('b5a1b1_case_a_password_change', 'Timeout', 'PgSleep');

            $recoveryWorker = $this->spawnAtomicityWorker([
                'operation' => 'recovery_regeneration',
                'application_name' => 'b5a1b1_case_a_recovery',
                'user_id' => $owner->id,
                'current_password' => self::PASSWORD,
                'totp_code' => app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
            ]);
            $this->waitForBackendWait('b5a1b1_case_a_recovery', 'Lock');

            $staleProfileWorker = $this->spawnAtomicityWorker([
                'operation' => 'profile_password_change',
                'application_name' => 'b5a1b1_case_a_stale_password_change',
                'user_id' => $owner->id,
                'current_password' => self::PASSWORD,
                'new_password' => 'case A stale mutation must not win',
            ]);
            $this->waitForBackendWait('b5a1b1_case_a_stale_password_change', 'Lock');

            $profileResult = $this->collectAtomicityWorker($profileWorker);
            $profileWorker = null;
            $recoveryResult = $this->collectAtomicityWorker($recoveryWorker);
            $recoveryWorker = null;
            $staleProfileResult = $this->collectAtomicityWorker($staleProfileWorker);
            $staleProfileWorker = null;
        } finally {
            $this->terminateAtomicityWorker($profileWorker);
            $this->terminateAtomicityWorker($recoveryWorker);
            $this->terminateAtomicityWorker($staleProfileWorker);
            $this->dropAtomicityTrigger('b5a1b1_hold_password_change');
        }

        $this->assertSame('success', $profileResult['status'] ?? null, $profileResult['_stderr'] ?? '');
        $this->assertSame('validation_rejected', $recoveryResult['status'] ?? null, $recoveryResult['_stderr'] ?? '');
        $this->assertSame('validation_rejected', $staleProfileResult['status'] ?? null, $staleProfileResult['_stderr'] ?? '');
        $this->assertTrue(Hash::check('case A replacement owner passphrase', $owner->fresh()->password));
        $this->assertSame($oldEpoch + 1, $owner->fresh()->auth_epoch);
        $this->assertSame(1, OwnerRecoveryCode::query()->max('generation'));
        $this->assertSame(1, IdentitySecurityEvent::query()->where('event_type', 'PASSWORD_RESET')->where('reason_code', 'PASSWORD_CHANGE')->count());
        $this->assertSame(1, IdentitySecurityEvent::query()->where('event_type', 'SESSIONS_REVOKED')->where('reason_code', 'PASSWORD_CHANGE')->count());
        $this->assertDatabaseMissing('identity_security_events', ['event_type' => 'RECOVERY_CODES_REGENERATED']);
        $this->assertDatabaseMissing('identity_security_events', ['event_type' => 'SESSIONS_REVOKED', 'reason_code' => 'RECOVERY_CODES_REGENERATED']);
    }

    public function test_recovery_regeneration_locking_first_completes_before_waiting_password_change(): void
    {
        [, $owner, , , $factor] = $this->activeOwner();
        $oldEpoch = $owner->auth_epoch;
        $recoveryWorker = null;
        $profileWorker = null;

        $this->dropAtomicityTrigger('b5a1b1_hold_recovery_regeneration');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION b5a1b1_hold_recovery_regeneration() RETURNS trigger AS $$
            BEGIN
                PERFORM pg_sleep(4);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER trg_b5a1b1_hold_recovery_regeneration
            BEFORE INSERT ON owner_recovery_codes
            FOR EACH ROW WHEN (NEW.generation = 2 AND NEW.ordinal = 1)
            EXECUTE FUNCTION b5a1b1_hold_recovery_regeneration();
        SQL);

        try {
            $recoveryWorker = $this->spawnAtomicityWorker([
                'operation' => 'recovery_regeneration',
                'application_name' => 'b5a1b1_case_b_recovery',
                'user_id' => $owner->id,
                'current_password' => self::PASSWORD,
                'totp_code' => app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
            ]);
            $this->waitForBackendWait('b5a1b1_case_b_recovery', 'Timeout', 'PgSleep');

            $profileWorker = $this->spawnAtomicityWorker([
                'operation' => 'profile_password_change',
                'application_name' => 'b5a1b1_case_b_password_change',
                'user_id' => $owner->id,
                'current_password' => self::PASSWORD,
                'new_password' => 'case B replacement owner passphrase',
            ]);
            $this->waitForBackendWait('b5a1b1_case_b_password_change', 'Lock');

            $recoveryResult = $this->collectAtomicityWorker($recoveryWorker);
            $recoveryWorker = null;
            $profileResult = $this->collectAtomicityWorker($profileWorker);
            $profileWorker = null;
        } finally {
            $this->terminateAtomicityWorker($recoveryWorker);
            $this->terminateAtomicityWorker($profileWorker);
            $this->dropAtomicityTrigger('b5a1b1_hold_recovery_regeneration');
        }

        $this->assertSame('success', $recoveryResult['status'] ?? null, $recoveryResult['_stderr'] ?? '');
        $this->assertSame(10, $recoveryResult['recovery_code_count'] ?? null);
        $this->assertSame('success', $profileResult['status'] ?? null, $profileResult['_stderr'] ?? '');
        $this->assertTrue(Hash::check('case B replacement owner passphrase', $owner->fresh()->password));
        $this->assertSame($oldEpoch + 2, $owner->fresh()->auth_epoch);
        $this->assertSame(2, OwnerRecoveryCode::query()->max('generation'));
        $this->assertDatabaseHas('identity_security_events', ['event_type' => 'RECOVERY_CODES_REGENERATED']);
        $this->assertDatabaseHas('identity_security_events', ['event_type' => 'SESSIONS_REVOKED', 'reason_code' => 'RECOVERY_CODES_REGENERATED']);
        $this->assertDatabaseHas('identity_security_events', ['event_type' => 'SESSIONS_REVOKED', 'reason_code' => 'PASSWORD_CHANGE']);
    }

    public function test_sensitive_action_reloads_authoritative_user_and_rejects_stale_password_snapshot(): void
    {
        [, $owner, $company, $property] = $this->activeOwner();
        $staleOwner = User::query()->findOrFail($owner->id);
        User::query()->whereKey($owner->id)->update(['password' => Hash::make('replacement password after stale snapshot')]);

        try {
            app(SensitiveActionConfirmationService::class)->confirm(
                $staleOwner,
                'administrative-sensitive-action',
                self::PASSWORD,
                $company->id,
                $property->id,
            );
            $this->fail('A stale in-memory password hash must not authorize sensitive confirmation.');
        } catch (DomainException $exception) {
            $this->assertSame('The password is incorrect.', $exception->getMessage());
        }

        $this->assertFalse(app(SensitiveActionConfirmationService::class)->hasValidConfirmation(
            $owner,
            'administrative-sensitive-action',
            $company->id,
            $property->id,
        ));
    }

    public function test_checkout_confirmation_reloads_authoritative_user_and_rejects_stale_password_snapshot(): void
    {
        [, $owner, $company, $property] = $this->activeOwner();
        $stay = $this->checkoutScenario($owner, $company, $property);
        $staleOwner = User::query()->findOrFail($owner->id);
        User::query()->whereKey($owner->id)->update(['password' => Hash::make('replacement checkout password')]);

        try {
            app(CheckoutSensitiveConfirmationService::class)->issueForCurrentSession(
                $staleOwner,
                $stay->id,
                'r2-checkout-stale-snapshot',
                self::PASSWORD,
            );
            $this->fail('A stale in-memory password hash must not authorize checkout confirmation.');
        } catch (DomainException $exception) {
            $this->assertSame('The password is incorrect.', $exception->getMessage());
        }

        $this->assertSame(0, CheckoutSensitiveConfirmationIssuance::query()->count());
    }

    public function test_sensitive_action_password_mutation_winning_first_rejects_old_password_after_lock_wait(): void
    {
        [, $owner, $company, $property] = $this->activeOwner();
        $passwordWorker = null;
        $confirmationWorker = null;

        $this->installPasswordChangeHoldTrigger();

        try {
            $passwordWorker = $this->spawnAtomicityWorker([
                'operation' => 'profile_password_change',
                'application_name' => 'b5a1b1_r2_sensitive_password_first',
                'user_id' => $owner->id,
                'current_password' => self::PASSWORD,
                'new_password' => 'r2 sensitive password first replacement',
            ]);
            $this->waitForBackendWait('b5a1b1_r2_sensitive_password_first', 'Timeout', 'PgSleep');

            $confirmationWorker = $this->spawnAtomicityWorker([
                'operation' => 'sensitive_confirmation',
                'application_name' => 'b5a1b1_r2_sensitive_waiter',
                'user_id' => $owner->id,
                'current_password' => self::PASSWORD,
                'company_id' => $company->id,
                'property_id' => $property->id,
                'intent' => 'administrative-sensitive-action',
            ]);
            $this->waitForBackendWait('b5a1b1_r2_sensitive_waiter', 'Lock');

            $passwordResult = $this->collectAtomicityWorker($passwordWorker);
            $passwordWorker = null;
            $confirmationResult = $this->collectAtomicityWorker($confirmationWorker);
            $confirmationWorker = null;
        } finally {
            $this->terminateAtomicityWorker($passwordWorker);
            $this->terminateAtomicityWorker($confirmationWorker);
            $this->dropAtomicityTrigger('b5a1b1_hold_password_change');
        }

        $this->assertSame('success', $passwordResult['status'] ?? null, $passwordResult['_stderr'] ?? '');
        $this->assertSame('domain_rejected', $confirmationResult['status'] ?? null, $confirmationResult['_stderr'] ?? '');
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'sensitive_action_confirmed')->count());
    }

    public function test_sensitive_confirmation_winning_first_is_invalid_after_waiting_password_mutation(): void
    {
        [, $owner, $company, $property] = $this->activeOwner();
        $oldEpoch = (int) $owner->auth_epoch;
        $confirmationWorker = null;
        $passwordWorker = null;

        try {
            $confirmationWorker = $this->spawnAtomicityWorker([
                'operation' => 'sensitive_confirmation',
                'application_name' => 'b5a1b1_r2_sensitive_first',
                'user_id' => $owner->id,
                'current_password' => self::PASSWORD,
                'company_id' => $company->id,
                'property_id' => $property->id,
                'intent' => 'administrative-sensitive-action',
                'hold_seconds' => 6,
            ]);
            $this->waitForBackendWait('b5a1b1_r2_sensitive_first', 'Timeout', 'PgSleep');

            $passwordWorker = $this->spawnAtomicityWorker([
                'operation' => 'profile_password_change',
                'application_name' => 'b5a1b1_r2_password_waiter',
                'user_id' => $owner->id,
                'current_password' => self::PASSWORD,
                'new_password' => 'r2 confirmation first replacement',
            ]);
            $this->waitForBackendWait('b5a1b1_r2_password_waiter', 'Lock');

            $confirmationResult = $this->collectAtomicityWorker($confirmationWorker);
            $confirmationWorker = null;
            $passwordResult = $this->collectAtomicityWorker($passwordWorker);
            $passwordWorker = null;
        } finally {
            $this->terminateAtomicityWorker($confirmationWorker);
            $this->terminateAtomicityWorker($passwordWorker);
        }

        $this->assertSame('success', $confirmationResult['status'] ?? null, $confirmationResult['_stderr'] ?? '');
        $this->assertSame('success', $passwordResult['status'] ?? null, $passwordResult['_stderr'] ?? '');
        $this->assertSame($oldEpoch + 1, (int) $owner->fresh()->auth_epoch);
        session(['sensitive_action_confirmation' => $confirmationResult['confirmation'] ?? []]);
        $this->assertFalse(app(SensitiveActionConfirmationService::class)->hasValidConfirmation(
            $owner,
            'administrative-sensitive-action',
            $company->id,
            $property->id,
        ));
    }

    public function test_checkout_password_mutation_winning_first_rejects_old_password_after_lock_wait(): void
    {
        [, $owner, $company, $property] = $this->activeOwner();
        $stay = $this->checkoutScenario($owner, $company, $property);
        $passwordWorker = null;
        $checkoutWorker = null;

        $this->installPasswordChangeHoldTrigger();

        try {
            $passwordWorker = $this->spawnAtomicityWorker([
                'operation' => 'profile_password_change',
                'application_name' => 'b5a1b1_r2_checkout_password_first',
                'user_id' => $owner->id,
                'current_password' => self::PASSWORD,
                'new_password' => 'r2 checkout password first replacement',
            ]);
            $this->waitForBackendWait('b5a1b1_r2_checkout_password_first', 'Timeout', 'PgSleep');

            $checkoutWorker = $this->spawnAtomicityWorker([
                'operation' => 'checkout_confirmation',
                'application_name' => 'b5a1b1_r2_checkout_waiter',
                'user_id' => $owner->id,
                'current_password' => self::PASSWORD,
                'company_id' => $company->id,
                'property_id' => $property->id,
                'front_desk_stay_id' => $stay->id,
                'idempotency_key' => 'r2-checkout-password-first',
            ]);
            $this->waitForBackendWait('b5a1b1_r2_checkout_waiter', 'Lock');

            $passwordResult = $this->collectAtomicityWorker($passwordWorker);
            $passwordWorker = null;
            $checkoutResult = $this->collectAtomicityWorker($checkoutWorker);
            $checkoutWorker = null;
        } finally {
            $this->terminateAtomicityWorker($passwordWorker);
            $this->terminateAtomicityWorker($checkoutWorker);
            $this->dropAtomicityTrigger('b5a1b1_hold_password_change');
        }

        $this->assertSame('success', $passwordResult['status'] ?? null, $passwordResult['_stderr'] ?? '');
        $this->assertSame('domain_rejected', $checkoutResult['status'] ?? null, $checkoutResult['_stderr'] ?? '');
        $this->assertSame(0, CheckoutSensitiveConfirmationIssuance::query()->count());
    }

    public function test_auth_epoch_change_invalidates_sensitive_action_confirmation(): void
    {
        [, $owner, $company, $property] = $this->activeOwner();
        $service = app(SensitiveActionConfirmationService::class);
        $service->confirm($owner, 'administrative-sensitive-action', self::PASSWORD, $company->id, $property->id);
        $this->assertTrue($service->hasValidConfirmation($owner, 'administrative-sensitive-action', $company->id, $property->id));

        User::query()->whereKey($owner->id)->increment('auth_epoch');

        $this->assertFalse($service->hasValidConfirmation($owner, 'administrative-sensitive-action', $company->id, $property->id));
        $this->assertNull($service->confirmationMetadataFor($owner, 'administrative-sensitive-action', $company->id, $property->id));
    }

    public function test_auth_epoch_change_invalidates_checkout_confirmation_before_consumption(): void
    {
        [, $owner, $company, $property] = $this->activeOwner();
        $stay = $this->checkoutScenario($owner, $company, $property);
        $service = app(CheckoutSensitiveConfirmationService::class);
        $service->issueForCurrentSession($owner, $stay->id, 'r2-checkout-epoch', self::PASSWORD);
        User::query()->whereKey($owner->id)->increment('auth_epoch');

        try {
            DB::transaction(fn () => $service->claimCurrentSessionConfirmationFor($owner, $stay->id, 'r2-checkout-epoch'));
            $this->fail('An auth_epoch change must invalidate a checkout confirmation.');
        } catch (DomainException $exception) {
            $this->assertSame(CheckoutSensitiveConfirmationService::ERROR_CONTEXT_REQUIRED, $exception->getMessage());
        }

        $this->assertSame(0, CheckoutSensitiveConfirmationConsumption::query()->count());
    }

    public function test_password_change_invalidates_pending_login_mfa_challenge_without_deleting_it(): void
    {
        [, $owner, $company, , $factor] = $this->activeOwner();
        $bearer = $this->beginApiLogin($owner, $company);
        $challenge = IdentityChallenge::query()->where('purpose', 'LOGIN_MFA')->sole();
        $issuedEpoch = $challenge->auth_epoch;

        $this->assertTrue(app(ProfileService::class)->changePassword($owner, self::PASSWORD, 'r3 pending challenge replacement'));
        $this->assertSame($issuedEpoch + 1, (int) $owner->fresh()->auth_epoch);

        $this->postJson('/auth/mfa/totp', [
            'challenge' => $bearer,
            'code' => app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
            'channel' => 'api',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $challenge->refresh();
        $this->assertSame($issuedEpoch, $challenge->auth_epoch);
        $this->assertNull($challenge->consumed_at);
        $this->assertNull($challenge->credential_issued_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_security_revocation_epoch_invalidates_pending_login_mfa_challenge_without_deleting_it(): void
    {
        [, $owner, $company, , $factor] = $this->activeOwner();
        $bearer = $this->beginApiLogin($owner, $company);
        $challenge = IdentityChallenge::query()->where('purpose', 'LOGIN_MFA')->sole();
        $issuedEpoch = $challenge->auth_epoch;

        app(SessionRevocationService::class)->revokeAll($owner, 'IDENTITY_COMPROMISE');

        $this->assertSame($issuedEpoch + 1, (int) $owner->fresh()->auth_epoch);
        $this->assertTrue(IdentityChallenge::query()->whereKey($challenge->id)->exists());
        $this->postJson('/auth/mfa/totp', [
            'challenge' => $bearer,
            'code' => app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
            'channel' => 'api',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $challenge->refresh();
        $this->assertNull($challenge->consumed_at);
        $this->assertNull($challenge->credential_issued_at);
    }

    public function test_epoch_change_after_mfa_prevents_api_token_and_session_issuance(): void
    {
        [, $owner, $company, , $factor] = $this->activeOwner();
        $bearer = $this->beginApiLogin($owner, $company);
        $authentication = app(OwnerAuthenticationService::class)->completeTotp(
            $bearer,
            app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
            'api',
            null,
        );
        $challenge = IdentityChallenge::query()->whereKey($authentication->challengeId)->sole();
        $this->assertNotNull($challenge->consumed_at);

        User::query()->whereKey($owner->id)->increment('auth_epoch');

        try {
            app(TokenService::class)->createForVerifiedOwner($authentication, 'r3-stale-epoch');
            $this->fail('A completed MFA proof from an older auth epoch must not issue an API credential.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mfa', $exception->errors());
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_sessions', 0);
        $this->assertNull($challenge->fresh()->credential_issued_at);
    }

    public function test_epoch_change_after_mfa_prevents_verified_owner_web_session_issuance(): void
    {
        [, $owner, $company, , $factor] = $this->activeOwner();
        $bearer = $this->beginApiLogin($owner, $company);
        $authentication = app(OwnerAuthenticationService::class)->completeTotp(
            $bearer,
            app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
            'api',
            null,
        );
        $challenge = IdentityChallenge::query()->whereKey($authentication->challengeId)->sole();
        User::query()->whereKey($owner->id)->increment('auth_epoch');

        try {
            app(TokenService::class)->recordVerifiedOwnerWebSession($authentication, 'r3-stale-web-session');
            $this->fail('A completed MFA proof from an older auth epoch must not issue a web credential.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mfa', $exception->errors());
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_sessions', 0);
        $this->assertNull($challenge->fresh()->credential_issued_at);
    }

    public function test_owner_auth_source_paths_preserve_challenge_activation_user_lock_order(): void
    {
        $this->assertMethodMatchesLockOrder(
            AuthService::class,
            'login',
            '/OwnerActivation::query\(\).*?->lockForUpdate\(\).*?User::query\(\).*?->lockForUpdate\(\)/s',
        );
        $this->assertMethodMatchesLockOrder(
            IdentityChallengeService::class,
            'assertActivation',
            '/lockValid\(.*?OwnerActivation::query\(\).*?->lockForUpdate\(\)/s',
        );
        $this->assertMethodMatchesLockOrder(
            OwnerAuthenticationService::class,
            'complete',
            '/lockValid\(.*?OwnerActivation::query\(\).*?->lockForUpdate\(\).*?User::query\(\).*?->lockForUpdate\(\)/s',
        );
        $this->assertMethodMatchesLockOrder(
            TokenService::class,
            'claimVerifiedOwner',
            '/IdentityChallenge::query\(\).*?->lockForUpdate\(\).*?OwnerActivation::query\(\).*?->lockForUpdate\(\).*?User::query\(\).*?->lockForUpdate\(\)/s',
        );
    }

    public function test_mfa_completion_and_new_owner_login_serialize_without_deadlock(): void
    {
        [$activation, $owner, $company, , $factor] = $this->activeOwner();
        $bearer = $this->beginApiLogin($owner, $company);
        $loginWorker = null;
        $completionWorker = null;

        try {
            $loginWorker = $this->spawnAtomicityWorker([
                'operation' => 'owner_login_with_activation_hold',
                'application_name' => 'b5a1b1_r3_login_vs_mfa_login',
                'activation_id' => $activation->id,
                'hold_seconds' => 4,
                'email' => $owner->email,
                'current_password' => self::PASSWORD,
                'company_id' => $company->id,
            ]);
            $this->waitForBackendWait('b5a1b1_r3_login_vs_mfa_login', 'Timeout', 'PgSleep');

            $completionWorker = $this->spawnAtomicityWorker([
                'operation' => 'complete_owner_mfa',
                'application_name' => 'b5a1b1_r3_login_vs_mfa_completion',
                'challenge' => $bearer,
                'totp_code' => app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
                'channel' => 'api',
            ]);
            $this->waitForBackendWait('b5a1b1_r3_login_vs_mfa_completion', 'Lock');

            $loginResult = $this->collectAtomicityWorker($loginWorker);
            $loginWorker = null;
            $completionResult = $this->collectAtomicityWorker($completionWorker);
            $completionWorker = null;
        } finally {
            $this->terminateAtomicityWorker($loginWorker);
            $this->terminateAtomicityWorker($completionWorker);
        }

        $this->assertSame('success', $loginResult['status'] ?? null, $loginResult['_stderr'] ?? '');
        $this->assertTrue($loginResult['mfa_required'] ?? false);
        $this->assertSame('success', $completionResult['status'] ?? null, $completionResult['_stderr'] ?? '');
        $this->assertNotNull(IdentityChallenge::query()->whereKey($completionResult['challenge_id'] ?? null)->value('consumed_at'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_verified_credential_issuance_and_new_owner_login_serialize_without_deadlock_and_issue_once(): void
    {
        [$activation, $owner, $company, , $factor] = $this->activeOwner();
        $bearer = $this->beginApiLogin($owner, $company);
        $authentication = app(OwnerAuthenticationService::class)->completeTotp(
            $bearer,
            app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
            'api',
            null,
        );
        $loginWorker = null;
        $credentialWorker = null;

        try {
            $loginWorker = $this->spawnAtomicityWorker([
                'operation' => 'owner_login_with_activation_hold',
                'application_name' => 'b5a1b1_r3_login_vs_credential_login',
                'activation_id' => $activation->id,
                'hold_seconds' => 4,
                'email' => $owner->email,
                'current_password' => self::PASSWORD,
                'company_id' => $company->id,
            ]);
            $this->waitForBackendWait('b5a1b1_r3_login_vs_credential_login', 'Timeout', 'PgSleep');

            $credentialWorker = $this->spawnAtomicityWorker([
                'operation' => 'create_verified_owner_token',
                'application_name' => 'b5a1b1_r3_login_vs_credential_issuance',
                'user_id' => $authentication->userId,
                'company_id' => $authentication->companyId,
                'property_id' => $authentication->propertyId,
                'challenge_id' => $authentication->challengeId,
            ]);
            $this->waitForBackendWait('b5a1b1_r3_login_vs_credential_issuance', 'Lock');

            $loginResult = $this->collectAtomicityWorker($loginWorker);
            $loginWorker = null;
            $credentialResult = $this->collectAtomicityWorker($credentialWorker);
            $credentialWorker = null;
        } finally {
            $this->terminateAtomicityWorker($loginWorker);
            $this->terminateAtomicityWorker($credentialWorker);
        }

        $this->assertSame('success', $loginResult['status'] ?? null, $loginResult['_stderr'] ?? '');
        $this->assertTrue($loginResult['mfa_required'] ?? false);
        $this->assertSame('success', $credentialResult['status'] ?? null, $credentialResult['_stderr'] ?? '');
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('user_sessions', 1);
        $this->assertNotNull(IdentityChallenge::query()->whereKey($authentication->challengeId)->sole()->credential_issued_at);
    }

    public function test_login_and_final_activation_serialize_without_opposing_owner_user_lock_order(): void
    {
        [$activation, $owner, $company, , $challenge] = $this->emailVerifiedActivation();
        $activationService = app(OwnerActivationService::class);
        $activationService->establishPassword($challenge, self::PASSWORD);
        $activationService->startMfa($challenge);
        $factor = OwnerMfaFactor::query()->sole();
        $counter = intdiv(time(), 30) - 1;
        $activationService->confirmMfa($challenge, app(OwnerTotpService::class)->currentCode($factor, $counter));
        $completionCode = app(OwnerTotpService::class)->currentCode($factor->fresh(), $counter + 1);

        $holderWorker = null;
        $completionWorker = null;
        $loginWorker = null;

        try {
            $holderWorker = $this->spawnAtomicityWorker([
                'operation' => 'hold_user_lock',
                'application_name' => 'b5a1b1_r2_user_lock_holder',
                'user_id' => $owner->id,
                'hold_seconds' => 6,
            ]);
            $this->waitForBackendWait('b5a1b1_r2_user_lock_holder', 'Timeout', 'PgSleep');

            $completionWorker = $this->spawnAtomicityWorker([
                'operation' => 'complete_activation',
                'application_name' => 'b5a1b1_r2_activation_completion',
                'challenge' => $challenge,
                'totp_code' => $completionCode,
            ]);
            $this->waitForBackendWait('b5a1b1_r2_activation_completion', 'Lock');

            $loginWorker = $this->spawnAtomicityWorker([
                'operation' => 'owner_login',
                'application_name' => 'b5a1b1_r2_owner_login',
                'email' => $owner->email,
                'current_password' => self::PASSWORD,
                'company_id' => $company->id,
            ]);
            $this->waitForBackendWait('b5a1b1_r2_owner_login', 'Lock');

            $holderResult = $this->collectAtomicityWorker($holderWorker);
            $holderWorker = null;
            $completionResult = $this->collectAtomicityWorker($completionWorker);
            $completionWorker = null;
            $loginResult = $this->collectAtomicityWorker($loginWorker);
            $loginWorker = null;
        } finally {
            $this->terminateAtomicityWorker($holderWorker);
            $this->terminateAtomicityWorker($completionWorker);
            $this->terminateAtomicityWorker($loginWorker);
        }

        $this->assertSame('success', $holderResult['status'] ?? null, $holderResult['_stderr'] ?? '');
        $this->assertSame('success', $completionResult['status'] ?? null, $completionResult['_stderr'] ?? '');
        $this->assertSame(10, $completionResult['recovery_code_count'] ?? null);
        $this->assertSame('success', $loginResult['status'] ?? null, $loginResult['_stderr'] ?? '');
        $this->assertTrue($loginResult['mfa_required'] ?? false);
        $this->assertSame(OwnerActivationStatus::Active, $activation->fresh()->status);
    }

    public function test_lost_activation_completion_response_cannot_redisplay_recovery_codes(): void
    {
        [$activation, $owner, , , $factor, , $challenge] = $this->activeOwner();
        $this->assertDatabaseCount('owner_recovery_codes', 10);

        try {
            app(OwnerActivationService::class)->complete(
                $challenge,
                app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + 1),
            );
            $this->fail('Completed activation must not replay recovery-code output.');
        } catch (ValidationException) {
            $this->assertSame(OwnerActivationStatus::Active, $activation->fresh()->status);
            $this->assertTrue($owner->fresh()->is_active);
            $this->assertDatabaseCount('owner_recovery_codes', 10);
        }
    }

    public function test_owner_bearer_bound_to_another_property_is_denied(): void
    {
        [, $owner, $company, $property, $factor] = $this->activeOwner();
        $otherProperty = PropertyFactory::new()->create(['company_id' => $company->id]);
        $plain = $this->finishApiLogin($owner, $company, $factor);
        $token = $owner->tokens()->sole();
        $token->forceFill(['property_id' => $otherProperty->id])->save();
        UserSession::query()->where('token_id', $token->id)->update(['property_id' => $otherProperty->id]);

        $this->withToken($plain)->getJson('/api/user')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_identity_security_event_writer_rejects_arbitrary_and_nested_secret_metadata_before_persistence(): void
    {
        $writer = app(IdentitySecurityEventService::class);
        $counterexamples = [
            'generic note' => ['note' => 'current password is secret'],
            'TOTP code' => ['totp_code' => '123456'],
            'camel-case session identifier' => ['sessionId' => 'session-secret'],
            'unknown field' => ['unreviewed_field' => 'secret'],
            'nested token' => ['channel' => ['token' => 'bearer-secret']],
            'arbitrary URL' => ['url' => 'https://example.test/reset?token=secret'],
            'authorization header' => ['authorization' => 'Bearer secret'],
        ];

        foreach ($counterexamples as $label => $metadata) {
            try {
                $writer->record('LOGIN_PASSWORD_ACCEPTED', 'SUCCESS', [], $metadata);
                $this->fail("{$label} metadata should be rejected.");
            } catch (\InvalidArgumentException) {
                $this->assertDatabaseCount('identity_security_events', 0);
            }
        }
    }

    public function test_identity_security_event_writer_validates_allowed_types_enums_and_bounds(): void
    {
        $writer = app(IdentitySecurityEventService::class);
        $invalid = [
            ['LOGIN_MFA_FAILED', 'FAILURE', ['reason_code' => 'SECOND_FACTOR_INVALID'], ['attempts_remaining' => '4']],
            ['LOGIN_PASSWORD_ACCEPTED', 'SUCCESS', [], ['channel' => 'browser']],
            ['LOGIN_PASSWORD_ACCEPTED', 'SUCCESS', [], ['channel' => str_repeat('a', 101)]],
            ['SESSIONS_REVOKED', 'SUCCESS', ['reason_code' => 'LOGOUT_ALL'], ['auth_epoch' => -1]],
            ['LOGIN_MFA_FAILED', 'FAILURE', ['reason_code' => 'NOT_APPROVED'], ['mfa_method' => 'totp', 'attempts_remaining' => 4]],
        ];

        foreach ($invalid as [$type, $outcome, $context, $metadata]) {
            try {
                $writer->record($type, $outcome, $context, $metadata);
                $this->fail('Invalid typed event metadata should be rejected.');
            } catch (\InvalidArgumentException) {
                $this->assertDatabaseCount('identity_security_events', 0);
            }
        }
    }

    public function test_identity_security_event_writer_accepts_every_current_legitimate_metadata_shape(): void
    {
        $patterns = [
            ['OWNER_INVITED', 'SUCCESS', [], []],
            ['OWNER_EMAIL_VERIFIED', 'SUCCESS', [], []],
            ['OWNER_PASSWORD_ESTABLISHED', 'SUCCESS', [], []],
            ['OWNER_MFA_ENROLLMENT_STARTED', 'SUCCESS', [], []],
            ['OWNER_MFA_ENROLLED', 'SUCCESS', [], []],
            ['OWNER_ACTIVATED', 'SUCCESS', [], []],
            ['LOGIN_PASSWORD_ACCEPTED', 'SUCCESS', [], ['channel' => 'api']],
            ['LOGIN_MFA_SUCCEEDED', 'SUCCESS', [], ['mfa_method' => 'totp']],
            ['LOGIN_MFA_FAILED', 'FAILURE', ['reason_code' => 'SECOND_FACTOR_INVALID'], ['mfa_method' => 'recovery_code', 'attempts_remaining' => 4]],
            ['RECOVERY_CODE_USED', 'SUCCESS', [], []],
            ['RECOVERY_CODES_REGENERATED', 'SUCCESS', [], ['recovery_generation' => 2]],
            ['PASSWORD_RESET', 'SUCCESS', ['reason_code' => 'PASSWORD_CHANGE'], []],
            ['SESSIONS_REVOKED', 'SUCCESS', ['reason_code' => 'PASSWORD_CHANGE'], ['auth_epoch' => 2]],
            ['OWNER_MFA_RESET_REQUESTED', 'FAILURE', ['reason_code' => 'HIGH_ASSURANCE_RECOVERY_REQUIRED'], []],
        ];

        foreach ($patterns as [$type, $outcome, $context, $metadata]) {
            $event = app(IdentitySecurityEventService::class)->record($type, $outcome, $context, $metadata);
            $this->assertSame($type, $event->event_type);
            $this->assertSame($metadata, $event->metadata);
        }

        $this->assertDatabaseCount('identity_security_events', count($patterns));
    }

    public function test_identity_security_event_database_enforces_object_shape_and_known_top_level_keys(): void
    {
        foreach (["'[]'::jsonb", "'{\"unknown\":true}'::jsonb"] as $metadata) {
            try {
                DB::table('identity_security_events')->insert([
                    'id' => (string) Str::ulid(),
                    'event_type' => 'OWNER_INVITED',
                    'outcome' => 'SUCCESS',
                    'metadata' => DB::raw($metadata),
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]);
                $this->fail('Database metadata defense should reject malformed shape or keys.');
            } catch (QueryException) {
                $this->assertDatabaseCount('identity_security_events', 0);
            }
        }
    }

    public function test_identity_security_events_remain_append_only(): void
    {
        $event = app(IdentitySecurityEventService::class)->record(
            'LOGIN_MFA_FAILED',
            'FAILURE',
            ['reason_code' => 'SECOND_FACTOR_INVALID'],
            ['mfa_method' => 'totp', 'attempts_remaining' => 4],
        );

        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    DB::table('identity_security_events')->where('id', $event->id)->update(['outcome' => 'SUCCESS']);
                } else {
                    DB::table('identity_security_events')->where('id', $event->id)->delete();
                }
                $this->fail('Identity event mutation should fail.');
            } catch (QueryException) {
                $this->assertDatabaseHas('identity_security_events', ['id' => $event->id, 'outcome' => 'FAILURE']);
            }
        }
    }

    private function installPasswordChangeHoldTrigger(): void
    {
        $this->dropAtomicityTrigger('b5a1b1_hold_password_change');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION b5a1b1_hold_password_change() RETURNS trigger AS $$
            BEGIN
                PERFORM pg_sleep(8);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER trg_b5a1b1_hold_password_change
            BEFORE INSERT ON identity_security_events
            FOR EACH ROW WHEN (NEW.event_type = 'PASSWORD_RESET')
            EXECUTE FUNCTION b5a1b1_hold_password_change();
        SQL);
    }

    private function checkoutScenario(User $actor, Company $company, Property $property): FrontDeskStay
    {
        Permission::firstOrCreate([
            'name' => FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->givePermissionTo(FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION);

        $guest = Guest::create([
            'property_id' => $property->id,
            'guest_code' => 'R2G-'.Str::upper(Str::random(5)),
            'full_name' => 'R2 Atomicity Guest',
            'guest_type' => 'individual',
        ]);
        $reservation = Reservation::create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'reservation_number' => 'R2R-'.Str::upper(Str::random(6)),
            'arrival_date' => today(),
            'departure_date' => today()->addDay(),
            'nights' => 1,
            'reservation_source' => 'direct',
            'status' => 'checked_in',
            'reserved_room_type' => 'standard',
        ]);
        $stay = FrontDeskStay::create([
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'status' => FrontDeskStayStatusEnum::InHouse,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        Auth::login($actor);
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        session([
            'active_property_id' => $property->id,
            'current_property_id' => $property->id,
            'active_company_id' => $company->id,
        ]);

        return $stay;
    }

    private function invitedActivation(): array
    {
        $company = CompanyFactory::new()->create();
        $property = PropertyFactory::new()->create(['company_id' => $company->id]);
        $actor = UserFactory::new()->create();
        $run = new PropertyBootstrapProvisioningRun;
        $run->forceFill([
            'environment' => PropertyBootstrapProvisioningEnvironmentEnum::Rehearsal,
            'idempotency_key' => 'owner-activation-'.Str::ulid(),
            'request_fingerprint' => str_repeat('a', 64),
            'status' => PropertyBootstrapProvisioningStatusEnum::InProgress,
            'canonical_sha' => str_repeat('b', 40),
            'source' => 'b5a1b1-test',
            'initiated_by' => $actor->id,
            'started_at' => now(),
            'evidence' => [],
        ])->save();
        $owner = UserFactory::new()->withProperty($property)->create([
            'email' => 'installation.owner.'.Str::lower(Str::random(8)).'@example.test',
            'email_verified_at' => null,
            'password' => null,
            'is_active' => false,
        ]);
        $activation = app(OwnerActivationService::class)->inviteExistingOwner(
            $owner,
            $run->id,
            'rehearsal',
            'installation-'.Str::ulid(),
            $company->id,
            $property->id,
        );

        return [$activation, $owner, $company, $property];
    }

    private function emailVerifiedActivation(): array
    {
        [$activation, $owner, $company, $property] = $this->invitedActivation();
        $token = app(OwnerActivationService::class)->issueToken($activation, 'verify_email');
        $challenge = app(OwnerActivationService::class)->redeemEmail($token, 'verify_email');

        return [$activation, $owner, $company, $property, $challenge];
    }

    private function activeOwner(): array
    {
        [$activation, $owner, $company, $property, $challenge] = $this->emailVerifiedActivation();
        $service = app(OwnerActivationService::class);
        $service->establishPassword($challenge, self::PASSWORD);
        $service->startMfa($challenge);
        $factor = OwnerMfaFactor::query()->sole();
        $counter = intdiv(time(), 30) - 1;
        $service->confirmMfa($challenge, app(OwnerTotpService::class)->currentCode($factor, $counter));
        $codes = $service->complete($challenge, app(OwnerTotpService::class)->currentCode($factor->fresh(), $counter + 1));

        return [$activation->fresh(), $owner->fresh(), $company, $property, $factor->fresh(), $codes, $challenge];
    }

    private function beginApiLogin(User $owner, Company $company): string
    {
        return $this->postJson('/auth/login', [
            'company_id' => $company->id,
            'email' => $owner->email,
            'password' => self::PASSWORD,
        ])->assertStatus(202)->json('challenge');
    }

    private function finishApiLogin(User $owner, Company $company, OwnerMfaFactor $factor, int $counterOffset = 1): string
    {
        $challenge = $this->beginApiLogin($owner, $company);

        $response = $this->postJson('/auth/mfa/totp', [
            'challenge' => $challenge,
            'code' => app(OwnerTotpService::class)->currentCode($factor, intdiv(time(), 30) + $counterOffset),
            'channel' => 'api',
        ]);
        $this->assertSame(200, $response->status(), (string) IdentitySecurityEvent::query()->where('event_type', 'LOGIN_MFA_FAILED')->latest('occurred_at')->value('reason_code'));

        return $response->json('token');
    }

    private function assertMethodMatchesLockOrder(string $class, string $method, string $expectedPattern): void
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());
        $source = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        $this->assertMatchesRegularExpression($expectedPattern, $source, "{$class}::{$method} must retain OWNER AUTH LOCK ORDER V2.");
        $this->assertDoesNotMatchRegularExpression(
            '/User::query\(\).*?->lockForUpdate\(\).*?OwnerActivation::query\(\).*?->lockForUpdate\(\)/s',
            $source,
            "{$class}::{$method} must not lock User before OwnerActivation.",
        );
    }

    private function spawnAtomicityWorker(array $arguments): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'b5a1b1-atomicity-'.Str::lower(Str::random(8));
        mkdir($directory, 0700, true);
        $workerFile = $directory.DIRECTORY_SEPARATOR.'worker.php';
        $argumentsFile = $directory.DIRECTORY_SEPARATOR.'arguments.json';
        $resultFile = $directory.DIRECTORY_SEPARATOR.'result.json';
        $stderrFile = $directory.DIRECTORY_SEPARATOR.'stderr.txt';
        $arguments['result_file'] = $resultFile;
        file_put_contents($workerFile, $this->atomicityWorkerSource());
        file_put_contents($argumentsFile, json_encode($arguments, JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, $workerFile, base_path(), $argumentsFile],
            [['pipe', 'r'], ['file', $stderrFile, 'a'], ['file', $stderrFile, 'a']],
            $pipes,
            base_path(),
            array_merge(getenv(), [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => 'ivorq_testing',
            ]),
        );
        if (! is_resource($process)) {
            $this->fail('Unable to spawn the privileged-auth atomicity worker.');
        }
        fclose($pipes[0]);

        return compact('process', 'directory', 'workerFile', 'argumentsFile', 'resultFile', 'stderrFile');
    }

    private function collectAtomicityWorker(array $worker): array
    {
        $deadline = microtime(true) + 30;
        do {
            $status = proc_get_status($worker['process']);
            if (! ($status['running'] ?? false)) {
                $exitCode = (int) ($status['exitcode'] ?? -1);
                proc_close($worker['process']);
                $result = is_file($worker['resultFile'])
                    ? json_decode((string) file_get_contents($worker['resultFile']), true)
                    : ['status' => 'missing_result'];
                $result = is_array($result) ? $result : ['status' => 'malformed_result'];
                $result['_exit_code'] = $exitCode;
                $result['_stderr'] = is_file($worker['stderrFile']) ? trim((string) file_get_contents($worker['stderrFile'])) : '';
                $this->removeAtomicityWorkerFiles($worker);

                return $result;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        $this->terminateAtomicityWorker($worker);
        $this->fail('Privileged-auth atomicity worker timed out.');
    }

    private function terminateAtomicityWorker(?array $worker): void
    {
        if ($worker === null) {
            return;
        }
        $status = proc_get_status($worker['process']);
        if ($status['running'] ?? false) {
            proc_terminate($worker['process']);
        }
        proc_close($worker['process']);
        $this->removeAtomicityWorkerFiles($worker);
    }

    private function removeAtomicityWorkerFiles(array $worker): void
    {
        foreach (['workerFile', 'argumentsFile', 'resultFile', 'stderrFile'] as $key) {
            if (is_file($worker[$key])) {
                @unlink($worker[$key]);
            }
        }
        @rmdir($worker['directory']);
    }

    private function waitForBackendWait(string $applicationName, string $waitEventType, ?string $waitEvent = null): void
    {
        $deadline = microtime(true) + 10;
        do {
            $backend = DB::selectOne(
                'SELECT wait_event_type, wait_event FROM pg_stat_activity WHERE datname = current_database() AND application_name = ? ORDER BY backend_start DESC LIMIT 1',
                [$applicationName],
            );
            if ($backend && $backend->wait_event_type === $waitEventType && ($waitEvent === null || $backend->wait_event === $waitEvent)) {
                $this->addToAssertionCount(1);

                return;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);

        $this->fail("Backend {$applicationName} did not reach the required PostgreSQL wait state.");
    }

    private function dropAtomicityTrigger(string $function): void
    {
        $trigger = $function === 'b5a1b1_hold_password_change'
            ? 'trg_b5a1b1_hold_password_change ON identity_security_events'
            : 'trg_b5a1b1_hold_recovery_regeneration ON owner_recovery_codes';
        DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
    }

    private function atomicityWorkerSource(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Services\AuthService;
use Modules\Foundation\Authentication\Services\OwnerActivationService;
use Modules\Foundation\Authentication\Services\OwnerAuthenticationService;
use Modules\Foundation\Authentication\Services\TokenService;
use Modules\Foundation\Authentication\ValueObjects\VerifiedMfaAuthentication;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Services\ProfileService;
use Shared\Services\CurrentPropertyService;

$root = $argv[1];
$arguments = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app['config']->set('session.driver', 'array');
$app->make('session.store')->setId((string) Str::uuid());
$app->make('session.store')->start();
DB::select("SELECT set_config('application_name', ?, false)", [$arguments['application_name']]);
DB::unprepared("SET lock_timeout TO '12s'");
DB::unprepared("SET statement_timeout TO '20s'");

try {
    if ($arguments['operation'] === 'profile_password_change') {
        $user = User::query()->findOrFail($arguments['user_id']);
        $changed = app(ProfileService::class)->changePassword(
            $user,
            $arguments['current_password'],
            $arguments['new_password'],
        );
        $result = ['status' => $changed ? 'success' : 'validation_rejected'];
    } elseif ($arguments['operation'] === 'recovery_regeneration') {
        $user = User::query()->findOrFail($arguments['user_id']);
        $codes = app(OwnerAuthenticationService::class)->regenerateRecoveryCodes(
            $user,
            $arguments['current_password'],
            $arguments['totp_code'],
        );
        $result = ['status' => 'success', 'recovery_code_count' => count($codes)];
    } elseif ($arguments['operation'] === 'sensitive_confirmation') {
        $user = User::query()->findOrFail($arguments['user_id']);
        app(CurrentPropertyService::class)->setPropertyId($arguments['property_id']);
        DB::transaction(function () use ($arguments, $user): void {
            app(SensitiveActionConfirmationService::class)->confirm(
                $user,
                $arguments['intent'],
                $arguments['current_password'],
                $arguments['company_id'],
                $arguments['property_id'],
            );
            if (($arguments['hold_seconds'] ?? 0) > 0) {
                DB::select('SELECT pg_sleep(?)', [(int) $arguments['hold_seconds']]);
            }
        });
        $result = [
            'status' => 'success',
            'confirmation' => session()->get('sensitive_action_confirmation', []),
        ];
    } elseif ($arguments['operation'] === 'checkout_confirmation') {
        $user = User::query()->findOrFail($arguments['user_id']);
        Auth::login($user);
        app(CurrentPropertyService::class)->setPropertyId($arguments['property_id']);
        session([
            'active_property_id' => $arguments['property_id'],
            'current_property_id' => $arguments['property_id'],
            'active_company_id' => $arguments['company_id'],
        ]);
        $issuance = app(CheckoutSensitiveConfirmationService::class)->issueForCurrentSession(
            $user,
            $arguments['front_desk_stay_id'],
            $arguments['idempotency_key'],
            $arguments['current_password'],
        );
        $result = ['status' => 'success', 'issuance_id' => $issuance->id];
    } elseif ($arguments['operation'] === 'hold_user_lock') {
        DB::transaction(function () use ($arguments): void {
            User::query()->whereKey($arguments['user_id'])->lockForUpdate()->firstOrFail();
            DB::select('SELECT pg_sleep(?)', [(int) $arguments['hold_seconds']]);
        });
        $result = ['status' => 'success'];
    } elseif ($arguments['operation'] === 'complete_activation') {
        $codes = app(OwnerActivationService::class)->complete($arguments['challenge'], $arguments['totp_code']);
        $result = ['status' => 'success', 'recovery_code_count' => count($codes)];
    } elseif ($arguments['operation'] === 'complete_owner_mfa') {
        $authentication = app(OwnerAuthenticationService::class)->completeTotp(
            $arguments['challenge'],
            $arguments['totp_code'],
            $arguments['channel'],
            null,
        );
        $result = ['status' => 'success', 'challenge_id' => $authentication->challengeId];
    } elseif ($arguments['operation'] === 'create_verified_owner_token') {
        $authentication = new VerifiedMfaAuthentication(
            $arguments['user_id'],
            $arguments['company_id'],
            $arguments['property_id'],
            $arguments['challenge_id'],
        );
        app(TokenService::class)->createForVerifiedOwner($authentication, 'r3-concurrency');
        $result = ['status' => 'success'];
    } elseif ($arguments['operation'] === 'owner_login_with_activation_hold') {
        $login = DB::transaction(function () use ($arguments): array {
            OwnerActivation::query()->whereKey($arguments['activation_id'])->lockForUpdate()->firstOrFail();
            DB::select('SELECT pg_sleep(?)', [(int) $arguments['hold_seconds']]);

            return app(AuthService::class)->login(
                $arguments['email'],
                $arguments['current_password'],
                $arguments['company_id'],
                'api',
            );
        });
        $result = ['status' => 'success', 'mfa_required' => $login['mfa_required']];
    } elseif ($arguments['operation'] === 'owner_login') {
        $login = app(AuthService::class)->login(
            $arguments['email'],
            $arguments['current_password'],
            $arguments['company_id'],
            'api',
        );
        $result = ['status' => 'success', 'mfa_required' => $login['mfa_required']];
    } else {
        throw new RuntimeException('Unknown atomicity worker operation.');
    }
} catch (ValidationException) {
    $result = ['status' => 'validation_rejected'];
} catch (DomainException) {
    $result = ['status' => 'domain_rejected'];
} catch (Throwable $throwable) {
    $result = ['status' => 'error', 'error' => $throwable::class.': '.$throwable->getMessage()];
}

file_put_contents($arguments['result_file'], json_encode($result, JSON_THROW_ON_ERROR));
exit($result['status'] === 'error' ? 1 : 0);
PHP;
    }
}
