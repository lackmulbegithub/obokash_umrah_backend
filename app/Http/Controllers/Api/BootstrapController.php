<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerCategory;
use App\Models\CustomerSource;
use App\Models\Country;
use App\Models\District;
use App\Models\OfficialEmail;
use App\Models\OfficialWhatsappNumber;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class BootstrapController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json([
            'teams' => Team::query()->where('is_active', true)->orderBy('team_name')->get(['id', 'team_name']),
            'categories' => CustomerCategory::query()->where('is_active', true)->orderBy('category_name')->get(['id', 'category_name']),
            'customer_sources' => CustomerSource::query()->where('is_active', true)->orderBy('source_name')->get(['id', 'source_name']),
            'visit_records' => config('customer.visit_records', []),
            'countries' => Country::query()->where('is_active', true)->orderBy('country_name')->get(['id', 'country_name', 'iso2', 'iso3']),
            'districts' => District::query()->where('is_active', true)->orderBy('district_name')->get(['id', 'country_id', 'district_name']),
            'official_whatsapp_numbers' => OfficialWhatsappNumber::query()->where('is_active', true)->orderBy('wa_number')->get(['id', 'wa_number', 'label']),
            'official_emails' => OfficialEmail::query()->where('is_active', true)->orderBy('email_address')->get(['id', 'email_address', 'label']),
            'services' => Service::query()->where('is_active', true)->orderBy('service_name')->get(['id', 'service_name']),
            'users' => User::query()->where('is_active', true)->orderBy('full_name')->get(['id', 'full_name', 'team_id']),
        ]);
    }
}
