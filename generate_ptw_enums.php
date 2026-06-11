<?php

$enumsPath = '/Users/gedeedi/Herd/ivorq/Modules/Operations/ContractorPTW/Enums';
if (!is_dir($enumsPath)) mkdir($enumsPath, 0755, true);

$enums = [
    'ContractorStatusEnum.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Enums;\n\nenum ContractorStatusEnum: string\n{\n    case PENDING = 'pending';\n    case APPROVED = 'approved';\n    case SUSPENDED = 'suspended';\n    case BLACKLISTED = 'blacklisted';\n}\n",
    'PermitStatusEnum.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Enums;\n\nenum PermitStatusEnum: string\n{\n    case DRAFT = 'draft';\n    case PENDING_APPROVAL = 'pending_approval';\n    case APPROVED = 'approved';\n    case ACTIVE = 'active';\n    case SUSPENDED = 'suspended';\n    case CLOSED = 'closed';\n    case REJECTED = 'rejected';\n}\n",
    'PermitTypeEnum.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Enums;\n\nenum PermitTypeEnum: string\n{\n    case HOT_WORK = 'hot_work';\n    case ELECTRICAL = 'electrical';\n    case HEIGHT = 'height';\n    case CONFINED_SPACE = 'confined_space';\n    case EXCAVATION = 'excavation';\n    case CHEMICAL = 'chemical';\n    case LOTO = 'loto';\n    case GENERAL = 'general';\n    case EMERGENCY = 'emergency';\n}\n",
    'RiskLevelEnum.php' => "<?php\n\nnamespace Modules\Operations\ContractorPTW\Enums;\n\nenum RiskLevelEnum: string\n{\n    case LOW = 'low';\n    case MEDIUM = 'medium';\n    case HIGH = 'high';\n    case CRITICAL = 'critical';\n}\n",
];

foreach ($enums as $file => $content) {
    file_put_contents("$enumsPath/$file", $content);
}
echo "Enums generated.\\n";
