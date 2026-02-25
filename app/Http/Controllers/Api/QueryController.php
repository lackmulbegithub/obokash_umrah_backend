<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreQueryRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerSourceLog;
use App\Models\GenericSource;
use App\Models\Query;
use App\Models\QueryItem;
use App\Models\QuerySourceLog;
use App\Models\ServiceQueue;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QueryController extends Controller
{
    public function intakeSearch(Request $request): JsonResponse
    {
        $mobile = trim((string) $request->query('mobile', ''));
        $name = trim((string) $request->query('name', ''));

        if ($mobile === '' && $name === '') {
            throw ValidationException::withMessages([
                'search' => ['Search by mobile or name is required.'],
            ]);
        }

        $normalizedMobile = $mobile !== '' ? $this->normalizeMobile($mobile) : '';

        $customerQuery = Customer::query()
            ->select($this->customerSelectColumns())
            ->orderByDesc('id');

        if ($mobile !== '') {
            $customerQuery->where(function ($builder) use ($mobile, $normalizedMobile): void {
                $builder->where('mobile_number', 'like', '%'.$normalizedMobile.'%');
                if ($normalizedMobile !== $mobile) {
                    $builder->orWhere('mobile_number', 'like', '%'.$mobile.'%');
                }
            });
        }

        if ($name !== '') {
            $customerQuery->whereRaw('LOWER(customer_name) LIKE ?', ['%'.mb_strtolower($name).'%']);
        }

        $customers = $customerQuery->limit(20)->get();
        $customer = $this->resolvePrimaryCustomer($customers, $normalizedMobile);

        if (! $customer) {
            return response()->json([
                'customer_state' => 'not_found',
                'customer_id' => null,
                'has_active_queries' => false,
                'active_queries' => [],
                'matches' => $customers->values(),
            ]);
        }

        $activeQueries = $this->findRunningQueries($customer->id);

        AuditLogger::log(
            auth()->id(),
            'customer',
            $customer->id,
            'query.search_performed',
            null,
            null,
            [
                'mobile' => $mobile,
                'name' => $name,
                'has_active_queries' => $activeQueries->isNotEmpty(),
            ],
        );

        return response()->json([
            'customer_state' => $this->resolveCustomerState($customer),
            'customer_id' => $customer->id,
            'customer' => $customer,
            'has_active_queries' => $activeQueries->isNotEmpty(),
            'active_queries' => $activeQueries,
            'matches' => $customers->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $status = trim($request->string('status')->toString());
        $serviceId = $request->integer('service_id');
        $myQueriesOnly = $request->boolean('my_queries', false);

        $query = Query::query()->with(['customer:id,customer_name,mobile_number', 'items.service:id,service_name']);

        if ($status !== '') {
            $statuses = collect(explode(',', $status))
                ->map(static fn (string $item): string => trim($item))
                ->filter(static fn (string $item): bool => $item !== '')
                ->values()
                ->all();

            if (count($statuses) <= 1) {
                $query->where('query_status', $statuses[0] ?? $status);
            } else {
                $query->whereIn('query_status', $statuses);
            }
        }

        if ($serviceId > 0) {
            $query->whereHas('items', function ($builder) use ($serviceId): void {
                $builder->where('service_id', $serviceId);
            });
        }

        if ($myQueriesOnly) {
            $query->where(function ($builder): void {
                $builder
                    ->where('assigned_user_id', auth()->id())
                    ->orWhereHas('items', function ($itemBuilder): void {
                        $itemBuilder->where('assigned_user_id', auth()->id());
                    });
            });
        }

        $queries = $query->latest()->paginate(20);

        return response()->json($queries);
    }

    public function show(Query $query): JsonResponse
    {
        $query->load([
            'createdByUser:id,full_name',
            'customer:id,customer_name,mobile_number,whatsapp_number,visit_record,country,district,address_line,customer_email',
            'customer.categories:id,category_name',
            'items.service:id,service_name',
            'items.assignedUser:id,full_name',
            'items.team:id,team_name',
        ]);

        $customer = $query->customer;
        $addressParts = array_values(array_filter([
            $customer?->address_line,
            $customer?->district,
            $customer?->country,
        ], static fn (?string $value): bool => $value !== null && trim($value) !== ''));

        return response()->json([
            'data' => [
                'id' => $query->id,
                'query_status' => $query->query_status,
                'query_details_text' => $query->query_details_text,
                'query_inputted_by' => $query->createdByUser?->full_name,
                'customer' => [
                    'customer_name' => $customer?->customer_name,
                    'mobile_number' => $customer?->mobile_number,
                    'whatsapp_number' => $customer?->whatsapp_number,
                    'visit_record' => $customer?->visit_record,
                    'customer_email' => $customer?->customer_email,
                    'address' => $addressParts !== [] ? implode(', ', $addressParts) : null,
                    'categories' => $customer?->categories?->pluck('category_name')->values()->all() ?? [],
                ],
                'items' => $query->items->values(),
            ],
        ]);
    }

    public function teamQueue(Request $request): JsonResponse
    {
        $serviceId = $request->integer('service_id');
        $teamId = $request->integer('team_id');
        $effectiveTeamId = $teamId > 0 ? $teamId : auth()->user()?->team_id;

        $query = QueryItem::query()
            ->with([
                'queryRecord.customer:id,customer_name,mobile_number',
                'service:id,service_name',
                'assignedUser:id,full_name',
                'team:id,team_name',
            ])
            ->where('item_status', 'active');

        if ($effectiveTeamId) {
            $query->where('team_id', $effectiveTeamId);
        }

        if ($serviceId > 0) {
            $query->where('service_id', $serviceId);
        }

        $items = $query->latest()->paginate(20);

        $items->getCollection()->transform(function (QueryItem $item) {
            return [
                'id' => $item->id,
                'item_status' => $item->item_status,
                'service' => $item->service,
                'assigned_user' => $item->assignedUser,
                'team' => $item->team,
                'query' => $item->queryRecord,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        });

        return response()->json($items);
    }

    public function assignToMe(QueryItem $queryItem): JsonResponse
    {
        $oldValues = [
            'assigned_user_id' => $queryItem->assigned_user_id,
            'team_id' => $queryItem->team_id,
        ];

        $queryItem->update([
            'assigned_user_id' => auth()->id(),
            'team_id' => $queryItem->team_id ?? auth()->user()?->team_id,
        ]);

        AuditLogger::log(
            auth()->id(),
            'query_item',
            $queryItem->id,
            'query.assignment.changed',
            $oldValues,
            [
                'assigned_user_id' => $queryItem->assigned_user_id,
                'team_id' => $queryItem->team_id,
            ],
            [
                'query_id' => $queryItem->query_id,
                'mode' => 'assign_to_me',
            ],
        );

        return response()->json([
            'message' => 'Query item assigned to you successfully.',
        ]);
    }

    public function store(StoreQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $customer = Customer::query()->findOrFail($validated['customer_id']);
        $customerState = $this->resolveCustomerState($customer);

        $serviceIds = collect($validated['service_ids'])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $selfServiceIds = collect($validated['self_service_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        $this->validateAssignmentRules(
            assignedType: (string) $validated['assigned_type'],
            serviceIds: $serviceIds,
            selfServiceIds: $selfServiceIds,
        );

        $runningQueries = $this->findRunningQueries((int) $validated['customer_id']);

        if ($runningQueries->isNotEmpty() && ! ($validated['force_create'] ?? false)) {
            return response()->json([
                'message' => 'Running/follow-up queries found for this customer.',
                'running_queries' => $runningQueries,
            ], 409);
        }

        $duplicateCandidates = $this->findDuplicateCandidates(
            customerId: (int) $validated['customer_id'],
            serviceIds: $serviceIds,
        );

        if ($duplicateCandidates->isNotEmpty() && ! ($validated['force_create'] ?? false)) {
            return response()->json([
                'message' => 'Possible duplicate query found in the last 7 days.',
                'duplicate_candidates' => $duplicateCandidates,
            ], 409);
        }

        $sourcePayload = $customerState === 'registered' && ! empty($validated['query_source_id'])
            ? [
                'query_source_id' => $validated['query_source_id'] ?? null,
                'source_wa_id' => $validated['source_wa_id'] ?? null,
                'source_email_id' => $validated['source_email_id'] ?? null,
                'referred_by_user_id' => $validated['referred_by_user_id'] ?? null,
                'referred_by_customer_id' => $validated['referred_by_customer_id'] ?? null,
            ]
            : $this->resolveQuerySourcePayloadFromCustomer((int) $customer->id);

        $this->validateQuerySourceRules($sourcePayload);

        $queryModel = DB::transaction(function () use ($validated, $serviceIds, $selfServiceIds, $sourcePayload): Query {
            $assignedType = $validated['assigned_type'];

            $query = Query::query()->create([
                'customer_id' => $validated['customer_id'],
                'created_by_user_id' => auth()->id(),
                'query_details_text' => $validated['query_details_text'],
                'query_status' => 'pending',
                'assigned_type' => $assignedType,
                'assigned_user_id' => $assignedType === 'self' ? auth()->id() : null,
                'team_id' => $validated['team_id'] ?? null,
            ]);

            $effectiveSelfServiceIds = $assignedType === 'self'
                ? ($selfServiceIds === [] ? $serviceIds : $selfServiceIds)
                : [];

            foreach ($serviceIds as $serviceId) {
                $teamId = null;
                $assignedUserId = null;
                $useSelf = $assignedType === 'self' && in_array($serviceId, $effectiveSelfServiceIds, true);

                if ($useSelf) {
                    $assignedUserId = auth()->id();
                }

                if (! $useSelf) {
                    $mappedQueue = ServiceQueue::query()->where('service_id', $serviceId)->where('is_active', true)->first();
                    $teamId = $validated['team_id'] ?? optional($mappedQueue)->team_id;
                    $assignedUserId = optional($mappedQueue)->queue_owner_user_id;

                    if (! $teamId) {
                        $teamId = auth()->user()?->team_id;
                    }
                }

                $item = QueryItem::query()->create([
                    'query_id' => $query->id,
                    'service_id' => $serviceId,
                    'assigned_user_id' => $assignedUserId,
                    'team_id' => $teamId,
                    'item_status' => 'active',
                ]);

                AuditLogger::log(
                    auth()->id(),
                    'query_item',
                    $item->id,
                    'query.assignment.created',
                    null,
                    [
                        'assigned_user_id' => $assignedUserId,
                        'team_id' => $teamId,
                        'item_status' => 'active',
                    ],
                    [
                        'query_id' => $query->id,
                        'service_id' => $serviceId,
                    ],
                );
            }

            QuerySourceLog::query()->create([
                'query_id' => $query->id,
                'source_id' => $sourcePayload['query_source_id'],
                'source_wa_id' => $sourcePayload['source_wa_id'] ?? null,
                'source_email_id' => $sourcePayload['source_email_id'] ?? null,
                'referred_by_user_id' => $sourcePayload['referred_by_user_id'] ?? null,
                'referred_by_customer_id' => $sourcePayload['referred_by_customer_id'] ?? null,
                'created_by_user_id' => auth()->id(),
            ]);

            AuditLogger::log(
                auth()->id(),
                'query',
                $query->id,
                'query.created',
                null,
                $query->only(['customer_id', 'query_status', 'assigned_type', 'assigned_user_id', 'team_id']),
                [
                    'service_ids' => $serviceIds,
                    'self_service_ids' => $effectiveSelfServiceIds,
                ],
            );

            return $query;
        });

        return response()->json([
            'message' => 'Query created successfully.',
            'data' => $queryModel->load(['items.service:id,service_name']),
        ], 201);
    }

    /**
     * @return array{
     *   query_source_id:int,
     *   source_wa_id:int|null,
     *   source_email_id:int|null,
     *   referred_by_user_id:int|null,
     *   referred_by_customer_id:int|null
     * }
     */
    private function resolveQuerySourcePayloadFromCustomer(int $customerId): array
    {
        $customerSourceLog = CustomerSourceLog::query()
            ->where('customer_id', $customerId)
            ->latest('id')
            ->first();

        if (! $customerSourceLog) {
            throw ValidationException::withMessages([
                'query_source_id' => ['Customer source is missing. Complete customer registration/source capture first.'],
            ]);
        }

        $sourceExists = GenericSource::query()->where('id', $customerSourceLog->source_id)->exists();
        if (! $sourceExists) {
            throw ValidationException::withMessages([
                'query_source_id' => ['Customer source is invalid.'],
            ]);
        }

        return [
            'query_source_id' => (int) $customerSourceLog->source_id,
            'source_wa_id' => $customerSourceLog->source_wa_id,
            'source_email_id' => $customerSourceLog->source_email_id,
            'referred_by_user_id' => $customerSourceLog->referred_by_user_id,
            'referred_by_customer_id' => $customerSourceLog->referred_by_customer_id,
        ];
    }

    public function updateStatus(Request $request, Query $query): JsonResponse
    {
        $allowedStatuses = array_keys(config('query_status', []));
        if ($allowedStatuses === []) {
            $allowedStatuses = ['pending', 'running', 'follow_up', 'sold', 'finished'];
        }

        $validated = $request->validate([
            'query_status' => ['required', Rule::in($allowedStatuses)],
        ]);

        $oldValues = [
            'query_status' => $query->query_status,
        ];

        $nextStatus = (string) $validated['query_status'];
        $currentStatus = (string) $query->query_status;

        // Pending -> Running can only be done by the assigned sales person.
        if ($currentStatus === 'pending' && $nextStatus === 'running') {
            $actorId = auth()->id();
            $isAssigned = (int) $query->assigned_user_id === (int) $actorId
                || $query->items()->where('assigned_user_id', $actorId)->exists();

            if (! $isAssigned) {
                throw ValidationException::withMessages([
                    'query_status' => ['Only assigned sales person can change status from Pending to Running.'],
                ]);
            }
        }

        $query->update([
            'query_status' => $nextStatus,
        ]);

        if (in_array($nextStatus, ['sold', 'finished'], true)) {
            $query->items()->update(['item_status' => 'closed']);
        } else {
            $query->items()->update(['item_status' => 'active']);
        }

        AuditLogger::log(
            auth()->id(),
            'query',
            $query->id,
            'query.status.changed',
            $oldValues,
            ['query_status' => $query->query_status],
        );

        return response()->json([
            'message' => 'Query status updated successfully.',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateQuerySourceRules(array $payload): void
    {
        if (empty($payload['query_source_id'])) {
            throw ValidationException::withMessages([
                'query_source_id' => ['Query source is required for registered customers.'],
            ]);
        }

        $sourceName = (string) GenericSource::query()->where('id', $payload['query_source_id'])->value('source_name');
        if ($sourceName === '') {
            throw ValidationException::withMessages([
                'query_source_id' => ['Invalid query source selected.'],
            ]);
        }

        $errors = [];
        if ($sourceName === 'WhatsApp Call/Message' && empty($payload['source_wa_id'])) {
            $errors['source_wa_id'][] = 'Official WhatsApp number is required for this query source.';
        }
        if ($sourceName === 'Email' && empty($payload['source_email_id'])) {
            $errors['source_email_id'][] = 'Official email is required for this query source.';
        }
        if ($sourceName === 'Referred by Colleague' && empty($payload['referred_by_user_id'])) {
            $errors['referred_by_user_id'][] = 'Referrer colleague is required for this query source.';
        }
        if ($sourceName === 'Referred by Customer' && empty($payload['referred_by_customer_id'])) {
            $errors['referred_by_customer_id'][] = 'Referrer customer is required for this query source.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param array<int, int> $serviceIds
     * @param array<int, int> $selfServiceIds
     */
    private function validateAssignmentRules(string $assignedType, array $serviceIds, array $selfServiceIds): void
    {
        if ($assignedType === 'team' && $selfServiceIds !== []) {
            throw ValidationException::withMessages([
                'self_service_ids' => ['Self service allocation is allowed only when assignment type is self.'],
            ]);
        }

        $invalidSelfSelections = array_values(array_diff($selfServiceIds, $serviceIds));
        if ($invalidSelfSelections !== []) {
            throw ValidationException::withMessages([
                'self_service_ids' => ['Self selected services must be within selected service list.'],
            ]);
        }
    }

    private function findRunningQueries(int $customerId)
    {
        $queries = Query::query()
            ->with([
                'customer:id,customer_name,mobile_number',
                'items.service:id,service_name',
                'items.assignedUser:id,full_name',
                'items.team:id,team_name',
            ])
            ->where('customer_id', $customerId)
            ->whereIn('query_status', config('query.running_statuses', ['running', 'follow_up']))
            ->latest()
            ->get(['id', 'customer_id', 'query_details_text', 'query_status', 'created_at']);

        if ($queries->isEmpty()) {
            return $queries;
        }

        $statusLogs = AuditLog::query()
            ->where('auditable_type', 'query')
            ->where('action', 'query.status.changed')
            ->whereIn('auditable_id', $queries->pluck('id')->all())
            ->latest('id')
            ->get(['auditable_id', 'actor_user_id', 'new_values']);

        $latestRelevant = [];
        foreach ($statusLogs as $log) {
            $queryId = (int) $log->auditable_id;
            if (array_key_exists($queryId, $latestRelevant)) {
                continue;
            }

            $nextStatus = (string) (($log->new_values ?? [])['query_status'] ?? '');
            if (! in_array($nextStatus, ['running', 'follow_up'], true)) {
                continue;
            }

            $latestRelevant[$queryId] = (int) ($log->actor_user_id ?? 0);
        }

        $userIds = collect($latestRelevant)->filter(static fn (int $id): bool => $id > 0)->values()->all();
        $nameByUserId = User::query()
            ->whereIn('id', $userIds)
            ->pluck('full_name', 'id');

        return $queries->map(function (Query $query) use ($latestRelevant, $nameByUserId) {
            $actorId = $latestRelevant[$query->id] ?? 0;
            $query->setAttribute('status_changed_by', $actorId > 0 ? ($nameByUserId[$actorId] ?? null) : null);

            return $query;
        });
    }

    /**
     * @param array<int, int> $serviceIds
     */
    private function findDuplicateCandidates(int $customerId, array $serviceIds)
    {
        if ($serviceIds === []) {
            return collect();
        }

        $since = CarbonImmutable::now()->subDays(7);

        return Query::query()
            ->with(['items.service:id,service_name'])
            ->where('customer_id', $customerId)
            ->whereIn('query_status', config('query.running_statuses', ['running', 'follow_up']))
            ->where('created_at', '>=', $since)
            ->whereHas('items', function ($builder) use ($serviceIds): void {
                $builder->whereIn('service_id', $serviceIds);
            })
            ->latest()
            ->get(['id', 'customer_id', 'query_details_text', 'query_status', 'created_at']);
    }

    private function resolvePrimaryCustomer($customers, string $normalizedMobile): ?Customer
    {
        if ($customers->isEmpty()) {
            return null;
        }

        if ($normalizedMobile !== '') {
            $exact = $customers->firstWhere('mobile_number', $normalizedMobile);
            if ($exact) {
                return $exact;
            }
        }

        return $customers->first();
    }

    /**
     * @return array<int, string>
     */
    private function customerSelectColumns(): array
    {
        $columns = ['id', 'customer_name', 'mobile_number'];
        if ($this->hasCustomerStatusColumn()) {
            $columns[] = 'status';
        }

        return $columns;
    }

    private function resolveCustomerState(Customer $customer): string
    {
        if (! $this->hasCustomerStatusColumn()) {
            return 'registered';
        }

        $status = (string) $customer->getAttribute('status');

        return in_array($status, ['referrer', 'referrer_only'], true)
            ? 'referrer'
            : 'registered';
    }

    private function hasCustomerStatusColumn(): bool
    {
        static $hasColumn;

        if ($hasColumn === null) {
            $hasColumn = Schema::hasColumn('customers', 'status');
        }

        return $hasColumn;
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
