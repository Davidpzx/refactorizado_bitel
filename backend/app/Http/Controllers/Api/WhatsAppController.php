<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Models\WhatsAppBotRegla;
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

        $cuenta = WhatsAppCuenta::findOrFail($id);
        $provider = WhatsAppProviderFactory::make($cuenta->provider);
        $provider->eliminarInstancia($cuenta->instancia);
        $cuenta->delete();

        return response()->json(['ok' => true]);
    }

    public function toggleBot(Request $request, int $id): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }
        $data = $request->validate(['bot_activo' => ['required', 'boolean']]);
        WhatsAppCuenta::findOrFail($id)->update(['bot_activo' => $data['bot_activo']]);

        return response()->json(['ok' => true]);
    }

    public function botReglas(): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        return response()->json(WhatsAppBotRegla::orderByDesc('prioridad')->orderBy('id')->get());
    }

    private function validarBotRegla(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', 'in:texto,menu,equipos'],
            'es_bienvenida' => ['sometimes', 'boolean'],
            'usa_promocion_dinamica' => ['sometimes', 'boolean'],
            'palabras_clave' => ['nullable', 'array'],
            'respuesta' => ['nullable', 'string'],
            'menu_titulo' => ['nullable', 'string', 'max:150'],
            'opciones' => ['nullable', 'array'],
            'prioridad' => ['sometimes', 'integer'],
            'activa' => ['sometimes', 'boolean'],
            'cuenta_id' => ['nullable', 'integer', 'exists:whatsapp_cuentas,id'],
        ]);
    }

    public function crearBotRegla(Request $request): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }
        $regla = WhatsAppBotRegla::create($this->validarBotRegla($request));

        return response()->json(['ok' => true, 'id' => $regla->id]);
    }

    public function actualizarBotRegla(Request $request, int $id): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }
        WhatsAppBotRegla::findOrFail($id)->update($this->validarBotRegla($request));

        return response()->json(['ok' => true]);
    }

    public function eliminarBotRegla(int $id): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }
        WhatsAppBotRegla::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function iniciarChat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'telefono' => ['required', 'string'],
            'nombre_contacto' => ['nullable', 'string', 'max:150'],
            'tienda_id' => ['nullable', 'string', 'max:10'],
            'crm_cliente_id' => ['nullable', 'integer'],
        ]);

        $tiendaId = $data['tienda_id'] ?? null;

        // Un jefe_tienda nunca resuelve cuenta fuera de su propia tienda, sin
        // importar que le hayan mandado otro tienda_id en el body.
        if (!$this->veTodasLasTiendas()) {
            $tiendaId = trim((string) Auth::user()?->tienda_id) ?: null;
        }

        $cuenta = null;
        if ($tiendaId !== null) {
            $cuenta = WhatsAppCuenta::where('tienda_id', $tiendaId)->where('estado', 'conectada')->first();
        }
        if (!$cuenta) {
            $cuenta = WhatsAppCuenta::whereNull('tienda_id')->where('estado', 'conectada')->first();
        }
        if (!$cuenta) {
            return response()->json(['message' => 'sin_cuenta'], 422);
        }

        $jid = WhatsAppChat::normalizarJid($data['telefono']);

        $chat = WhatsAppChat::firstOrCreate(
            ['cuenta_id' => $cuenta->id, 'jid' => $jid],
            [
                'nombre_contacto' => $data['nombre_contacto'] ?? null,
                'numero_contacto' => $data['telefono'],
                'crm_cliente_id' => $data['crm_cliente_id'] ?? null,
                'no_leidos' => 0,
            ]
        );

        return response()->json(['cuenta_id' => $cuenta->id, 'chat' => $chat]);
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