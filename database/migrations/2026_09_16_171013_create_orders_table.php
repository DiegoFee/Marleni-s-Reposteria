<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('order_number', 30)->unique();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('created_by');
            $table->enum('capture_mode', ['standard', 'custom']);
            $table->unsignedTinyInteger('cake_category_id')->nullable();
            $table->unsignedTinyInteger('base_price_id')->nullable();
            $table->string('cake_description', 500)->nullable();
            $table->decimal('agreed_price', 10, 2);
            $table->dateTime('delivery_at');
            $table->enum('status', ['pending', 'delivered', 'cancelled'])->default('pending');
            $table->index('customer_id');
            $table->index(['status', 'delivery_at']);
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cake_category_id')->references('id')->on('cake_categories')->restrictOnDelete();
            $table->foreign('base_price_id')->references('id')->on('base_prices')->restrictOnDelete();
            $table->timestamps();
        });

        if (in_array(Schema::getConnection()->getConfig('driver'), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_agreed_price_positive CHECK (agreed_price > 0)');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_capture_mode_integrity CHECK ((capture_mode = 'standard' AND cake_category_id IS NOT NULL AND base_price_id IS NOT NULL) OR (capture_mode = 'custom' AND cake_category_id IS NULL AND base_price_id IS NULL AND cake_description IS NOT NULL AND CHAR_LENGTH(TRIM(cake_description)) > 0))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
