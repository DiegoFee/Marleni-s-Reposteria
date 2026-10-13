<?php

namespace App\Livewire\Forms;

use App\Models\Customer;
use App\Services\CustomerContactData;
use Livewire\Form;

class CustomerContactForm extends Form
{
    public string $fullName = '';

    public string $phone = '';

    public ?Customer $customer = null;

    public function setCustomer(?Customer $customer): void
    {
        $this->customer = $customer;
        $this->fullName = $customer?->full_name ?? '';
        $this->phone = $customer?->phoneFormatted() ?? '';
        $this->resetValidation();
    }

    /**
     * @return array{full_name: string, phone: string}
     */
    public function validatedAttributes(?Customer $customer = null): array
    {
        $this->customer = $customer;
        $this->fullName = CustomerContactData::normalizeName($this->fullName);
        $this->phone = CustomerContactData::normalizePhone($this->phone);

        $validated = $this->validate();

        return [
            'full_name' => $validated['fullName'],
            'phone' => $validated['phone'],
        ];
    }

    public function save(?Customer $customer = null): Customer
    {
        $attributes = $this->validatedAttributes($customer);

        if ($customer === null) {
            return Customer::query()->create($attributes);
        }

        $customer->update($attributes);

        return $customer->refresh();
    }

    /**
     * @return array{fullName: array<int, mixed>, phone: array<int, mixed>}
     */
    protected function rules(): array
    {
        return CustomerContactData::rules($this->customer);
    }
}
