<?php

namespace Database\Seeders;

use App\Models\CustomerCategory;
use App\Models\CustomerSource;
use App\Models\OfficialEmail;
use App\Models\OfficialWhatsappNumber;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DictionarySeeder extends Seeder
{
    public function run(): void
    {
        $teams = [];
        foreach (['Sales', 'Marketing', 'Operations'] as $teamName) {
            $teams[$teamName] = Team::query()->firstOrCreate(['team_name' => $teamName], ['is_active' => true]);
        }

        foreach (['B2C', 'B2B', 'Corporate'] as $category) {
            CustomerCategory::query()->firstOrCreate(['category_name' => $category], ['is_active' => true]);
        }

        $sources = [
            'Mobile Call',
            'WhatsApp Call/Message',
            'Email',
            'Walking',
            'Referred by Colleague',
            'Referred by Customer',
            'Google',
            'Web Chat',
            'Facebook',
            'YouTube',
            'Missed Call - IP',
            'Others',
        ];

        foreach ($sources as $source) {
            CustomerSource::query()->firstOrCreate(['source_name' => $source], ['is_active' => true]);
        }

        foreach (['+8801700000001', '+8801700000002'] as $index => $wa) {
            OfficialWhatsappNumber::query()->firstOrCreate(['wa_number' => $wa], ['label' => 'Hotline-'.($index + 1), 'is_active' => true]);
        }

        foreach (['info@obokash.com', 'sales@obokash.com'] as $index => $email) {
            OfficialEmail::query()->firstOrCreate(['email_address' => $email], ['label' => 'Inbox-'.($index + 1), 'is_active' => true]);
        }

        $services = [
            'Umrah Package' => 'Operations',
            'Hajj Package' => 'Operations',
            'Tour Package' => 'Operations',
            'Visa' => 'Sales',
            'Hotel' => 'Operations',
            'Air Ticket' => 'Sales',
            'Guide' => 'Operations',
            'Transport' => 'Operations',
            'Others' => 'Marketing',
        ];

        $queueOwnerId = User::query()->where('email', 'admin@obokash.com')->value('id');

        foreach ($services as $serviceName => $teamName) {
            $service = Service::query()->firstOrCreate(['service_name' => $serviceName], ['is_active' => true]);

            ServiceQueue::query()->firstOrCreate([
                'service_id' => $service->id,
                'team_id' => $teams[$teamName]->id,
            ], [
                'queue_owner_user_id' => $queueOwnerId,
                'is_active' => true,
            ]);
        }
    }
}
