<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Volt::test('settings.password')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Volt::test('settings.password')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});

test('password updates reject oversized password values', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('settings.password')
        ->set('current_password', 'password')
        ->set('password', str_repeat('a', 256))
        ->set('password_confirmation', str_repeat('a', 256))
        ->call('updatePassword')
        ->assertHasErrors('password');
});

test('password updates reject passwords over bcrypt byte limit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $password = str_repeat('ñ', 37);

    expect(mb_strlen($password))->toBe(37);
    expect(strlen($password))->toBe(74);

    Volt::test('settings.password')
        ->set('current_password', 'password')
        ->set('password', $password)
        ->set('password_confirmation', $password)
        ->call('updatePassword')
        ->assertHasErrors('password');
});

test('password updates accept passwords at bcrypt byte limit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $password = str_repeat('a', 72);

    Volt::test('settings.password')
        ->set('current_password', 'password')
        ->set('password', $password)
        ->set('password_confirmation', $password)
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check($password, $user->refresh()->password))->toBeTrue();
});
