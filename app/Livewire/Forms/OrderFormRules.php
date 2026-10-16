<?php

namespace App\Livewire\Forms;

use App\Enums\CaptureMode;
use App\Services\MoneyValidation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;

class OrderFormRules
{
    /**
     * Funcion: order()
     *
     * Descripcion: Construye reglas segun la modalidad, el cliente nuevo, la fecha de entrega y el anticipo requerido.
     *
     * Version: 1.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function order(
        string $captureMode,
        bool $newCustomer = false,
        bool $requireFutureDelivery = true,
        bool $includeDeposit = false,
    ): array {
        $isStandard = $captureMode === CaptureMode::Standard->value;
        $deliveryAtRules = ['required', 'date'];

        if ($requireFutureDelivery) {
            $deliveryAtRules[] = 'after_or_equal:now';
        }

        $rules = [
            'customerId' => $newCustomer
                ? ['prohibited']
                : [
                    'required',
                    'integer',
                    Rule::exists('customers', 'id')->where(fn (QueryBuilder $query) => $query->whereNull('deleted_at')),
                ],
            'captureMode' => ['required', Rule::enum(CaptureMode::class)],
            'cakeCategoryId' => $isStandard
                ? ['required', 'integer', Rule::exists('cake_categories', 'id')->where(fn (QueryBuilder $query) => $query->where('is_active', true))]
                : ['nullable', 'prohibited'],
            'basePriceId' => $isStandard
                ? ['required', 'integer', Rule::exists('base_prices', 'id')->where(fn (QueryBuilder $query) => $query->where('is_active', true))]
                : ['nullable', 'prohibited'],
            'cakeDescription' => $isStandard
                ? ['nullable', 'string', 'max:500']
                : ['required', 'string', 'max:500'],
            'agreedPrice' => MoneyValidation::positiveAmountRules(),
            'deliveryAt' => $deliveryAtRules,
        ];

        if ($includeDeposit) {
            $rules['depositAmount'] = MoneyValidation::optionalDepositRules();
        }

        return $rules;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function payment(): array
    {
        return [
            'paymentAmount' => MoneyValidation::positiveAmountRules(),
            'paymentPaidAt' => ['required', 'date'],
            'paymentNotes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function voidPayment(): array
    {
        return [
            'voidReason' => ['required', 'string', 'max:500'],
        ];
    }
}
