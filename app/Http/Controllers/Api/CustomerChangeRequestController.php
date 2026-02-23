<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerEditRequest;
use App\Models\CustomerReferral;
use App\Models\CustomerSourceLog;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerChangeRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = trim((string) $request->query('status', 'pending'));

        $query = CustomerEditRequest::query()
            ->with(['customer:id,customer_name,mobile_number', 'requester:id,full_name', 'approver:id,full_name'])
            ->orderByDesc('id');

        if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    public function show(CustomerEditRequest $changeRequest): JsonResponse
    {
        $changeRequest->load(['customer:id,customer_name,mobile_number', 'requester:id,full_name', 'approver:id,full_name']);

        return response()->json([
            'data' => [
                'id' => $changeRequest->id,
                'status' => $changeRequest->status,
                'note' => $changeRequest->note,
                'created_at' => optional($changeRequest->created_at)->toISOString(),
                'decided_at' => optional($changeRequest->decided_at)->toISOString(),
                'customer' => $changeRequest->customer,
                'requested_by' => $changeRequest->requester?->full_name,
                'approved_by' => $changeRequest->approver?->full_name,
                'old_data' => $changeRequest->old_data_json,
                'new_data' => $changeRequest->new_data_json,
                'diff' => $this->buildDiff($changeRequest->old_data_json ?? [], $changeRequest->new_data_json ?? []),
            ],
        ]);
    }

    public function approve(Request $request, CustomerEditRequest $changeRequest): JsonResponse
    {
        if ($changeRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been decided.'], 422);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($changeRequest, $validated): void {
            /** @var array<string, mixed> $newData */
            $newData = $changeRequest->new_data_json ?? [];
            $customer = Customer::query()->findOrFail($changeRequest->customer_id);

            $customer->fill([
                'mobile_number' => $newData['mobile_number'] ?? $customer->mobile_number,
                'customer_name' => $newData['customer_name'] ?? $customer->customer_name,
                'gender' => $newData['gender'] ?? $customer->gender,
                'whatsapp_number' => $newData['whatsapp_number'] ?? $customer->whatsapp_number,
                'visit_record' => $newData['visit_record'] ?? $customer->visit_record,
                'country' => $newData['country'] ?? $customer->country,
                'district' => $newData['district'] ?? $customer->district,
                'address_line' => $newData['address_line'] ?? $customer->address_line,
                'customer_email' => $newData['customer_email'] ?? $customer->customer_email,
            ]);
            $customer->save();

            if (isset($newData['category_ids']) && is_array($newData['category_ids'])) {
                $customer->categories()->sync($newData['category_ids']);
            }

            if (isset($newData['source_id'])) {
                CustomerSourceLog::query()->create([
                    'customer_id' => $customer->id,
                    'source_id' => (int) $newData['source_id'],
                    'source_wa_id' => $newData['source_wa_id'] ?? null,
                    'source_email_id' => $newData['source_email_id'] ?? null,
                    'referred_by_user_id' => $newData['referred_by_user_id'] ?? null,
                    'referred_by_customer_id' => $newData['referred_by_customer_id'] ?? null,
                    'created_by_user_id' => auth()->id(),
                ]);

                if (! empty($newData['referred_by_customer_id'])) {
                    CustomerReferral::query()->firstOrCreate([
                        'referrer_customer_id' => $newData['referred_by_customer_id'],
                        'referred_customer_id' => $customer->id,
                    ], [
                        'created_by_user_id' => auth()->id(),
                    ]);
                }
            }

            $changeRequest->update([
                'status' => 'approved',
                'approved_by_user_id' => auth()->id(),
                'note' => $validated['note'] ?? null,
                'decided_at' => now(),
            ]);

            AuditLogger::log(
                auth()->id(),
                'customer_edit_request',
                $changeRequest->id,
                'customer.edit.approved',
                $changeRequest->old_data_json ?? [],
                $changeRequest->new_data_json ?? [],
                ['customer_id' => $customer->id],
            );
        });

        return response()->json([
            'message' => 'Customer change request approved and applied.',
        ]);
    }

    public function reject(Request $request, CustomerEditRequest $changeRequest): JsonResponse
    {
        if ($changeRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been decided.'], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $changeRequest->update([
            'status' => 'rejected',
            'approved_by_user_id' => auth()->id(),
            'note' => $validated['reason'],
            'decided_at' => now(),
        ]);

        AuditLogger::log(
            auth()->id(),
            'customer_edit_request',
            $changeRequest->id,
            'customer.edit.rejected',
            $changeRequest->old_data_json ?? [],
            $changeRequest->new_data_json ?? [],
            ['reason' => $validated['reason']],
        );

        return response()->json([
            'message' => 'Customer change request rejected.',
        ]);
    }

    /**
     * @param array<string, mixed> $oldData
     * @param array<string, mixed> $newData
     * @return array<int, array<string, mixed>>
     */
    private function buildDiff(array $oldData, array $newData): array
    {
        $keys = array_unique(array_merge(array_keys($oldData), array_keys($newData)));
        $diff = [];

        foreach ($keys as $key) {
            $oldValue = $oldData[$key] ?? null;
            $newValue = $newData[$key] ?? null;
            if ($oldValue !== $newValue) {
                $diff[] = [
                    'field' => $key,
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $diff;
    }
}
