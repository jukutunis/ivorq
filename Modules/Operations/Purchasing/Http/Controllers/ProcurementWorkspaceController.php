<?php

namespace Modules\Operations\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Inventory\Models\InventoryItem;
use Modules\Operations\Inventory\Models\InventoryUnit;
use Modules\Operations\Purchasing\Enums\PurchaseOrderStatusEnum;
use Modules\Operations\Purchasing\Enums\PurchaseRequestStatusEnum;
use Modules\Operations\Purchasing\Enums\ReceiptSourceType;
use Modules\Operations\Purchasing\Models\PurchaseOrder;
use Modules\Operations\Purchasing\Models\PurchaseRequest;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Shared\Services\CurrentPropertyService;

class ProcurementWorkspaceController extends Controller
{
    public function index(CurrentPropertyService $currentProperty): Response
    {
        $actor = auth()->user();
        abort_unless($actor, 401);

        $canViewVendors = $actor->can('purchasing.vendor.view');
        $canViewRequests = $actor->can('purchasing.purchase-request.view');
        $canViewOrders = $actor->can('purchasing.purchase-order.view');
        abort_unless($canViewVendors || $canViewRequests || $canViewOrders, 403, 'Procurement workspace permission is required.');

        $property = Property::query()
            ->whereKey($currentProperty->resolveOrFail())
            ->where('is_active', true)
            ->firstOrFail();

        $vendors = $canViewVendors
            ? Vendor::query()
                ->forCompany($property->company_id)
                ->with(['category', 'contacts'])
                ->orderBy('name')
                ->limit(150)
                ->get()
            : collect();

        $requests = $canViewRequests
            ? PurchaseRequest::query()
                ->with(['department', 'requester', 'lines.inventoryItem', 'lines.unit', 'revisionSnapshots'])
                ->latest()
                ->limit(150)
                ->get()
            : collect();

        $orders = $canViewOrders
            ? PurchaseOrder::query()
                ->addSelect([
                    'receipt_source_type' => DB::table('purchase_order_receipt_source_authorities')
                        ->select('source_type')
                        ->whereColumn('purchase_order_id', 'purchase_orders.id')
                        ->limit(1),
                ])
                ->with(['vendor', 'purchaseRequest', 'lines.inventoryItem', 'lines.unit', 'revisionSnapshots'])
                ->latest()
                ->limit(150)
                ->get()
                ->map(function (PurchaseOrder $order): PurchaseOrder {
                    $order->setAttribute(
                        'receiving_url',
                        in_array($order->status, [
                            PurchaseOrderStatusEnum::Approved,
                            PurchaseOrderStatusEnum::Issued,
                            PurchaseOrderStatusEnum::PartiallyReceived,
                        ], true)
                            && $order->getAttribute('receipt_source_type') === ReceiptSourceType::InventoryGoodsReceipt->value
                            ? route('operations.inventory.goods-receipts.index', ['purchase_order_id' => $order->id])
                            : null
                    );

                    return $order;
                })
            : collect();

        return Inertia::render('Operations/Purchasing/ProcurementWorkspace', [
            'context' => [
                'property_id' => $property->id,
                'property_name' => $property->name,
                'company_id' => $property->company_id,
                'currency_code' => $property->currency,
            ],
            'vendors' => $vendors,
            'purchaseRequests' => $requests,
            'purchaseOrders' => $orders,
            'approvalQueue' => [
                'purchase_requests' => $requests->where('status', PurchaseRequestStatusEnum::PendingReview)->values(),
                'purchase_orders' => $orders->where('status', PurchaseOrderStatusEnum::PendingReview)->values(),
            ],
            'reference' => [
                'categories' => VendorCategory::query()->orderBy('name')->get(['id', 'name', 'category_code']),
                'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
                'items' => InventoryItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
                'units' => InventoryUnit::query()->orderBy('name')->get(['id', 'name', 'code']),
            ],
            'abilities' => [
                'vendor_view' => $canViewVendors,
                'request_view' => $canViewRequests,
                'order_view' => $canViewOrders,
                'vendor_create' => $actor->can('purchasing.vendor.create'),
                'vendor_edit' => $actor->can('purchasing.vendor.edit'),
                'vendor_approve' => $actor->can('purchasing.vendor.approve'),
                'request_create' => $actor->can('purchasing.purchase-request.create'),
                'request_edit' => $actor->can('purchasing.purchase-request.edit'),
                'request_approve' => $actor->can('purchasing.purchase-request.approve'),
                'request_cancel' => $actor->can('purchasing.purchase-request.cancel'),
                'order_create' => $actor->can('purchasing.purchase-order.create'),
                'order_edit' => $actor->can('purchasing.purchase-order.edit'),
                'order_approve' => $actor->can('purchasing.purchase-order.approve'),
                'order_issue' => $actor->can('purchasing.purchase-order.issue'),
                'order_cancel' => $actor->can('purchasing.purchase-order.cancel'),
            ],
        ]);
    }
}
