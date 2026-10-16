<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt as LivewireVolt;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response
        ->assertOk()
        ->assertSee('Nombre de usuario')
        ->assertSee('Contraseña')
        ->assertDontSee('¿Olvidaste tu contraseña?')
        ->assertDontSee('Correo electrónico');
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = LivewireVolt::test('auth.login')
        ->set('username', $user->username)
        ->set('password', 'password')
        ->call('login');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('username matching is case-sensitive', function () {
    $user = User::factory()->create(['username' => 'marleni']);

    $component = LivewireVolt::test('auth.login')
        ->set('username', 'MARLENI')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('username')
        ->assertSee(__('auth.failed'));

    expect(substr_count($component->html(true), __('auth.failed')))->toBe(1);

    $this->assertGuest();
});

test('users can authenticate with the exact mixed-case username', function () {
    $user = User::factory()->create(['username' => 'Marleni']);

    LivewireVolt::test('auth.login')
        ->set('username', 'Marleni')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('password matching remains case-sensitive', function () {
    $user = User::factory()->create([
        'password' => Hash::make('CaseSensitivePassword9!'),
    ]);

    LivewireVolt::test('auth.login')
        ->set('username', $user->username)
        ->set('password', 'casesensitivepassword9!')
        ->call('login')
        ->assertHasErrors('username');

    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    LivewireVolt::test('auth.login')
        ->set('username', $user->username)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('username');

    $this->assertGuest();
});

test('login attempts are limited after repeated invalid credentials', function () {
    $user = User::factory()->create();

    $login = LivewireVolt::test('auth.login')
        ->set('username', $user->username)
        ->set('password', 'wrong-password');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $login->call('login')->assertHasErrors('username');
    }

    $login
        ->call('login')
        ->assertHasErrors('username')
        ->assertSee('Demasiados intentos');

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
