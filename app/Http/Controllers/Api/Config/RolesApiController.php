<?php

namespace App\Http\Controllers\Api\Config;

use App\Http\Controllers\Controller;
use App\Models\Administrador;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Validator;

class RolesApiController extends Controller
{
    public function listadoRoles()
    {
        $roles = Role::all()->pluck('name', 'id');
        return ['success' => true, 'roles' => $roles];
    }


    public function borrarRolGlobal(Request $request)
    {
        $request->validate([
            'idrol' => 'required|integer'
        ]);

        DB::beginTransaction();

        try {
            $idRol = (int) $request->input('idrol');

            $role = Role::where('id', $idRol)
                ->where('guard_name', 'api')
                ->firstOrFail();

            // Quitar permisos asociados
            $role->syncPermissions([]);

            // Eliminar rol
            $role->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Rol eliminado correctamente'
            ];

        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            Log::warning('Rol no encontrado', ['idrol' => $request->idrol]);

            return [
                'success' => false,
                'message' => 'Rol no encontrado'
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error al borrar rol', [
                'idrol' => $request->idrol,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Error interno al eliminar rol'
            ];
        }
    }





    public function tablaRolesPermisos($id){

        $role = Role::findById($id, 'api');

        $permisos = $role->permissions()
            ->select('id', 'name')
            ->get();

        return response()->json([
            'success' => true,
            'permisos' => $permisos
        ]);
    }


    public function borrarPermiso(Request $request)
    {
        $request->validate([
            'idrol' => 'required|integer',
            'idpermiso' => 'required|integer',
        ]);

        DB::beginTransaction();

        try {
            // Rol (MISMO guard)
            $role = Role::findById($request->idrol, 'api');

            // Permiso (MISMO guard)
            $permission = Permission::findById($request->idpermiso, 'api');

            // Revocar permiso
            $role->revokePermissionTo($permission);

            DB::commit();

            return ['success' => true];
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error al borrar permiso del rol', [
                'idrol' => $request->idrol,
                'idpermiso' => $request->idpermiso,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'No se pudo eliminar el permiso',
            ], 500);
        }
    }



    public function nuevoRol(Request $request)
    {
        $validar = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
        ]);

        if ($validar->fails()) {
            return response()->json(['success' => 0, 'errors' => $validar->errors()], 422);
        }

        // Verificar si existe el rol
        if (Role::where('name', $request->nombre)->exists()) {
            return response()->json(['success' => 1, 'message' => 'El rol ya existe'], 409);
        }

        Role::create([
            'name' => $request->nombre,
            'guard_name' => 'api'
        ]);

        return response()->json(['success' => 2, 'message' => 'Rol creado exitosamente'], 201);
    }


    public function agregarPermiso(Request $request)
    {
        Log::info($request->all());

        $validar = Validator::make($request->all(), [
            'idrol' => 'required|integer|exists:roles,id',
            'idpermiso' => 'required|integer|exists:permissions,id',
        ]);

        if ($validar->fails()) {
            return response()->json([
                'success' => 0,
                'errors' => $validar->errors()
            ], 422);
        }

        try {
            // 🔒 Rol solo guard api
            $role = Role::where('id', $request->idrol)
                ->where('guard_name', 'api')
                ->firstOrFail();

            // 🔒 Permiso existente (NO se crea)
            $permission = Permission::where('id', $request->idpermiso)
                ->where('guard_name', 'api')
                ->firstOrFail();

            // ⚠️ Ya asignado
            if ($role->hasPermissionTo($permission)) {
                return response()->json([
                    'success' => 2,
                    'message' => 'El rol ya tiene este permiso'
                ], 409);
            }

            // ✅ Asignar permiso
            $role->givePermissionTo($permission);

            return response()->json([
                'success' => 1,
                'message' => 'Permiso asignado correctamente',
                'permiso' => [
                    'id' => $permission->id,
                    'name' => $permission->name
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error('Error al asignar permiso', [
                'idrol' => $request->idrol,
                'idpermiso' => $request->idpermiso,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => 0,
                'message' => 'Error al asignar el permiso'
            ], 500);
        }
    }



    public function tablaTodosPermisos(){

        $permisos = Permission::orderBy('name')->get();

        return [
            'success' => 1,
            'permisos' => $permisos
        ];
    }


    public function borrarPermisoGlobal(Request $request)
    {
        $request->validate([
            'idpermiso' => 'required|integer|exists:permissions,id',
        ]);

        try {
            $permiso = Permission::find($request->idpermiso);

            if (!$permiso) {
                return response()->json([
                    'success' => 0,
                    'message' => 'Permiso no encontrado',
                ], 404);
            }

            $permiso->delete();

            return response()->json([
                'success' => 1,
                'message' => 'Permiso eliminado correctamente',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 0,
                'message' => 'Error al eliminar permiso',
            ], 500);
        }
    }


    public function nuevoPermisoExtra(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string|max:200',
        ]);

        try {
            $permisoExistente = Permission::where('name', $request->nombre)
                ->where('guard_name', 'api')
                ->first();

            if ($permisoExistente) {
                return response()->json([
                    'success' => 2,
                    'message' => 'El permiso ya existe',
                    'permiso' => $permisoExistente->name, // 👈 CLAVE
                ], 409);
            }

            Permission::create([
                'name' => $request->nombre,
                'guard_name' => 'api',
                'description' => $request->descripcion ?? null,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'Permiso creado correctamente',
            ]);

        } catch (\Throwable $e) {
            Log::error('Error al crear permiso: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al crear el permiso',
            ], 500);
        }
    }



    public function tablaUsuarios(){
        $usuarios = Administrador::orderBy('id', 'ASC')
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'nombre' => $u->nombre,
                    'correo' => $u->email,
                    'estado' => $u->estado,
                    'rol' => $u->getRoleNames()->first() ?? 'Sin rol',
                ];
            });

        return response()->json($usuarios);
    }

    public function nuevoUsuario(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|unique:administradores,email',
            'password' => 'required|string|min:8',
            'rol' => 'required|string|exists:roles,name',
        ], [
            // Mensajes personalizados
            'nombre.required' => 'El nombre es obligatorio',
            'email.required' => 'El correo es obligatorio',
            'email.email' => 'El correo no es válido',
            'email.unique' => 'Este correo ya está registrado',
            'password.required' => 'La contraseña es obligatoria',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'rol.required' => 'El rol es obligatorio',
            'rol.exists' => 'El rol seleccionado no existe',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $usuario = Administrador::create([
                'nombre' => $request->nombre,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'estado' => true,
            ]);

            // Spatie
            $usuario->assignRole($request->rol);

            return response()->json([
                'success' => true,
                'message' => 'Usuario creado correctamente',
                'data' => $usuario,
            ], 201);

        } catch (\Exception $e) {

            Log::info('Error al crear usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el usuario',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function informacionAdministrador(Request $request){
        $usuario = Administrador::find($request->id);

        if (!$usuario) {
            return response()->json([
                'success' => 2,
                'message' => 'Usuario no encontrado'
            ]);
        }

        // Todos los roles disponibles
        $roles = Role::orderBy('name')->pluck('name', 'id');

        // Rol actual del usuario (Spatie)
        $rolActual = $usuario->getRoleNames()->first(); // string | null

        return response()->json([
            'success' => 1,
            'info' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'email' => $usuario->email,
                'estado' => $usuario->estado,
            ],
            'roles' => $roles,
            'rol_actual' => $rolActual,
        ]);
    }



    public function actualizarAdministrador(Request $request, $id)
    {
        $usuario = Administrador::find($id);

        if (!$usuario) {
            return response()->json([
                'success' => 2,
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:50',
            'email' => 'required|email|max:100|unique:administradores,email,' . $usuario->id,
            'password' => 'nullable|string|min:8',
            'rol' => 'required|integer|exists:roles,id',
            'estado' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'errors' => $validator->errors()
            ], 422);
        }

        // =========================
        // REGLA: Usuario principal no se puede desactivar
        // =========================
        if ($usuario->id == 1 && $request->estado == false) {
            return response()->json([
                'success' => 0,
                'message' => 'El administrador principal no puede ser desactivado'
            ], 422);
        }

        try {

            // Datos básicos
            $usuario->nombre = $request->nombre;
            $usuario->email = $request->email;
            $usuario->estado = $request->estado;

            if ($request->filled('password')) {
                $usuario->password = bcrypt($request->password);
            }

            $usuario->save();

            // Rol
            $role = Role::find($request->rol);

            if (!$role) {
                DB::rollBack();
                return response()->json([
                    'success' => 0,
                    'message' => 'Rol no válido'
                ], 422);
            }

            $usuario->syncRoles([$role->name]);

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'Administrador actualizado correctamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error actualizarAdministrador: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

}
