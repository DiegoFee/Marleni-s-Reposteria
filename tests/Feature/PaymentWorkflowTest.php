<?php

use App\Enums\ActivityEventType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;

test('payments classify deposits partials and settlements while recalculating the balance', function () {
    $user = User::factory()->owner()->create();
    $this->actingAs($user);
    $order = Order::factory()->create(['agreed_price' => '300.00']);
    $paidAt = now()->format('Y-m-d\TH:i');
    $component = Volt::test('orders.show', ['order' => $order]);

    $component
        ->set('paymentAmount', '100.00')
        ->set('paymentPaidAt', $paidAt)
        ->set('paymentNotes', 'Anticipo recibido')
        ->call('savePayment')
        ->assertHasNoErrors();

    $component
        ->set('paymentAmount', '50.00')
        ->call('savePayment')
        ->assertHasNoErrors();

    $component
        ->set('paymentAmount', '150.00')
        ->call('savePayment')
        ->assertHasNoErrors()
        ->assertSee('Total pagado')
        ->assertSee('Q 300.00')
        ->assertSee('Historial de pagos')
        ->assertSee('Anticipo')
        ->assertSee('Abono')
        ->assertSee('Liquidación')
        ->assertSee('Saldo pendiente')
        ->assertSee('Q 0.00');

    expect(Payment::query()->where('order_id', $order->id)->orderBy('id')->pluck('payment_type')->map(fn (PaymentType $paymentType): string => $paymentType->value)->all())->toBe([
        PaymentType::Deposit->value,
        PaymentType::Partial->value,
        PaymentType::Settlement->value,
    ]);
    expect(Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Registered->value)->count())->toBe(3);
    expect(number_format((float) Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Registered->value)->sum('amount'), 2, '.', ''))->toBe('300.00');
    expect(Payment::query()->where('order_id', $order->id)->firstOrFail()->notes)->toBe('Anticipo recibido');
    expect(ActivityLog::query()->where('order_id', $order->id)->where('event_type', ActivityEventType::PaymentRegistered->value)->count())->toBe(3);
});

test('payments that exceed the agreed price are rejected without being persisted', function () {
    $user = User::factory()->owner()->create();
    $this->actingAs($user);
    $order = Order::factory()->create(['agreed_price' => '100.00']);
    $component = Volt::test('orders.show', ['order' => $order]);

    $component
        ->set('paymentAmount', '70.00')
        ->call('savePayment')
        ->assertHasNoErrors();

    $component
        ->set('paymentAmount', '30.01')
        ->call('savePayment')
        ->assertHasErrors('paymentAmount')
        ->assertSee('El pago no puede superar el saldo pendiente.');

    expect(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
    expect(ActivityLog::query()->where('order_id', $order->id)->where('event_type', ActivityEventType::PaymentRegistered->value)->count())->toBe(1);
});

test('payment forms reject amounts above the database precision', function () {
    $user = User::factory()->owner()->create();
    $order = Order::factory()->create(['agreed_price' => '99999999.99']);
    $this->actingAs($user);

    Volt::test('orders.show', ['order' => $order])
        ->set('paymentAmount', '100000000.00')
        ->call('savePayment')
        ->assertHasErrors('paymentAmount')
        ->assertSee('El importe del pago no puede superar Q 99,999,999.99.');

    Volt::test('orders.show', ['order' => $order])
        ->set('paymentAmount', '10.001')
        ->call('savePayment')
        ->assertHasErrors('paymentAmount')
        ->assertSee('El importe del pago debe tener como máximo dos decimales.');

    expect(Payment::query()->where('order_id', $order->id)->count())->toBe(0);
});

test('payment validation displays the localized required amount message', function () {
    $user = User::factory()->owner()->create();
    $order = Order::factory()->for($user, 'createdBy')->create();
    $this->actingAs($user);

    $component = Volt::test('orders.show', ['order' => $order])
        ->set('paymentAmount', '')
        ->call('savePayment')
        ->assertHasErrors('paymentAmount')
        ->assertSee('Indica el importe del pago.');

    expect(substr_count($component->html(true), 'Indica el importe del pago.'))->toBe(1);

    expect(Payment::query()->where('order_id', $order->getKey())->count())->toBe(0);
});

test('payment service rejects amounts with more than two decimal places', function () {
    $user = User::factory()->owner()->create();
    $order = Order::factory()->for($user, 'createdBy')->create(['agreed_price' => '200.00']);

    expect(fn () => app(PaymentService::class)->register($order, [
        'amount' => '10.001',
        'paid_at' => now(),
    ], $user))->toThrow(ValidationException::class, 'El importe del pago debe tener como máximo dos decimales.');

    expect(Payment::query()->where('order_id', $order->getKey())->count())->toBe(0);
    expect(ActivityLog::query()->where('order_id', $order->getKey())->where('event_type', ActivityEventType::PaymentRegistered->value)->exists())->toBeFalse();
});

test('payment voiding requires an in-page reason and recalculates the balance without deleting the payment', function () {
    $user = User::factory()->owner()->create();
    $this->actingAs($user);
    $order = Order::factory()->create(['agreed_price' => '200.00']);
    $component = Volt::test('orders.show', ['order' => $order]);

    $component
        ->set('paymentAmount', '80.00')
        ->call('savePayment')
        ->assertHasNoErrors();

    $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

    $component
        ->call('requestPaymentVoid', $payment->id)
        ->assertSet('showVoidForm', true)
        ->call('voidPayment')
        ->assertHasErrors('voidReason')
        ->assertSee('Indica el motivo de la anulación.');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Registered);

    $component
        ->set('voidReason', 'El cliente solicito corregir el registro')
        ->call('voidPayment')
        ->assertHasNoErrors()
        ->assertSee('Q 200.00');

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::Voided);
    expect($payment->voided_at)->not->toBeNull();
    expect($payment->voided_by)->toBe($user->id);
    expect($payment->void_reason)->toBe('El cliente solicito corregir el registro');
    expect(Payment::query()->whereKey($payment->id)->count())->toBe(1);
    expect(ActivityLog::query()->where('payment_id', $payment->id)->where('event_type', ActivityEventType::PaymentVoided->value)->exists())->toBeTrue();
    expect(number_format((float) Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Registered->value)->sum('amount'), 2, '.', ''))->toBe('0.00');
});

test('agreed price cannot be reduced below active partial payments', function () {
    $user = User::factory()->owner()->create();
    $this->actingAs($user);
    $order = Order::factory()->create(['agreed_price' => '200.00']);

    app(PaymentService::class)->register($order, [
        'amount' => '75.00',
        'paid_at' => now(),
    ], $user);

    Volt::test('orders.show', ['order' => $order->refresh()])
        ->call('startEditing')
        ->set('agreedPrice', '74.99')
        ->call('saveChanges')
        ->assertHasErrors('agreedPrice');

    expect($order->refresh()->agreed_price)->toBe('200.00');
});
