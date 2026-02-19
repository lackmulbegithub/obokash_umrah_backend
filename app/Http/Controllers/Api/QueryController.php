<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreQueryRequest;
use App\Models\Query;
use App\Models\QueryItem;
use App\Models\ServiceQueue;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QueryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();
        $serviceId = $request->integer('service_id');
        $myQueriesOnly = $request->boolean('my_queries', false);

        $query = Query::query()->with(['customer:id,customer_name,mobile_number', 'items.service:id,service_name']);

        if ($status !== '') {
            $query->where('query_status', $status);
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
        return response()->json([
            'data' => $query->load([
                'customer:id,customer_name,mobile_number,whatsapp_number',
                'items.service:id,service_name',
                'items.assignedUser:id,full_name',
                'items.team:id,team_name',
            ]),
        ]);
    }

    public function teamQueue(Request $request): JsonResponse
    {
        $serviceId = $request->integer('service_id');
        $teamId = $request->integer('team_id');
        $effectiveTeamId = $teamId > 0 ? $teamId : auth()->user()?->team_id;

        $query = QueryItem::query()
            ->with([
                'query.customer:id,customer_name,mobile_number',
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

        $runningQueries = Query::query()
            ->where('customer_id', $validated['customer_id'])
            ->where('query_status', 'active')
            ->get(['id', 'query_details_text', 'created_at']);

        if ($runningQueries->isNotEmpty() && ! ($validated['force_create'] ?? false)) {
            return response()->json([
                'message' => 'Active queries found for this customer.',
                'running_queries' => $runningQueries,
            ], 409);
        }

        $queryModel = DB::transaction(function () use ($validated): Query {
            $assignedType = $validated['assigned_type'];

            $query = Query::query()->create([
                'customer_id' => $validated['customer_id'],
                'created_by_user_id' => auth()->id(),
                'query_details_text' => $validated['query_details_text'],
                'query_status' => 'active',
                'assigned_type' => $assignedType,
                'assigned_user_id' => $assignedType === 'self' ? auth()->id() : null,
                'team_id' => $assignedType === 'team' ? ($validated['team_id'] ?? null) : null,
            ]);

            foreach ($validated['service_ids'] as $serviceId) {
                $teamId = null;
                $assignedUserId = null;

                if ($assignedType === 'self') {
                    $assignedUserId = auth()->id();
                }

                if ($assignedType === 'team') {
                    $mappedQueue = ServiceQueue::query()
                        ->where('service_id', $serviceId)
                        ->where('is_active', true)
                        ->first();

                    $teamId = $validated['team_id'] ?? optional($mappedQueue)->team_id;
                    $assignedUserId = optional($mappedQueue)->queue_owner_user_id;
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

            AuditLogger::log(
                auth()->id(),
                'query',
                $query->id,
                'query.created',
                null,
                $query->only(['customer_id', 'query_status', 'assigned_type', 'assigned_user_id', 'team_id']),
            );

            return $query;
        });

        return response()->json([
            'message' => 'Query created successfully.',
            'data' => $queryModel->load(['items.service:id,service_name']),
        ], 201);
    }

    public function updateStatus(Request $request, Query $query): JsonResponse
    {
        $validated = $request->validate([
            'query_status' => ['required', 'in:active,closed'],
        ]);

        $oldValues = [
            'query_status' => $query->query_status,
        ];

        $query->update([
            'query_status' => $validated['query_status'],
        ]);

        if ($validated['query_status'] === 'closed') {
            $query->items()->update(['item_status' => 'closed']);
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
}
