<?php

namespace Shared\Exceptions;

use Exception;

class BusinessLogicException extends Exception
{
    public function __construct(string $message = "Business logic error", int $code = 400)
    {
        parent::__construct($message, $code);
    }

    public function render()
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 400);
    }
}
