<?php

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Order $order): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Order $order): bool
    {
        return $user->isOwner();
    }

    // -----------------------------------------------------------
    // Logica critica: La asistente solo cambia el estado de pedidos pendientes que no estan archivados.
    // -----------------------------------------------------------
    public function changeStatus(User $user, Order $order): bool
    {
        return in_array($user->role, [UserRole::Owner, UserRole::Assistant], true)
            && ! $order->trashed()
            && $order->status === OrderStatus::Pending;
    }

    public function registerPayment(User $user, Order $order): bool
    {
        return $user->isOwner();
    }

    public function voidPayment(User $user, Order $order): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Order $order): bool
    {
        return $user->isOwner() && ! $order->trashed();
    }

    // -----------------------------------------------------------
    // Logica critica: Solo la duena elimina pedidos archivados o entregados de forma permanente.
    // -----------------------------------------------------------
    public function forceDelete(User $user, Order $order): bool
    {
        return $user->isOwner()
            && ($order->trashed() || $order->status === OrderStatus::Delivered);
    }
}
