<?php

namespace Modules\Foundation\Authentication\Services;

use Modules\Foundation\Audit\Repositories\AuditLogRepository;
use Modules\Foundation\Authentication\Models\IdentitySecurityEvent;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class SecurityOperationsReadService
{
    public function __construct(
        private CurrentPropertyService $currentProperty,
        private AuditLogRepository $auditLogs,
    ) {}

    public function forActor(User $actor, ?string $eventType = null): array
    {
        $propertyId = $this->currentProperty->resolveOrFail();
        $property = Property::query()
            ->whereKey($propertyId)
            ->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->first();

        $authoritativeActor = User::query()->whereKey($actor->id)->where('is_active', true)->first();
        abort_unless($authoritativeActor && $property && $authoritativeActor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists(), 403);

        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($propertyId);
        try {
            abort_unless($authoritativeActor->can('audit.view'), 403);
        } finally {
            setPermissionsTeamId($previousTeamId);
        }

        $identity = IdentitySecurityEvent::query()
            ->where('company_id', $property->company_id)
            ->where('property_id', $propertyId)
            ->when($eventType !== null, fn ($query) => $query->where('event_type', $eventType))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(20, [
                'id', 'event_type', 'outcome', 'actor_user_id', 'subject_user_id',
                'reason_code', 'occurred_at',
            ], 'identity_page')
            ->withQueryString()
            ->through(fn (IdentitySecurityEvent $event): array => [
                'id' => $event->id,
                'event' => $event->event_type,
                'outcome' => $event->outcome,
                'actor_user_id' => $event->actor_user_id,
                'subject_id' => $event->subject_user_id,
                'reason_code' => $event->reason_code,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ]);

        return [
            'property' => $property->name,
            'identity_events' => $identity,
            'operational_events' => $this->auditLogs->securityOperationsForProperty($propertyId),
        ];
    }
}
