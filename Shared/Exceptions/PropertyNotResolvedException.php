<?php

namespace Shared\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class PropertyNotResolvedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Property context could not be resolved.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
