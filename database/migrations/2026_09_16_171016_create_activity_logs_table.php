<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->enum('event_type', [
                'order_created',
                'order_updated',
                'order_status_changed',
                'payment_registered',
                'payment_voided',
                'notification_sent',
                'notification_failed',
            ]);
            $table->enum('notification_channel', ['whatsapp', 'telegram'])->nullable();
            $table->enum('reminder_window', ['48_hours', '24_hours'])->nullable();
            $table->string('notification_key', 80)->nullable()->unique();
            $table->string('provider_message_id', 191)->nullable();
            $table->json('details');
            $table->dateTime('created_at');
            $table->index(['order_id', 'event_type', 'created_at']);
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
