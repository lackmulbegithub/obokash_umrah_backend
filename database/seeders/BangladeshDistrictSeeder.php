<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\District;
use Illuminate\Database\Seeder;

class BangladeshDistrictSeeder extends Seeder
{
    public function run(): void
    {
        $bangladesh = Country::query()->firstOrCreate(
            ['country_name' => 'Bangladesh'],
            ['iso2' => 'BD', 'iso3' => 'BGD', 'is_active' => true]
        );

        $districts = [
            'Bagerhat', 'Bandarban', 'Barguna', 'Barishal', 'Bhola', 'Bogura', 'Brahmanbaria', 'Chandpur',
            'Chattogram', 'Chuadanga', "Cox's Bazar", 'Cumilla', 'Dhaka', 'Dinajpur', 'Faridpur', 'Feni',
            'Gaibandha', 'Gazipur', 'Gopalganj', 'Habiganj', 'Jamalpur', 'Jashore', 'Jhalokathi', 'Jhenaidah',
            'Joypurhat', 'Khagrachhari', 'Khulna', 'Kishoreganj', 'Kurigram', 'Kushtia', 'Lakshmipur', 'Lalmonirhat',
            'Madaripur', 'Magura', 'Manikganj', 'Meherpur', 'Moulvibazar', 'Munshiganj', 'Mymensingh', 'Naogaon',
            'Narail', 'Narayanganj', 'Narsingdi', 'Natore', 'Netrokona', 'Nilphamari', 'Noakhali', 'Pabna',
            'Panchagarh', 'Patuakhali', 'Pirojpur', 'Rajbari', 'Rajshahi', 'Rangamati', 'Rangpur', 'Satkhira',
            'Shariatpur', 'Sherpur', 'Sirajganj', 'Sunamganj', 'Sylhet', 'Tangail', 'Thakurgaon',
        ];

        foreach ($districts as $districtName) {
            District::query()->updateOrCreate(
                [
                    'country_id' => $bangladesh->id,
                    'district_name' => $districtName,
                ],
                [
                    'is_active' => true,
                ]
            );
        }
    }
}
