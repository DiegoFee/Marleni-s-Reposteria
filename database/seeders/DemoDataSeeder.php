<?php

namespace Database\Seeders;

use App\Enums\CaptureMode;
use App\Models\BasePrice;
use App\Models\CakeCategory;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    public function __construct(
        private OrderService $orderService,
        private PaymentService $paymentService,
    ) {}

    public function run(): void
    {
        $admin = User::query()->oldest('id')->first();

        if ($admin === null) {
            throw new RuntimeException('Crea primero la administradora con ADMIN_EMAIL y ADMIN_PASSWORD.');
        }

        $customer = Customer::query()->firstOrCreate(
            ['phone' => '5550000909'],
            ['full_name' => 'Cliente de Demostracion Fase 9'],
        );

        if (Order::query()->where('customer_id', $customer->getKey())->exists()) {
            $this->command?->warn('Los datos de demostracion ya existen para este cliente.');

            return;
        }

        $category = CakeCategory::query()->where('is_active', true)->oldest('id')->first();
        $basePrice = BasePrice::query()->where('is_active', true)->oldest('id')->first();

        if ($category === null || $basePrice === null) {
            throw new RuntimeException('Ejecuta primero el seeder de catalogos.');
        }

        $order = $this->orderService->create([
            'customer_id' => $customer->getKey(),
            'capture_mode' => CaptureMode::Standard,
            'cake_category_id' => $category->getKey(),
            'base_price_id' => $basePrice->getKey(),
            'agreed_price' => '225.00',
            'delivery_at' => now()->addHours(12),
            'deposit_amount' => '75.00',
        ], $admin);

        $this->paymentService->register($order, [
            'amount' => '50.00',
            'paid_at' => now(),
            'notes' => 'Abono de demostracion',
        ], $admin);

        $this->command?->info("Datos de demostracion creados: {$order->order_number}");
    }
}
