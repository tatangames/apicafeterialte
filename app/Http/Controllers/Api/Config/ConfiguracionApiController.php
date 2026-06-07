<?php

namespace App\Http\Controllers\Api\Config;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\UnidadesMedida;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ConfiguracionApiController extends Controller
{

    public function tablaCategorias(){
        $categorias = Categoria::orderBy('id', 'ASC')->get();

        return response()->json($categorias);
    }


    public function registrarCategoria(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
        ]);

        try {
            Categoria::create([
                'nombre' => $request->nombre,
                'estado' => true,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'creado correctamente',
            ]);

        } catch (\Throwable $e) {
            Log::error('Error al crear categoria: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al crear categoria',
            ], 500);
        }
    }


    public function actualizarCategoria(Request $request, $id)
    {
        $categoria = Categoria::find($id);

        if (!$categoria) {
            return response()->json([
                'success' => 2,
                'message' => 'Categoria no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:100',
            'estado' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'errors' => $validator->errors()
            ], 422);
        }

        try {

            // Datos básicos
            $categoria->nombre = $request->nombre;
            $categoria->estado = $request->estado;
            $categoria->save();

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'Categoria actualizado correctamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error actualizar: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }




    // ================================ UNIDAD DE MEDIDA ==============================


    public function tablaUnidadMedida(){
        $unidadmedida = UnidadesMedida::orderBy('id', 'ASC')->get();

        return response()->json($unidadmedida);
    }

    public function registrarUnidadMedida(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
        ]);

        try {
            UnidadesMedida::create([
                'nombre' => $request->nombre,
                'estado' => true,
            ]);

            return response()->json([
                'success' => 1,
                'message' => 'creado correctamente',
            ]);

        } catch (\Throwable $e) {
            Log::error('Error al crear unidad medida: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error al crear unidad medida',
            ], 500);
        }
    }

    public function actualizarUnidadMedida(Request $request, $id)
    {
        $unidadmedida = UnidadesMedida::find($id);

        if (!$unidadmedida) {
            return response()->json([
                'success' => 2,
                'message' => 'Unidad medida no encontrado'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:100',
            'estado' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => 0,
                'errors' => $validator->errors()
            ], 422);
        }

        try {

            // Datos básicos
            $unidadmedida->nombre = $request->nombre;
            $unidadmedida->estado = $request->estado;
            $unidadmedida->save();

            DB::commit();

            return response()->json([
                'success' => 1,
                'message' => 'Unidad medida actualizado correctamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error actualizar: ' . $e->getMessage());

            return response()->json([
                'success' => 0,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }


}
