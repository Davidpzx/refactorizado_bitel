<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DispositivoController extends Controller
{
    // ── POST /api/v1/autorizar-dispositivo (público) ───────────────────────────
    public function autorizar(Request $request): JsonResponse
    {
        $dni        = trim($request->input('dni', ''));
        $deviceHash = trim($request->input('device_hash', ''));
        $pin        = trim((string)$request->input('pin', ''));

        if (strlen($dni) !== 8 || !ctype_digit($dni)) {
            return response()->json(['status' => 'error', 'mensaje' => 'DNI inválido. Se esperan 8 dígitos.']);
        }
        if (empty($deviceHash)) {
            return response()->json(['status' => 'error', 'mensaje' => 'Huella de dispositivo no enviada.']);
        }
        if (!str_starts_with($deviceHash, 'kyro-hw-')) {
            return response()->json(['status' => 'error', 'mensaje' => 'Formato de huella inválido.']);
        }

        // Extraer DNI embebido al final: kyro-hw-[40chars]-[DNI8]
        $partes     = explode('-', $deviceHash);
        $dniEmbedido = end($partes);

        $agente = DB::table('agentes')
            ->where('dni', $dni)
            ->select('id', 'dni', 'nombres', 'estado', 'hash_dispositivo', 'pin_seguridad')
            ->first();

        if (!$agente) {
            return response()->json(['status' => 'error', 'mensaje' => 'Agente no encontrado.']);
        }
        if ($agente->estado !== 'ACTIVO') {
            return response()->json(['status' => 'error', 'mensaje' => 'Cuenta de agente inactiva o restringida.']);
        }

        // ── Modo A: sin PIN ────────────────────────────────────────────────────
        if (empty($pin)) {
            if ($dniEmbedido !== $agente->dni) {
                try {
                    DB::table('log_fraude_dispositivo')->insert([
                        'fecha_hora'       => now(),
                        'agente_id'        => $agente->id,
                        'nombre_agente'    => $agente->nombres,
                        'dni_ingresado'    => $dni,
                        'dni_duenio_hash'  => $dniEmbedido,
                        'tienda_intento'   => 'API_AUTH',
                    ]);
                } catch (\Throwable) {}
                return response()->json(['status' => 'error', 'mensaje' => 'Este navegador está vinculado al DNI de otro agente. Usa tu propio dispositivo.']);
            }

            $hashDb = trim($agente->hash_dispositivo ?? '');

            if ($hashDb === '') {
                DB::table('agentes')->where('id', $agente->id)->update([
                    'hash_dispositivo'    => $deviceHash,
                    'fecha_registro_disp' => now(),
                ]);
                return response()->json(['status' => 'ok', 'primer_dispositivo' => true, 'mensaje' => 'Dispositivo registrado automáticamente.']);
            }

            if ($hashDb === $deviceHash) {
                return response()->json(['status' => 'ok', 'mensaje' => 'Huella válida.']);
            }

            return response()->json(['status' => 'require_pin', 'mensaje' => 'Este agente ya tiene un dispositivo registrado. Ingresa tu PIN para autorizar el nuevo equipo.']);
        }

        // ── Modo B: con PIN ────────────────────────────────────────────────────
        if ($dniEmbedido !== $agente->dni) {
            return response()->json(['status' => 'error', 'mensaje' => 'Este navegador está vinculado al DNI de otro agente. Usa tu propio dispositivo.']);
        }

        $pinDb = trim((string)($agente->pin_seguridad ?? ''));
        if ($pinDb === '' || $pin !== $pinDb) {
            return response()->json(['status' => 'error', 'mensaje' => 'PIN incorrecto. Ingresa los 4 dígitos de tu PIN o consulta con el administrador.']);
        }

        DB::table('agentes')->where('id', $agente->id)->update([
            'hash_dispositivo'    => $deviceHash,
            'fecha_registro_disp' => now(),
        ]);

        return response()->json(['status' => 'ok', 'mensaje' => 'Dispositivo autorizado y registrado correctamente.']);
    }
}
