<?php

namespace App\Console\Commands;

use App\Enums\ActivityEventType;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RepairArchivedPendingOrders extends Command
{
    protected $signature = 'orders:repair-archived-pending {--apply : Aplica la corrección después de revisar la simulación}';

    protected $description = 'Corrige pedidos archivados que conservan el estado Pendiente';

    public function handle(): int
    {
        $archivedPendingOrders = $this->archivedPendingOrders();
        $candidateCount = (clone $archivedPendingOrders)->count();

        if (! $this->option('apply')) {
            if ($candidateCount === 0) {
                $this->components->info('Simulación: no hay pedidos archivados pendientes para corregir. No se modificaron datos.');

                return self::SUCCESS;
            }

            $this->components->warn("Simulación: {$candidateCount} pedido(s) pasarían de Pendiente a Cancelado. No se modificaron datos.");

            $archivedPendingOrders
                ->select(['id', 'order_number', 'deleted_at'])
                ->chunkById(100, function (Collection $orders): void {
                    foreach ($orders as $order) {
                        $archivedAt = $order->deleted_at?->format('d/m/Y H:i') ?? '—';
                        $this->line("{$order->order_number} · archivado el {$archivedAt}");
                    }
                });

            return self::SUCCESS;
        }

        if ($candidateCount === 0) {
            $this->components->info('No hay pedidos archivados pendientes para corregir.');

            return self::SUCCESS;
        }

        $repairedCount = 0;

        $archivedPendingOrders
            ->select('id')
            ->chunkById(100, function (Collection $orders) use (&$repairedCount): void {
                foreach ($orders as $order) {
                    if ($this->repairArchivedPendingOrder((int) $order->getKey())) {
                        $repairedCount++;
                    }
                }
            });

        $this->components->info("Pedidos regularizados: {$repairedCount}.");

        return self::SUCCESS;
    }

    private function archivedPendingOrders(): Builder
    {
        return Order::withTrashed()
            ->whereNotNull('deleted_at')
            ->where('status', OrderStatus::Pending->value);
    }

    private function repairArchivedPendingOrder(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $order = Order::withTrashed()
                ->whereKey($orderId)
                ->lockForUpdate()
                ->first();

            if (
                $order === null
                || ! $order->trashed()
                || $order->status !== OrderStatus::Pending
            ) {
                return false;
            }

            $deletedAt = $order->deleted_at?->toIso8601String();
            $repairedAt = now();
            $order->status = OrderStatus::Cancelled;
            $order->save();

            $order->activityLogs()->create([
                'actor_user_id' => null,
                'event_type' => ActivityEventType::OrderStatusChanged,
                'details' => [
                    'from' => OrderStatus::Pending->value,
                    'to' => OrderStatus::Cancelled->value,
                    'reason' => 'archived_pending_repair',
                    'archived_at' => $deletedAt,
                ],
                'created_at' => $repairedAt,
            ]);

            return true;
        }, attempts: 3);
    }
}
