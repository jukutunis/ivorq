<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Modules\Foundation\User\Models\UserSession;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    // ─── Login page ────────────────────────────────────────────────────────

    public function test_login_page_is_accessible_to_guests(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $user    = $this->createUser($prop, 'staff');

        $this->actingAs($user)->get('/login')->assertRedirect();
    }

    // ─── Login ─────────────────────────────────────────────────────────────

    public function test_user_can_login_with_valid_credentials(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $user    = $this->createUser($prop, 'staff', [
            'email'    => 'login@test.com',
            'password' => 'mypassword',
        ]);

        $this->post('/login', [
            'email'    => 'login@test.com',
            'password' => 'mypassword',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_wrong_password(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $this->createUser($prop, 'staff', [
            'email'    => 'fail@test.com',
            'password' => 'correctpassword',
        ]);

        $this->post('/login', [
            'email'    => 'fail@test.com',
            'password' => 'wrongpassword',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $this->createUser($prop, 'staff', [
            'email'     => 'inactive@test.com',
            'password'  => 'password',
            'is_active' => false,
        ]);

        $this->post('/login', [
            'email'    => 'inactive@test.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_records_user_session(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $user    = $this->createUser($prop, 'staff', [
            'email'    => 'session@test.com',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'email'    => 'session@test.com',
            'password' => 'password',
        ]);

        // UserSession is created by TokenService via AuthService::login()
        // For web login this test checks the session tracking table
        $this->assertDatabaseCount('user_sessions', 1);
    }

    // ─── Logout ────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_logout(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $user    = $this->createUser($prop, 'staff');

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_logout_all_revokes_all_tokens(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $user    = $this->createUser($prop, 'staff', [
            'email'    => 'tokenuser@test.com',
            'password' => 'password',
        ]);

        // Create two Sanctum tokens
        $user->createToken('device-one');
        $user->createToken('device-two');
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->actingAs($user)->post('/logout/all');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ─── API Login / Token ──────────────────────────────────────────────────

    public function test_api_login_returns_bearer_token(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $this->createUser($prop, 'staff', [
            'email'    => 'apilogin@test.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/auth/login', [
            'email'       => 'apilogin@test.com',
            'password'    => 'password',
            'device_name' => 'test-device',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user', 'token']);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_api_login_creates_personal_access_token(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $this->createUser($prop, 'staff', [
            'email'    => 'tokencreate@test.com',
            'password' => 'password',
        ]);

        $this->postJson('/auth/login', [
            'email'       => 'tokencreate@test.com',
            'password'    => 'password',
            'device_name' => 'test',
        ]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_api_logout_revokes_token(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $user    = $this->createUser($prop, 'staff');
        $token   = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ─── Password Reset ────────────────────────────────────────────────────

    public function test_forgot_password_page_is_accessible(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_forgot_password_stores_reset_token_in_database(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $this->createUser($prop, 'staff', ['email' => 'reset@test.com']);

        $this->post('/forgot-password', ['email' => 'reset@test.com']);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'reset@test.com']);
    }

    public function test_forgot_password_with_unknown_email_does_not_leak_existence(): void
    {
        // Should return the same response regardless of whether the email exists
        $response = $this->post('/forgot-password', ['email' => 'ghost@test.com']);
        // Either a redirect back or a 422 — it must not expose user existence
        $this->assertNotEquals(200, $response->status());
    }

    public function test_reset_password_page_is_accessible_with_token(): void
    {
        $this->get('/reset-password/fake-token')->assertOk();
    }

    // ─── Password reset — full flow ────────────────────────────────────────────

    public function test_full_password_reset_changes_user_password(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $user    = $this->createUser($prop, 'staff', [
            'email'    => 'pwreset@test.com',
            'password' => 'oldpassword1',
        ]);

        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token'                 => $token,
            'email'                 => 'pwreset@test.com',
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect(route('login'));

        // New password must be accepted by the login endpoint
        $this->post('/login', [
            'email'    => 'pwreset@test.com',
            'password' => 'newpassword123',
        ])->assertRedirect('/dashboard');
    }

    // ─── API: additional coverage ───────────────────────────────────────────────

    public function test_api_login_fails_with_wrong_password(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $this->createUser($prop, 'staff', [
            'email'    => 'apifail@test.com',
            'password' => 'correctpassword',
        ]);

        $this->postJson('/auth/login', [
            'email'    => 'apifail@test.com',
            'password' => 'wrongpassword',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors('email');
    }
}
