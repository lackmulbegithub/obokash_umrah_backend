<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        $customerId = (string) $this->route('customer')->id;

        return [
            'mobile_number' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('customers', 'mobile_number')->ignore($customerId)],
            'customer_name' => ['sometimes', 'required', 'string', 'max:255'],
            'gender' => ['sometimes', 'required', 'in:male,female,other'],
            'whatsapp_number' => ['sometimes', 'required', 'string', 'max:20'],
            'visit_record' => ['sometimes', 'required', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
