<?php

namespace Modules\Finance\Payables\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Finance\Payables\Enums\PaymentMethodEnum;
use Modules\Finance\Payables\Http\Resources\PaymentVoucherResource;
use Modules\Finance\Payables\Models\PaymentVoucher;
use Modules\Finance\Payables\Services\PaymentVoucherService;

class PaymentVoucherController extends Controller
{
    public function __construct(private PaymentVoucherService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PaymentVoucher::class);

        $propertyId = $request->header('X-Property-Id') ?? request()->user()->current_property_id;

        $pvs = PaymentVoucher::where('property_id', $propertyId)
            ->latest()
            ->paginate();

        return PaymentVoucherResource::collection($pvs);
    }

    public function show(PaymentVoucher $paymentVoucher): PaymentVoucherResource
    {
        $this->authorize('view', $paymentVoucher);

        return new PaymentVoucherResource($paymentVoucher->load('lines.accountPayable'));
    }

    public function store(Request $request): PaymentVoucherResource
    {
        $this->authorize('create', PaymentVoucher::class);
        $propertyId = $request->header('X-Property-Id') ?? request()->user()->current_property_id;

        $validated = $request->validate([
            'vendor_id' => 'required|string',
            'payment_date' => 'required|date',
            'payment_method' => 'required|string|in:' . implode(',', PaymentMethodEnum::values()),
            'reference_no' => 'nullable|string',
            'remarks' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.account_payable_id' => 'required|string',
            'lines.*.amount_paid' => 'required|numeric|min:0.01',
            'lines.*.remarks' => 'nullable|string',
        ]);

        $validated['property_id'] = $propertyId;

        $pv = $this->service->create($validated);

        return new PaymentVoucherResource($pv);
    }

    public function post(PaymentVoucher $paymentVoucher): PaymentVoucherResource
    {
        $this->authorize('post', $paymentVoucher);

        $pv = $this->service->post($paymentVoucher);

        return new PaymentVoucherResource($pv->load('lines.accountPayable'));
    }

    public function cancel(PaymentVoucher $paymentVoucher): PaymentVoucherResource
    {
        $this->authorize('cancel', $paymentVoucher);

        $pv = $this->service->cancel($paymentVoucher);

        return new PaymentVoucherResource($pv->load('lines.accountPayable'));
    }
}
