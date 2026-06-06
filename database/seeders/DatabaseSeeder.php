<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // 1️⃣ Roles y permisos
        $this->call(RolesAndPermissionsSeeder::class);

        // 2️⃣ Usuarios (admin, editor, etc.)
        $this->call(AdminSeeder::class);
    }
}
