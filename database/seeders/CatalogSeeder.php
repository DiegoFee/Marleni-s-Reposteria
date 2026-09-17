<?php

namespace Database\Seeders;

use App\Models\BasePrice;
use App\Models\CakeCategory;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Pasteles Fríos', 'Tres Leches', 'Pasteles Secos'] as $name) {
            CakeCategory::query()->firstOrCreate(
                ['name' => $name],
                ['is_active' => true],
            );
        }

        foreach (['85.00', '150.00', '165.00', '175.00', '225.00', '325.00'] as $amount) {
            BasePrice::query()->firstOrCreate(
                ['amount' => $amount],
                ['is_active' => true],
            );
        }
    }
}
