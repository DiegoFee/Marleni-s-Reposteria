<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = config('admin.username');
        $password = config('admin.password');

        if (blank($username) || blank($password)) {
            throw new RuntimeException('Configura ADMIN_USERNAME y ADMIN_PASSWORD antes de ejecutar los seeders.');
        }

        $admin = User::query()->firstOrNew(['username' => $username]);
        $admin->fill([
            'name' => config('admin.name', 'Administradora'),
            'password' => Hash::make($password),
        ]);

        if (blank($admin->email)) {
            $admin->email = $username.'@local.invalid';
        }

        $admin->save();

    }
}
