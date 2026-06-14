<?php

namespace Modules\Finance\AccountsPayable\Exceptions;

use Exception;

class InvoiceMatchingException extends Exception
{
    public static function directExpenseCannotBeMatched(): self
    {
        return new self("Direct expense invoices bypass GRNI matching.");
    }

    public static function receiptLineMissing(): self
    {
        return new self("GRNI matched invoice line must have a receipt line ID.");
    }

    public static function invoicedQuantityExceedsReceived(): self
    {
        return new self("Invoiced quantity cannot exceed the received quantity.");
    }
    
    public static function propertyMismatch(): self
    {
        return new self("Invoice and receipt must belong to the same property.");
    }
}
