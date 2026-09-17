<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (blank($email) || blank($password)) {
            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => config('admin.name', 'Administradora'),
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );
    }
}
