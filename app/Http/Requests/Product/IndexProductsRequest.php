<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'min_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_price' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                Rule::when($this->filled('min_price'), ['gte:min_price']),
            ],
            'in_stock' => ['sometimes', 'nullable', 'boolean'],
            'sort' => ['sometimes', Rule::in(['price', 'title', 'created_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
