<?php

namespace Modules\Foundation\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Http\Requests\StoreUserRequest;
use Modules\Foundation\User\Http\Requests\UpdateUserRequest;
use Modules\Foundation\User\Http\Resources\UserResource;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Services\UserLifecycleService;
use Modules\Foundation\User\Services\UserService;
use Shared\Services\CurrentPropertyService;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService,
        private UserLifecycleService $lifecycle,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $users = $this->userService->paginateForProperty($propertyId);

        return Inertia::render('Foundation/User/Index', [
            'users' => UserResource::collection($users),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Foundation/User/Create');
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->resolveOrFail(),
        ]);

        $user = $this->userService->create($data);

        return redirect()->route('users.show', $user->id)
            ->with('success', 'User created successfully.');
    }

    public function show(string $id): Response
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $user = $this->userService->findForProperty($id, $propertyId);
        $this->authorize('view', $user);
        $companyId = Property::query()->whereKey($propertyId)->value('company_id');
        $memberships = $user->properties()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get()
            ->map(fn (Property $property): array => [
                'property_id' => $property->id,
                'name' => $property->name,
                'status' => $property->pivot->status,
                'is_default' => (bool) $property->pivot->is_default,
                'is_current' => $property->id === $propertyId,
            ]);

        return Inertia::render('Foundation/User/Show', [
            'user' => new UserResource($user),
            'memberships' => $memberships,
        ]);
    }

    public function edit(string $id): Response
    {
        $user = $this->userService->findForProperty($id, app(CurrentPropertyService::class)->resolveOrFail());
        $this->authorize('update', $user);

        return Inertia::render('Foundation/User/Edit', [
            'user' => new UserResource($user),
        ]);
    }

    public function update(UpdateUserRequest $request, string $id): RedirectResponse
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $target = $this->userService->findForProperty($id, $propertyId);
        $this->authorize('update', $target);
        $user = $this->userService->updateForProperty($id, $propertyId, $request->validated());

        return redirect()->route('users.show', $user->id)
            ->with('success', 'User updated successfully.');
    }

    public function activate(Request $request, string $id): RedirectResponse
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $target = $this->userService->findForProperty($id, $propertyId);
        $this->authorize('update', $target);
        $this->lifecycle->activate($request->user(), $id, $propertyId);

        return redirect()->route('users.show', $id)
            ->with('success', 'User activated successfully.');
    }

    public function deactivate(Request $request, string $id): RedirectResponse
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $target = $this->userService->findForProperty($id, $propertyId);
        $this->authorize('update', $target);
        $this->lifecycle->deactivate($request->user(), $id, $propertyId);

        return redirect()->route('users.show', $id)
            ->with('success', 'User deactivated successfully.');
    }

    public function offboard(Request $request, string $id): RedirectResponse
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $target = $this->userService->findForProperty($id, $propertyId);
        $this->authorize('delete', $target);
        $this->lifecycle->offboard($request->user(), $id, $propertyId);

        return redirect()->route('users.index')
            ->with('success', 'User offboarded successfully.');
    }

    public function setDefaultProperty(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['property_id' => ['required', 'string', 'size:26']]);
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $target = $this->userService->findForProperty($id, $propertyId);
        $this->authorize('update', $target);
        $this->lifecycle->setDefaultProperty($request->user(), $id, $propertyId, $data['property_id']);

        return redirect()->route('users.show', $id)
            ->with('success', 'Default Property changed successfully.');
    }

    public function removeCurrentMembership(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['replacement_property_id' => ['nullable', 'string', 'size:26']]);
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $target = $this->userService->findForProperty($id, $propertyId);
        $this->authorize('update', $target);
        $this->lifecycle->removeMembership(
            $request->user(),
            $id,
            $propertyId,
            $data['replacement_property_id'] ?? null,
        );

        return redirect()->route('users.index')
            ->with('success', 'Property membership removed successfully.');
    }
}
