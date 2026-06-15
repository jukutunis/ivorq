<?php

namespace Tests\Unit\Finance\Banking;

use PHPUnit\Framework\TestCase;
use Modules\Finance\Banking\Services\Parsers\CsvStatementParser;
use Modules\Finance\Banking\DTOs\ParsedStatementDTO;
use Modules\Finance\Banking\DTOs\ParsedStatementLineDTO;
use Exception;

class CsvStatementParserTest extends TestCase
{
    protected CsvStatementParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CsvStatementParser();
    }

    public function test_supports_method()
    {
        $this->assertTrue($this->parser->supports('text/csv', 'csv'));
        $this->assertTrue($this->parser->supports('text/plain', 'txt'));
        
        $this->assertFalse($this->parser->supports('application/pdf', 'pdf'));
        $this->assertFalse($this->parser->supports('application/vnd.ms-excel', 'xlsx'));
    }

    public function test_dto_creation()
    {
        $line = new ParsedStatementLineDTO(
            transaction_date: '2023-01-01',
            description: 'Test Desc',
            reference: 'REF123',
            external_reference: 'EXT123',
            amount: 100.50
        );

        $statement = new ParsedStatementDTO(
            statement_date: '2023-01-31',
            opening_balance: 1000.0,
            closing_balance: 1100.5,
            currency_code: 'USD',
            bank_account_reference: 'ACC123'
        );

        $statement->addLine($line);

        $this->assertEquals('2023-01-31', $statement->statement_date);
        $this->assertCount(1, $statement->lines);
        $this->assertEquals(100.50, $statement->lines[0]->amount);
    }

    public function test_csv_parsing_single_amount_column()
    {
        $csv = "Date,Description,Ref,Amount\n2023-05-01,Deposit,DEP01,500.00\n2023-05-02,Withdrawal,WDR01,-200.50";

        $config = [
            'has_header' => true,
            'date_col' => 0,
            'desc_col' => 1,
            'ref_col' => 2,
            'amount_col' => 3,
            'date_format' => 'Y-m-d'
        ];

        $parsed = $this->parser->parse($csv, $config);

        $this->assertCount(2, $parsed->lines);
        
        // Inflow
        $this->assertEquals('2023-05-01', $parsed->lines[0]->transaction_date);
        $this->assertEquals('Deposit', $parsed->lines[0]->description);
        $this->assertEquals('DEP01', $parsed->lines[0]->reference);
        $this->assertEquals(500.00, $parsed->lines[0]->amount);

        // Outflow
        $this->assertEquals('2023-05-02', $parsed->lines[1]->transaction_date);
        $this->assertEquals('Withdrawal', $parsed->lines[1]->description);
        $this->assertEquals('WDR01', $parsed->lines[1]->reference);
        $this->assertEquals(-200.50, $parsed->lines[1]->amount);
    }

    public function test_csv_parsing_separate_amount_columns()
    {
        $csv = "Date,Desc,Ref,In,Out\n2023-05-01,Dep,R1,1000,\n2023-05-02,Wdr,R2,,300";

        $config = [
            'has_header' => true,
            'date_col' => 0,
            'desc_col' => 1,
            'ref_col' => 2,
            'amount_in_col' => 3,
            'amount_out_col' => 4,
            'date_format' => 'Y-m-d'
        ];

        $parsed = $this->parser->parse($csv, $config);

        $this->assertCount(2, $parsed->lines);
        $this->assertEquals(1000.0, $parsed->lines[0]->amount);
        $this->assertEquals(-300.0, $parsed->lines[1]->amount);
    }

    public function test_date_normalization()
    {
        // 01/15/2023 -> 2023-01-15 (m/d/Y)
        $csv = "Date,Desc,Amount\n01/15/2023,Test,100";

        $config = [
            'has_header' => true,
            'date_col' => 0,
            'desc_col' => 1,
            'amount_col' => 2,
            'date_format' => 'm/d/Y'
        ];

        $parsed = $this->parser->parse($csv, $config);
        $this->assertEquals('2023-01-15', $parsed->lines[0]->transaction_date);
    }

    public function test_amount_normalization()
    {
        // Test various amount formats
        $csv = "Date,Desc,Amount\n" .
               "2023-01-01,European,\"1.000,50\"\n" . // European format
               "2023-01-02,US,\"1,000.50\"\n" . // US format
               "2023-01-03,Negative,\"(500.00)\"\n" . // Parentheses
               "2023-01-04,Currency,\"$1,234.56\""; // Currency symbol

        $config = [
            'has_header' => true,
            'date_col' => 0,
            'desc_col' => 1,
            'amount_col' => 2,
        ];

        $parsed = $this->parser->parse($csv, $config);
        
        $this->assertEquals(1000.50, $parsed->lines[0]->amount);
        $this->assertEquals(1000.50, $parsed->lines[1]->amount);
        $this->assertEquals(-500.00, $parsed->lines[2]->amount);
        $this->assertEquals(1234.56, $parsed->lines[3]->amount);
    }

    public function test_malformed_rows_are_rejected()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Malformed row/');

        $csv = "Date,Desc,Amount\n2023-01-01,MissingAmount";

        $config = [
            'has_header' => true,
            'date_col' => 0,
            'desc_col' => 1,
            'amount_col' => 2,
        ];

        $this->parser->parse($csv, $config);
    }

    public function test_invalid_dates_are_rejected()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Invalid date format/');

        $csv = "Date,Desc,Amount\n99-99-9999,Test,100";

        $config = [
            'has_header' => true,
            'date_col' => 0,
            'desc_col' => 1,
            'amount_col' => 2,
        ];

        $this->parser->parse($csv, $config);
    }

    public function test_invalid_amount_formats_are_rejected()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Invalid amount format/');

        $csv = "Date,Desc,Amount\n2023-01-01,Test,InvalidAmountStr";

        $config = [
            'has_header' => true,
            'date_col' => 0,
            'desc_col' => 1,
            'amount_col' => 2,
        ];

        $this->parser->parse($csv, $config);
    }
}
