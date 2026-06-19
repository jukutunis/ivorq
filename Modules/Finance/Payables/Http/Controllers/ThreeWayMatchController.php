<?php

namespace Modules\Finance\Payables\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Finance\Payables\Http\Resources\ThreeWayMatchResource;
use Modules\Finance\Payables\Models\ThreeWayMatch;
use Modules\Finance\AccountsPayable\Models\ApInvoice;
use Modules\Finance\Payables\Services\ThreeWayMatchingEngine;

class ThreeWayMatchController extends Controller
{
    public function __construct(
        private readonly ThreeWayMatchingEngine $engine
    ) {}

    public function show(string $id): ThreeWayMatchResource
    {
        $match = ThreeWayMatch::with('lines')->findOrFail($id);
        
        $this->authorize('view', $match);

        return new ThreeWayMatchResource($match);
    }

    public function match(string $invoiceId): JsonResponse
    {
        $invoice = ApInvoice::with('lines')->findOrFail($invoiceId);
        
        // $this->authorize('createMatch', $invoice);

        if ($invoice->threeWayMatch) {
            return response()->json([
                'message' => 'Invoice has already been matched.'
            ], 422);
        }

        $match = $this->engine->performMatch($invoice);

        $match->load('lines');

        return response()->json([
            'message' => 'Three-way match completed.',
            'data' => new ThreeWayMatchResource($match)
        ], 201);
    }
}
