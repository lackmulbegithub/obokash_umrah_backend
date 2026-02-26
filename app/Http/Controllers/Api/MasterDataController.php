<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\District;
use App\Models\OfficialEmail;
use App\Models\OfficialWhatsappNumber;
use App\Models\ServiceQueue;
use App\Models\ServiceQueueAuthorization;
use App\Models\TeamRoleAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class MasterDataController extends Controller
{
    public function teamRoleAssignments(Request $request): JsonResponse
    {
        $teamId = $request->integer('team_id');
        $teamRole = trim($request->string('team_role')->toString());

        $query = TeamRoleAssignment::query()
            ->with([
                'team:id,team_name',
                'user:id,full_name,team_id',
                'grantedByUser:id,full_name',
            ])
            ->orderBy('team_id')
            ->orderBy('team_role')
            ->orderBy('user_id');

        if ($teamId > 0) {
            $query->where('team_id', $teamId);
        }

        if ($teamRole !== '') {
            $query->where('team_role', $teamRole);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function upsertTeamRoleAssignment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'team_role' => ['required', 'in:head,delegate_head,member'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->where('id', $validated['user_id'])->where('is_active', true)->first();
        if (! $user) {
            return response()->json(['message' => 'Assigned user must be active.'], 422);
        }

        if ((int) $user->team_id !== (int) $validated['team_id']) {
            return response()->json(['message' => 'Assigned user must belong to selected team.'], 422);
        }

        if ($validated['team_role'] === 'head') {
            TeamRoleAssignment::query()
                ->where('team_id', $validated['team_id'])
                ->where('team_role', 'head')
                ->where('user_id', '!=', $validated['user_id'])
                ->update(['is_active' => false]);
        }

        $row = TeamRoleAssignment::query()->updateOrCreate(
            [
                'team_id' => $validated['team_id'],
                'user_id' => $validated['user_id'],
                'team_role' => $validated['team_role'],
            ],
            [
                'is_active' => $validated['is_active'] ?? true,
                'granted_by_user_id' => auth()->id(),
            ],
        );

        return response()->json(['message' => 'Team role assignment saved.', 'data' => $row->fresh()]);
    }

    public function serviceQueues(): JsonResponse
    {
        $rows = ServiceQueue::query()
            ->with([
                'service:id,service_name',
                'team:id,team_name',
                'queueOwner:id,full_name',
            ])
            ->orderBy('service_id')
            ->orderBy('team_id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function upsertServiceQueue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'queue_owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $queueOwner = null;
        if (! empty($validated['queue_owner_user_id'])) {
            $queueOwner = User::query()
                ->where('id', $validated['queue_owner_user_id'])
                ->where('is_active', true)
                ->first();

            if (! $queueOwner) {
                return response()->json(['message' => 'Queue owner must be an active user.'], 422);
            }

            if ((int) $queueOwner->team_id !== (int) $validated['team_id']) {
                return response()->json(['message' => 'Queue owner must belong to selected team.'], 422);
            }
        }

        $row = ServiceQueue::query()->updateOrCreate(
            [
                'service_id' => $validated['service_id'],
                'team_id' => $validated['team_id'],
            ],
            [
                'queue_owner_user_id' => $validated['queue_owner_user_id'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ],
        );

        return response()->json(['message' => 'Service queue mapping saved.', 'data' => $row->fresh()]);
    }

    public function serviceQueueAuthorizations(Request $request): JsonResponse
    {
        $serviceId = $request->integer('service_id');
        $teamId = $request->integer('team_id');

        $query = ServiceQueueAuthorization::query()
            ->with([
                'service:id,service_name',
                'team:id,team_name',
                'user:id,full_name,team_id',
            ])
            ->orderBy('service_id')
            ->orderBy('team_id')
            ->orderBy('user_id');

        if ($serviceId > 0) {
            $query->where('service_id', $serviceId);
        }

        if ($teamId > 0) {
            $query->where('team_id', $teamId);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function upsertServiceQueueAuthorization(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'can_view_queue' => ['nullable', 'boolean'],
            'can_distribute' => ['nullable', 'boolean'],
            'can_assign_to_self' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->where('id', $validated['user_id'])->where('is_active', true)->first();
        if (! $user) {
            return response()->json(['message' => 'Authorized user must be active.'], 422);
        }

        if ((int) $user->team_id !== (int) $validated['team_id']) {
            return response()->json(['message' => 'Authorized user must belong to selected team.'], 422);
        }

        $mappedQueueExists = ServiceQueue::query()
            ->where('service_id', $validated['service_id'])
            ->where('team_id', $validated['team_id'])
            ->where('is_active', true)
            ->exists();

        if (! $mappedQueueExists) {
            return response()->json(['message' => 'No active queue mapping found for selected service/team.'], 422);
        }

        $row = ServiceQueueAuthorization::query()->updateOrCreate(
            [
                'service_id' => $validated['service_id'],
                'team_id' => $validated['team_id'],
                'user_id' => $validated['user_id'],
            ],
            [
                'can_view_queue' => $validated['can_view_queue'] ?? true,
                'can_distribute' => $validated['can_distribute'] ?? false,
                'can_assign_to_self' => $validated['can_assign_to_self'] ?? true,
                'is_active' => $validated['is_active'] ?? true,
            ],
        );

        return response()->json(['message' => 'Queue authorization saved.', 'data' => $row->fresh()]);
    }

    public function whatsappAccounts(): JsonResponse
    {
        return response()->json([
            'data' => OfficialWhatsappNumber::query()->orderBy('wa_number')->get(),
        ]);
    }

    public function storeWhatsappAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wa_number' => ['required', 'string', 'max:20'],
            'label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $normalizedWa = $this->normalizeMobileForUniqueCheck((string) $validated['wa_number']);
        $this->ensureOfficialWhatsappUnique($normalizedWa, null);

        $row = OfficialWhatsappNumber::query()->create([
            'wa_number' => $normalizedWa,
            'label' => $validated['label'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Official WhatsApp added.', 'data' => $row], 201);
    }

    public function updateWhatsappAccount(Request $request, OfficialWhatsappNumber $officialWhatsappNumber): JsonResponse
    {
        $validated = $request->validate([
            'wa_number' => ['sometimes', 'required', 'string', 'max:20'],
            'label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('wa_number', $validated)) {
            $normalizedWa = $this->normalizeMobileForUniqueCheck((string) $validated['wa_number']);
            $this->ensureOfficialWhatsappUnique($normalizedWa, (int) $officialWhatsappNumber->id);
            $validated['wa_number'] = $normalizedWa;
        }

        $officialWhatsappNumber->update($validated);

        return response()->json(['message' => 'Official WhatsApp updated.', 'data' => $officialWhatsappNumber]);
    }

    private function ensureOfficialWhatsappUnique(string $normalizedWaNumber, ?int $ignoreId): void
    {
        $rows = OfficialWhatsappNumber::query()
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get(['id', 'wa_number']);

        foreach ($rows as $row) {
            if ($this->normalizeMobileForUniqueCheck((string) $row->wa_number) === $normalizedWaNumber) {
                throw ValidationException::withMessages([
                    'wa_number' => ['This WhatsApp number already exists (duplicate in different format).'],
                ]);
            }
        }
    }

    private function normalizeMobileForUniqueCheck(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';
        if ($digits === '') {
            return trim($mobile);
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

    public function emails(): JsonResponse
    {
        return response()->json([
            'data' => OfficialEmail::query()->orderBy('email_address')->get(),
        ]);
    }

    public function storeEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_address' => ['required', 'email', 'max:255', 'unique:official_emails,email_address'],
            'label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $row = OfficialEmail::query()->create([
            'email_address' => $validated['email_address'],
            'label' => $validated['label'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Official email added.', 'data' => $row], 201);
    }

    public function updateEmail(Request $request, OfficialEmail $officialEmail): JsonResponse
    {
        $validated = $request->validate([
            'email_address' => ['sometimes', 'required', 'email', 'max:255', 'unique:official_emails,email_address,'.$officialEmail->id],
            'label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $officialEmail->update($validated);

        return response()->json(['message' => 'Official email updated.', 'data' => $officialEmail]);
    }

    public function staff(): JsonResponse
    {
        return response()->json([
            'data' => User::query()->orderBy('full_name')->get(['id', 'full_name', 'email', 'mobile', 'team_id', 'is_active']),
        ]);
    }

    public function storeStaff(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:20', 'unique:users,mobile'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $user = User::query()->create([
            'full_name' => $validated['full_name'],
            'email' => $validated['email'] ?? null,
            'mobile' => $validated['mobile'] ?? null,
            'team_id' => $validated['team_id'] ?? null,
            'password' => Hash::make($validated['password'] ?? Str::random(12)),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Staff created.', 'data' => $user], 201);
    }

    public function updateStaff(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'mobile' => ['nullable', 'string', 'max:20', 'unique:users,mobile,'.$user->id],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user->update($validated);

        return response()->json(['message' => 'Staff updated.', 'data' => $user]);
    }

    public function districts(): JsonResponse
    {
        return response()->json([
            'data' => District::query()->orderBy('district_name')->get(['id', 'country_id', 'district_name', 'is_active']),
        ]);
    }

    public function storeDistrict(Request $request): JsonResponse
    {
        $countryId = (int) ($request->integer('country_id') ?: (int) Country::query()->where('country_name', 'Bangladesh')->value('id'));

        $validated = $request->validate([
            'district_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('districts', 'district_name')->where(fn ($query) => $query->where('country_id', $countryId)),
            ],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $row = District::query()->create([
            'country_id' => $countryId,
            'district_name' => $validated['district_name'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'District added.', 'data' => $row], 201);
    }

    public function updateDistrict(Request $request, District $district): JsonResponse
    {
        $countryId = (int) ($request->integer('country_id') ?: ($district->country_id ?? Country::query()->where('country_name', 'Bangladesh')->value('id')));

        $validated = $request->validate([
            'district_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('districts', 'district_name')->ignore($district->id)->where(fn ($query) => $query->where('country_id', $countryId)),
            ],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $district->update($validated);

        return response()->json(['message' => 'District updated.', 'data' => $district]);
    }
}

