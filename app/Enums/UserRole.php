<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Assistant = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Dueña',
            self::Assistant => 'Asistente',
        };
    }
}
