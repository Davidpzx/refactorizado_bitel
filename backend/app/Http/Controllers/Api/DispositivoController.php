<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispositivoController extends Controller
{
    public function autorizar(Request $request): JsonResponse
    {
        $dni = trim((string) $request->input('dni', ''));
        $deviceHash = trim((string) $request->input('device_hash', ''));
        $pin = trim((string) $request->input('pin', ''));

        if (! preg_match('/^\d{8}$/', $dni)) {
            return response()->json(['status' => 'error', 'mensaje' => 'DNI inválido. Se esperan 8 dígitos.']);
        }
        if ($deviceHash === '') {
            return response()->json(['status' => 'error', 'mensaje' => 'Huella de dispositivo no enviada.']);
        }
        if (strlen($deviceHash) > 128 || ! str_starts_with($deviceHash, 'kyro-hw-')) {
            return response()->json(['status' => 'error', 'mensaje' => 'Formato de huella inválido.']);
        }

        $dniEmbedido = substr($deviceHash, strrpos($deviceHash, '-') + 1);

        try {
            $agente = Agente::query()
                ->where('dni', $dni)
                ->first(['id', 'dni', 'nombres', 'estado', 'hash_dispositivo', 'pin_seguridad']);

            if (! $agente) {
                return response()->json(['status' => 'error', 'mensaje' => 'Agente no encontrado.']);
            }
            if ($agente->estado !== 'ACTIVO') {
                return response()->json(['status' => 'error', 'mensaje' => 'Cuenta de agente inactiva o restringida.']);
            }

            if ($pin === '') {
                return $this->autorizarSinPin($agente, $dni, $deviceHash, $dniEmbedido);
            }

            if (! preg_match('/^\d{4}$/', $pin) || $dniEmbedido !== $agente->dni || ! $agente->verificarPin($pin)) {
                return response()->json([
                    'status' => 'error',
                    'mensaje' => $dniEmbedido !== $agente->dni
                        ? 'Este navegador está vinculado al DNI de otro agente. Usa tu propio dispositivo.'
                        : 'PIN incorrecto. Ingresa los 4 dígitos de tu PIN o consulta con el administrador.',
                ]);
            }

            DB::transaction(function () use ($agente, $deviceHash) {
                Agente::query()->whereKey($agente->id)->lockForUpdate()->first();

                DB::table('agentes')->where('id', $agente->id)->update([
                    'hash_dispositivo' => $deviceHash,
                    'fecha_registro_disp' => now(),
                ]);
            });

            return response()->json([
                'status' => 'ok',
                'mensaje' => 'Dispositivo autorizado y registrado correctamente.',
            ]);
        } catch (\Throwable $e) {
            Log::error('dispositivo.autorizacion_fallida', [
                'dni' => $dni,
                'exception' => $e,
            ]);

            return response()->json([
                'status' => 'error',
                'mensaje' => 'No pudimos validar el dispositivo en este momento. Puedes intentar nuevamente o usar Token.',
            ], 500);
        }
    }

    private function autorizarSinPin(
        Agente $agente,
        string $dni,
        string $deviceHash,
        string $dniEmbedido
    ): JsonResponse {
        if ($dniEmbedido !== $agente->dni) {
            try {
                DB::table('log_fraude_dispositivo')->insert([
                    'fecha_hora' => now(),
                    'agente_id' => $agente->id,
                    'nombre_agente' => $agente->nombres,
                    'dni_ingresado' => $dni,
                    'dni_duenio_hash' => $dniEmbedido,
                    'tienda_intento' => 'API_AUTH',
                ]);
            } catch (\Throwable) {
                // El log no debe bloquear la respuesta de seguridad.
            }

            return response()->json([
                'status' => 'error',
                'mensaje' => 'Este navegador está vinculado al DNI de otro agente. Usa tu propio dispositivo.',
            ]);
        }

        $hashDb = trim((string) $agente->hash_dispositivo);

        if ($hashDb === '') {
            DB::table('agentes')->where('id', $agente->id)->update([
                'hash_dispositivo' => $deviceHash,
                'fecha_registro_disp' => now(),
            ]);

            return response()->json([
                'status' => 'ok',
                'primer_dispositivo' => true,
                'mensaje' => 'Dispositivo registrado automáticamente.',
            ]);
        }

        if (hash_equals($hashDb, $deviceHash)) {
            return response()->json(['status' => 'ok', 'mensaje' => 'Huella válida.']);
        }

        return response()->json([
            'status' => 'require_pin',
            'mensaje' => 'Este agente ya tiene un dispositivo registrado. Ingresa tu PIN para autorizar el nuevo equipo.',
        ]);
    }
}
