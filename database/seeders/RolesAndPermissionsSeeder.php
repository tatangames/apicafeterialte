<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Limpia cache de Spatie
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        /*
        |--------------------------------------------------------------------------
        | Roles
        |--------------------------------------------------------------------------
        */

        // Administrador (tu rol)
        $roleAdmin = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'api',
        ]);

        // Dueño / Editor del sitio
        $roleEditor = Role::firstOrCreate([
            'name' => 'editor',
            'guard_name' => 'api',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Permisos
        |--------------------------------------------------------------------------
        */

        // Admin
        Permission::firstOrCreate([
            'name' => 'admin.sidebar.roles.y.permisos',
            'guard_name' => 'api',
        ], [
            'description' => 'Sidebar Admin sección roles y permisos',
        ])->syncRoles([$roleAdmin]);

        // Editor
        Permission::firstOrCreate([
            'name' => 'editor.sidebar.dashboard',
            'guard_name' => 'api',
        ], [
            'description' => 'Sidebar dashboard para editor',
        ])->syncRoles([$roleEditor]);

        /*
        |--------------------------------------------------------------------------
        | Permisos por módulo (config/permisos.php)
        |--------------------------------------------------------------------------
        */

        foreach (config('permisos.permisos') as $nombre => $descripcion) {
            Permission::updateOrCreate(
                ['name' => $nombre, 'guard_name' => 'api'],
                ['description' => $descripcion]
            );
        }

        // Roles base de la panadería. Solo se agregan permisos: si el administrador
        // ajustó un rol desde el sistema, volver a correr el seeder no se los quita.
        foreach (config('permisos.roles') as $rol => $permisos) {
            Role::firstOrCreate(['name' => $rol, 'guard_name' => 'api'])
                ->givePermissionTo($permisos);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
