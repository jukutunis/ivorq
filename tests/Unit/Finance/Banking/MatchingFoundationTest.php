<?php

namespace Tests\Unit\Finance\Banking;

use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use Carbon\Carbon;
use Modules\Finance\Banking\DTOs\MatchCandidateDTO;
use Modules\Finance\Banking\DTOs\MatchResultDTO;
use Modules\Finance\Banking\DTOs\MatchingConfiguration;
use Modules\Finance\Banking\Services\Matching\AbstractMatchingEngine;
use Modules\Finance\Banking\Services\Matching\Contracts\MatchingEngineInterface;
use Modules\Finance\Banking\Models\BankStatementLine;

class DummyMatchingEngine extends AbstractMatchingEngine
{
    public function findCandidates(BankStatementLine $line): array
    {
        return [];
    }

    public function scoreCandidate(BankStatementLine $line, MatchCandidateDTO $candidate): MatchCandidateDTO
    {
        return $candidate;
    }

    public function evaluate(BankStatementLine $line, array $candidates): MatchResultDTO
    {
        return new MatchResultDTO(false, 0, 'dummy', null);
    }

    // Expose protected methods for testing
    public function testCalculateAmountScore(float $bank, float $treasury): float
    {
        return $this->calculateAmountScore($bank, $treasury);
    }

    public function testCalculateDateScore(Carbon $bank, Carbon $treasury): float
    {
        return $this->calculateDateScore($bank, $treasury);
    }

    public function testCalculateReferenceScore(?string $bank, ?string $treasury): float
    {
        return $this->calculateReferenceScore($bank, $treasury);
    }

    public function testCalculateConfidence(float $amt, float $date, float $ref): float
    {
        return $this->calculateConfidence($amt, $date, $ref);
    }
}

class MatchingFoundationTest extends TestCase
{
    public function test_candidate_creation_and_validation()
    {
        $candidate = new MatchCandidateDTO('VendorPayment', 'ULID1', 'BSL1');
        $this->assertEquals('VendorPayment', $candidate->matchable_type);

        $candidate->setScores(90, 80, 70, 85);
        $this->assertEquals(90, $candidate->amount_score);
        
        $this->expectException(InvalidArgumentException::class);
        $candidate->setScores(150, 0, 0, 0); // > 100
    }

    public function test_candidate_creation_rejects_nulls()
    {
        $this->expectException(InvalidArgumentException::class);
        new MatchCandidateDTO('', '', '');
    }

    public function test_result_creation_and_validation()
    {
        $candidate = new MatchCandidateDTO('VendorPayment', 'ULID1', 'BSL1');
        $result = new MatchResultDTO(true, 95.0, 'Match', $candidate);

        $this->assertTrue($result->is_match);
        
        $this->expectException(InvalidArgumentException::class);
        new MatchResultDTO(false, -10, 'Fail', null); // < 0
    }

    public function test_configuration_validation()
    {
        $config = new MatchingConfiguration(3, 1000.0, 80.0, 85.0);
        $this->assertEquals(3, $config->date_tolerance_days);

        $this->expectException(InvalidArgumentException::class);
        new MatchingConfiguration(-1, 0, 0, 0); // Negative tolerance
    }

    public function test_score_calculations()
    {
        $config = new MatchingConfiguration(3, 1000.0, 80.0, 85.0);
        $engine = new DummyMatchingEngine($config);

        // Amount Score
        $this->assertEquals(100.0, $engine->testCalculateAmountScore(10000, 10000));
        $this->assertEquals(50.0, $engine->testCalculateAmountScore(10000, 10500)); // 500 diff out of 1000 tolerance
        $this->assertEquals(0.0, $engine->testCalculateAmountScore(10000, 12000)); // Beyond tolerance

        // Date Score
        $d1 = Carbon::parse('2023-01-01');
        $d2 = Carbon::parse('2023-01-01');
        $this->assertEquals(100.0, $engine->testCalculateDateScore($d1, $d2));
        
        $d3 = Carbon::parse('2023-01-02'); // 1 day diff
        $this->assertEquals(66.67, round($engine->testCalculateDateScore($d1, $d3), 2));
        
        $d4 = Carbon::parse('2023-01-10'); // 9 days diff
        $this->assertEquals(0.0, $engine->testCalculateDateScore($d1, $d4));

        // Reference Score
        $this->assertEquals(100.0, $engine->testCalculateReferenceScore('INV-001', 'INV-001'));
        $this->assertEquals(0.0, $engine->testCalculateReferenceScore('INV-001', null));
        
        // Similar reference (e.g., 1 char diff in 7 length = 85.7% similar)
        $score = $engine->testCalculateReferenceScore('INV-001', 'INV-002');
        $this->assertGreaterThanOrEqual(80.0, $score);
        
        // Totally different
        $score2 = $engine->testCalculateReferenceScore('INV-001', 'PAYMENT');
        $this->assertEquals(0.0, $score2); // Will be below 80% threshold
    }

    public function test_confidence_calculations()
    {
        $config = new MatchingConfiguration(3, 1000.0, 80.0, 85.0);
        $engine = new DummyMatchingEngine($config);

        // Exact Match
        $this->assertEquals(100.0, $engine->testCalculateConfidence(100, 100, 100));

        // Date variance only
        $this->assertEquals(95.0, $engine->testCalculateConfidence(100, 80, 100));

        // Reference variance only
        $this->assertEquals(90.0, $engine->testCalculateConfidence(100, 100, 85));

        // Tolerance match (amount exact, date var, ref var)
        $this->assertEquals(80.0, $engine->testCalculateConfidence(100, 80, 85));

        // Failed amount match
        $this->assertEquals(0.0, $engine->testCalculateConfidence(0, 100, 100));
    }
}
