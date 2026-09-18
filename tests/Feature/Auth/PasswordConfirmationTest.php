<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/confirm-password');

    $response->assertStatus(200);
});

test('password can be confirmed', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Volt::test('auth.confirm-password')
        ->set('password', 'password')
        ->call('confirmPassword');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));
});

test('password is not confirmed with invalid password', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Volt::test('auth.confirm-password')
        ->set('password', 'wrong-password')
        ->call('confirmPassword');

    $response->assertHasErrors(['password']);
});

test('password confirmation is rate limited after repeated failures', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        Volt::test('auth.confirm-password')
            ->set('password', 'wrong-password')
            ->call('confirmPassword')
            ->assertHasErrors(['password']);
    }

    $response = Volt::test('auth.confirm-password')
        ->set('password', 'password')
        ->call('confirmPassword');

    $response->assertHasErrors(['password']);
    expect(RateLimiter::tooManyAttempts(
        mb_strtolower($user->username).'|'.request()->ip(),
        5,
    ))->toBeTrue();
});
