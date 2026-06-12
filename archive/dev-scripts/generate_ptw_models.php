<?php

$modelsPath = '/Users/gedeedi/Herd/ivorq/Modules/Operations/ContractorPTW/Models';
if (!is_dir($modelsPath)) mkdir($modelsPath, 0755, true);

$models = [
    'ContractorCompany' => "contractor_companies",
    'ContractorWorkerGlobal' => "contractor_worker_globals",
    'ContractorWorkerPropertyProfile' => "contractor_worker_property_profiles",
    'ContractorInsurance' => "contractor_insurances",
    'ContractorCertification' => "contractor_certifications",
    'ContractorInduction' => "contractor_inductions",
    'ContractorAccessPass' => "contractor_access_passes",
    'PermitToWork' => "permit_to_works",
    'PermitRiskAssessment' => "permit_risk_assessments",
    'PermitIsolation' => "permit_isolations",
    'PermitApproval' => "permit_approvals",
    'PermitAttachment' => "permit_attachments",
    'PermitAudit' => "permit_audits",
];

foreach ($models as $name => $table) {
    $content = "<?php\n\nnamespace Modules\Operations\ContractorPTW\Models;\n\nuse Illuminate\Database\Eloquent\Model;\nuse Shared\Traits\HasUlid;\n";
    if (in_array($name, ['ContractorWorkerPropertyProfile', 'ContractorInduction', 'ContractorAccessPass', 'PermitToWork', 'PermitAudit'])) {
        $content .= "use Shared\Traits\BelongsToProperty;\n";
    }
    if (in_array($name, ['ContractorCompany', 'ContractorWorkerGlobal', 'ContractorWorkerPropertyProfile', 'ContractorInsurance', 'PermitToWork'])) {
        $content .= "use Illuminate\Database\Eloquent\SoftDeletes;\n";
    }
    
    $content .= "\nclass $name extends Model\n{\n    use HasUlid;\n";
    if (in_array($name, ['ContractorWorkerPropertyProfile', 'ContractorInduction', 'ContractorAccessPass', 'PermitToWork', 'PermitAudit'])) {
        $content .= "    use BelongsToProperty;\n";
    }
    if (in_array($name, ['ContractorCompany', 'ContractorWorkerGlobal', 'ContractorWorkerPropertyProfile', 'ContractorInsurance', 'PermitToWork'])) {
        $content .= "    use SoftDeletes;\n";
    }
    $content .= "\n    protected \$table = '$table';\n    protected \$guarded = [];\n}\n";
    
    file_put_contents("$modelsPath/$name.php", $content);
}

echo "Models generated.\\n";
