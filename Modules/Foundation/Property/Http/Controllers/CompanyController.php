<?php

namespace Modules\Foundation\Property\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Modules\Foundation\Property\Http\Requests\StoreCompanyRequest;
use Modules\Foundation\Property\Http\Requests\UpdateCompanyRequest;
use Modules\Foundation\Property\Http\Resources\CompanyResource;
use Modules\Foundation\Property\Services\CompanyService;

class CompanyController extends Controller
{
    public function __construct(
        private CompanyService $companyService
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', \Modules\Foundation\Property\Models\Company::class);

        $companies = $this->companyService->paginate();

        return Inertia::render('Foundation/Company/Index', [
            'companies' => CompanyResource::collection($companies),
        ]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $company = $this->companyService->create($request->validated());

        return redirect()->route('companies.show', $company->id)
            ->with('success', 'Company created successfully.');
    }

    public function show(string $id): Response
    {
        $company = $this->companyService->find($id);

        return Inertia::render('Foundation/Company/Show', [
            'company' => new CompanyResource($company),
        ]);
    }

    public function edit(string $id): Response
    {
        $company = $this->companyService->find($id);
        $this->authorize('update', $company);

        return Inertia::render('Foundation/Company/Edit', [
            'company' => new CompanyResource($company),
        ]);
    }

    public function update(UpdateCompanyRequest $request, string $id): RedirectResponse
    {
        $company = $this->companyService->update($id, $request->validated());

        return redirect()->route('companies.show', $company->id)
            ->with('success', 'Company updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $company = $this->companyService->find($id);
        $this->authorize('delete', $company);

        $this->companyService->delete($id);

        return redirect()->route('companies.index')
            ->with('success', 'Company deleted successfully.');
    }
}
