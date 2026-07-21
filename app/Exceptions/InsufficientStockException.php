<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsufficientStockException extends Exception
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Insufficient stock.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], 422);
    }
}
