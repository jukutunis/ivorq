<?php

namespace Modules\Operations\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Operations\Purchasing\Http\Requests\StoreGoodsReceiptRequest;
use Modules\Operations\Purchasing\Http\Resources\GoodsReceiptResource;
use Modules\Operations\Purchasing\Models\GoodsReceipt;
use Modules\Operations\Purchasing\Services\ReceivingService;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private ReceivingService $receivingService
    ) {}

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $this->authorize('create', GoodsReceipt::class);

        $data = $request->validated();
        $purchaseOrderId = $data['purchase_order_id'];

        $goodsReceipt = $this->receivingService->receive($purchaseOrderId, $data);

        return response()->json([
            'message' => 'Goods received successfully.',
            'data' => new GoodsReceiptResource($goodsReceipt)
        ], 201);
    }
}
