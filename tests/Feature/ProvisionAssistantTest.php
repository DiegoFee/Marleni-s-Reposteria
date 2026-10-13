<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('assistant provisioning creates a limited account using a hidden confirmed password', function () {
    $password = 'CorrectHorseBattery9!';

    $this->artisan('users:provision-assistant', [
        'username' => 'asistente-nuevo',
        'name' => 'Asistente Nueva',
    ])
        ->expectsQuestion('Contraseña para la cuenta Asistente', $password)
        ->expectsQuestion('Confirma la contraseña', $password)
        ->assertSuccessful();

    $assistant = User::query()->where('username', 'asistente-nuevo')->firstOrFail();

    expect($assistant->role)->toBe(UserRole::Assistant);
    expect(Hash::check($password, $assistant->password))->toBeTrue();
});

test('assistant provisioning rejects a mismatched password without creating a user', function () {
    $this->artisan('users:provision-assistant', [
        'username' => 'asistente-invalida',
        'name' => 'Asistente Inválida',
    ])
        ->expectsQuestion('Contraseña para la cuenta Asistente', 'CorrectHorseBattery9!')
        ->expectsQuestion('Confirma la contraseña', 'AnotherPassword9!')
        ->assertFailed();

    $this->assertDatabaseMissing('users', ['username' => 'asistente-invalida']);
});
