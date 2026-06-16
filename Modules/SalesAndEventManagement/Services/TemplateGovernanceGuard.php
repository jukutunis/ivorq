<?php

namespace Modules\SalesAndEventManagement\Services;

use InvalidArgumentException;
use Modules\SalesAndEventManagement\Enums\TemplateStatusEnum;
use Modules\SalesAndEventManagement\Models\EventExecutionTemplate;
use Illuminate\Validation\ValidationException;

class TemplateGovernanceGuard
{
    public function enforceCreatorIsNotApprover(EventExecutionTemplate $template, string $approverId): void
    {
        if ($template->created_by === $approverId) {
            throw ValidationException::withMessages([
                'approved_by' => 'The creator of the template cannot also be the approver.',
            ]);
        }
    }

    public function enforceImmutability(EventExecutionTemplate $template): void
    {
        if (in_array($template->status, [TemplateStatusEnum::Published, TemplateStatusEnum::Archived, TemplateStatusEnum::Superseded])) {
            throw ValidationException::withMessages([
                'status' => 'Published, Archived, or Superseded templates are immutable and cannot be modified.',
            ]);
        }
    }

    public function enforceRevisionChainIntegrity(EventExecutionTemplate $template, EventExecutionTemplate $previousTemplate): void
    {
        if ($template->revision_number <= $previousTemplate->revision_number) {
            throw ValidationException::withMessages([
                'revision_number' => 'New revision number must be strictly greater than the previous revision.',
            ]);
        }

        if ($template->company_id !== $previousTemplate->company_id) {
            throw ValidationException::withMessages([
                'company_id' => 'Revision chain must maintain the same company ownership.',
            ]);
        }

        if ($template->property_id !== $previousTemplate->property_id) {
            throw ValidationException::withMessages([
                'property_id' => 'Revision chain must maintain the same property ownership.',
            ]);
        }
    }

    public function enforcePropertyIsolation(EventExecutionTemplate $template, ?string $contextPropertyId): void
    {
        if ($template->property_id && $template->property_id !== $contextPropertyId) {
            throw ValidationException::withMessages([
                'property_id' => 'Template does not belong to the active property context.',
            ]);
        }
    }

    public function enforceCompanyIsolation(EventExecutionTemplate $template, string $contextCompanyId): void
    {
        if ($template->company_id !== $contextCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => 'Template does not belong to the active company context.',
            ]);
        }
    }
}
