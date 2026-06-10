<?php

namespace Modules\Finance\Banking\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Http\Resources\BankAccountResource;
use Modules\Finance\Banking\Services\BankAccountService;

class BankAccountController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected BankAccountService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BankAccount::class);

        $query = BankAccount::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json([
            'data' => BankAccountResource::collection($query->get())
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', BankAccount::class);

        $validated = $request->validate([
            'bank_name' => 'required|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:255',
            'currency_code' => 'nullable|string|size:3',
            'opening_balance' => 'required|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $bankAccount = $this->service->create($validated);

        return response()->json([
            'data' => new BankAccountResource($bankAccount)
        ], 201);
    }

    public function show(BankAccount $bankAccount): JsonResponse
    {
        $this->authorize('view', $bankAccount);

        return response()->json([
            'data' => new BankAccountResource($bankAccount)
        ]);
    }

    public function update(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorize('update', $bankAccount);

        $validated = $request->validate([
            'bank_name' => 'string|max:255',
            'account_name' => 'string|max:255',
            'account_number' => 'string|max:255',
            'is_active' => 'boolean',
        ]);

        $updated = $this->service->update($bankAccount, $validated);

        return response()->json([
            'data' => new BankAccountResource($updated)
        ]);
    }

    public function destroy(BankAccount $bankAccount): JsonResponse
    {
        $this->authorize('delete', $bankAccount);

        $this->service->delete($bankAccount);

        return response()->json(null, 204);
    }
}
