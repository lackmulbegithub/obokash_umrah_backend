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
            'query_details_text' => ['required', 'string', 'max:5000'],
            'assigned_type' => ['required', 'in:self,team'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['required', 'integer', 'distinct', 'exists:services,id'],
            'self_service_ids' => ['nullable', 'array'],
            'self_service_ids.*' => ['required', 'integer', 'distinct', 'exists:services,id'],
            'query_source_id' => ['nullable', 'integer', 'exists:generic_sources,id'],
            'source_wa_id' => ['nullable', 'integer', 'exists:official_whatsapp_numbers,id'],
            'source_email_id' => ['nullable', 'integer', 'exists:official_emails,id'],
            'referred_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'referred_by_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'force_create' => ['nullable', 'boolean'],
        ];
    }
}
