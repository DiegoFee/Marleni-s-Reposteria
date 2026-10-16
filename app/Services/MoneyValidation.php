<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

final class MoneyValidation
{
    public const MAX_AMOUNT = '99999999.99';

    /**
     * Funcion: formatInputValue()
     *
     * Descripcion: Formatea importes validos sin alterar valores invalidos ni redondear entradas con mas de dos decimales.
     *
     * Version: 1.0
     */
    public static function formatInputValue(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $amount = trim((string) $value);

        if ($amount === '' || preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)\z/', $amount) !== 1) {
            return $amount;
        }

        $decimal = BigDecimal::of($amount);

        if ($decimal->getScale() > 2) {
            return $amount;
        }

        return $decimal->toScale(2)->toString();
    }

    /**
     * @return array<int, string>
     */
    public static function positiveAmountRules(): array
    {
        return ['required', 'numeric', 'decimal:0,2', 'max:'.self::MAX_AMOUNT, 'gt:0'];
    }

    /**
     * @return array<int, string>
     */
    public static function optionalDepositRules(): array
    {
        return ['nullable', 'numeric', 'decimal:0,2', 'max:'.self::MAX_AMOUNT, 'min:0', 'lte:agreedPrice'];
    }

    public static function requiredPositiveAmount(mixed $value, string $attribute): BigDecimal
    {
        if (blank($value)) {
            self::fail($attribute, 'required');
        }

        $amount = self::parseAmount($value, $attribute);

        if ($amount->isLessThanOrEqualTo(0)) {
            self::fail($attribute, 'gt');
        }

        if ($amount->isGreaterThan(self::MAX_AMOUNT)) {
            self::fail($attribute, 'max');
        }

        return $amount;
    }

    public static function optionalNonNegativeAmount(mixed $value, string $attribute): BigDecimal
    {
        if (blank($value)) {
            return BigDecimal::of('0');
        }

        $amount = self::parseAmount($value, $attribute);

        if ($amount->isNegative()) {
            self::fail($attribute, 'min');
        }

        if ($amount->isGreaterThan(self::MAX_AMOUNT)) {
            self::fail($attribute, 'max');
        }

        return $amount;
    }

    /**
     * Funcion: parseAmount()
     *
     * Descripcion: Convierte el importe a decimal exacto y rechaza mas de dos decimales en lugar de redondear pagos.
     *
     * Version: 1.0
     */
    private static function parseAmount(mixed $value, string $attribute): BigDecimal
    {
        if (! is_numeric($value)) {
            self::fail($attribute, 'numeric');
        }

        $value = trim((string) $value);

        if (! preg_match('/\A[+-]?\d*\.?\d*\z/', $value)) {
            self::fail($attribute, 'decimal');
        }

        $sign = str_starts_with($value, '-') ? '-' : (str_starts_with($value, '+') ? '+' : '');
        $unsignedValue = ltrim($value, '+-');

        if (str_starts_with($unsignedValue, '.')) {
            $unsignedValue = '0'.$unsignedValue;
        }

        if (str_ends_with($unsignedValue, '.')) {
            $unsignedValue = substr($unsignedValue, 0, -1);
        }

        $amount = BigDecimal::of($sign.$unsignedValue);

        if ($amount->getScale() > 2) {
            self::fail($attribute, 'decimal');
        }

        return $amount;
    }

    private static function fail(string $attribute, string $rule): never
    {
        throw ValidationException::withMessages([
            $attribute => trans("validation.custom.{$attribute}.{$rule}"),
        ]);
    }
}
