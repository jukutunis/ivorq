<?php

namespace Modules\Foundation\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Modules\Foundation\User\Http\Requests\StoreUserRequest;
use Modules\Foundation\User\Http\Requests\UpdateUserRequest;
use Modules\Foundation\User\Http\Resources\UserResource;
use Modules\Foundation\User\Services\UserService;
use Shared\Services\CurrentPropertyService;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', \Modules\Foundation\User\Models\User::class);

        $users = $this->userService->paginate();

        return Inertia::render('Foundation/User/Index', [
            'users' => UserResource::collection($users),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', \Modules\Foundation\User\Models\User::class);

        return Inertia::render('Foundation/User/Create');
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $user = $this->userService->create($data);

        return redirect()->route('users.show', $user->id)
            ->with('success', 'User created successfully.');
    }

    public function show(string $id): Response
    {
        $user = $this->userService->find($id);
        $this->authorize('view', $user);

        return Inertia::render('Foundation/User/Show', [
            'user' => new UserResource($user),
        ]);
    }

    public function edit(string $id): Response
    {
        $user = $this->userService->find($id);
        $this->authorize('update', $user);

        return Inertia::render('Foundation/User/Edit', [
            'user' => new UserResource($user),
        ]);
    }

    public function update(UpdateUserRequest $request, string $id): RedirectResponse
    {
        $user = $this->userService->update($id, $request->validated());

        return redirect()->route('users.show', $user->id)
            ->with('success', 'User updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $user = $this->userService->find($id);
        $this->authorize('delete', $user);

        $this->userService->delete($id);

        return redirect()->route('users.index')
            ->with('success', 'User deleted successfully.');
    }
}
