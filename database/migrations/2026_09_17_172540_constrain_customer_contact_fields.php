<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $usedNames = [];
        $usedPhones = [];

        DB::transaction(function () use (&$usedNames, &$usedPhones): void {
            DB::table('customers')
                ->select(['id', 'full_name', 'phone'])
                ->orderBy('id')
                ->chunkById(500, function (Collection $customers) use (&$usedNames, &$usedPhones): void {
                    $customers->each(function (object $customer) use (&$usedNames, &$usedPhones): void {
                        $fullName = trim((string) $customer->full_name);
                        $phone = trim((string) $customer->phone);

                        if ($fullName === '' || mb_strlen($fullName) > 100) {
                            throw new RuntimeException('No se puede limitar el nombre de un cliente existente.');
                        }

                        if (! preg_match('/^\d{8}$/', $phone)) {
                            throw new RuntimeException('No se puede limitar el teléfono de un cliente existente.');
                        }

                        $nameKey = Str::of($fullName)->ascii()->lower()->toString();

                        if (isset($usedNames[$nameKey]) || isset($usedPhones[$phone])) {
                            throw new RuntimeException('La migración encontró nombres o teléfonos de clientes duplicados.');
                        }

                        DB::table('customers')
                            ->where('id', $customer->id)
                            ->update([
                                'full_name' => $fullName,
                                'phone' => $phone,
                            ]);

                        $usedNames[$nameKey] = true;
                        $usedPhones[$phone] = true;
                    });
                });
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_full_name_index');
            $table->dropIndex('customers_phone_index');
            $table->string('full_name', 100)->change();
            $table->string('phone', 8)->change();
            $table->unique('full_name');
            $table->unique('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_full_name_unique');
            $table->dropUnique('customers_phone_unique');
            $table->string('full_name', 150)->change();
            $table->string('phone', 25)->change();
            $table->index('full_name');
            $table->index('phone');
        });
    }
};
