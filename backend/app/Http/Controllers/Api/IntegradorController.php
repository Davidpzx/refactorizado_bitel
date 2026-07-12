<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\CuadreBitelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Integrador Bitel (agente extractor local). Puerto del legacy sis_bipay:
 * - api/agente_config.php, recibir_saldo.php, recibir_morosidad.php,
 *   recibir_bitel_historico.php (máquina-a-máquina, sin sesión)
 * - gerencia/configuracion_integrador.php + api/descargar_agente.php (admin/tienda)
 * - api/solicitar_extraccion.php + solicitar_bitel_historico.php (admin)
 *
 * Credenciales cifradas con Crypt (AES-256, clave = APP_KEY).
 * API key M2M: config('services.integrador.api_key') / env INTEGRADOR_API_KEY.
 */
class IntegradorController extends Controller
{
    // ══════════════════════════ M2M (agente → servidor) ══════════════════════

    /** POST /v1/integrador/agente-config — entrega la config al agente a cambio de su token. */
    public function agenteConfig(Request $request): JsonResponse
    {
        $token = trim((string) $request->input('token', ''));
        if (! preg_match('/^[0-9a-f]{64}$/i', $token)) {
            return response()->json(['ok' => false, 'error' => 'Token inválido.'], 403);
        }

        $fila = DB::table('integrador_credenciales')
            ->where('agente_token_hash', hash('sha256', $token))
            ->where('activo', 1)->first();

        if (! $fila) {
            return response()->json(['ok' => false, 'error' => 'Token no reconocido o desactivado.'], 403);
        }

        try {
            $password = Crypt::decryptString($fila->bitel_password_enc);
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'error' => 'No se pudo descifrar la credencial.'], 500);
        }

        DB::table('integrador_credenciales')
            ->where('tienda_codigo', $fila->tienda_codigo)
            ->update(['last_config_fetch' => now()]);

        return response()->json([
            'ok' => true,
            'bitel_username' => $fila->bitel_username,
            'bitel_password' => $password,
            'channel_code' => $fila->tienda_codigo,
            'channel_type' => $fila->channel_type,
            'sync_intervalo_min' => (int) $fila->sync_intervalo_min,
        ]);
    }

    /** POST /v1/integrador/recibir-saldo — sync principal: saldo + movimientos + churn/morosidad. */
    public function recibirSaldo(Request $request): JsonResponse
    {
        if ($err = $this->validarM2M($request)) {
            return $err;
        }
        $data = $request->json()->all();

        $saldoBipay = filter_var($data['saldo_bipay'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($saldoBipay === false || $saldoBipay === null) {
            return response()->json(['ok' => false, 'error' => 'saldo_bipay inválido'], 400);
        }

        $channelCode = $this->sanearCodigo($data['channel_code'] ?? '');
        $fechaReporte = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['fecha_reporte'] ?? '')
            ? $data['fecha_reporte'] : now()->toDateString();
        $movimientos = is_array($data['movimientos'] ?? null) ? $data['movimientos'] : [];

        // ── Identificar / auto-registrar la cuenta Bipay destino ──────────────
        $codigoCanal = $this->sanearCodigo($data['cod_tienda'] ?? '') ?: $channelCode;
        if ($codigoCanal === '') {
            foreach ($movimientos as $mov) {
                if ($cod = $this->sanearCodigo($mov['cod_tienda'] ?? '')) {
                    $codigoCanal = $cod;
                    break;
                }
            }
        }

        $cuentaId = $this->resolverCuenta($codigoCanal, (int) ($data['cuenta_id'] ?? 0), $saldoBipay);
        if (! $cuentaId) {
            return response()->json(['ok' => false, 'error' => 'No se pudo identificar ni registrar la cuenta Bipay de destino.'], 400);
        }

        // ── Agrupar movimientos por tienda+fecha y categoría ──────────────────
        $grouped = $this->agruparMovimientos($movimientos, $fechaReporte);

        try {
            $resultado = DB::transaction(function () use ($cuentaId, $saldoBipay, $data, $grouped, $movimientos, $channelCode, $codigoCanal, $fechaReporte) {
                $cuenta = DB::table('cuentas_bipay')->where('id', $cuentaId)->lockForUpdate()->first();
                if (! $cuenta) {
                    throw new \RuntimeException("La cuenta Bipay con ID {$cuentaId} no existe.");
                }

                $saldoAnterior = (float) ($cuenta->saldo_actual ?? 0);
                $saldoAnypayAnterior = (float) ($cuenta->saldo_anypay ?? 0);
                $saldoAnypayNuevo = isset($data['saldo_anypay']) && $data['saldo_anypay'] !== null
                    ? (float) $data['saldo_anypay'] : $saldoAnypayAnterior;
                $saldoNuevo = $saldoBipay + $saldoAnypayNuevo;
                $diferencia = $saldoNuevo - $saldoAnterior;

                DB::table('cuentas_bipay')->where('id', $cuentaId)->update([
                    'saldo_bipay' => $saldoBipay,
                    'saldo_anypay' => $saldoAnypayNuevo,
                    'saldo_actual' => $saldoNuevo,
                    'estado_api' => 'OK',
                ]);

                foreach ($grouped as $g) {
                    $ventasBrutas = $g['postpago'] + $g['paquetes'] + $g['recargas'] + $g['prepago'] + $g['portabilidad'] + $g['otros'];
                    // upsert() = ON DUPLICATE KEY UPDATE portable; clave de conflicto = índice
                    // único uk_tienda_fecha_mov (tienda_codigo, fecha).
                    DB::table('bitel_movimientos_diarios')->upsert([[
                        'tienda_codigo' => $g['tienda_codigo'],
                        'fecha' => $g['fecha'],
                        'postpago' => $g['postpago'],
                        'paquetes' => $g['paquetes'],
                        'recargas' => $g['recargas'],
                        'prepago' => $g['prepago'],
                        'portabilidad' => $g['portabilidad'],
                        'reembolsos' => $g['reembolsos'],
                        'otros' => $g['otros'],
                        'total_neto' => $ventasBrutas - $g['reembolsos'],
                    ]], ['tienda_codigo', 'fecha'], [
                        'postpago', 'paquetes', 'recargas', 'prepago',
                        'portabilidad', 'reembolsos', 'otros', 'total_neto',
                    ]);
                }

                $clientesProcesados = $this->upsertClientesEstado($data['clientes_estado'] ?? [], $fechaReporte);
                $morosidadProcesadas = $this->upsertLineasMorosidad($data['lineas_morosidad'] ?? [], date('Y-m', strtotime($fechaReporte)));

                // Historial solo si el saldo realmente se movió (evita filas +0.00)
                if (abs($diferencia) >= 0.01) {
                    DB::table('transacciones_bipay')->insert([
                        'cuenta_origen_id' => $cuentaId,
                        'tipo_operacion' => 'AJUSTE',
                        'plataforma' => strtoupper($cuenta->tipo ?? 'BIPAY'),
                        'monto' => $diferencia,
                        'saldo_origen_pre' => $saldoAnterior,
                        'saldo_anypay_pre' => $saldoAnypayAnterior,
                        'observacion' => sprintf(
                            'Sync automático MSuite | Canal: %s | PC: %s | Movimientos agrupados: %d',
                            $channelCode, $data['hostname'] ?? 'desconocido', count($movimientos)
                        ),
                        'creado_por' => 0,
                    ]);
                }

                $this->insertarDetalleOperaciones($movimientos, $channelCode);

                // Marcar la última sincronización exitosa de la tienda (el panel admin
                // lee last_sync_at; sin esto la columna quedaba siempre vacía).
                if ($codigoCanal !== '') {
                    DB::table('integrador_credenciales')
                        ->where('tienda_codigo', $codigoCanal)
                        ->update(['last_sync_at' => now()]);
                }

                return [
                    'saldo_anterior' => $saldoAnterior,
                    'saldo_nuevo' => $saldoNuevo,
                    'diferencia' => $diferencia,
                    'alerta_umbral' => $saldoNuevo < (float) ($cuenta->umbral_alerta ?? 0),
                    'clientes' => $clientesProcesados,
                    'morosidad' => $morosidadProcesadas,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('[INTEGRADOR] recibirSaldo: '.$e->getMessage());

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        // Entregar al agente las colas pendientes (deudas on-demand + histórico Bitel)
        $pendientesDeudas = DB::table('solicitudes_extraccion')
            ->where('tipo', 'deudas')->where('estado', 'PENDIENTE')
            ->orderBy('fecha_solicitud')->limit(12)->get(['id', 'periodo']);
        $pendientesBitelHist = DB::table('bitel_historico_queue')
            ->where('estado', 'PENDIENTE')
            ->orderBy('fecha_solicitud')->limit(7)->get(['id', 'fecha']);

        return response()->json([
            'ok' => true,
            'message' => 'Sincronización exitosa',
            'cuenta_id' => $cuentaId,
            'saldo_anterior' => $resultado['saldo_anterior'],
            'saldo_nuevo' => $resultado['saldo_nuevo'],
            'diferencia' => $resultado['diferencia'],
            'alerta_umbral' => $resultado['alerta_umbral'],
            'movs_procesados' => count($movimientos),
            'clientes_procesados' => $resultado['clientes'],
            'morosidad_procesadas' => $resultado['morosidad'],
            'pendientes_deudas' => $pendientesDeudas,
            'pendientes_bitel_historico' => $pendientesBitelHist,
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /** POST /v1/integrador/recibir-morosidad — resultado del reporte de deudas on-demand. */
    public function recibirMorosidad(Request $request): JsonResponse
    {
        if ($err = $this->validarM2M($request)) {
            return $err;
        }
        $data = $request->json()->all();

        $periodo = preg_match('/^\d{4}-\d{2}$/', $data['periodo'] ?? '') ? $data['periodo'] : now()->format('Y-m');
        $solicitudId = (int) ($data['solicitud_id'] ?? 0);

        try {
            [$clientes, $morosidad] = DB::transaction(function () use ($data, $periodo, $solicitudId) {
                $clientes = $this->upsertClientesEstado($data['clientes_estado'] ?? [], $periodo.'-01');
                $morosidad = $this->upsertLineasMorosidad($data['lineas_morosidad'] ?? [], $periodo);

                if ($solicitudId > 0) {
                    DB::table('solicitudes_extraccion')->where('id', $solicitudId)->update([
                        'estado' => 'LISTO',
                        'total_lineas' => $clientes + $morosidad,
                        'fecha_procesado' => now(),
                        'mensaje' => null,
                    ]);
                }

                return [$clientes, $morosidad];
            });
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json([
            'ok' => true, 'periodo' => $periodo,
            'clientes_procesados' => $clientes, 'morosidad_procesadas' => $morosidad,
        ]);
    }

    /** POST /v1/integrador/recibir-bitel-historico — movimientos de una fecha encolada. */
    public function recibirBitelHistorico(Request $request): JsonResponse
    {
        if ($err = $this->validarM2M($request)) {
            return $err;
        }
        $data = $request->json()->all();

        $queueId = (int) ($data['queue_id'] ?? 0);
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['fecha'] ?? '') ? $data['fecha'] : '';
        if (! $queueId || ! $fecha) {
            return response()->json(['ok' => false, 'error' => 'queue_id o fecha inválidos'], 400);
        }

        try {
            DB::table('bitel_historico_queue')->where('id', $queueId)
                ->where('estado', 'PENDIENTE')->update(['estado' => 'PROCESANDO']);

            $movs = $this->insertarDetalleOperaciones(
                is_array($data['movimientos'] ?? null) ? $data['movimientos'] : [],
                '',
                $fecha.' 00:00:00'
            );

            DB::table('bitel_historico_queue')->where('id', $queueId)->update([
                'estado' => 'LISTO', 'movs_procesados' => $movs,
                'fecha_procesado' => now(), 'mensaje' => null,
            ]);

            return response()->json(['ok' => true, 'fecha' => $fecha, 'movs_procesados' => $movs]);
        } catch (\Throwable $e) {
            DB::table('bitel_historico_queue')->where('id', $queueId)
                ->update(['estado' => 'ERROR', 'mensaje' => substr($e->getMessage(), 0, 255)]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ══════════════════════ Admin: credenciales por tienda ════════════════════

    /** GET /v1/integrador/credenciales — estado por tienda (admin ve todas; tienda solo la suya). */
    public function credenciales(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = DB::table('tiendas as t')
            ->leftJoin('integrador_credenciales as c', 'c.tienda_codigo', '=', 't.codigo')
            ->where('t.codigo', '<>', 'CENTRAL')
            ->orderBy('t.codigo')
            ->select([
                't.codigo', 't.nombre', 'c.bitel_username', 'c.sync_intervalo_min',
                'c.activo', 'c.last_config_fetch', 'c.last_sync_at', 'c.updated_at',
                DB::raw('(c.id IS NOT NULL) AS configurada'),
            ]);

        if (! $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            $q->where('t.codigo', $user->tienda_id ?? '');
        }

        $filas = $q->get()->map(function ($f) {
            $f->estado_agente = $this->estadoAgente($f->last_config_fetch, (int) ($f->sync_intervalo_min ?? 15), $f->configurada ? (bool) $f->activo : true);

            return $f;
        });

        return response()->json(['data' => $filas]);
    }

    /** POST /v1/integrador/credenciales — crear/actualizar credenciales de una tienda. */
    public function guardarCredenciales(Request $request): JsonResponse
    {
        $request->validate([
            'tienda_codigo' => 'required|string|max:20',
            'bitel_username' => 'required|string|max:80',
            'bitel_password' => 'nullable|string|max:200',
            'sync_intervalo_min' => 'nullable|integer',
        ]);

        $codigo = strtoupper(trim($request->input('tienda_codigo')));
        if ($err = $this->autorizarTienda($request, $codigo)) {
            return $err;
        }

        $username = trim($request->input('bitel_username'));
        $password = (string) $request->input('bitel_password', '');
        $intervalo = max(5, min(240, (int) $request->input('sync_intervalo_min', 15)));

        $existente = DB::table('integrador_credenciales')->where('tienda_codigo', $codigo)->first();

        if ($existente) {
            DB::table('integrador_credenciales')->where('tienda_codigo', $codigo)->update([
                'bitel_username' => $username,
                'bitel_password_enc' => $password !== '' ? Crypt::encryptString($password) : $existente->bitel_password_enc,
                'sync_intervalo_min' => $intervalo,
            ]);

            return response()->json(['ok' => true, 'message' => 'Credenciales actualizadas. El agente las usará en su siguiente ciclo.']);
        }

        if ($password === '') {
            return response()->json(['ok' => false, 'error' => 'La contraseña Bitel es obligatoria en el primer registro.'], 422);
        }

        $token = bin2hex(random_bytes(32));
        DB::table('integrador_credenciales')->insert([
            'tienda_codigo' => $codigo,
            'bitel_username' => $username,
            'bitel_password_enc' => Crypt::encryptString($password),
            'sync_intervalo_min' => $intervalo,
            'agente_token_hash' => hash('sha256', $token),
            'creado_en' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Credenciales registradas. Copia el token AHORA: no se volverá a mostrar.',
            'token' => $token,
        ]);
    }

    /** POST /v1/integrador/credenciales/{codigo}/regenerar-token */
    public function regenerarToken(Request $request, string $codigo): JsonResponse
    {
        $codigo = strtoupper(trim($codigo));
        if ($err = $this->autorizarTienda($request, $codigo)) {
            return $err;
        }

        $token = bin2hex(random_bytes(32));
        $n = DB::table('integrador_credenciales')->where('tienda_codigo', $codigo)
            ->update(['agente_token_hash' => hash('sha256', $token)]);

        if ($n === 0) {
            return response()->json(['ok' => false, 'error' => 'Esa tienda aún no tiene credenciales registradas.'], 404);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Token regenerado. El anterior quedó invalidado. Copia el nuevo AHORA.',
            'token' => $token,
        ]);
    }

    /** POST /v1/integrador/credenciales/{codigo}/toggle — activar/desactivar (solo admin). */
    public function toggleActivo(Request $request, string $codigo): JsonResponse
    {
        $codigo = strtoupper(trim($codigo));
        DB::statement('UPDATE integrador_credenciales SET activo = 1 - activo WHERE tienda_codigo = ?', [$codigo]);

        return response()->json(['ok' => true, 'message' => 'Estado actualizado.']);
    }

    /**
     * GET /v1/integrador/descargar-agente?tienda=X&token=Y — ZIP de instalación.
     * El token viaja solo en el instante posterior a su generación (no se persiste en claro).
     * Los binarios del agente se leen de storage/app/integrador/agente si existen.
     */
    public function descargarAgente(Request $request)
    {
        $codigo = strtoupper(trim($request->query('tienda', '')));
        $token = trim($request->query('token', ''));

        if ($codigo === '' || ! preg_match('/^[0-9a-f]{64}$/i', $token)) {
            return response()->json(['ok' => false, 'error' => 'Token ausente o inválido.'], 403);
        }
        if ($err = $this->autorizarTienda($request, $codigo)) {
            return $err;
        }

        $hash = DB::table('integrador_credenciales')
            ->where('tienda_codigo', $codigo)->where('activo', 1)
            ->value('agente_token_hash');
        if (! $hash || ! hash_equals($hash, hash('sha256', $token))) {
            return response()->json(['ok' => false, 'error' => 'El token no corresponde a la tienda (¿fue regenerado?).'], 403);
        }

        // Sin los binarios del agente el instalador es inerte (la tarea programada no
        // tendría qué ejecutar). Fallar ruidosamente en vez de repartir un ZIP roto.
        $agenteDir = storage_path('app/integrador/agente');
        $requeridos = ['agente_bipay.php', 'BitelBipayClient.php', 'ejecutar_agente.bat'];
        $faltantes = array_values(array_filter(
            $requeridos,
            fn ($a) => ! is_file($agenteDir.DIRECTORY_SEPARATOR.$a)
        ));
        if ($faltantes) {
            return response()->json([
                'ok' => false,
                'error' => 'Los binarios del agente no están provisionados en el servidor '
                    .'(storage/app/integrador/agente): '.implode(', ', $faltantes)
                    .'. Contacta al administrador del sistema.',
            ], 503);
        }

        $servidorCentral = rtrim(config('app.url'), '/').'/api/v1/integrador/recibir-saldo';
        $apiKeyCentral = config('services.integrador.api_key');

        // Sin key central no tiene sentido entregar el agente: quedaría con API_KEY_CENTRAL
        // vacía y sus POST serían rechazados. Fallar controladamente en vez de repartir basura.
        if (! is_string($apiKeyCentral) || $apiKeyCentral === '') {
            return response()->json([
                'ok' => false,
                'error' => 'La API key central del integrador no está configurada en el servidor. '
                    .'Contacta al administrador del sistema.',
            ], 503);
        }

        $configPhp = "<?php\n"
            ."// Config del agente Bipay — tienda {$codigo}. Generado el ".now()->format('Y-m-d H:i').".\n"
            ."// Las credenciales Bitel NO viven aquí: el agente las descarga del servidor\n"
            ."// central con AGENTE_TOKEN (gestionar en la web: Integrador Bipay).\n"
            ."define('AGENTE_TOKEN',     '".addslashes($token)."');\n"
            ."define('SERVIDOR_CENTRAL', '".addslashes($servidorCentral)."');\n"
            ."define('API_KEY_CENTRAL',  '".addslashes($apiKeyCentral)."');\n"
            ."define('CUENTA_BD_ID',      1);\n"
            ."define('FECHA_REPORTE',    '');\n"
            ."define('LOG_FILE',         __DIR__ . '/agente_bipay.log');\n"
            ."define('DEBUG_MODE',       false);\n";

        $instalarBat = "@echo off\r\n"
            ."REM Instala la Tarea Programada del agente Bipay ({$codigo}) — ejecutar como Administrador\r\n"
            ."net session >nul 2>&1\r\n"
            ."if %errorlevel% neq 0 ( echo [ERROR] Ejecuta este archivo como Administrador. & pause & exit /b 1 )\r\n"
            ."where php >nul 2>&1\r\n"
            ."if %errorlevel% neq 0 ( echo [ERROR] PHP no esta en el PATH. & pause & exit /b 1 )\r\n"
            ."set DEST=C:\\BitelSync\r\n"
            ."if not exist %DEST% mkdir %DEST%\r\n"
            ."xcopy /y \"%~dp0*\" \"%DEST%\\\" >nul\r\n"
            ."schtasks /create /tn \"Sync Bipay Bitel - {$codigo}\" /tr \"%DEST%\\ejecutar_agente.bat\" /sc minute /mo 5 /ru SYSTEM /f\r\n"
            ."if %errorlevel% equ 0 ( echo [OK] Tarea instalada. ) else ( echo [ERROR] No se pudo crear la tarea. )\r\n"
            ."pause\r\n";

        $tmpZip = tempnam(sys_get_temp_dir(), 'agente_').'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['ok' => false, 'error' => 'No se pudo crear el ZIP.'], 500);
        }

        // Binarios del agente (validados arriba; se copian desde storage/app/integrador/agente/).
        foreach ($requeridos as $a) {
            $zip->addFile($agenteDir.DIRECTORY_SEPARATOR.$a, $a);
        }
        $zip->addFromString('config.php', $configPhp);
        $zip->addFromString('instalar_tarea.bat', $instalarBat);
        $zip->addFromString('desinstalar_tarea.bat', "@echo off\r\nschtasks /delete /tn \"Sync Bipay Bitel - {$codigo}\" /f\r\npause\r\n");
        $zip->addFromString('LEEME.txt',
            "AGENTE BIPAY — {$codigo}\r\n"
            ."1. Copia esta carpeta descomprimida a la PC con MSuite.\r\n"
            ."2. Clic derecho en instalar_tarea.bat -> Ejecutar como administrador (una sola vez).\r\n"
            ."3. Listo: sincroniza solo cada N minutos (configurable en la web).\r\n"
            ."Para desinstalar: desinstalar_tarea.bat.\r\n");
        $zip->close();

        return response()->download($tmpZip, "agente_bipay_{$codigo}.zip")->deleteFileAfterSend(true);
    }

    // ═══════════════════ Admin: colas de extracción ══════════════════════════

    /** POST /v1/integrador/solicitar-extraccion {periodo: YYYY-MM} — deudas on-demand. */
    public function solicitarExtraccion(Request $request): JsonResponse
    {
        $periodo = trim((string) $request->input('periodo', ''));
        if (! preg_match('/^\d{4}-\d{2}$/', $periodo)) {
            return response()->json(['ok' => false, 'error' => 'Periodo inválido'], 400);
        }

        DB::statement("
            INSERT INTO solicitudes_extraccion (periodo, tipo, estado, solicitado_por)
            VALUES (?, 'deudas', 'PENDIENTE', ?)
            ON DUPLICATE KEY UPDATE estado = 'PENDIENTE', fecha_solicitud = NOW(),
                fecha_procesado = NULL, mensaje = NULL, solicitado_por = VALUES(solicitado_por)
        ", [$periodo, $request->user()->id]);

        return response()->json(['ok' => true, 'periodo' => $periodo, 'estado' => 'PENDIENTE']);
    }

    /** POST /v1/integrador/solicitar-bitel-historico {fecha: YYYY-MM-DD}. */
    public function solicitarBitelHistorico(Request $request): JsonResponse
    {
        $fecha = trim((string) $request->input('fecha', ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return response()->json(['ok' => false, 'error' => 'Fecha inválida'], 400);
        }
        if ($fecha > now()->toDateString()) {
            return response()->json(['ok' => false, 'error' => 'No se puede solicitar fecha futura'], 400);
        }

        DB::statement("
            INSERT INTO bitel_historico_queue (fecha, estado, solicitado_por, fecha_solicitud)
            VALUES (?, 'PENDIENTE', ?, NOW())
            ON DUPLICATE KEY UPDATE estado = 'PENDIENTE', solicitado_por = VALUES(solicitado_por),
                fecha_solicitud = NOW(), fecha_procesado = NULL, movs_procesados = 0, mensaje = NULL
        ", [$fecha, $request->user()->id]);

        return response()->json(['ok' => true, 'fecha' => $fecha, 'estado' => 'PENDIENTE']);
    }

    /** GET /v1/integrador/morosidad?periodo=YYYY-MM — líneas de morosidad + estado de solicitud. */
    public function morosidad(Request $request): JsonResponse
    {
        $periodo = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('periodo', ''))
            ? $request->query('periodo') : now()->format('Y-m');

        return response()->json([
            'periodo' => $periodo,
            'solicitud' => DB::table('solicitudes_extraccion')->where('periodo', $periodo)->where('tipo', 'deudas')->first(),
            'lineas' => DB::table('lineas_morosidad')->where('periodo', $periodo)
                ->orderByDesc('monto_deuda')->limit(1000)->get(),
            'clientes_estado' => DB::table('clientes_estado')->where('periodo', $periodo)
                ->selectRaw("COUNT(*) total, SUM(es_churn) churn, SUM(estado_linea = 'SUSPENDIDO') suspendidos, SUM(monto_deuda) deuda_total")
                ->first(),
        ]);
    }

    // ═══════════════════════════ Helpers privados ═════════════════════════════

    private function validarM2M(Request $request): ?JsonResponse
    {
        // Sin key central configurada NO se autentica a nadie: rechazar antes de comparar
        // para que un config vacío no cuele como válido ('' === '' / null === null).
        $apiKeyCentral = config('services.integrador.api_key');
        if (! is_string($apiKeyCentral) || $apiKeyCentral === '') {
            return response()->json(['ok' => false, 'error' => 'Integrador no configurado en el servidor.'], 403);
        }

        $data = $request->json()->all();
        if (! is_array($data) || empty($data)) {
            // agente_config manda form-data; el resto JSON
            $data = $request->all();
        }
        $apiKeyRecibida = (string) ($data['api_key'] ?? '');
        if ($apiKeyRecibida === '' || ! hash_equals($apiKeyCentral, $apiKeyRecibida)) {
            return response()->json(['ok' => false, 'error' => 'API key inválida'], 403);
        }
        if (abs(time() - (int) ($data['timestamp'] ?? 0)) > 300) {
            return response()->json(['ok' => false, 'error' => 'Timestamp expirado (desincronización de reloj)'], 400);
        }

        return null;
    }

    private function autorizarTienda(Request $request, string $codigo): ?JsonResponse
    {
        $user = $request->user();
        $ok = $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)
            || ($user->esRol(Usuario::ROL_JEFE_TIENDA) && $codigo !== '' && $codigo === strtoupper((string) ($user->tienda_id ?? '')));

        return $ok ? null : response()->json(['ok' => false, 'error' => 'No tienes permiso sobre esa tienda.'], 403);
    }

    private function sanearCodigo(?string $codigo): string
    {
        return preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) $codigo));
    }

    private function estadoAgente(?string $lastFetch, int $intervalo, bool $activo): array
    {
        if (! $activo) {
            return ['estado' => 'DESACTIVADO', 'minutos' => null];
        }
        if (! $lastFetch) {
            return ['estado' => 'SIN_CONTACTO', 'minutos' => null];
        }
        $mins = (time() - strtotime($lastFetch)) / 60;
        $lim = max(10, 2 * ($intervalo ?: 15));

        return $mins <= $lim
            ? ['estado' => 'EN_LINEA', 'minutos' => (int) round($mins)]
            : ['estado' => 'CAIDO', 'minutos' => (int) round($mins)];
    }

    private function resolverCuenta(string $codigoCanal, int $cuentaIdJson, float $saldoBipay): ?int
    {
        if ($codigoCanal === '') {
            return $cuentaIdJson > 0 && DB::table('cuentas_bipay')->where('id', $cuentaIdJson)->exists()
                ? $cuentaIdJson : null;
        }

        $tienda = DB::table('tiendas')->where('codigo', $codigoCanal)->first(['id', 'cuenta_bipay_id']);

        if ($tienda && $tienda->cuenta_bipay_id !== null) {
            return (int) $tienda->cuenta_bipay_id;
        }

        $nuevaCuentaId = (int) DB::table('cuentas_bipay')->insertGetId([
            'alias' => 'Tienda '.$codigoCanal,
            'tipo' => 'HIJO',
            'numero_cuenta' => $codigoCanal,
            'nombre_titular' => 'Auto-Registro',
            'razon_social' => 'Nueva Cuenta '.$codigoCanal,
            'saldo_bipay' => $saldoBipay,
            'saldo_anypay' => 0.0,
            'saldo_actual' => $saldoBipay,
            'umbral_alerta' => 0.0,
            'activa' => 1,
        ]);

        if ($tienda) {
            DB::table('tiendas')->where('id', $tienda->id)->update(['cuenta_bipay_id' => $nuevaCuentaId]);
        } else {
            DB::table('tiendas')->insert([
                'codigo' => $codigoCanal,
                'nombre' => 'Tienda Nueva',
                'cuenta_bipay_id' => $nuevaCuentaId,
                'radio_permitido' => 60,
            ]);
        }

        return $nuevaCuentaId;
    }

    private function agruparMovimientos(array $movimientos, string $fechaReporte): array
    {
        $grouped = [];
        foreach ($movimientos as $mov) {
            $tienda = $this->sanearCodigo($mov['cod_tienda'] ?? '') ?: 'SIN_TIENDA';
            $categoria = strtolower(trim($mov['categoria'] ?? ''));
            if ($categoria === 'ignorar') {
                continue;
            }

            $fechaMov = null;
            $tiempo = trim($mov['tiempo'] ?? '');
            if ($tiempo) {
                if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $tiempo, $m)) {
                    $fechaMov = sprintf('%s-%02d-%02d', $m[3], $m[2], $m[1]);
                } elseif (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $tiempo, $m)) {
                    $fechaMov = sprintf('%s-%02d-%02d', $m[1], $m[2], $m[3]);
                }
            }
            $fechaMov ??= $fechaReporte;

            $key = $tienda.'_'.$fechaMov;
            $grouped[$key] ??= [
                'tienda_codigo' => $tienda, 'fecha' => $fechaMov,
                'postpago' => 0.0, 'paquetes' => 0.0, 'recargas' => 0.0, 'prepago' => 0.0,
                'portabilidad' => 0.0, 'reembolsos' => 0.0, 'otros' => 0.0,
            ];

            $monto = abs((float) ($mov['cantidad'] ?? 0));
            $slot = match ($categoria) {
                'postpago' => 'postpago',
                'paquetes' => 'paquetes',
                'recarga_bipay', 'recargas' => 'recargas',
                'prepago' => 'prepago',
                'portabilidad' => 'portabilidad',
                'reembolsos_bipay', 'reembolsos' => 'reembolsos',
                default => 'otros',
            };
            $grouped[$key][$slot] += $monto;
        }

        return $grouped;
    }

    private function insertarDetalleOperaciones(array $movimientos, string $channelCode, ?string $fallbackFechaHora = null): int
    {
        $insertados = 0;
        foreach ($movimientos as $m) {
            $fechaHora = ! empty($m['tiempo'])
                ? date('Y-m-d H:i:s', strtotime($m['tiempo']))
                : ($fallbackFechaHora ?? now()->toDateTimeString());
            $tienda = ! empty($m['cod_tienda']) ? $m['cod_tienda'] : $channelCode;
            $descRaw = trim(($m['descripcion'] ?? '').' '.($m['isdn_cliente'] ?? '').' '.($m['nota'] ?? ''));

            // insertOrIgnore() = INSERT IGNORE portable; el índice único idx_unique_mov
            // (tienda_codigo, fecha_hora, descripcion, monto) descarta duplicados en silencio.
            $insertados += DB::table('bitel_operaciones_detalle')->insertOrIgnore([
                'tienda_codigo' => $tienda,
                'fecha_hora' => $fechaHora,
                'tipo_operacion' => substr($m['tipo_transaccion'] ?? 'OTROS', 0, 100),
                'descripcion' => substr($descRaw, 0, 255),
                'monto' => (float) ($m['cantidad'] ?? 0),
                'codigo_personal' => CuadreBitelService::extraerCodigoPersonal($descRaw),
            ]);
        }

        return $insertados;
    }

    private function upsertClientesEstado(array $clientesEstado, string $fechaBase): int
    {
        $n = 0;
        foreach ($clientesEstado as $c) {
            $linea = preg_replace('/\D/', '', (string) ($c['numero_linea'] ?? ''));
            if ($linea === '') {
                continue;
            }
            $estadoLinea = in_array($c['estado_linea'] ?? '', ['ACTIVO', 'SUSPENDIDO', 'BAJA'], true)
                ? $c['estado_linea'] : 'ACTIVO';
            $periodo = preg_match('/^\d{4}-\d{2}$/', $c['periodo'] ?? '')
                ? $c['periodo'] : date('Y-m', strtotime($fechaBase));

            // upsert() portable; conflicto = índice único uq_linea_periodo (numero_linea, periodo).
            DB::table('clientes_estado')->upsert([[
                'numero_linea' => substr($linea, 0, 20),
                'dni_cliente' => substr(preg_replace('/\D/', '', (string) ($c['dni_cliente'] ?? '')), 0, 12),
                'cod_tienda' => $this->sanearCodigo($c['cod_tienda'] ?? ''),
                'plan' => substr((string) ($c['plan'] ?? ''), 0, 100),
                'fecha_alta' => ! empty($c['fecha_alta']) ? date('Y-m-d', strtotime($c['fecha_alta'])) : null,
                'estado_linea' => $estadoLinea,
                'estado_bloqueo' => in_array($c['estado_bloqueo'] ?? '', ['BLOQUEO_1', 'BLOQUEO_2', 'NORMAL'], true) ? $c['estado_bloqueo'] : 'NORMAL',
                'dias_mora' => (int) ($c['dias_mora'] ?? 0),
                'monto_deuda' => (float) ($c['monto_deuda'] ?? 0),
                'es_churn' => $estadoLinea === 'BAJA' ? 1 : 0,
                'periodo' => $periodo,
            ]], ['numero_linea', 'periodo'], [
                'dni_cliente', 'cod_tienda', 'plan', 'fecha_alta', 'estado_linea',
                'estado_bloqueo', 'dias_mora', 'monto_deuda', 'es_churn',
            ]);
            $n++;
        }

        return $n;
    }

    private function upsertLineasMorosidad(array $lineas, string $periodoDefault): int
    {
        $n = 0;
        foreach ($lineas as $lm) {
            $linea = preg_replace('/\D/', '', (string) ($lm['numero_linea'] ?? ''));
            if ($linea === '') {
                continue;
            }
            $estadoLinea = in_array($lm['estado_linea'] ?? '', ['ACTIVO', 'SUSPENDIDO', 'BAJA'], true)
                ? $lm['estado_linea'] : 'ACTIVO';
            $periodo = preg_match('/^\d{4}-\d{2}$/', $lm['periodo'] ?? '') ? $lm['periodo'] : $periodoDefault;

            // upsert() portable; conflicto = índice único uq_linea_periodo_mor (numero_linea, periodo).
            DB::table('lineas_morosidad')->upsert([[
                'numero_linea' => substr($linea, 0, 20),
                'cliente' => substr((string) ($lm['cliente'] ?? ''), 0, 150),
                'cod_tienda' => $this->sanearCodigo($lm['cod_tienda'] ?? ''),
                'plan' => substr((string) ($lm['plan'] ?? ''), 0, 100),
                'fecha_alta' => ! empty($lm['fecha_alta']) ? date('Y-m-d', strtotime($lm['fecha_alta'])) : null,
                'estado_linea' => $estadoLinea,
                'fecha_cancelacion' => ! empty($lm['fecha_cancelacion']) ? date('Y-m-d', strtotime($lm['fecha_cancelacion'])) : null,
                'dias_mora' => (int) ($lm['dias_mora'] ?? 0),
                'monto_deuda' => (float) ($lm['monto_deuda'] ?? 0),
                'es_churn' => (int) ($lm['es_churn'] ?? 0),
                'periodo' => $periodo,
            ]], ['numero_linea', 'periodo'], [
                'cliente', 'cod_tienda', 'plan', 'fecha_alta', 'estado_linea',
                'fecha_cancelacion', 'dias_mora', 'monto_deuda', 'es_churn',
            ]);
            $n++;
        }

        return $n;
    }
}
