<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Store Owner',
            'email' => 'owner@wearmemaanyag.com',
            'password' => 'password',
            'phone' => '09123456789',
            'address' => 'Wear Me Maanyag Thrift Store',
            'role' => 'store_owner',
            'profile_image' => 'default-profile.png',
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Cashier',
            'email' => 'cashier@wearmemaanyag.com',
            'password' => 'password',
            'phone' => '09987654321',
            'address' => 'Wear Me Maanyag Thrift Store',
            'role' => 'cashier',
            'profile_image' => 'default-profile.png',
            'status' => 'active',
        ]);
    }
}