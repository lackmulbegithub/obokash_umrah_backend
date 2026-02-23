<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCustomerRequest;
use App\Http\Requests\Api\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerEditRequest;
use App\Models\CustomerReferral;
use App\Models\CustomerSource;
use App\Models\CustomerSourceLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $mobile = trim((string) $request->query('mobile', ''));
        $name = trim((string) $request->query('name', ''));
        $perPage = max(5, min((int) $request->query('per_page', 10), 50));

        $query = Customer::query()->with('categories:id,category_name')->orderByDesc('id');

        if ($mobile !== '') {
            $query->where('mobile_number', 'like', '%'.$this->normalizeMobile($mobile).'%');
        }

        if ($name !== '') {
            $query->whereRaw('LOWER(customer_name) LIKE ?', ['%'.mb_strtolower($name).'%']);
        }

        $customers = $query->paginate($perPage);
        $sourceLogs = CustomerSourceLog::query()
            ->whereIn('customer_id', $customers->pluck('id')->all())
            ->latest('id')
            ->get()
            ->groupBy('customer_id')
            ->map(static fn ($logs) => $logs->first());

        $rows = $customers->getCollection()->map(function (Customer $customer) use ($sourceLogs): array {
            /** @var \App\Models\CustomerSourceLog|null $sourceLog */
            $sourceLog = $sourceLogs->get($customer->id);

            return [
                'id' => $customer->id,
                'mobile_number' => $customer->mobile_number,
                'customer_name' => $customer->customer_name,
                'gender' => $customer->gender,
                'whatsapp_number' => $customer->whatsapp_number,
                'visit_record' => $customer->visit_record,
                'country' => $customer->country,
                'district' => $customer->district,
                'address_line' => $customer->address_line,
                'customer_email' => $customer->customer_email,
                'categories' => $customer->categories->pluck('category_name')->values(),
                'source_id' => $sourceLog?->source_id,
                'created_at' => optional($customer->created_at)->toISOString(),
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'last_page' => $customers->lastPage(),
            ],
        ]);
    }

    public function search(): JsonResponse
    {
        $mobile = request()->string('mobile')->toString();
        $name = request()->string('name')->toString();
        $limit = max(5, min((int) request()->integer('limit', 15), 50));

        $query = Customer::query()->with('categories:id,category_name');

        if ($mobile !== '') {
            $query->where('mobile_number', 'like', '%'.$this->normalizeMobile($mobile).'%');
        }

        if ($name !== '') {
            $query->whereRaw('LOWER(customer_name) LIKE ?', ['%'.mb_strtolower($name).'%']);
        }

        $customers = $query->orderBy('customer_name')->limit($limit)->get();

        return response()->json(['data' => $customers]);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load('categories:id,category_name');
        $sourceLog = CustomerSourceLog::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->first();

        return response()->json([
            'data' => [
                'id' => $customer->id,
                'mobile_number' => $customer->mobile_number,
                'customer_name' => $customer->customer_name,
                'gender' => $customer->gender,
                'whatsapp_number' => $customer->whatsapp_number,
                'visit_record' => $customer->visit_record,
                'country' => $customer->country,
                'district' => $customer->district,
                'address_line' => $customer->address_line,
                'customer_email' => $customer->customer_email,
                'category_ids' => $customer->categories->pluck('id')->values(),
                'source_id' => $sourceLog?->source_id,
                'source_wa_id' => $sourceLog?->source_wa_id,
                'source_email_id' => $sourceLog?->source_email_id,
                'referred_by_user_id' => $sourceLog?->referred_by_user_id,
                'referred_by_customer' => $sourceLog?->referred_by_customer_id !== null,
                'referred_by_customer_id' => $sourceLog?->referred_by_customer_id,
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $mobile = $this->normalizeMobile($validated['mobile_number']);
        $whatsapp = $this->normalizeMobile($validated['whatsapp_number']);

        $sourceRuleErrors = $this->validateSourceConditionalRules($validated);
        if ($sourceRuleErrors !== []) {
            throw ValidationException::withMessages($sourceRuleErrors);
        }

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
                'referred_by_customer_id' => ($validated['referred_by_customer'] ?? false) ? ($validated['referred_by_customer_id'] ?? null) : null,
                'created_by_user_id' => auth()->id(),
            ]);

            if (($validated['referred_by_customer'] ?? false) && ! empty($validated['referred_by_customer_id'])) {
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

        $warnings = $this->collectDuplicateWarnings($customer);

        return response()->json([
            'message' => 'Customer created successfully.',
            'warnings' => $warnings,
            'data' => $customer->load('categories:id,category_name'),
        ], 201);
    }

    public function storeMinimal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_number' => ['required', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:255'],
        ]);

        $mobile = $this->normalizeMobile($validated['mobile_number']);
        if (Customer::query()->where('mobile_number', $mobile)->exists()) {
            throw ValidationException::withMessages([
                'mobile_number' => ['A customer with this mobile number already exists.'],
            ]);
        }

        $customer = Customer::query()->create([
            'mobile_number' => $mobile,
            'customer_name' => trim((string) ($validated['customer_name'] ?? 'Unnamed Customer')),
            'gender' => 'other',
            'whatsapp_number' => $mobile,
            'visit_record' => 'No Travel',
            'country' => 'Bangladesh',
            'is_active' => true,
        ]);

        AuditLogger::log(
            auth()->id(),
            'customer',
            $customer->id,
            'customer.minimal.created',
            null,
            $customer->only(['mobile_number', 'customer_name'])
        );

        return response()->json([
            'message' => 'Minimal customer created successfully.',
            'data' => $customer,
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
        $sourceLog = $this->latestSourceLog($customer->id);
        $oldData['category_ids'] = $customer->categories()->pluck('customer_categories.id')->values()->all();
        $oldData['source_id'] = $sourceLog?->source_id;
        $oldData['source_wa_id'] = $sourceLog?->source_wa_id;
        $oldData['source_email_id'] = $sourceLog?->source_email_id;
        $oldData['referred_by_user_id'] = $sourceLog?->referred_by_user_id;
        $oldData['referred_by_customer'] = $sourceLog?->referred_by_customer_id !== null;
        $oldData['referred_by_customer_id'] = $sourceLog?->referred_by_customer_id;

        $newData = array_merge($oldData, $validated);

        if (array_key_exists('mobile_number', $newData)) {
            $newData['mobile_number'] = $this->normalizeMobile((string) $newData['mobile_number']);
        }

        if (array_key_exists('whatsapp_number', $newData)) {
            $newData['whatsapp_number'] = $this->normalizeMobile((string) $newData['whatsapp_number']);
        }
        if (($newData['referred_by_customer'] ?? false) === false) {
            $newData['referred_by_customer_id'] = null;
        }

        $sourceRuleErrors = $this->validateSourceConditionalRules($newData, true);
        if ($sourceRuleErrors !== []) {
            throw ValidationException::withMessages($sourceRuleErrors);
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<int, string>>
     */
    private function validateSourceConditionalRules(array $payload, bool $forUpdate = false): array
    {
        $errors = [];
        $sourceId = (int) ($payload['source_id'] ?? 0);
        if ($sourceId <= 0) {
            return $forUpdate ? [] : ['source_id' => ['Customer source is required.']];
        }

        $sourceName = (string) CustomerSource::query()->where('id', $sourceId)->value('source_name');
        if ($sourceName === '') {
            return ['source_id' => ['Invalid customer source selected.']];
        }

        if ($sourceName === 'WhatsApp Call/Message' && empty($payload['source_wa_id'])) {
            $errors['source_wa_id'][] = 'Official WhatsApp is required for WhatsApp source.';
        }

        if ($sourceName === 'Email' && empty($payload['source_email_id'])) {
            $errors['source_email_id'][] = 'Official email is required for Email source.';
        }

        if ($sourceName === 'Referred by Colleague' && empty($payload['referred_by_user_id'])) {
            $errors['referred_by_user_id'][] = 'Referrer colleague is required for this source.';
        }

        if ($sourceName === 'Referred by Customer') {
            if (! array_key_exists('referred_by_customer', $payload)) {
                $errors['referred_by_customer'][] = 'Please select whether customer referrer is available (Yes/No).';
            } elseif (($payload['referred_by_customer'] ?? false) && empty($payload['referred_by_customer_id'])) {
                $errors['referred_by_customer_id'][] = 'Referrer customer is required when "Yes" is selected.';
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function collectDuplicateWarnings(Customer $customer): array
    {
        $warnings = [];

        if ($customer->whatsapp_number !== '') {
            $exists = Customer::query()
                ->where('whatsapp_number', $customer->whatsapp_number)
                ->where('id', '!=', $customer->id)
                ->exists();
            if ($exists) {
                $warnings[] = 'Another customer already uses this WhatsApp number.';
            }
        }

        if (! empty($customer->customer_email)) {
            $exists = Customer::query()
                ->where('customer_email', $customer->customer_email)
                ->where('id', '!=', $customer->id)
                ->exists();
            if ($exists) {
                $warnings[] = 'Another customer already uses this email.';
            }
        }

        return $warnings;
    }

    private function latestSourceLog(int $customerId): ?CustomerSourceLog
    {
        return CustomerSourceLog::query()
            ->where('customer_id', $customerId)
            ->latest('id')
            ->first();
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
