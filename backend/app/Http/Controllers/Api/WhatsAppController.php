<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use App\Models\WhatsAppMensaje;
use App\Services\WhatsApp\WhatsAppProviderFactory;
use App\Support\TiendaGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class WhatsAppController extends Controller
{
    private function veTodasLasTiendas(): bool
    {
        $user = Auth::user();

        return $user instanceof Usuario
            && $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE);
    }

    private function esAdministrador(): bool
    {
        $user = Auth::user();

        return $user instanceof Usuario
            && $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR);
    }

    private function autorizarCuenta(WhatsAppCuenta $cuenta): void
    {
        $user = Auth::user();

        abort_if(
            TiendaGuard::bloqueaAcceso($this->veTodasLasTiendas(), $user?->tienda_id, $cuenta->tienda_id),
            403,
            'No tienes permisos sobre esta cuenta de WhatsApp.'
        );
    }

    private function cuentasVisiblesQuery()
    {
        $query = WhatsAppCuenta::query();

        if ($this->veTodasLasTiendas()) {
            return $query;
        }

        $tiendaId = trim((string) Auth::user()?->tienda_id);
        if (TiendaGuard::bloqueaAcceso(false, $tiendaId, $tiendaId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('tienda_id', $tiendaId);
    }

    public function cuentas(): JsonResponse
    {
        return response()->json(
            $this->cuentasVisiblesQuery()
                ->orderBy('nombre')
                ->get()
        );
    }

    public function crearCuenta(Request $request): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores pueden conectar cuentas.'], 403);
        }

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'numero' => ['required', 'string', 'max:20'],
            'tienda_id' => ['nullable', 'string', 'max:10'],
        ]);

        $instancia = Str::slug($data['nombre']) . '-' . Str::random(6);

        $cuenta = WhatsAppCuenta::create([
            'nombre' => $data['nombre'],
            'numero' => $data['numero'],
            'instancia' => $instancia,
            'provider' => 'evolution',
            'tienda_id' => $data['tienda_id'] ?? null,
            'estado' => 'qr_pendiente',
        ]);

        $provider = WhatsAppProviderFactory::make($cuenta->provider);
        $provider->crearInstancia($instancia);
        $qr = $provider->obtenerQR($instancia);

        return response()->json(['cuenta' => $cuenta, 'qr' => $qr]);
    }

    public function qr(int $id): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $cuenta = WhatsAppCuenta::findOrFail($id);
        $provider = WhatsAppProviderFactory::make($cuenta->provider);
        $estado = $provider->estadoInstancia($cuenta->instancia);

        if ($estado !== $cuenta->estado) {
            $cuenta->update(['estado' => $estado]);
        }

        $qr = $estado === 'conectada' ? '' : $provider->obtenerQR($cuenta->instancia);

        return response()->json(['estado' => $estado, 'qr' => $qr]);
    }

    public function eliminarCuenta(int $id): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        WhatsAppCuenta::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function chats(Request $request): JsonResponse
    {
        $cuentaId = $request->query('cuenta_id');

        if ($cuentaId !== null && $cuentaId !== '') {
            $cuenta = WhatsAppCuenta::findOrFail((int) $cuentaId);
            $this->autorizarCuenta($cuenta);

            return response()->json(
                WhatsAppChat::where('cuenta_id', $cuenta->id)
                    ->with('cuenta:id,nombre,tienda_id')
                    ->orderByDesc('ultimo_mensaje_at')
                    ->get()
            );
        }

        $cuentasVisiblesIds = $this->cuentasVisiblesQuery()->pluck('id');

        return response()->json(
            WhatsAppChat::whereIn('cuenta_id', $cuentasVisiblesIds)
                ->with('cuenta:id,nombre,tienda_id')
                ->orderByDesc('ultimo_mensaje_at')
                ->get()
        );
    }

    public function mensajes(int $id): JsonResponse
    {
        $chat = WhatsAppChat::with('cuenta')->findOrFail($id);
        $this->autorizarCuenta($chat->cuenta);

        $mensajes = WhatsAppMensaje::where('chat_id', $chat->id)
            ->orderByDesc('timestamp')
            ->paginate(50);

        return response()->json($mensajes);
    }

    public function enviarMensaje(Request $request, int $id): JsonResponse
    {
        $chat = WhatsAppChat::with('cuenta')->findOrFail($id);
        $this->autorizarCuenta($chat->cuenta);

        $data = $request->validate([
            'tipo' => ['required', 'in:texto,imagen'],
            'contenido' => ['nullable', 'string'],
            'media_url' => ['nullable', 'url'],
        ]);

        if (($data['tipo'] ?? null) === 'texto' && trim((string) ($data['contenido'] ?? '')) === '') {
            return response()->json(['message' => 'El contenido es requerido para mensajes de texto.'], 422);
        }

        if (($data['tipo'] ?? null) === 'imagen' && trim((string) ($data['media_url'] ?? '')) === '') {
            return response()->json(['message' => 'La media_url es requerida para mensajes de imagen.'], 422);
        }

        $provider = WhatsAppProviderFactory::make($chat->cuenta->provider);

        if ($data['tipo'] === 'texto') {
            $resultado = $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, (string) $data['contenido']);
        } else {
            $resultado = $provider->enviarMedia(
                $chat->cuenta->instancia,
                $chat->jid,
                (string) $data['media_url'],
                $data['tipo'],
                $data['contenido'] ?? null
            );
        }

        $mensaje = WhatsAppMensaje::create([
            'chat_id' => $chat->id,
            'direccion' => 'out',
            'tipo' => $data['tipo'],
            'contenido' => $data['contenido'] ?? null,
            'media_url' => $data['media_url'] ?? null,
            'wa_message_id' => $resultado['key']['id'] ?? null,
            'enviado_por' => Auth::id(),
            'timestamp' => now(),
        ]);

        $chat->update(['ultimo_mensaje_at' => $mensaje->timestamp]);

        return response()->json($mensaje);
    }
}