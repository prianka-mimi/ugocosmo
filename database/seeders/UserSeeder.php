<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Alice Johnson',   'username' => 'alice',   'email' => 'alice@example.com'],
            ['name' => 'Bob Smith',       'username' => 'bob',     'email' => 'bob@example.com'],
            ['name' => 'Carol Williams', 'username' => 'carol',   'email' => 'carol@example.com'],
            ['name' => 'David Brown',    'username' => 'david',   'email' => 'david@example.com'],
            ['name' => 'Eva Martinez',   'username' => 'eva',     'email' => 'eva@example.com'],
            ['name' => 'Frank Lee',      'username' => 'frank',   'email' => 'frank@example.com'],
            ['name' => 'Grace Kim',      'username' => 'grace',   'email' => 'grace@example.com'],
            ['name' => 'Henry Wilson',   'username' => 'henry',   'email' => 'henry@example.com'],
            ['name' => 'Isla Turner',    'username' => 'isla',    'email' => 'isla@example.com'],
            ['name' => 'Jack Davis',     'username' => 'jack',    'email' => 'jack@example.com'],
        ];

        foreach ($users as $data) {
            User::firstOrCreate(['email' => $data['email']], array_merge($data, [
                'password' => Hash::make('12345678'),
                'role' => 'user',
                'status' => 'active',
            ]));
        }
    }
}
