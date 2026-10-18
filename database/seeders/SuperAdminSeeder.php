<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'fp@squadbcc.com'],
            [
                'name'           => 'SQUAD Admin',
                'password'       => Hash::make('squad2014'),
                'company_id'     => null,
                'is_super_admin' => true,
                'is_active'      => true,
                'job_title_en'   => 'Founder & Super Admin',
                'theme'          => 'dark',
            ]
        );

        $this->command->info('✅ Super Admin created: fp@squadbcc.com / squad2014');
    }
}