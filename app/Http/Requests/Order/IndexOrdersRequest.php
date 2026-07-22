<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(OrderStatus::class)],
            'user_id' => [
                'sometimes',
                'nullable',
                Rule::prohibitedIf(fn (): bool => ! $this->user()->is_admin),
                'integer',
                'exists:users,id',
            ],
            'sort' => ['sometimes', Rule::in(['created_at', 'total'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
