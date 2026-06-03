<?php

namespace Shared\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class NotFoundException extends Exception
{
    public function __construct(string $resource = 'Resource')
    {
        parent::__construct("{$resource} not found.");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 404);
    }
}
