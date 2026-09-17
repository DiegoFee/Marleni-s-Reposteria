<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('registered_by');
            $table->decimal('amount', 10, 2);
            $table->enum('payment_type', ['deposit', 'partial', 'settlement']);
            $table->dateTime('paid_at');
            $table->enum('status', ['registered', 'voided'])->default('registered');
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->index(['order_id', 'status', 'paid_at']);
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('registered_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('voided_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamps();
        });

        if (in_array(Schema::getConnection()->getConfig('driver'), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_integrity CHECK ((status = 'registered' AND voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (status = 'voided' AND voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL AND CHAR_LENGTH(TRIM(void_reason)) > 0))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
