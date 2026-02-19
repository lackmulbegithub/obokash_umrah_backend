<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCustomerRequest;
use App\Http\Requests\Api\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerEditRequest;
use App\Models\CustomerReferral;
use App\Models\CustomerSourceLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function search(): JsonResponse
    {
        $mobile = request()->string('mobile')->toString();
        $name = request()->string('name')->toString();

        $query = Customer::query();

        if ($mobile !== '') {
            $query->where('mobile_number', 'like', '%'.$this->normalizeMobile($mobile).'%');
        }

        if ($name !== '') {
            $query->where('customer_name', 'like', '%'.$name.'%');
        }

        $customers = $query
            ->with(['categories:id,category_name'])
            ->orderBy('customer_name')
            ->limit(20)
            ->get();

        return response()->json(['data' => $customers]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $mobile = $this->normalizeMobile($validated['mobile_number']);
        $whatsapp = $this->normalizeMobile($validated['whatsapp_number']);

        $customer = DB::transaction(function () use ($validated, $mobile, $whatsapp): Customer {
            $customer = Customer::query()->create([
                'mobile_number' => $mobile,
                'customer_name' => $validated['customer_name'],
                'gender' => $validated['gender'],
                'whatsapp_number' => $whatsapp,
                'visit_record' => $validated['visit_record'],
                'country' => $validated['country'] ?? 'Bangladesh',
                'district' => $validated['district'] ?? null,
                'address_line' => $validated['address_line'] ?? null,
                'customer_email' => $validated['customer_email'] ?? null,
                'is_active' => true,
            ]);

            $customer->categories()->sync($validated['category_ids']);

            CustomerSourceLog::query()->create([
                'customer_id' => $customer->id,
                'source_id' => $validated['source_id'],
                'source_wa_id' => $validated['source_wa_id'] ?? null,
                'source_email_id' => $validated['source_email_id'] ?? null,
                'referred_by_user_id' => $validated['referred_by_user_id'] ?? null,
                'referred_by_customer_id' => $validated['referred_by_customer_id'] ?? null,
                'created_by_user_id' => auth()->id(),
            ]);

            if (! empty($validated['referred_by_customer_id'])) {
                CustomerReferral::query()->firstOrCreate([
                    'referrer_customer_id' => $validated['referred_by_customer_id'],
                    'referred_customer_id' => $customer->id,
                ], [
                    'created_by_user_id' => auth()->id(),
                ]);
            }

            AuditLogger::log(
                auth()->id(),
                'customer',
                $customer->id,
                'customer.created',
                null,
                $customer->only(['mobile_number', 'customer_name', 'gender', 'whatsapp_number', 'visit_record']),
            );

            return $customer;
        });

        return response()->json([
            'message' => 'Customer created successfully.',
            'data' => $customer->load('categories:id,category_name'),
        ], 201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $validated = $request->validated();

        $oldData = $customer->only([
            'mobile_number',
            'customer_name',
            'gender',
            'whatsapp_number',
            'visit_record',
            'country',
            'district',
            'address_line',
            'customer_email',
        ]);

        $newData = array_merge($oldData, $validated);

        if (array_key_exists('mobile_number', $newData)) {
            $newData['mobile_number'] = $this->normalizeMobile((string) $newData['mobile_number']);
        }

        if (array_key_exists('whatsapp_number', $newData)) {
            $newData['whatsapp_number'] = $this->normalizeMobile((string) $newData['whatsapp_number']);
        }

        $editRequest = CustomerEditRequest::query()->create([
            'customer_id' => $customer->id,
            'requested_by_user_id' => auth()->id(),
            'status' => 'pending',
            'old_data_json' => $oldData,
            'new_data_json' => $newData,
        ]);

        AuditLogger::log(
            auth()->id(),
            'customer',
            $customer->id,
            'customer.edit.requested',
            $oldData,
            $newData,
            ['edit_request_id' => $editRequest->id],
        );

        return response()->json([
            'message' => 'Customer change request submitted for approval.',
        ]);
    }

    private function normalizeMobile(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        if (str_starts_with($digits, '880') && strlen($digits) >= 12) {
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
