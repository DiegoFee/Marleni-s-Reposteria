<?php

namespace App\Livewire\Forms;

use App\Models\Customer;
use Illuminate\Validation\Rule;
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
        $this->phone = $customer?->phone ?? '';
        $this->resetValidation();
    }

    /**
     * @return array{full_name: string, phone: string}
     */
    public function validatedAttributes(?Customer $customer = null): array
    {
        $this->customer = $customer;
        $this->fullName = trim($this->fullName);
        $this->phone = trim($this->phone);

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
        $fullNameUnique = Rule::unique(Customer::class, 'full_name');
        $phoneUnique = Rule::unique(Customer::class, 'phone');

        if ($this->customer !== null) {
            $fullNameUnique->ignore($this->customer);
            $phoneUnique->ignore($this->customer);
        }

        return [
            'fullName' => ['required', 'string', 'max:100', $fullNameUnique],
            'phone' => ['required', 'digits:8', $phoneUnique],
        ];
    }
}
