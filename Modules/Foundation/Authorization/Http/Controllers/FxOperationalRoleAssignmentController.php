<?php

namespace Modules\Foundation\Authorization\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Foundation\Authorization\Services\FxOperationalRoleAssignmentService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class FxOperationalRoleAssignmentController extends Controller
{
    public function __construct(private readonly FxOperationalRoleAssignmentService $assignmentService) {}

    public function index(Request $request): InertiaResponse
    {
        $propertyId = $this->resolvePropertyId($request);
        $this->authorizeManager($request, $propertyId);

        return Inertia::render('Ivorq/Finance/FxOperationalRoleAssignmentWorkspace', [
            'roles' => FxOperationalRoleAssignmentService::APPROVED_ROLES,
            'users' => $this->usersForProperty($propertyId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $propertyId = $this->resolvePropertyId($request);
        $this->authorizeManager($request, $propertyId);

        $validated = $request->validate([
            'target_user_id' => ['required', 'string', 'ulid'],
            'role' => ['required', 'string', 'in:' . implode(',', FxOperationalRoleAssignmentService::APPROVED_ROLES)],
            'action' => ['required', 'string', 'in:assign,revoke'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $target = User::query()->findOrFail($validated['target_user_id']);

        try {
            if ($validated['action'] === 'assign') {
                $this->assignmentService->assign($request->user(), $target, $validated['role'], $propertyId, $validated['reason']);
                $message = 'FX operational role assigned.';
            } else {
                $this->assignmentService->revoke($request->user(), $target, $validated['role'], $propertyId, $validated['reason']);
                $message = 'FX operational role revoked.';
            }

            return redirect()
                ->route('finance.fx-operational-role-assignments.index')
                ->with('success', $message);
        } catch (DomainException $exception) {
            return redirect()
                ->route('finance.fx-operational-role-assignments.index')
                ->with('error', $exception->getMessage());
        }
    }

    private function usersForProperty(string $propertyId): array
    {
        return User::query()
            ->whereHas('properties', function ($query) use ($propertyId): void {
                $query->where('properties.id', $propertyId)
                    ->where('property_user.status', 'active');
            })
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'fx_roles' => $this->assignmentService->targetRolesForProperty($user, $propertyId),
            ])
            ->values()
            ->all();
    }

    private function authorizeManager(Request $request, string $propertyId): void
    {
        $user = $request->user();

        if (!$user) {
            abort(403, 'Unauthorized.');
        }

        $hasPropertyAccess = $user->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            abort(403, 'Unauthorized.');
        }

        setPermissionsTeamId($propertyId);

        if (!$user->can(FxOperationalRoleAssignmentService::MANAGE_PERMISSION)) {
            abort(403, 'Unauthorized.');
        }
    }

    private function resolvePropertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();

        app(CurrentPropertyService::class)->setPropertyId($propertyId);
        setPermissionsTeamId($propertyId);

        return $propertyId;
    }
}
