<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\Inventory\Enums\GoodsReceiptExceptionResolutionTypeEnum;
use Modules\Operations\Inventory\Models\GoodsReceipt;
use Modules\Operations\Inventory\Models\GoodsReceiptLineException;
use Modules\Operations\Inventory\Services\ControlledGoodsReceiptExceptionService;
use Modules\Operations\Inventory\Services\ControlledGoodsReceiptPostingService;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Enums\ReceiptSourceType;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Receiving\Enums\DiscrepancyTypeEnum;
use Modules\Operations\Receiving\Enums\InspectionResultEnum;
use Shared\Services\CurrentPropertyService;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly ControlledGoodsReceiptPostingService $postingService,
        private readonly ControlledGoodsReceiptExceptionService $exceptionService,
        private readonly SensitiveActionConfirmationService $confirmationService,
    ) {}

    public function index(): InertiaResponse
    {
        if (! auth()->user()->hasPermissionTo('inventory.purchasing.goods-receipt.receive')
            && ! auth()->user()->hasPermissionTo('inventory.ledger.view')) {
            abort(403, 'Unauthorized.');
        }

        $approvedPos = PurchaseOrder::query()
            ->join(
                'purchase_order_receipt_source_authorities as receipt_authorities',
                'receipt_authorities.purchase_order_id',
                '=',
                'purchase_orders.id'
            )
            ->where('receipt_authorities.source_type', ReceiptSourceType::InventoryGoodsReceipt->value)
            ->whereIn('purchase_orders.status', [
                PurchaseOrderStatusEnum::Approved->value,
                PurchaseOrderStatusEnum::Issued->value,
                PurchaseOrderStatusEnum::PartiallyReceived->value,
            ])
            ->select('purchase_orders.*')
            ->with(['vendor', 'lines.inventoryItem', 'lines.unit'])
            ->orderByDesc('purchase_orders.created_at')
            ->get();

        $recentReceipts = GoodsReceipt::query()
            ->with(['purchaseOrder.vendor', 'lines.inventoryItem', 'lines.stockMovement', 'receivedBy'])
            ->orderByDesc('created_at')
            ->take(25)
            ->get();

        $confirmationExists = false;
        $actor = auth()->user();
        if ($actor) {
            $confirmationExists = $this->confirmationService->hasValidConfirmation(
                $actor,
                'inventory-goods-receipt-posting',
                null,
                app(CurrentPropertyService::class)->getPropertyId()
            );
        }

        return Inertia::render('Operations/Inventory/GoodsReceiptWorkspace', [
            'approvedPos' => $approvedPos,
            'recentReceipts' => $recentReceipts,
            'confirmationExists' => $confirmationExists,
        ]);
    }

    public function show(GoodsReceipt $goodsReceipt): InertiaResponse
    {
        $goodsReceipt->load([
            'purchaseOrder.vendor',
            'purchaseOrder.purchaseRequest',
            'lines.purchaseOrderLine',
            'lines.inventoryItem',
            'lines.inventoryLocation',
            'lines.inventoryUnit',
            'lines.stockMovement',
            'lines.exceptions.reporter',
            'lines.exceptions.resolver',
            'exceptions.reporter',
            'exceptions.resolver',
            'receivedBy',
            'createdBy',
            'voidedBy',
        ]);

        return Inertia::render('Operations/Inventory/GoodsReceiptDetail', [
            'receipt' => $goodsReceipt,
            'capabilities' => [
                'canReport' => auth()->user()->hasPermissionTo(ControlledGoodsReceiptExceptionService::REPORT_PERMISSION),
                'canResolve' => auth()->user()->hasPermissionTo(ControlledGoodsReceiptExceptionService::RESOLVE_PERMISSION),
            ],
            'exceptionOptions' => [
                'discrepancyTypes' => array_map(fn ($case) => $case->value, DiscrepancyTypeEnum::cases()),
                'inspectionResults' => array_map(fn ($case) => $case->value, InspectionResultEnum::cases()),
                'resolutionTypes' => array_map(fn ($case) => $case->value, GoodsReceiptExceptionResolutionTypeEnum::cases()),
            ],
        ]);
    }

    public function createDraft(Request $request): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('inventory.purchasing.goods-receipt.receive')) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'purchase_order_id' => 'required|string',
            'lines' => 'required|array|min:1',
            'lines.*.purchase_order_line_id' => 'required|string',
            'lines.*.inventory_location_id' => 'required|string',
            'lines.*.inventory_unit_id' => 'required|string',
            'lines.*.received_quantity' => 'required|numeric|min:0.001',
        ]);

        $actorId = auth()->id();

        $receipt = $this->postingService->createDraft(
            $validated['purchase_order_id'],
            $validated['lines'],
            $actorId,
        );

        return redirect()->route('operations.inventory.goods-receipts.show', $receipt->id);
    }

    public function submitForConfirmation(GoodsReceipt $goodsReceipt): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('inventory.purchasing.goods-receipt.receive')) {
            abort(403, 'Unauthorized.');
        }

        $goodsReceipt = $this->exceptionService->submitForConfirmation($goodsReceipt, auth()->user());

        return redirect()->route('operations.inventory.goods-receipts.show', $goodsReceipt->id);
    }

    public function post(GoodsReceipt $goodsReceipt, Request $request): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('inventory.purchasing.goods-receipt.receive')) {
            abort(403, 'Unauthorized.');
        }

        $actorId = auth()->id();
        $receipt = $this->postingService->post($goodsReceipt, $actorId);

        return redirect()->route('operations.inventory.goods-receipts.show', $receipt->id);
    }

    public function reportException(GoodsReceipt $goodsReceipt, Request $request): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo(ControlledGoodsReceiptExceptionService::REPORT_PERMISSION)) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'goods_receipt_line_id' => ['required', 'string'],
            'discrepancy_type' => ['nullable', Rule::in(array_column(DiscrepancyTypeEnum::cases(), 'value'))],
            'inspection_result' => ['nullable', Rule::in(array_column(InspectionResultEnum::cases(), 'value'))],
            'observed_quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        $this->exceptionService->report(
            $goodsReceipt,
            $validated['goods_receipt_line_id'],
            $validated,
            auth()->user(),
        );

        return redirect()->route('operations.inventory.goods-receipts.show', $goodsReceipt->id);
    }

    public function resolveException(GoodsReceiptLineException $exception, Request $request): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo(ControlledGoodsReceiptExceptionService::RESOLVE_PERMISSION)) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'resolution_type' => ['required', Rule::in(array_column(GoodsReceiptExceptionResolutionTypeEnum::cases(), 'value'))],
            'accepted_quantity' => ['nullable', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $resolved = $this->exceptionService->resolve(
            $exception,
            GoodsReceiptExceptionResolutionTypeEnum::from($validated['resolution_type']),
            isset($validated['accepted_quantity']) ? (string) $validated['accepted_quantity'] : null,
            $validated['reason'],
            auth()->user(),
        );

        return redirect()->route('operations.inventory.goods-receipts.show', $resolved->goods_receipt_id);
    }

    public function voidReceipt(GoodsReceipt $goodsReceipt, Request $request): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo(ControlledGoodsReceiptExceptionService::RESOLVE_PERMISSION)) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $receipt = $this->exceptionService->voidReceipt($goodsReceipt, $validated['reason'], auth()->user());

        return redirect()->route('operations.inventory.goods-receipts.show', $receipt->id);
    }
}
