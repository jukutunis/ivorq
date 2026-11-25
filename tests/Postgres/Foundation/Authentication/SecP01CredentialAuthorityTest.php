<?php

namespace Tests\Postgres\Foundation\Authentication;

use Database\Factories\CompanyFactory;
use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Foundation\Authentication\Services\AuthenticatedSecurityService;
use Modules\Foundation\Authentication\Services\TokenService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Models\UserSession;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class SecP01CredentialAuthorityTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private Company $company;

    private Property $property;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = CompanyFactory::new()->create();
        $this->property = PropertyFactory::new()->create(['company_id' => $this->company->id]);
        $this->user = UserFactory::new()->withProperty($this->property)->create([
            'email' => 'ordinary@example.test',
            'is_active' => true,
        ])->fresh();
    }

    protected function tearDown(): void
    {
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_web_login_establishes_canonical_and_compatibility_property_context(): void
    {
        $response = $this->withSession(['login.tenant_id' => $this->company->id])
            ->post('/login', [
                'email' => $this->user->email,
                'password' => 'password',
            ]);

        $response->assertRedirect('/frontdesk');
        $response->assertSessionHas('current_property_id', $this->property->id);
        $response->assertSessionHas('active_property_id', $this->property->id);
        $response->assertSessionHas('active_company_id', $this->company->id);
        $response->assertSessionHas('auth_epoch', 0);
        $this->assertDatabaseHas('user_sessions', [
            'user_id' => $this->user->id,
            'property_id' => $this->property->id,
            'auth_epoch' => 0,
            'channel' => 'web',
        ]);
    }

    public function test_ordinary_web_credential_requires_canonical_context_and_authoritative_session_row(): void
    {
        [$request, $sessionRow] = $this->webRequest();
        $security = app(AuthenticatedSecurityService::class);

        $this->assertTrue($security->valid($this->user, $request));

        app(CurrentPropertyService::class)->clear();
        $request->session()->put('active_property_id', (string) Str::ulid());
        $this->assertFalse($security->valid($this->user, $request));

        $request->session()->put('active_property_id', $this->property->id);
        $request->session()->forget('current_property_id');
        $this->assertFalse($security->valid($this->user, $request));

        $request->session()->put('current_property_id', $this->property->id);
        $sessionRow->delete();
        $this->assertFalse($security->valid($this->user, $request));
    }

    public function test_explicit_property_and_company_disagreement_fail_closed(): void
    {
        [$request] = $this->webRequest();
        $security = app(AuthenticatedSecurityService::class);
        $otherCompany = CompanyFactory::new()->create();
        $otherProperty = PropertyFactory::new()->create(['company_id' => $otherCompany->id]);

        app(CurrentPropertyService::class)->setPropertyId($otherProperty->id);
        $this->assertFalse($security->valid($this->user, $request));

        app(CurrentPropertyService::class)->clear();
        $request->session()->put('active_company_id', $otherCompany->id);
        $this->assertFalse($security->valid($this->user, $request));
    }

    public function test_ordinary_api_token_is_bounded_narrow_and_session_bound(): void
    {
        $plainTextToken = app(TokenService::class)->createForPasswordAuthentication(
            $this->user,
            $this->property->id,
            'sec-p01-test',
        );
        $token = PersonalAccessToken::findToken($plainTextToken);

        $this->assertNotNull($token);
        $this->assertSame([TokenService::ORDINARY_TOKEN_ABILITY], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(now()->addHours(7)->addMinutes(59), now()->addHours(8)->addMinute()));
        $this->assertSame($this->property->id, $token->property_id);
        $this->assertSame(0, $token->auth_epoch);

        $request = Request::create('/api/security-check', 'GET');
        $request->setUserResolver(fn () => $this->user);
        $this->assertTrue(app(AuthenticatedSecurityService::class)->valid($this->user, $request, $token));

        UserSession::query()->where('token_id', $token->id)->delete();
        app(CurrentPropertyService::class)->clear();
        $this->assertFalse(app(AuthenticatedSecurityService::class)->valid($this->user, $request, $token));
    }

    public function test_removed_membership_or_inactive_company_invalidates_credential(): void
    {
        [$request] = $this->webRequest();
        $security = app(AuthenticatedSecurityService::class);

        $this->user->properties()->updateExistingPivot($this->property->id, ['status' => 'inactive']);
        $this->assertFalse($security->valid($this->user, $request));

        $this->user->properties()->updateExistingPivot($this->property->id, ['status' => 'active']);
        $this->company->forceFill(['is_active' => false])->save();
        $this->assertFalse($security->valid($this->user, $request));
    }

    private function webRequest(): array
    {
        $session = app('session.store');
        $session->start();
        $session->put([
            'current_property_id' => $this->property->id,
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'auth_epoch' => $this->user->auth_epoch,
        ]);

        $row = UserSession::query()->create([
            'user_id' => $this->user->id,
            'property_id' => $this->property->id,
            'web_session_digest' => hash('sha256', "IVORQ-WEB-SESSION-V1\0".$session->getId()),
            'auth_epoch' => $this->user->auth_epoch,
            'channel' => 'web',
            'device_name' => 'test',
            'last_active_at' => now(),
        ]);

        $request = Request::create('/security-check', 'GET');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $this->user);

        return [$request, $row];
    }
}
