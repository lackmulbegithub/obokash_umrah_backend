<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'query_details_text' => ['required', 'string'],
            'assigned_type' => ['required', 'in:self,team'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['required', 'integer', 'exists:services,id'],
            'force_create' => ['nullable', 'boolean'],
        ];
    }
}
