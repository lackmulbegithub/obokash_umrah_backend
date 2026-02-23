<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'category_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'category_ids.*' => ['required', 'integer', 'exists:customer_categories,id'],
            'source_id' => ['sometimes', 'required', 'integer', 'exists:customer_sources,id'],
            'source_wa_id' => ['nullable', 'integer', 'exists:official_whatsapp_numbers,id'],
            'source_email_id' => ['nullable', 'integer', 'exists:official_emails,id'],
            'referred_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'referred_by_customer' => ['nullable', 'boolean'],
            'referred_by_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $mobile = $this->input('mobile_number');
        $whatsapp = $this->input('whatsapp_number');

        $merge = [];
        if ($mobile !== null) {
            $merge['mobile_number'] = $this->normalizeMobile((string) $mobile);
        }
        if ($whatsapp !== null) {
            $merge['whatsapp_number'] = $this->normalizeMobile((string) $whatsapp);
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mobile = $this->input('mobile_number');
            if ($mobile !== null && ! preg_match('/^\+8801\d{9}$/', (string) $mobile)) {
                $validator->errors()->add('mobile_number', 'Mobile number must be a valid BD mobile in +880 format.');
            }

            $whatsapp = $this->input('whatsapp_number');
            if ($whatsapp !== null && ! preg_match('/^\+8801\d{9}$/', (string) $whatsapp)) {
                $validator->errors()->add('whatsapp_number', 'WhatsApp number must be a valid BD mobile in +880 format.');
            }
        });
    }

    private function normalizeMobile(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '8801')) {
            return '+'.substr($digits, 0, 13);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+88'.$digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            return '+880'.$digits;
        }

        return '+'.$digits;
    }
}
