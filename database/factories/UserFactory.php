<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (User $user): void {
            $user->role = UserRole::Assistant;
        });
    }

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->numerify('usuario####'),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(): static
    {
        return $this->afterMaking(function (User $user): void {
            $user->role = UserRole::Owner;
        });
    }

    public function assistant(): static
    {
        return $this->afterMaking(function (User $user): void {
            $user->role = UserRole::Assistant;
        });
    }
}
