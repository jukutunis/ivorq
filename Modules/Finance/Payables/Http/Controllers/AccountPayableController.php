<?php

namespace Modules\Finance\Payables\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Finance\Payables\Http\Resources\AccountPayableResource;
use Modules\Finance\Payables\Models\AccountPayable;
use Modules\Finance\Payables\Models\VendorInvoice;
use Modules\Finance\Payables\Services\AccountPayableService;

class AccountPayableController extends Controller
{
    public function __construct(private AccountPayableService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AccountPayable::class);

        $propertyId = $request->header('X-Property-Id') ?? request()->user()->current_property_id;

        $aps = AccountPayable::where('property_id', $propertyId)
            ->latest()
            ->paginate();

        return AccountPayableResource::collection($aps);
    }

    public function show(AccountPayable $accountPayable): AccountPayableResource
    {
        $this->authorize('view', $accountPayable);

        return new AccountPayableResource($accountPayable);
    }

    public function generate(VendorInvoice $vendorInvoice): AccountPayableResource
    {
        $this->authorize('create', AccountPayable::class);
        $this->authorize('view', $vendorInvoice); // Ensure they can access the invoice

        $ap = $this->service->createFromMatchedInvoice($vendorInvoice);

        return new AccountPayableResource($ap);
    }
}
