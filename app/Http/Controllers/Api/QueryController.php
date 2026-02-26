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
use App\Models\ServiceQueueAuthorization;
use App\Models\TeamRoleAssignment;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
            'items.teamQueueOwner:id,full_name',
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
        $actor = auth()->user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user can not access team queue.'],
            ]);
        }

        $serviceId = $request->integer('service_id');
        $teamId = $request->integer('team_id');
        $queueState = trim($request->string('queue_state', 'not_assigned')->toString());
        $workflowStatus = trim($request->string('workflow_status')->toString());
        $effectiveTeamId = $teamId > 0 ? $teamId : auth()->user()?->team_id;
        $query = $this->buildTeamQueueBaseQuery($actor, $serviceId, $effectiveTeamId)
            ->with([
                'queryRecord.customer:id,customer_name,mobile_number',
                'service:id,service_name',
                'assignedUser:id,full_name',
                'team:id,team_name',
                'teamQueueOwner:id,full_name',
            ]);

        if ($queueState === 'not_assigned') {
            $query->where('assigned_type', 'team')->whereNull('assigned_user_id');
        } elseif ($queueState === 'distributed') {
            $query->where('assigned_type', 'self')->whereNotNull('assigned_user_id');
        } elseif ($queueState !== 'all') {
            throw ValidationException::withMessages([
                'queue_state' => ['Allowed values: not_assigned, distributed, all.'],
            ]);
        }

        if ($workflowStatus !== '') {
            $allowedWorkflowStatuses = ['pending', 'running', 'follow_up', 'sold', 'finished'];
            if (! in_array($workflowStatus, $allowedWorkflowStatuses, true)) {
                throw ValidationException::withMessages([
                    'workflow_status' => ['Allowed values: pending, running, follow_up, sold, finished.'],
                ]);
            }

            $query->where('workflow_status', $workflowStatus);
        }

        $items = $query->latest()->paginate(20);

        $items->getCollection()->transform(function (QueryItem $item) {
            return [
                'id' => $item->id,
                'item_status' => $item->item_status,
                'service' => $item->service,
                'assigned_user' => $item->assignedUser,
                'team' => $item->team,
                'assigned_type' => $item->assigned_type,
                'team_queue_owner_user_id' => $item->team_queue_owner_user_id,
                'assigned_by_user_id' => $item->assigned_by_user_id,
                'assignment_note' => $item->assignment_note,
                'query' => $item->queryRecord,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        });

        return response()->json($items);
    }

    public function teamQueueCounters(Request $request): JsonResponse
    {
        $actor = auth()->user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user can not access team queue counters.'],
            ]);
        }

        $serviceId = $request->integer('service_id');
        $teamId = $request->integer('team_id');
        $effectiveTeamId = $teamId > 0 ? $teamId : auth()->user()?->team_id;

        $baseQuery = $this->buildTeamQueueBaseQuery($actor, $serviceId, $effectiveTeamId);
        $soldSince = CarbonImmutable::now()->subDays(30);

        $notAssigned = (clone $baseQuery)
            ->where('assigned_type', 'team')
            ->whereNull('assigned_user_id')
            ->count();

        $pending = (clone $baseQuery)
            ->whereNotNull('assigned_user_id')
            ->where('workflow_status', 'pending')
            ->count();

        $running = (clone $baseQuery)
            ->whereNotNull('assigned_user_id')
            ->where('workflow_status', 'running')
            ->count();

        $followUp = (clone $baseQuery)
            ->whereNotNull('assigned_user_id')
            ->where('workflow_status', 'follow_up')
            ->count();

        $sold = (clone $baseQuery)
            ->whereNotNull('assigned_user_id')
            ->where('workflow_status', 'sold')
            ->where('updated_at', '>=', $soldSince)
            ->count();

        $finished = (clone $baseQuery)
            ->whereNotNull('assigned_user_id')
            ->where('workflow_status', 'finished')
            ->count();

        return response()->json([
            'data' => [
                'not_assigned' => $notAssigned,
                'pending' => $pending,
                'running' => $running,
                'follow_up' => $followUp,
                'sold' => $sold,
                'finished' => $finished,
            ],
        ]);
    }

    public function selfQueue(Request $request): JsonResponse
    {
        $actor = auth()->user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user can not access self queue.'],
            ]);
        }

        $serviceId = $request->integer('service_id');
        $workflowStatus = trim($request->string('workflow_status', 'pending')->toString());
        $allowedWorkflowStatuses = ['pending', 'running', 'follow_up', 'sold', 'finished', 'all'];
        if (! in_array($workflowStatus, $allowedWorkflowStatuses, true)) {
            throw ValidationException::withMessages([
                'workflow_status' => ['Allowed values: pending, running, follow_up, sold, finished, all.'],
            ]);
        }

        $query = $this->buildSelfQueueBaseQuery($actor, $serviceId)
            ->with([
                'queryRecord.customer:id,customer_name,mobile_number',
                'service:id,service_name',
                'team:id,team_name',
            ]);

        if ($workflowStatus !== 'all') {
            $query->where('workflow_status', $workflowStatus);
        }

        $items = $query->latest()->paginate(20);

        $items->getCollection()->transform(function (QueryItem $item) {
            return [
                'id' => $item->id,
                'workflow_status' => $item->workflow_status,
                'item_status' => $item->item_status,
                'service' => $item->service,
                'team' => $item->team,
                'query' => $item->queryRecord,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        });

        return response()->json($items);
    }

    public function selfQueueCounters(Request $request): JsonResponse
    {
        $actor = auth()->user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user can not access self queue counters.'],
            ]);
        }

        $serviceId = $request->integer('service_id');
        $baseQuery = $this->buildSelfQueueBaseQuery($actor, $serviceId);
        $soldSince = CarbonImmutable::now()->subDays(30);
        $finishedSince = CarbonImmutable::now()->subDays(30);

        $pending = (clone $baseQuery)->where('workflow_status', 'pending')->count();
        $running = (clone $baseQuery)->where('workflow_status', 'running')->count();
        $followUp = (clone $baseQuery)->where('workflow_status', 'follow_up')->count();
        $sold = (clone $baseQuery)->where('workflow_status', 'sold')->where('updated_at', '>=', $soldSince)->count();
        $finished = (clone $baseQuery)->where('workflow_status', 'finished')->where('updated_at', '>=', $finishedSince)->count();

        return response()->json([
            'data' => [
                'pending' => $pending,
                'running' => $running,
                'follow_up' => $followUp,
                'sold' => $sold,
                'finished' => $finished,
            ],
        ]);
    }

    public function queueNotificationBadges(Request $request): JsonResponse
    {
        $actor = auth()->user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user can not access notification badges.'],
            ]);
        }

        $today = CarbonImmutable::today();

        $selfBase = $this->buildSelfQueueBaseQuery($actor, 0);
        $selfPending = (clone $selfBase)
            ->where('workflow_status', 'pending')
            ->count();
        $selfFollowUpDue = (clone $selfBase)
            ->where('workflow_status', 'follow_up')
            ->whereDate('follow_up_date', '<=', $today->toDateString())
            ->count();
        $selfEvents = $selfPending + $selfFollowUpDue;

        $teamBase = $this->buildTeamQueueBaseQuery($actor, 0, null);
        $teamNotAssigned = (clone $teamBase)
            ->where('assigned_type', 'team')
            ->whereNull('assigned_user_id')
            ->count();
        $teamFollowUpDue = (clone $teamBase)
            ->where('assigned_type', 'self')
            ->whereNotNull('assigned_user_id')
            ->where('workflow_status', 'follow_up')
            ->whereDate('follow_up_date', '<=', $today->toDateString())
            ->count();
        $teamEvents = $teamNotAssigned + $teamFollowUpDue;

        return response()->json([
            'data' => [
                'self_pending' => $selfPending,
                'self_follow_up_due' => $selfFollowUpDue,
                'self_events' => $selfEvents,
                'team_not_assigned' => $teamNotAssigned,
                'team_follow_up_due' => $teamFollowUpDue,
                'team_events' => $teamEvents,
                'total_events' => $selfEvents + $teamEvents,
            ],
        ]);
    }

    public function assignToMe(QueryItem $queryItem): JsonResponse
    {
        $actor = auth()->user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user can not assign query item.'],
            ]);
        }

        $isSuperUser = $actor->hasAnyRole(['Super Admin', 'Admin']);
        if (! $isSuperUser && ! $this->canManageQueueItem($queryItem, $actor, 'self')) {
            throw ValidationException::withMessages([
                'authorization' => ['You are not allowed to self-assign this query item.'],
            ]);
        }

        if ($queryItem->assigned_type !== 'team') {
            throw ValidationException::withMessages([
                'query_item' => ['Only team-queued items can be self-assigned from team queue.'],
            ]);
        }

        $oldValues = [
            'assigned_type' => $queryItem->assigned_type,
            'assigned_user_id' => $queryItem->assigned_user_id,
            'team_id' => $queryItem->team_id,
            'team_queue_owner_user_id' => $queryItem->team_queue_owner_user_id,
        ];

        $queryItem->update([
            'assigned_type' => 'self',
            'assigned_user_id' => auth()->id(),
            'assigned_by_user_id' => auth()->id(),
            'assignment_note' => 'Self-assigned from team queue',
            'team_id' => $queryItem->team_id ?? auth()->user()?->team_id,
        ]);

        AuditLogger::log(
            auth()->id(),
            'query_item',
            $queryItem->id,
            'query.assignment.changed',
            $oldValues,
            [
                'assigned_type' => $queryItem->assigned_type,
                'assigned_user_id' => $queryItem->assigned_user_id,
                'team_id' => $queryItem->team_id,
                'team_queue_owner_user_id' => $queryItem->team_queue_owner_user_id,
            ],
            [
                'query_id' => $queryItem->query_id,
                'mode' => 'assign_to_me',
                'acted_as' => $this->resolveActorMode($queryItem, $actor),
            ],
        );

        return response()->json([
            'message' => 'Query item assigned to you successfully.',
        ]);
    }

    public function assignToUser(Request $request, QueryItem $queryItem): JsonResponse
    {
        $actor = auth()->user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user can not assign query item.'],
            ]);
        }

        $validated = $request->validate([
            'assigned_user_id' => ['required', 'integer', 'exists:users,id'],
            'distribution_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetUser = User::query()->where('id', $validated['assigned_user_id'])->where('is_active', true)->first();
        if (! $targetUser) {
            throw ValidationException::withMessages([
                'assigned_user_id' => ['Selected user is not active.'],
            ]);
        }

        $isSuperUser = $actor->hasAnyRole(['Super Admin', 'Admin']);
        if (! $isSuperUser && ! $this->canManageQueueItem($queryItem, $actor, 'distribute')) {
            throw ValidationException::withMessages([
                'authorization' => ['You are not allowed to distribute this query item.'],
            ]);
        }

        if ($queryItem->assigned_type !== 'team') {
            throw ValidationException::withMessages([
                'query_item' => ['Only team-queued items can be distributed to a team member.'],
            ]);
        }

        if (! $isSuperUser && $queryItem->team_id && $targetUser->team_id !== $queryItem->team_id) {
            throw ValidationException::withMessages([
                'assigned_user_id' => ['Target user must belong to the same team as the queue item.'],
            ]);
        }

        $oldValues = [
            'assigned_type' => $queryItem->assigned_type,
            'assigned_user_id' => $queryItem->assigned_user_id,
            'team_id' => $queryItem->team_id,
            'team_queue_owner_user_id' => $queryItem->team_queue_owner_user_id,
        ];

        $queryItem->update([
            'assigned_type' => 'self',
            'assigned_user_id' => $targetUser->id,
            'assigned_by_user_id' => auth()->id(),
            'assignment_note' => $validated['distribution_note'] ?? null,
            'team_id' => $queryItem->team_id ?? $targetUser->team_id,
        ]);

        AuditLogger::log(
            auth()->id(),
            'query_item',
            $queryItem->id,
            'query.assignment.changed',
            $oldValues,
            [
                'assigned_type' => $queryItem->assigned_type,
                'assigned_user_id' => $queryItem->assigned_user_id,
                'team_id' => $queryItem->team_id,
                'team_queue_owner_user_id' => $queryItem->team_queue_owner_user_id,
            ],
            [
                'query_id' => $queryItem->query_id,
                'mode' => 'assign_to_user',
                'acted_as' => $this->resolveActorMode($queryItem, $actor),
                'distribution_note' => $validated['distribution_note'] ?? null,
            ],
        );

        return response()->json([
            'message' => 'Query item assigned to team member successfully.',
        ]);
    }

    public function reassignToUser(Request $request, QueryItem $queryItem): JsonResponse
    {
        $actor = auth()->user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user can not reassign query item.'],
            ]);
        }

        $validated = $request->validate([
            'assigned_user_id' => ['required', 'integer', 'exists:users,id'],
            'distribution_note' => ['required', 'string', 'max:1000'],
        ]);

        $targetUser = User::query()->where('id', $validated['assigned_user_id'])->where('is_active', true)->first();
        if (! $targetUser) {
            throw ValidationException::withMessages([
                'assigned_user_id' => ['Selected user is not active.'],
            ]);
        }

        $isSuperUser = $actor->hasAnyRole(['Super Admin', 'Admin']);
        $canManage = $this->canManageQueueItem($queryItem, $actor, 'distribute');
        $isCurrentAssignee = (int) $queryItem->assigned_user_id === (int) $actor->id && $actor->can('query.reassign');

        if (! $isSuperUser && ! $canManage && ! $isCurrentAssignee) {
            throw ValidationException::withMessages([
                'authorization' => ['You are not allowed to reassign this query item.'],
            ]);
        }

        if (! $isSuperUser && $queryItem->team_id && (int) $targetUser->team_id !== (int) $queryItem->team_id) {
            throw ValidationException::withMessages([
                'assigned_user_id' => ['Target user must belong to the same team as the query item.'],
            ]);
        }

        $oldValues = [
            'assigned_type' => $queryItem->assigned_type,
            'assigned_user_id' => $queryItem->assigned_user_id,
            'assigned_by_user_id' => $queryItem->assigned_by_user_id,
            'assignment_note' => $queryItem->assignment_note,
        ];

        $queryItem->update([
            'assigned_type' => 'self',
            'assigned_user_id' => $targetUser->id,
            'assigned_by_user_id' => $actor->id,
            'assignment_note' => $validated['distribution_note'],
        ]);

        AuditLogger::log(
            $actor->id,
            'query_item',
            $queryItem->id,
            'query.assignment.reassigned',
            $oldValues,
            [
                'assigned_type' => $queryItem->assigned_type,
                'assigned_user_id' => $queryItem->assigned_user_id,
                'assigned_by_user_id' => $queryItem->assigned_by_user_id,
                'assignment_note' => $queryItem->assignment_note,
            ],
            [
                'query_id' => $queryItem->query_id,
                'mode' => 'reassign_to_user',
                'acted_as' => $isCurrentAssignee ? 'assignee' : $this->resolveActorMode($queryItem, $actor),
            ],
        );

        return response()->json([
            'message' => 'Query item reassigned successfully.',
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
                $mappedQueueQuery = ServiceQueue::query()
                    ->where('service_id', $serviceId)
                    ->where('is_active', true);

                if (! empty($validated['team_id'])) {
                    $mappedQueueQuery->where('team_id', (int) $validated['team_id']);
                }

                /** @var ServiceQueue|null $mappedQueue */
                $mappedQueue = $mappedQueueQuery->orderBy('id')->first();
                if (! $mappedQueue) {
                    throw ValidationException::withMessages([
                        'service_ids' => ["No active service queue mapping found for service_id={$serviceId}."],
                    ]);
                }

                $teamId = (int) $mappedQueue->team_id;
                $queueOwnerUserId = $mappedQueue->queue_owner_user_id ? (int) $mappedQueue->queue_owner_user_id : null;
                $useSelf = $assignedType === 'self' && in_array($serviceId, $effectiveSelfServiceIds, true);
                $itemAssignedType = $useSelf ? 'self' : 'team';
                $assignedUserId = $useSelf ? auth()->id() : null;

                $item = QueryItem::query()->create([
                    'query_id' => $query->id,
                    'service_id' => $serviceId,
                    'assigned_type' => $itemAssignedType,
                    'assigned_user_id' => $assignedUserId,
                    'assigned_by_user_id' => auth()->id(),
                    'assignment_note' => null,
                    'team_id' => $teamId,
                    'team_queue_owner_user_id' => $queueOwnerUserId,
                    'item_status' => 'active',
                ]);

                AuditLogger::log(
                    auth()->id(),
                    'query_item',
                    $item->id,
                    'query.assignment.created',
                    null,
                    [
                        'assigned_type' => $itemAssignedType,
                        'assigned_user_id' => $assignedUserId,
                        'team_id' => $teamId,
                        'team_queue_owner_user_id' => $queueOwnerUserId,
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

    public function updateItemStatus(Request $request, QueryItem $queryItem): JsonResponse
    {
        $actor = auth()->user();
        if (! $actor) {
            throw ValidationException::withMessages([
                'auth' => ['Unauthenticated user can not change query item status.'],
            ]);
        }

        $allowedStatuses = ['pending', 'running', 'follow_up', 'sold', 'finished', 'reviewed_with_call', 'reviewed_without_call'];
        $validated = $request->validate([
            'workflow_status' => ['required', Rule::in($allowedStatuses)],
            'quotation_date' => ['nullable', 'date'],
            'follow_up_date' => ['nullable', 'date'],
            'finished_note' => ['nullable', 'string', 'max:2000'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $nextStatus = (string) $validated['workflow_status'];
        $currentStatus = (string) $queryItem->workflow_status;
        $isSuperUser = $actor->hasAnyRole(['Super Admin', 'Admin']);
        $isAssignee = (int) $queryItem->assigned_user_id === (int) $actor->id;
        $canManage = $this->canManageQueueItem($queryItem, $actor, 'distribute');

        $allowedTransitions = [
            'pending' => ['running', 'finished'],
            'running' => ['follow_up', 'sold', 'finished'],
            'follow_up' => ['follow_up', 'sold', 'finished'],
            'sold' => [],
            'finished' => ['reviewed_with_call', 'reviewed_without_call'],
            'reviewed_with_call' => [],
            'reviewed_without_call' => [],
        ];

        if (! in_array($nextStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            throw ValidationException::withMessages([
                'workflow_status' => ["Invalid transition from {$currentStatus} to {$nextStatus}."],
            ]);
        }

        if (in_array($nextStatus, ['running', 'follow_up', 'sold', 'finished'], true) && ! $isAssignee && ! $isSuperUser) {
            throw ValidationException::withMessages([
                'workflow_status' => ['Only assigned user or admin can update operational status.'],
            ]);
        }

        if (in_array($nextStatus, ['reviewed_with_call', 'reviewed_without_call'], true) && ! $isSuperUser && ! $canManage) {
            throw ValidationException::withMessages([
                'workflow_status' => ['Only team head/delegate or admin can mark reviewed status.'],
            ]);
        }

        if ($currentStatus === 'pending' && $nextStatus === 'running' && empty($validated['quotation_date'])) {
            throw ValidationException::withMessages([
                'quotation_date' => ['Quotation date is required when moving from Pending to Running.'],
            ]);
        }

        if ($nextStatus === 'follow_up' && empty($validated['follow_up_date'])) {
            throw ValidationException::withMessages([
                'follow_up_date' => ['Follow-up date is required when setting Follow Up status.'],
            ]);
        }

        if ($nextStatus === 'finished' && empty($validated['finished_note'])) {
            throw ValidationException::withMessages([
                'finished_note' => ['Finished note is required before closing this query item.'],
            ]);
        }

        if (in_array($nextStatus, ['reviewed_with_call', 'reviewed_without_call'], true) && empty($validated['review_note'])) {
            throw ValidationException::withMessages([
                'review_note' => ['Review note is required for reviewed status.'],
            ]);
        }

        $followUpMaxLimit = (int) config('query.followup_max_limit', 3);
        if ($nextStatus === 'follow_up' && $queryItem->workflow_status !== 'follow_up') {
            $nextCount = (int) $queryItem->follow_up_count + 1;
            if ($nextCount > $followUpMaxLimit) {
                throw ValidationException::withMessages([
                    'follow_up_date' => ["Follow-up limit exceeded. Max allowed follow-up count is {$followUpMaxLimit}."],
                ]);
            }
        }

        $oldValues = [
            'workflow_status' => $queryItem->workflow_status,
            'quotation_date' => optional($queryItem->quotation_date)->toDateString(),
            'follow_up_date' => optional($queryItem->follow_up_date)->toDateString(),
            'follow_up_count' => $queryItem->follow_up_count,
            'finished_note' => $queryItem->finished_note,
            'review_status' => $queryItem->review_status,
            'review_note' => $queryItem->review_note,
            'reviewed_by_user_id' => $queryItem->reviewed_by_user_id,
        ];

        $updatePayload = [
            'workflow_status' => $nextStatus,
        ];

        if ($nextStatus === 'running') {
            $updatePayload['quotation_date'] = $validated['quotation_date'];
        }

        if ($nextStatus === 'follow_up') {
            $updatePayload['follow_up_date'] = $validated['follow_up_date'];
            $updatePayload['follow_up_count'] = $queryItem->workflow_status === 'follow_up'
                ? $queryItem->follow_up_count
                : ((int) $queryItem->follow_up_count + 1);
        }

        if ($nextStatus === 'finished') {
            $updatePayload['finished_note'] = $validated['finished_note'];
            $updatePayload['item_status'] = 'closed';
        }

        if ($nextStatus === 'sold') {
            $updatePayload['item_status'] = 'closed';
        }

        if (in_array($nextStatus, ['reviewed_with_call', 'reviewed_without_call'], true)) {
            $updatePayload['review_status'] = $nextStatus;
            $updatePayload['review_note'] = $validated['review_note'];
            $updatePayload['reviewed_by_user_id'] = $actor->id;
            $updatePayload['reviewed_at'] = now();
        }

        $queryItem->update($updatePayload);
        $this->syncQueryStatusFromItems($queryItem->query_id);

        AuditLogger::log(
            $actor->id,
            'query_item',
            $queryItem->id,
            'query_item.status.changed',
            $oldValues,
            [
                'workflow_status' => $queryItem->workflow_status,
                'quotation_date' => optional($queryItem->quotation_date)->toDateString(),
                'follow_up_date' => optional($queryItem->follow_up_date)->toDateString(),
                'follow_up_count' => $queryItem->follow_up_count,
                'finished_note' => $queryItem->finished_note,
                'review_status' => $queryItem->review_status,
                'review_note' => $queryItem->review_note,
                'reviewed_by_user_id' => $queryItem->reviewed_by_user_id,
            ],
            [
                'query_id' => $queryItem->query_id,
            ],
        );

        return response()->json([
            'message' => 'Query item status updated successfully.',
            'data' => $queryItem->fresh(),
        ]);
    }

    private function canManageQueueItem(QueryItem $queryItem, User $actor, string $action): bool
    {
        if (! in_array($action, ['view', 'self', 'distribute'], true)) {
            return false;
        }

        $permissionAllowed = match ($action) {
            'view' => $actor->can('query_view_team_queue') || $actor->can('query.view'),
            'self' => $actor->can('query_item_assign_self_from_team_queue') || $actor->can('query.assign'),
            'distribute' => $actor->can('query_item_assign_team_member') || $actor->can('query.assign'),
        };

        if (! $permissionAllowed) {
            return false;
        }

        $ownerQueueExists = ServiceQueue::query()
            ->where('service_id', $queryItem->service_id)
            ->where('team_id', $queryItem->team_id)
            ->where('is_active', true)
            ->where('queue_owner_user_id', $actor->id)
            ->exists();

        if ($ownerQueueExists) {
            return true;
        }

        $teamRoleAllowed = TeamRoleAssignment::query()
            ->where('team_id', $queryItem->team_id)
            ->where('user_id', $actor->id)
            ->whereIn('team_role', ['head', 'delegate_head'])
            ->where('is_active', true)
            ->exists();

        if ($teamRoleAllowed) {
            return true;
        }

        $authorization = ServiceQueueAuthorization::query()
            ->where('service_id', $queryItem->service_id)
            ->where('team_id', $queryItem->team_id)
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (! $authorization) {
            return false;
        }

        return match ($action) {
            'view' => (bool) $authorization->can_view_queue,
            'self' => (bool) $authorization->can_assign_to_self,
            'distribute' => (bool) $authorization->can_distribute,
            default => false,
        };
    }

    private function resolveActorMode(QueryItem $queryItem, User $actor): string
    {
        $isOwner = ServiceQueue::query()
            ->where('service_id', $queryItem->service_id)
            ->where('team_id', $queryItem->team_id)
            ->where('is_active', true)
            ->where('queue_owner_user_id', $actor->id)
            ->exists();

        if ($isOwner) {
            return 'owner';
        }

        $isTeamHead = TeamRoleAssignment::query()
            ->where('team_id', $queryItem->team_id)
            ->where('user_id', $actor->id)
            ->where('team_role', 'head')
            ->where('is_active', true)
            ->exists();

        if ($isTeamHead) {
            return 'team_head';
        }

        return 'delegate';
    }

    private function buildTeamQueueBaseQuery(User $actor, int $serviceId, ?int $effectiveTeamId): Builder
    {
        $query = QueryItem::query()->where('item_status', 'active');

        if ($effectiveTeamId) {
            $query->where('team_id', $effectiveTeamId);
        }

        if ($serviceId > 0) {
            $query->where('service_id', $serviceId);
        }

        $isSuperUser = $actor->hasAnyRole(['Super Admin', 'Admin']);
        if ($isSuperUser) {
            return $query;
        }

        $canView = $actor->can('query_view_team_queue') || $actor->can('query.view');
        if (! $canView) {
            throw ValidationException::withMessages([
                'authorization' => ['You are not authorized to view team queue items.'],
            ]);
        }

        $query->where(function (Builder $builder) use ($actor): void {
            $builder
                ->whereExists(function ($subQuery) use ($actor): void {
                    $subQuery->selectRaw('1')
                        ->from('service_queues')
                        ->whereColumn('service_queues.service_id', 'query_items.service_id')
                        ->whereColumn('service_queues.team_id', 'query_items.team_id')
                        ->where('service_queues.is_active', true)
                        ->where('service_queues.queue_owner_user_id', $actor->id);
                })
                ->orWhereExists(function ($subQuery) use ($actor): void {
                    $subQuery->selectRaw('1')
                        ->from('service_queue_authorizations')
                        ->whereColumn('service_queue_authorizations.service_id', 'query_items.service_id')
                        ->whereColumn('service_queue_authorizations.team_id', 'query_items.team_id')
                        ->where('service_queue_authorizations.user_id', $actor->id)
                        ->where('service_queue_authorizations.is_active', true)
                        ->where('service_queue_authorizations.can_view_queue', true);
                })
                ->orWhereExists(function ($subQuery) use ($actor): void {
                    $subQuery->selectRaw('1')
                        ->from('team_role_assignments')
                        ->whereColumn('team_role_assignments.team_id', 'query_items.team_id')
                        ->where('team_role_assignments.user_id', $actor->id)
                        ->whereIn('team_role_assignments.team_role', ['head', 'delegate_head'])
                        ->where('team_role_assignments.is_active', true);
                });
        });

        return $query;
    }

    private function buildSelfQueueBaseQuery(User $actor, int $serviceId): Builder
    {
        if (! ($actor->can('query.view') || $actor->can('query.create') || $actor->can('query.change_status'))) {
            throw ValidationException::withMessages([
                'authorization' => ['You are not authorized to view self query sheet.'],
            ]);
        }

        $query = QueryItem::query()
            ->where('assigned_user_id', $actor->id)
            ->whereHas('queryRecord')
            ->whereHas('queryRecord.customer');

        if ($serviceId > 0) {
            $query->where('service_id', $serviceId);
        }

        return $query;
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

    private function syncQueryStatusFromItems(int $queryId): void
    {
        $items = QueryItem::query()->where('query_id', $queryId)->get(['workflow_status']);
        if ($items->isEmpty()) {
            return;
        }

        $statuses = $items->pluck('workflow_status')->all();
        $nextStatus = 'pending';

        if (in_array('running', $statuses, true)) {
            $nextStatus = 'running';
        } elseif (in_array('follow_up', $statuses, true)) {
            $nextStatus = 'follow_up';
        } elseif (in_array('pending', $statuses, true)) {
            $nextStatus = 'pending';
        } elseif (in_array('sold', $statuses, true)) {
            $nextStatus = 'sold';
        } else {
            $nextStatus = 'finished';
        }

        Query::query()->where('id', $queryId)->update(['query_status' => $nextStatus]);
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
            ? 'referrer_only'
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
