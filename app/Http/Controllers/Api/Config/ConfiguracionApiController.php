<?php

namespace App\Http\Controllers\Api\Config;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\UnidadesMedida;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;

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



    private string $printerName = 'STAR1';
    private string $fontName    = 'Consolas';
    private float  $fontSize    = 11;   // sube/baja el tamaño aquí
    private int    $width       = 32;   // columnas aprox. para Consolas 11pt en 80mm

    public function testPrint()
    {
        return $this->imprimir($this->ticketPrueba());
    }

    private function centrar(string $texto): string
    {
        $espacios = max(0, intval(($this->width - mb_strlen($texto)) / 2));
        return str_repeat(' ', $espacios) . $texto . "\r\n";
    }

    private function separador(): string
    {
        return str_repeat('-', $this->width) . "\r\n";
    }

    private function ticketPrueba(): string
    {
        $t  = $this->separador();
        $t .= $this->centrar('CAFETERIA EDUARDO');
        $t .= $this->centrar('Tel: 0000-0000');
        $t .= $this->separador();
        $t .= $this->centrar('PRUEBA DE IMPRESION');
        $t .= $this->separador();
        $t .= "Fecha    : " . now()->format('d/m/Y') . "\r\n";
        $t .= "Hora     : " . now()->format('H:i:s') . "\r\n";
        $t .= "Impresora: " . $this->printerName . "\r\n";
        $t .= $this->separador();
        $t .= $this->centrar('Impresion correcta!');
        $t .= $this->separador();
        $t .= "\r\n\r\n\r\n";
        return $t;
    }

    private function imprimir(string $contenido): \Illuminate\Http\JsonResponse
    {
        // 1) Guardamos el ticket en UTF-8 con BOM (para que PowerShell lea bien los acentos/ñ)
        $tmpTxt = 'C:\\Windows\\Temp\\ticket_' . time() . '.txt';
        file_put_contents($tmpTxt, "\xEF\xBB\xBF" . $contenido);

        // 2) Generamos un script PowerShell que dibuja el texto: negrita, tamaño y SIN margenes
        $ps1 = 'C:\\Windows\\Temp\\print_' . time() . '.ps1';

        $script = <<<PS
Add-Type -AssemblyName System.Drawing

\$texto  = Get-Content -LiteralPath '$tmpTxt' -Raw -Encoding UTF8
\$lineas = \$texto -split "`r`n"

\$pd = New-Object System.Drawing.Printing.PrintDocument
\$pd.PrinterSettings.PrinterName = '{$this->printerName}'
\$pd.DocumentName = 'Ticket'

# Margenes en 0 -> el texto sale pegado al borde, no en el centro
\$pd.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins(0,0,0,0)

\$fuente = New-Object System.Drawing.Font('{$this->fontName}', {$this->fontSize}, [System.Drawing.FontStyle]::Bold)

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

        // 3) Ejecutamos el script
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
