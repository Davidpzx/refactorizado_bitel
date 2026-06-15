<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReporteBorrador;
use App\Services\UserAgentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReporteBorradorController extends Controller
{
    public function __construct(private readonly UserAgentResolver $userAgentResolver)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $tiendaId = $this->tiendaId($request);
        $agenteId = $this->userAgentResolver->resolveOrFail($user)->id;
        $fecha = $this->fechaOperativa();

        $borrador = ReporteBorrador::query()
            ->where('tienda_id', $tiendaId)
            ->whereDate('fecha', $fecha)
            ->orderByRaw('CASE WHEN agente_id = ? THEN 0 ELSE 1 END', [$agenteId])
            ->orderByDesc('actualizado_en')
            ->first();

        if (! $borrador) {
            return response()->json([
                'success' => true,
                'borrador' => null,
            ]);
        }

        $datos = $borrador->datos_json;
        $datos['_cloud_ts'] = $borrador->actualizado_en->getTimestampMs();
        $datos['_cloud_agente'] = $borrador->agente_id;
        $datos['_mismo_usuario'] = (int) $borrador->agente_id === (int) $agenteId;

        return response()->json([
            'success' => true,
            'borrador_id' => $borrador->id,
            'borrador' => $datos,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->boolean('eliminar')) {
            return $this->destroy($request);
        }

        $user = $request->user();
        $tiendaId = $this->tiendaId($request);
        $agenteId = $this->userAgentResolver->resolveOrFail($user)->id;
        $fecha = $this->fechaOperativa();
        $payload = $request->isJson()
            ? $request->json()->all()
            : $request->request->all();
        unset($payload['_csrf']);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $ahora = now();

        $existia = ReporteBorrador::query()
            ->where('agente_id', $agenteId)
            ->where('tienda_id', $tiendaId)
            ->whereDate('fecha', $fecha)
            ->exists();

        DB::table('reportes_borradores')->upsert(
            [[
                'agente_id' => $agenteId,
                'tienda_id' => $tiendaId,
                'fecha' => $fecha,
                'datos_json' => $json,
                'creado_en' => $ahora,
                'actualizado_en' => $ahora,
            ]],
            ['agente_id', 'tienda_id', 'fecha'],
            ['datos_json', 'actualizado_en']
        );

        $borrador = ReporteBorrador::query()
            ->where('agente_id', $agenteId)
            ->where('tienda_id', $tiendaId)
            ->whereDate('fecha', $fecha)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'accion' => $existia ? 'actualizado' : 'creado',
            'borrador_id' => $existia ? null : $borrador->id,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $tiendaId = $this->tiendaId($request);
        $agenteId = $this->userAgentResolver->resolveOrFail($user)->id;

        ReporteBorrador::query()
            ->where('agente_id', $agenteId)
            ->where('tienda_id', $tiendaId)
            ->whereDate('fecha', $this->fechaOperativa())
            ->delete();

        return response()->json([
            'success' => true,
            'msg' => 'Borrador eliminado en la nube.',
        ]);
    }

    private function tiendaId(Request $request): string
    {
        $tiendaId = trim((string) $request->user()->tienda_id);

        abort_if($tiendaId === '', 422, 'El usuario autenticado no tiene una tienda asignada.');

        return $tiendaId;
    }

    private function fechaOperativa(): string
    {
        return now(config('reportes.timezone'))->toDateString();
    }
}
