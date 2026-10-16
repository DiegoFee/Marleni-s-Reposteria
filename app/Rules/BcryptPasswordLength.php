<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BcryptPasswordLength implements ValidationRule
{
    /**
     * Funcion: validate()
     *
     * Descripcion: Limita la contrasena a los bytes procesados por bcrypt para evitar que se ignoren caracteres finales.
     *
     * Version: 1.0
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > 72) {
            $fail('La contraseña no puede superar 72 bytes.');
        }
    }
}
