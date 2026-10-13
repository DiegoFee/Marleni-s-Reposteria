<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Rules\BcryptPasswordLength;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProvisionAssistant extends Command
{
    protected $signature = 'users:provision-assistant {username?} {name?}';

    protected $description = 'Crea una cuenta Asistente con contraseña ingresada de forma segura';

    public function handle(): int
    {
        $username = trim((string) ($this->argument('username') ?? $this->ask('Nombre de usuario')));
        $name = trim((string) ($this->argument('name') ?? $this->ask('Nombre para mostrar')));
        $email = $username.'@local.invalid';

        $validator = Validator::make([
            'username' => $username,
            'name' => $name,
            'email' => $email,
        ], [
            'username' => ['required', 'string', 'max:60', 'regex:/\A[a-zA-Z0-9._-]+\z/', Rule::unique(User::class, 'username')],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
        ], [
            'username.regex' => 'El nombre de usuario solo puede contener letras ASCII, números, puntos, guiones y guiones bajos.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $password = (string) $this->secret('Contraseña para la cuenta Asistente');
        $passwordConfirmation = (string) $this->secret('Confirma la contraseña');
        $passwordValidator = Validator::make([
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'password' => ['required', 'string', 'max:255', new BcryptPasswordLength, Password::defaults(), 'confirmed'],
        ]);

        if ($passwordValidator->fails()) {
            foreach ($passwordValidator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $assistant = new User;
        $assistant->name = $name;
        $assistant->username = $username;
        $assistant->email = $email;
        $assistant->password = $password;
        $assistant->role = UserRole::Assistant;
        $assistant->save();

        $this->components->info("Cuenta Asistente creada para {$username}.");

        return self::SUCCESS;
    }
}
