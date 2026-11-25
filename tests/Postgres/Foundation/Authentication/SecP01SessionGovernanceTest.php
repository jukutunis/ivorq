<?php

namespace Tests\Postgres\Foundation\Authentication;

use Database\Factories\PropertyFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Foundation\Authentication\Models\IdentitySecurityEvent;
use Modules\Foundation\Authentication\Services\SessionGovernanceService;
use Modules\Foundation\Authentication\Services\TokenService;
use Modules\Foundation\User\Models\UserSession;
use Shared\Exceptions\NotFoundException;
use Tests\PostgresTestCase;

class SecP01SessionGovernanceTest extends PostgresTestCase
{
    use RefreshDatabase;

    public function test_session_center_lists_only_actor_with_masked_network_and_current_marker(): void
    {
        $property = PropertyFactory::new()->create();
        $actor = UserFactory::new()->withProperty($property)->create();
        $other = UserFactory::new()->withProperty($property)->create();
        $request = $this->webRequest($actor, $property->id, 'actor-session');
        UserSession::query()->create([
            'user_id' => $actor->id,
            'property_id' => $property->id,
            'web_session_digest' => $this->digest($request->session()->getId()),
            'auth_epoch' => 0,
            'channel' => 'web',
            'ip_address' => '192.168.12.99',
            'last_active_at' => now(),
        ]);
        UserSession::query()->create([
            'user_id' => $other->id,
            'property_id' => $property->id,
            'web_session_digest' => $this->digest('other-session'),
            'auth_epoch' => 0,
            'channel' => 'web',
            'ip_address' => '10.1.2.3',
        ]);

        $sessions = app(SessionGovernanceService::class)->actorSessions($actor, $request);

        $this->assertCount(1, $sessions);
        $this->assertTrue($sessions[0]['is_current']);
        $this->assertSame('192.168.x.x', $sessions[0]['ip_address']);
        $this->assertArrayNotHasKey('web_session_digest', $sessions[0]);
    }

    public function test_revoke_one_api_session_deletes_only_actor_token_and_evidence(): void
    {
        $property = PropertyFactory::new()->create();
        $actor = UserFactory::new()->withProperty($property)->create();
        $other = UserFactory::new()->withProperty($property)->create();
        $actorPlain = app(TokenService::class)->createForPasswordAuthentication($actor, $property->id, 'actor-api');
        $otherPlain = app(TokenService::class)->createForPasswordAuthentication($other, $property->id, 'other-api');
        $actorToken = PersonalAccessToken::findToken($actorPlain);
        $otherToken = PersonalAccessToken::findToken($otherPlain);
        $actorSession = UserSession::query()->where('token_id', $actorToken->id)->firstOrFail();
        $request = Request::create('/api/auth/sessions/'.$actorSession->id, 'DELETE');
        $request->setUserResolver(fn () => $actor->withAccessToken($actorToken));

        $current = app(SessionGovernanceService::class)->revokeOne($actor->withAccessToken($actorToken), $actorSession->id, $request);

        $this->assertTrue($current);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $actorToken->id]);
        $this->assertDatabaseMissing('user_sessions', ['id' => $actorSession->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->id]);
        $this->assertSame(1, IdentitySecurityEvent::query()->where('event_type', 'SESSION_REVOKED')->count());
    }

    public function test_revoke_one_web_session_matches_digest_without_persisting_raw_id_and_denies_other_actor(): void
    {
        $property = PropertyFactory::new()->create();
        $actor = UserFactory::new()->withProperty($property)->create();
        $other = UserFactory::new()->withProperty($property)->create();
        $rawTargetId = 'raw-session-id-never-in-user-sessions';
        DB::table('sessions')->insert([
            'id' => $rawTargetId,
            'user_id' => $actor->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode('test'),
            'last_activity' => time(),
        ]);
        $row = UserSession::query()->create([
            'user_id' => $actor->id,
            'property_id' => $property->id,
            'web_session_digest' => $this->digest($rawTargetId),
            'auth_epoch' => 0,
            'channel' => 'web',
        ]);
        $request = $this->webRequest($actor, $property->id, 'different-current-session');

        $this->assertFalse(app(SessionGovernanceService::class)->revokeOne($actor, $row->id, $request));
        $this->assertDatabaseMissing('sessions', ['id' => $rawTargetId]);
        $this->assertDatabaseMissing('user_sessions', ['id' => $row->id]);

        $otherRow = UserSession::query()->create([
            'user_id' => $other->id,
            'property_id' => $property->id,
            'web_session_digest' => $this->digest('other'),
            'auth_epoch' => 0,
            'channel' => 'web',
        ]);
        $this->expectException(NotFoundException::class);
        app(SessionGovernanceService::class)->revokeOne($actor, $otherRow->id, $request);
    }

    private function webRequest($user, string $propertyId, string $sessionId): Request
    {
        $session = app('session.store');
        $session->setId($sessionId);
        $session->start();
        $request = Request::create('/profile', 'GET');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function digest(string $sessionId): string
    {
        return hash('sha256', "IVORQ-WEB-SESSION-V1\0".$sessionId);
    }
}
