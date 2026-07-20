<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvalidStatusTransitionException extends Exception
{
    public function __construct(
        private readonly OrderStatus $from,
        private readonly OrderStatus $to,
    ) {
        parent::__construct("Cannot change status from {$from->value} to {$to->value}.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 422);
    }
}
