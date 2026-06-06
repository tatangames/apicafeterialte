<?php

namespace Database\Seeders;

use App\Models\Administrador;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Administrador::create([
            'nombre' => 'Administrador',
            'email' => 't@gmail.com',
            'password' => Hash::make('1234'),
            'estado' => true,
        ])->assignRole('admin');

        Administrador::create([
            'nombre' => 'Editor',
            'email' => 'e@gmail.com',
            'password' => Hash::make('1234'),
            'estado' => true,
        ])->assignRole('editor');
    }
}
