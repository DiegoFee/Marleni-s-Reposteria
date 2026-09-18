<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = config('admin.username');
        $password = config('admin.password');

        if (blank($username)) {
            throw new RuntimeException('Configura ADMIN_USERNAME antes de ejecutar los seeders.');
        }

        $admin = User::query()->firstOrNew(['username' => $username]);
        $admin->name = config('admin.name', 'Administradora');

        if (! $admin->exists) {
            if (blank($password)) {
                throw new RuntimeException('Configura ADMIN_PASSWORD antes de crear la administradora.');
            }

            $admin->password = Hash::make($password);
        }

        if (blank($admin->email)) {
            $admin->email = $username.'@local.invalid';
        }

        $admin->save();
    }
}
