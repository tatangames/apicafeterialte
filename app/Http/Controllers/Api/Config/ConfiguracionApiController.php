<?php

namespace App\Http\Controllers\Api\Config;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Impresora;
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



    // ================================ IMPRESORA ==============================


    // GET /admin/impresora/index
    public function indexImpresora()
    {
        $impresoras = Impresora::with('categorias')
            ->orderBy('alias')
            ->get();

        return response()->json([
            'data' => $impresoras,
        ]);
    }

    // POST /admin/impresora/store
    public function nuevaImpresora(Request $request)
    {
        $request->validate([
            'alias'          => 'required|string|max:200',
            'ip_impresora'   => 'required|string|max:50',
            'ip_servidor'    => 'required|string|max:50',
            'tipo_impresion' => 'required|in:escpos',
            'cash_drawer'    => 'required|in:ninguno,pin2,pin5',
            'activo'         => 'boolean',
            'categorias'     => 'required|array|min:1',
            'categorias.*'   => 'exists:categorias,id',
        ]);

        $impresora = Impresora::create($request->only([
            'alias', 'ip_impresora', 'ip_servidor',
            'tipo_impresion', 'cash_drawer', 'activo',
        ]));
        $impresora->categorias()->sync($request->categorias);

        return response()->json([
            'message' => 'Impresora registrada correctamente.',
            'data'    => $impresora->load('categorias'),
        ], 201);
    }

    // GET /admin/impresora/{impresora}
    public function showImpresora(Impresora $impresora)
    {
        return response()->json([
            'data' => $impresora->load('categorias'),
        ]);
    }

    // POST /admin/impresora/{impresora}/update
    public function updateImpresora(Request $request, Impresora $impresora)
    {
        $request->validate([
            'alias'          => 'required|string|max:200',
            'ip_impresora'   => 'required|string|max:50',
            'ip_servidor'    => 'required|string|max:50',
            'tipo_impresion' => 'required|in:escpos',
            'cash_drawer'    => 'required|in:ninguno,pin2,pin5',
            'activo'         => 'boolean',
            'categorias'     => 'required|array|min:1',
            'categorias.*'   => 'exists:categorias,id',
        ]);

        $impresora->update($request->only([
            'alias', 'ip_impresora', 'ip_servidor',
            'tipo_impresion', 'cash_drawer', 'activo',
        ]));
        $impresora->categorias()->sync($request->categorias);

        return response()->json([
            'message' => 'Impresora actualizada correctamente.',
            'data'    => $impresora->load('categorias'),
        ]);
    }

    // DELETE /admin/impresora/{impresora}/destroy
    public function borrarImpresora(Impresora $impresora)
    {
        $impresora->delete();

        return response()->json([
            'message' => 'Impresora eliminada correctamente.',
        ]);
    }



    public function testPrint(Impresora $impresora)
    {
        $printerName = 'STAR1';
        $fontName    = 'Consolas';
        $fontSize    = 11;
        $width       = 32;

        $centrar = function (string $texto) use ($width): string {
            $espacios = max(0, intval(($width - mb_strlen($texto)) / 2));
            return str_repeat(' ', $espacios) . $texto . "\r\n";
        };

        $separador = str_repeat('-', $width) . "\r\n";

        $contenido  = $separador;
        $contenido .= $centrar('CAFETERIA EDUARDO');
        $contenido .= $centrar('Tel: 0000-0000');
        $contenido .= $separador;
        $contenido .= $centrar('PRUEBA DE IMPRESION');
        $contenido .= $separador;
        $contenido .= "Fecha    : " . now()->format('d/m/Y') . "\r\n";
        $contenido .= "Hora     : " . now()->format('H:i:s') . "\r\n";
        $contenido .= "Impresora: " . $impresora->alias . "\r\n";
        $contenido .= "IP       : " . $impresora->ip_impresora . "\r\n";
        $contenido .= $separador;
        $contenido .= $centrar('Impresion correcta!');
        $contenido .= $separador;
        $contenido .= "\r\n\r\n\r\n";

        // Guardar ticket en archivo temporal
        $tmpTxt = 'C:\\Windows\\Temp\\ticket_' . time() . '.txt';
        file_put_contents($tmpTxt, "\xEF\xBB\xBF" . $contenido);

        // Script PowerShell
        $ps1 = 'C:\\Windows\\Temp\\print_' . time() . '.ps1';
        $script = <<<PS
        Add-Type -AssemblyName System.Drawing
        \$texto  = Get-Content -LiteralPath '$tmpTxt' -Raw -Encoding UTF8
        \$lineas = \$texto -split "`r`n"
        \$pd = New-Object System.Drawing.Printing.PrintDocument
        \$pd.PrinterSettings.PrinterName = '$printerName'
        \$pd.DocumentName = 'Ticket'
        \$pd.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins(0,0,0,0)
        \$fuente = New-Object System.Drawing.Font('$fontName', $fontSize, [System.Drawing.FontStyle]::Bold)
        \$global:idx = 0
        \$pd.add_PrintPage({
            param(\$sender, \$e)
            \$y  = 0
            \$lh = \$fuente.GetHeight(\$e.Graphics)
            while (\$global:idx -lt \$lineas.Length) {
                \$e.Graphics.DrawString(\$lineas[\$global:idx], \$fuente, [System.Drawing.Brushes]::Black, 0, \$y)
                \$y += \$lh
                \$global:idx++
            }
            \$e.HasMorePages = \$false
        })
        try {
            \$pd.Print()
            Write-Output 'OK'
        } catch {
            Write-Output ("ERROR: " + \$_.Exception.Message)
        }
        PS;

        file_put_contents($ps1, $script);

        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "' . $ps1 . '"';
        exec($cmd, $output, $code);

        @unlink($tmpTxt);
        @unlink($ps1);

        $ok = $code === 0 && in_array('OK', $output, true);

        return response()->json([
            'success' => $ok,
            'message' => $ok ? 'Impreso correctamente' : 'Error al imprimir',
            'output'  => $output,
        ]);
    }






}
