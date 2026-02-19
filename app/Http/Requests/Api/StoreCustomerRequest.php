<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
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
            'mobile_number' => ['required', 'string', 'max:20', 'unique:customers,mobile_number'],
            'customer_name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:male,female,other'],
            'whatsapp_number' => ['required', 'string', 'max:20'],
            'visit_record' => ['required', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['required', 'integer', 'exists:customer_categories,id'],
            'source_id' => ['required', 'integer', 'exists:customer_sources,id'],
            'source_wa_id' => ['nullable', 'integer', 'exists:official_whatsapp_numbers,id'],
            'source_email_id' => ['nullable', 'integer', 'exists:official_emails,id'],
            'referred_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'referred_by_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ];
    }
}
