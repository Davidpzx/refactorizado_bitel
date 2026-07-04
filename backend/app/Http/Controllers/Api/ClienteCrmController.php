<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Cliente Activo" del cuadre: CRM ligero por DNI usado desde Nuevo Cuadre.
 * Puerto de api/buscar_cliente_crm.php y api/guardar_cliente_crm.php del legacy.
 */
class ClienteCrmController extends Controller
{
    private const OPERADORAS = ['Bitel', 'Claro', 'Movistar', 'Entel', 'Otra'];

    /** GET /v1/clientes-crm/{dni} — botón "Recuperar Cliente". */
    public function buscar(string $dni): JsonResponse
    {
        if (! preg_match('/^\d{8}$/', $dni)) {
            return response()->json(['ok' => false, 'error' => 'El DNI debe tener exactamente 8 dígitos numéricos.'], 422);
        }

        $cliente = DB::table('crm_clientes')->where('dni', $dni)
            ->first(['nombres', 'apellidos', 'telefono', 'operadora']);

        if (! $cliente) {
            return response()->json(['ok' => false, 'error' => 'No hay un registro previo para este DNI en el CRM.'], 404);
        }

        return response()->json([
            'ok'        => true,
            'nombres'   => $cliente->nombres,
            'apellidos' => $cliente->apellidos,
            'telefono'  => $cliente->telefono,
            'operadora' => $cliente->operadora ?: 'Bitel',
        ]);
    }

    /**
     * POST /v1/clientes-crm — upsert atómico de cliente + interacción.
     * Regla de negocio: si la operación es portabilidad (MNP), el cliente
     * PASA a Bitel y la operadora seleccionada se registra como origen.
     */
    public function guardar(Request $request): JsonResponse
    {
        $request->validate([
            'dni'              => 'required|digits:8',
            'nombres'          => 'required|string|max:150',
            'apellidos'        => 'required|string|max:150',
            'telefono'         => 'nullable|string|max:20',
            'operacion'        => 'required|string|max:50',
            'producto_interes' => 'nullable|string|max:100',
            'motivo_rechazo'   => 'nullable|string|max:100',
            'operadora'        => 'nullable|string|max:20',
            'fuente'           => 'nullable|in:RENIEC_API,MANUAL_CON_FALLBACK',
        ]);

        // Normalizar teléfono: solo dígitos; quitar prefijo país +51
        $telefono = preg_replace('/\D+/', '', (string) $request->input('telefono', ''));
        if (strlen($telefono) > 9 && str_starts_with($telefono, '51')) {
            $telefono = substr($telefono, -9);
        }
        $telefono = substr($telefono, 0, 15);

        $operadora = in_array($request->input('operadora'), self::OPERADORAS, true)
            ? $request->input('operadora') : 'Otra';
        $operacion = trim((string) $request->input('operacion'));

        $esPortabilidad  = stripos($operacion, 'portabilidad') !== false || stripos($operacion, 'MNP') !== false;
        $operadoraFinal  = $esPortabilidad ? 'Bitel' : $operadora;
        $operadoraOrigen = $esPortabilidad ? $operadora : null;

        $observacion = ($esPortabilidad && $operadoraOrigen && $operadoraOrigen !== 'Bitel')
            ? "Portabilidad desde: {$operadoraOrigen}" : null;

        $user = $request->user();

        try {
            $clienteId = DB::transaction(function () use ($request, $telefono, $operadoraFinal, $operacion, $observacion, $user) {
                // ON DUPLICATE KEY + LAST_INSERT_ID(id) evita la carrera contra el índice único de dni.
                // Solo se actualizan telefono/operadora (no se pisan nombres/apellidos/fuente_nombre:
                // la fuente queda fijada por el primer registro, igual que el nombre que describe).
                DB::statement('
                    INSERT INTO crm_clientes (dni, nombres, apellidos, fuente_nombre, telefono, operadora, fecha_registro)
                    VALUES (?,?,?,?,?,?, NOW())
                    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id),
                        telefono = VALUES(telefono), operadora = VALUES(operadora)
                ', [
                    $request->input('dni'),
                    trim($request->input('nombres')),
                    trim($request->input('apellidos')),
                    $request->input('fuente', 'RENIEC_API'),
                    $telefono,
                    $operadoraFinal,
                ]);
                $clienteId = (int) DB::getPdo()->lastInsertId();

                DB::table('crm_interacciones')->insert([
                    'cliente_id'       => $clienteId,
                    'tienda_codigo'    => (string) ($user->tienda_id ?? ''),
                    'agente_nombre'    => (string) ($request->input('agente') ?: $user->nombre ?? 'Sistema'),
                    'tipo_operacion'   => $operacion,
                    'producto_interes' => $request->input('producto_interes') ?: null,
                    'motivo_rechazo'   => $request->input('motivo_rechazo') ?: null,
                    'observacion'      => $observacion,
                    'fuente_registro'  => $request->input('fuente', 'RENIEC_API'),
                    'fecha_hora'       => now(),
                ]);

                return $clienteId;
            });
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Error de base de datos: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'ok'              => true,
            'cliente_id'      => $clienteId,
            'operadora'       => $operadoraFinal,
            'es_portabilidad' => $esPortabilidad,
        ]);
    }
}
