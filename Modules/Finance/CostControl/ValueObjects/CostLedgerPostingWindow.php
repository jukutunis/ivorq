<?php
namespace Modules\Finance\CostControl\ValueObjects;
use InvalidArgumentException;
use DateTime;

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
        if (empty($propertyId)) throw new InvalidArgumentException("propertyId cannot be empty");
        
        if (!($d = DateTime::createFromFormat('Y-m-d', $sourceBusinessDate)) || $d->format('Y-m-d') !== $sourceBusinessDate) {
            throw new InvalidArgumentException("sourceBusinessDate must be a valid calendar date");
        }

        if (($currentOpenCorrectionBusinessDate !== null && $currentOpenCorrectionFinancialPeriodId === null) ||
            ($currentOpenCorrectionBusinessDate === null && $currentOpenCorrectionFinancialPeriodId !== null)) {
            throw new InvalidArgumentException("correction business date and correction FinancialPeriod ID must either both exist or both be null");
        }

        if ($currentOpenCorrectionFinancialPeriodId !== null && trim($currentOpenCorrectionFinancialPeriodId) === '') {
            throw new InvalidArgumentException("correction FinancialPeriod ID cannot be blank");
        }

        if ($currentOpenCorrectionBusinessDate !== null) {
            if (!($d = DateTime::createFromFormat('Y-m-d', $currentOpenCorrectionBusinessDate)) || $d->format('Y-m-d') !== $currentOpenCorrectionBusinessDate) {
                throw new InvalidArgumentException("correction business date must be a valid calendar date");
            }
            if ($currentOpenCorrectionBusinessDate < $sourceBusinessDate) {
                throw new InvalidArgumentException("correction business date must be on or after sourceBusinessDate");
            }
        }

        $this->propertyId = $propertyId;
        $this->sourceBusinessDate = $sourceBusinessDate;
        $this->isPropertyBusinessDateOpen = $isPropertyBusinessDateOpen;
        $this->isFinancialPeriodOpen = $isFinancialPeriodOpen;
        $this->currentOpenCorrectionBusinessDate = $currentOpenCorrectionBusinessDate;
        $this->currentOpenCorrectionFinancialPeriodId = $currentOpenCorrectionFinancialPeriodId;
    }
}