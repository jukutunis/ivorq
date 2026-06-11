<?php

$contractsPath = '/Users/gedeedi/Herd/ivorq/Modules/Operations/ContractorPTW/Contracts';
$servicesPath = '/Users/gedeedi/Herd/ivorq/Modules/Operations/ContractorPTW/Services';
if (!is_dir($contractsPath)) mkdir($contractsPath, 0755, true);
if (!is_dir($servicesPath)) mkdir($servicesPath, 0755, true);

$contracts = [
    'WorkOrderPermitRequirement.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Contracts;\n\ninterface WorkOrderPermitRequirement\n{\n    public function requiresPermit(string \$workOrderId): bool;\n}\n",
    'WorkOrderSafetyValidation.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Contracts;\n\ninterface WorkOrderSafetyValidation\n{\n    public function isWorkOrderSafeToStart(string \$workOrderId): bool;\n}\n",
];

$services = [
    'PermitApprovalService.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Services;\n\nclass PermitApprovalService\n{\n    public function approvePermit(string \$permitId, string \$approverId, string \$role, string \$signature): void\n    {\n        // logic\n    }\n}\n",
    'ContractorValidationService.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Services;\n\nclass ContractorValidationService\n{\n    public function validateAccess(string \$workerProfileId): bool\n    {\n        return true;\n    }\n}\n",
    'EmergencyOverrideService.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Services;\n\nclass EmergencyOverrideService\n{\n    public function triggerOverride(string \$permitId, string \$reason, string \$userId): void\n    {\n        // logic\n    }\n}\n",
    'PermitExpiryService.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Services;\n\nclass PermitExpiryService\n{\n    public function checkExpiries(): void\n    {\n        // logic\n    }\n}\n",
];

foreach ($contracts as $file => $content) {
    file_put_contents("$contractsPath/$file", $content);
}

foreach ($services as $file => $content) {
    file_put_contents("$servicesPath/$file", $content);
}

echo "Services and Contracts generated.\\n";
