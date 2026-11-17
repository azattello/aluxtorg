<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        Warehouse::query()->firstOrCreate(
            ['code' => 'main'],
            ['name' => 'Main warehouse', 'is_active' => true],
        );
    }
}
