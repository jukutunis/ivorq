<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Models\UserSession;
use Shared\Exceptions\NotFoundException;

class SessionGovernanceService
{
    public function __construct(private IdentitySecurityEventService $events) {}

    public function actorSessions(User $actor, Request $request): array
    {
        $currentTokenId = $actor->currentAccessToken()?->id;
        $currentWebDigest = $request->hasSession()
            ? $this->digest($request->session()->getId())
            : null;
        $properties = Property::query()
            ->whereIn('id', UserSession::query()->where('user_id', $actor->id)->whereNotNull('property_id')->pluck('property_id'))
            ->pluck('name', 'id');

        return UserSession::query()
            ->where('user_id', $actor->id)
            ->latest('last_active_at')
            ->get()
            ->map(fn (UserSession $session): array => [
                'id' => $session->id,
                'channel' => $session->channel,
                'device_name' => $session->device_name,
                'ip_address' => $this->maskIp($session->ip_address),
                'user_agent' => $session->user_agent === null ? null : mb_strimwidth($session->user_agent, 0, 120, '…'),
                'property' => $properties[$session->property_id] ?? null,
                'last_active_at' => $session->last_active_at?->toIso8601String(),
                'created_at' => $session->created_at?->toIso8601String(),
                'is_current' => ($session->token_id !== null && $session->token_id === $currentTokenId)
                    || ($session->web_session_digest !== null && hash_equals($session->web_session_digest, (string) $currentWebDigest)),
            ])
            ->all();
    }

    public function revokeOne(User $actor, string $sessionId, Request $request): bool
    {
        return DB::transaction(function () use ($actor, $sessionId, $request): bool {
            $session = UserSession::query()
                ->where('user_id', $actor->id)
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->first();
            if (! $session) {
                throw new NotFoundException('Session');
            }

            $current = false;
            if ($session->token_id !== null) {
                $current = $actor->currentAccessToken()?->id === $session->token_id;
                DB::table('personal_access_tokens')
                    ->where('id', $session->token_id)
                    ->where('tokenable_type', $actor->getMorphClass())
                    ->where('tokenable_id', $actor->id)
                    ->delete();
            } elseif ($session->web_session_digest !== null) {
                $current = $request->hasSession()
                    && hash_equals($session->web_session_digest, $this->digest($request->session()->getId()));
                $this->deleteMatchingLaravelSession($actor, $session->web_session_digest);
            }

            $property = $session->property_id === null ? null : Property::query()->find($session->property_id);
            $session->delete();
            $this->events->record('SESSION_REVOKED', 'SUCCESS', [
                'actor_user_id' => $actor->id,
                'subject_user_id' => $actor->id,
                'company_id' => $property?->company_id,
                'property_id' => $property?->id,
            ], [
                'channel' => $session->channel === 'api' ? 'api' : 'web',
            ]);

            return $current;
        });
    }

    public function touchCurrent(User $actor, Request $request, mixed $token): void
    {
        $query = UserSession::query()
            ->where('user_id', $actor->id)
            ->where(fn ($builder) => $builder->whereNull('last_active_at')->orWhere('last_active_at', '<=', now()->subMinutes(5)));
        if ($token instanceof PersonalAccessToken) {
            $query->where('token_id', $token->id);
        } elseif ($request->hasSession()) {
            $query->where('web_session_digest', $this->digest($request->session()->getId()));
        } else {
            return;
        }

        $query->update(['last_active_at' => now()]);
    }

    private function deleteMatchingLaravelSession(User $actor, string $digest): void
    {
        DB::table('sessions')
            ->where('user_id', $actor->id)
            ->select('id')
            ->orderBy('id')
            ->get()
            ->each(function (object $candidate) use ($digest): void {
                if (hash_equals($digest, $this->digest((string) $candidate->id))) {
                    DB::table('sessions')->where('id', $candidate->id)->delete();
                }
            });
    }

    private function digest(string $sessionId): string
    {
        return hash('sha256', "IVORQ-WEB-SESSION-V1\0".$sessionId);
    }

    private function maskIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.x.x";
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)).':…';
        }

        return null;
    }
}
