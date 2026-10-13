<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('assistant')->after('username');
        });

        $adminUsername = config('admin.username');

        if (filled($adminUsername)) {
            // -----------------------------------------------------------
            // Logica critica: Asigna el rol de propietaria a la cuenta administradora existente para conservar su acceso.
            // -----------------------------------------------------------
            DB::table('users')
                ->where('username', $adminUsername)
                ->update(['role' => 'owner']);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
