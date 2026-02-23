<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\District;
use App\Models\OfficialEmail;
use App\Models\OfficialWhatsappNumber;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MasterDataController extends Controller
{
    public function whatsappAccounts(): JsonResponse
    {
        return response()->json([
            'data' => OfficialWhatsappNumber::query()->orderBy('wa_number')->get(),
        ]);
    }

    public function storeWhatsappAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wa_number' => ['required', 'string', 'max:20', 'unique:official_whatsapp_numbers,wa_number'],
            'label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $row = OfficialWhatsappNumber::query()->create([
            'wa_number' => $validated['wa_number'],
            'label' => $validated['label'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Official WhatsApp added.', 'data' => $row], 201);
    }

    public function updateWhatsappAccount(Request $request, OfficialWhatsappNumber $officialWhatsappNumber): JsonResponse
    {
        $validated = $request->validate([
            'wa_number' => ['sometimes', 'required', 'string', 'max:20', 'unique:official_whatsapp_numbers,wa_number,'.$officialWhatsappNumber->id],
            'label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $officialWhatsappNumber->update($validated);

        return response()->json(['message' => 'Official WhatsApp updated.', 'data' => $officialWhatsappNumber]);
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
