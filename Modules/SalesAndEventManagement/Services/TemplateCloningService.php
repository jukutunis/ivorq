<?php

namespace Modules\SalesAndEventManagement\Services;

use Illuminate\Support\Facades\DB;
use Modules\SalesAndEventManagement\Enums\TemplateStatusEnum;
use Modules\SalesAndEventManagement\Models\EventExecutionTemplate;
use Modules\SalesAndEventManagement\Models\OperationalPackage;

class TemplateCloningService
{
    public function __construct(
        private TemplateGovernanceGuard $governanceGuard
    ) {}

    public function createRevision(EventExecutionTemplate $template, string $userId): EventExecutionTemplate
    {
        if ($template->status !== TemplateStatusEnum::Published) {
            throw new \InvalidArgumentException("Only published templates can be revised.");
        }

        return DB::transaction(function () use ($template, $userId) {
            $newTemplate = $template->replicate(['created_at', 'updated_at', 'deleted_at', 'approved_at', 'published_at', 'approved_by']);
            $newTemplate->status = TemplateStatusEnum::Draft;
            $newTemplate->revision_number = $template->revision_number + 1;
            $newTemplate->previous_template_id = $template->id;
            $newTemplate->created_by = $userId;
            $newTemplate->save();

            $this->clonePackagesAndSections($template, $newTemplate, $userId);

            $template->status = TemplateStatusEnum::Superseded;
            $template->save();

            return $newTemplate;
        });
    }

    public function cloneCorporateToProperty(EventExecutionTemplate $companyTemplate, string $propertyId, string $userId): EventExecutionTemplate
    {
        if ($companyTemplate->property_id !== null) {
            throw new \InvalidArgumentException("Source template must be a Company template.");
        }

        if ($companyTemplate->status !== TemplateStatusEnum::Published) {
            throw new \InvalidArgumentException("Only published company templates can be cloned to properties.");
        }

        return DB::transaction(function () use ($companyTemplate, $propertyId, $userId) {
            $newTemplate = $companyTemplate->replicate(['created_at', 'updated_at', 'deleted_at', 'approved_at', 'published_at', 'approved_by']);
            $newTemplate->status = TemplateStatusEnum::Draft;
            $newTemplate->property_id = $propertyId;
            $newTemplate->revision_number = 0;
            $newTemplate->previous_template_id = $companyTemplate->id;
            $newTemplate->created_by = $userId;
            $newTemplate->save();

            $this->clonePackagesAndSections($companyTemplate, $newTemplate, $userId);

            return $newTemplate;
        });
    }

    private function clonePackagesAndSections(EventExecutionTemplate $sourceTemplate, EventExecutionTemplate $targetTemplate, string $userId): void
    {
        $sourceTemplate->load([
            'operationalPackages.venueSetupSections',
            'operationalPackages.fbSections',
            'operationalPackages.avSections',
            'operationalPackages.billingSections',
            'operationalPackages.specialRequestSections',
            'operationalPackages.taskSections',
            'operationalPackages.staffingSections',
        ]);

        foreach ($sourceTemplate->operationalPackages as $package) {
            $newPackage = $package->replicate(['created_at', 'updated_at', 'deleted_at']);
            $newPackage->event_execution_template_id = $targetTemplate->id;
            $newPackage->created_by = $userId;
            $newPackage->save();

            $this->replicateSections($package->venueSetupSections, $newPackage->id, $userId);
            $this->replicateSections($package->fbSections, $newPackage->id, $userId);
            $this->replicateSections($package->avSections, $newPackage->id, $userId);
            $this->replicateSections($package->billingSections, $newPackage->id, $userId);
            $this->replicateSections($package->specialRequestSections, $newPackage->id, $userId);
            $this->replicateSections($package->taskSections, $newPackage->id, $userId);
            $this->replicateSections($package->staffingSections, $newPackage->id, $userId);
        }
    }

    private function replicateSections($sections, string $newPackageId, string $userId): void
    {
        foreach ($sections as $section) {
            $newSection = $section->replicate(['created_at', 'updated_at']);
            $newSection->operational_package_id = $newPackageId;
            $newSection->created_by = $userId;
            $newSection->save();
        }
    }
}
