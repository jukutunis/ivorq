<?php

namespace Modules\Operations\AssetManagement\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Operations\AssetManagement\DTOs\AssetDTO;
use Modules\Operations\AssetManagement\Http\Requests\StoreAssetRequest;

class AssetController extends Controller
{
    public function index(): JsonResponse
    {
        // Require cursor pagination as per Architecture Lock ADR-007
        $assets = Asset::cursorPaginate(100);
        return response()->json($assets);
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $dto = AssetDTO::fromArray($request->validated());
        
        $asset = Asset::create((array) $dto);
        
        return response()->json($asset, 201);
    }

    public function show(string $id): JsonResponse
    {
        $asset = Asset::with(['movements', 'warranties', 'commissionings'])->findOrFail($id);
        return response()->json($asset);
    }

    public function update(StoreAssetRequest $request, string $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);
        
        // Locked Asset Policy handled in Policies Phase
        $asset->update($request->validated());

        return response()->json($asset);
    }

    public function destroy(string $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);
        $asset->delete(); // Soft delete
        return response()->json(null, 204);
    }
}
