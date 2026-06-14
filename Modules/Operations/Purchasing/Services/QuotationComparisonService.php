<?php

namespace Modules\Operations\Purchasing\Services;

use Modules\Operations\Purchasing\Repositories\QuotationRepository;
use Modules\Operations\Purchasing\Models\RFQ;
use Modules\Operations\Purchasing\Models\Quotation;

class QuotationComparisonService
{
    public function __construct(
        protected QuotationRepository $quotationRepository
    ) {
    }

    /**
     * Compare quotes and return array sorted by best score.
     * Score logic: lowest total amount = best.
     */
    public function compare(RFQ $rfq): array
    {
        $quotes = $this->quotationRepository->getByRfqId($rfq->id);

        $scored = $quotes->map(function ($quote) {
            // Simple scoring: amount based. Could add vendor score or lead time.
            $score = $quote->total_amount + $quote->tax_amount + $quote->freight_amount;
            return [
                'quotation' => $quote,
                'score' => (float) $score,
            ];
        })->sortBy('score')->values()->all();

        return $scored;
    }

    public function award(Quotation $winningQuote): void
    {
        $winningQuote->update(['is_winner' => true]);

        $rfq = $winningQuote->rfq;
        $rfq->update(['status' => 'Awarded']);
        
        // Ensure other quotes are not winners
        Quotation::where('rfq_id', $rfq->id)
            ->where('id', '!=', $winningQuote->id)
            ->update(['is_winner' => false]);
    }
}
