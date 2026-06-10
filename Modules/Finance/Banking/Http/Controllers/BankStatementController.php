<?php

namespace Modules\Finance\Banking\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Finance\Banking\Http\Resources\BankStatementResource;
use Modules\Finance\Banking\Services\BankStatementService;
use Illuminate\Http\JsonResponse;

class BankStatementController extends Controller
{
    public function __construct(
        protected BankStatementService $service
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', BankStatement::class);

        $propertyId = $request->header('X-Property-Id');
        
        $statements = BankStatement::where('property_id', $propertyId)
            ->withCount('lines')
            ->orderBy('statement_date', 'desc')
            ->paginate();

        return BankStatementResource::collection($statements);
    }

    public function store(Request $request): BankStatementResource
    {
        Gate::authorize('create', BankStatement::class);

        $data = $request->validate([
            'bank_account_id' => 'required|string|exists:bank_accounts,id',
            'statement_date' => 'required|date',
            'opening_balance' => 'required|numeric',
            'imported_closing_balance' => 'required|numeric',
        ]);

        $data['property_id'] = $request->header('X-Property-Id');

        $statement = $this->service->create($data);

        return new BankStatementResource($statement);
    }

    public function show(Request $request, BankStatement $statement): BankStatementResource
    {
        Gate::authorize('view', $statement);

        $statement->load('lines');

        return new BankStatementResource($statement);
    }

    public function import(Request $request, BankStatement $statement): BankStatementResource|JsonResponse
    {
        Gate::authorize('import', $statement);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        try {
            $csvContent = $request->file('file')->get();
            $updatedStatement = $this->service->import($statement, $csvContent);
            $updatedStatement->load('lines');
            return new BankStatementResource($updatedStatement);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
