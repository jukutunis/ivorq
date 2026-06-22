<?php
namespace Modules\Finance\CostControl\ValueObjects;

class CostLedgerPostingWindow
{
    public readonly string $propertyId;
    public readonly string $sourceBusinessDate;
    public readonly bool $isPropertyBusinessDateOpen;
    public readonly bool $isFinancialPeriodOpen;
    public readonly ?string $currentOpenCorrectionBusinessDate;
    public readonly ?string $currentOpenCorrectionFinancialPeriodId;

    public function __construct(
        string $propertyId,
        string $sourceBusinessDate,
        bool $isPropertyBusinessDateOpen,
        bool $isFinancialPeriodOpen,
        ?string $currentOpenCorrectionBusinessDate = null,
        ?string $currentOpenCorrectionFinancialPeriodId = null
    ) {
        $this->propertyId = $propertyId;
        $this->sourceBusinessDate = $sourceBusinessDate;
        $this->isPropertyBusinessDateOpen = $isPropertyBusinessDateOpen;
        $this->isFinancialPeriodOpen = $isFinancialPeriodOpen;
        $this->currentOpenCorrectionBusinessDate = $currentOpenCorrectionBusinessDate;
        $this->currentOpenCorrectionFinancialPeriodId = $currentOpenCorrectionFinancialPeriodId;
    }
}