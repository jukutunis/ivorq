<?php

namespace Modules\Operations\Engineering\Enums;

enum PmFrequencyEnum: string
{
    case Daily      = 'daily';
    case Weekly     = 'weekly';
    case Monthly    = 'monthly';
    case Quarterly  = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual     = 'annual';
    case Custom     = 'custom';

    public function label(): string
    {
        return match($this) {
            self::Daily      => 'Daily',
            self::Weekly     => 'Weekly',
            self::Monthly    => 'Monthly',
            self::Quarterly  => 'Quarterly',
            self::SemiAnnual => 'Semi-Annual',
            self::Annual     => 'Annual',
            self::Custom     => 'Custom (Days)',
        };
    }

    /**
     * Returns the number of days between PM task generations for this frequency.
     * Returns null for Custom — caller must use the PM's frequency_days field.
     */
    public function intervalDays(): ?int
    {
        return match($this) {
            self::Daily      => 1,
            self::Weekly     => 7,
            self::Monthly    => 30,
            self::Quarterly  => 90,
            self::SemiAnnual => 180,
            self::Annual     => 365,
            self::Custom     => null,
        };
    }
}
