<?php

namespace Modules\Finance\GeneralLedger\DTOs;

use Modules\Finance\GeneralLedger\Enums\PackageStatusEnum;

class FinancialStatementPackageDTO
{
    public function __construct(
        public array $metadata,
        public TrialBalanceDTO $trial_balance,
        public ProfitLossDTO $profit_loss,
        public BalanceSheetDTO $balance_sheet,
        public CashFlowDTO $cash_flow,
        public array $validations,
        public PackageStatusEnum $status,
    ) {}
}
