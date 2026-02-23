<?php

namespace App\Http\Requests\Api;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'mobile_number' => ['required', 'string', 'max:20'],
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
            'referred_by_customer' => ['nullable', 'boolean'],
            'referred_by_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_ids.required' => 'The Category Field is Required.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $mobile = $this->normalizeMobile((string) $this->input('mobile_number', ''));
        $whatsapp = $this->normalizeMobile((string) $this->input('whatsapp_number', ''));

        $this->merge([
            'mobile_number' => $mobile,
            'whatsapp_number' => $whatsapp,
            'country' => $this->input('country', 'Bangladesh'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mobile = (string) $this->input('mobile_number', '');
            if ($mobile === '' || ! preg_match('/^\+8801\d{9}$/', $mobile)) {
                $validator->errors()->add('mobile_number', 'Mobile number must be a valid BD mobile in +880 format.');
            }

            $whatsapp = (string) $this->input('whatsapp_number', '');
            if ($whatsapp === '' || ! preg_match('/^\+8801\d{9}$/', $whatsapp)) {
                $validator->errors()->add('whatsapp_number', 'WhatsApp number must be a valid BD mobile in +880 format.');
            }

            if ($mobile !== '' && Customer::query()->where('mobile_number', $mobile)->exists()) {
                $validator->errors()->add('mobile_number', 'A customer with this mobile number already exists.');
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
