<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CustomerContactData
{
    public static function normalizeName(string $fullName): string
    {
        $trimmedName = trim($fullName);

        return preg_replace('/\s+/u', ' ', $trimmedName) ?? $trimmedName;
    }

    /**
     * Funcion: normalizePhone()
     *
     * Descripcion: Quita separadores solo en telefonos de ocho digitos para conservar los ceros iniciales y dejar que la validacion rechace otros formatos.
     *
     * Version: 1.0
     */
    public static function normalizePhone(string $phone): string
    {
        $trimmedPhone = trim($phone);

        if (preg_match('/\A[0-9]{4}(?:\s*-\s*|\s+)?[0-9]{4}\z/u', $trimmedPhone) !== 1) {
            return $trimmedPhone;
        }

        return preg_replace('/[\s-]+/u', '', $trimmedPhone) ?? $trimmedPhone;
    }

    public static function phoneSearchTerm(string $search): ?string
    {
        $trimmedSearch = trim($search);

        if ($trimmedSearch === '' || preg_match('/\A[0-9][0-9\s-]{0,15}\z/u', $trimmedSearch) !== 1) {
            return null;
        }

        $digits = preg_replace('/[\s-]+/u', '', $trimmedSearch);

        return $digits !== null && strlen($digits) <= 8 ? $digits : null;
    }

    /**
     * Funcion: rules()
     *
     * Descripcion: Valida el nombre y telefono del cliente y excluye el registro actual al comprobar datos unicos.
     *
     * Version: 1.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(
        ?Customer $customer = null,
        string $fullNameField = 'fullName',
        string $phoneField = 'phone',
    ): array {
        $fullNameUnique = Rule::unique(Customer::class, 'full_name');
        $phoneUnique = Rule::unique(Customer::class, 'phone');

        if ($customer !== null) {
            $fullNameUnique->ignore($customer);
            $phoneUnique->ignore($customer);
        }

        return [
            $fullNameField => [
                'required',
                'string',
                'max:100',
                "regex:/\\A\\p{L}[\\p{L}\\p{M}'’.-]*(?:\\s+\\p{L}[\\p{L}\\p{M}'’.-]*)+\\z/u",
                $fullNameUnique,
            ],
            $phoneField => [
                'required',
                'regex:/\A[0-9]{8}\z/',
                $phoneUnique,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{full_name: string, phone: string}
     */
    public static function validatedAttributes(array $attributes): array
    {
        $fullName = self::normalizeName(is_string($attributes['full_name'] ?? null) ? $attributes['full_name'] : '');
        $phone = self::normalizePhone(is_string($attributes['phone'] ?? null) ? $attributes['phone'] : '');

        Validator::make([
            'customerContact' => [
                'fullName' => $fullName,
                'phone' => $phone,
            ],
        ], self::rules(
            fullNameField: 'customerContact.fullName',
            phoneField: 'customerContact.phone',
        ))->validate();

        return [
            'full_name' => $fullName,
            'phone' => $phone,
        ];
    }

    /**
     * Funcion: uniqueConstraintField()
     *
     * Descripcion: Relaciona las restricciones unicas de la base de datos con el campo correspondiente del formulario.
     *
     * Version: 1.0
     */
    public static function uniqueConstraintField(QueryException $exception): ?string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'customers.full_name'),
            str_contains($message, 'customers_full_name_unique') => 'customerContact.fullName',
            str_contains($message, 'customers.phone'),
            str_contains($message, 'customers_phone_unique') => 'customerContact.phone',
            default => null,
        };
    }
}
