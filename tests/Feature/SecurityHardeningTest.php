<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

test('private application pages require authentication', function () {
    $this->get('/pedidos')->assertRedirect(route('login'));
    $this->get('/clientes')->assertRedirect(route('login'));
    $this->get('/catalogos')->assertRedirect(route('login'));
    $this->get('/historial')->assertRedirect(route('login'));
    $this->get('/settings/profile')->assertRedirect(route('login'));
});

test('authorization failures return a restricted page with the warning', function () {
    config(['app.debug' => false]);

    $this->actingAs(User::factory()->assistant()->create())
        ->get('/pedidos/crear')
        ->assertForbidden()
        ->assertSee('Por favor no vulneres esta aplicación :(', false);
});

test('login rejects oversized passwords before attempting authentication', function () {
    Volt::test('auth.login')
        ->set('username', 'usuario-valido')
        ->set('password', str_repeat('a', 256))
        ->call('login')
        ->assertHasErrors('password');
});

test('admin seeder rejects weak passwords before creating the owner', function () {
    config()->set([
        'admin.username' => 'owner-with-weak-password',
        'admin.password' => 'short-pass',
    ]);

    expect(fn () => (new AdminUserSeeder)->run())
        ->toThrow(ValidationException::class);

    $this->assertDatabaseMissing('users', ['username' => 'owner-with-weak-password']);
});

test('admin seeder rejects passwords over bcrypt byte limit', function () {
    config()->set([
        'admin.username' => 'owner-with-long-password',
        'admin.password' => str_repeat('ñ', 37),
    ]);

    expect(fn () => (new AdminUserSeeder)->run())
        ->toThrow(ValidationException::class);

    $this->assertDatabaseMissing('users', ['username' => 'owner-with-long-password']);
});

test('security headers are present on successful responses', function () {
    $this->get('/login')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertDontSee('Por favor no vulneres esta aplicación :(');
});

test('security headers are present on exception responses', function () {
    $this->get('/missing-route')
        ->assertNotFound()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertDontSee('Por favor no vulneres esta aplicación :(');
});

test('Boost diagnostic routes are not exposed over HTTP', function () {
    config(['app.env' => 'local']);

    $this->post('/_boost/browser-logs', ['logs' => []])->assertNotFound();
});

test('login attempts are rate limited across usernames from one source IP', function () {
    $user = User::factory()->create();

    for ($attempt = 0; $attempt < 15; $attempt++) {
        Volt::test('auth.login')
            ->set('username', 'spray'.$attempt)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('username');
    }

    expect(RateLimiter::tooManyAttempts('login-ip|'.request()->ip(), 15))->toBeTrue();

    Volt::test('auth.login')
        ->set('username', $user->username)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('username');
});
