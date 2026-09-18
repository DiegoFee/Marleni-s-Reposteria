<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_deletion_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 30);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->json('details');
            $table->dateTime('deleted_at');
            $table->index(['order_number', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_deletion_audits');
    }
};
