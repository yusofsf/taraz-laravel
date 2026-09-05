<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(['mobile' => '09191546136'], [
            'name' => 'مدیر سامانه', 'email' => 'admin@taraz.local',
            'password' => Hash::make('123456789'), 'is_admin' => true,
            'can_add_users' => true, 'can_add_products' => true, 'can_change_balance' => true,
        ]);
    }
}
