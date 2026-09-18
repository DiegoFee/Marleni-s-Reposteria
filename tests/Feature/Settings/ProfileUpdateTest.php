<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/settings/profile')->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();
    $originalUsername = $user->username;
    $originalEmail = $user->email;

    $this->actingAs($user);

    $response = Volt::test('settings.profile')
        ->set('name', 'Test User')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->username)->toEqual($originalUsername);
    expect($user->email)->toEqual($originalEmail);
});

test('profile does not expose email or account deletion controls', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/settings/profile');

    $response
        ->assertSee('Nombre de usuario')
        ->assertDontSee('Correo electrónico')
        ->assertDontSee('Eliminar cuenta');
});

test('profile updates leave the user account intact', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('name', 'Updated User')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('Updated User');
    expect(auth()->check())->toBeTrue();
});
