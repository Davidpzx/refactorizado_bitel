# 09 — Plan de Ciberseguridad (auditoría por lectura de código)

**Fecha:** 2026-07-10 · **Autor:** worker de planificación (Fable, razonamiento bajo) · **Alcance:** backend Laravel 12 (`backend/`) + frontend React 19 (`frontend/`) + infra VPS (Dokploy/Traefik).
**Método:** SOLO lectura de código y configuración. **Cero escaneos activos contra producción.** Los hallazgos citan archivo:línea del working tree actual (`main`, post-deploy 2026-07-10).
**Regla de ejecución:** los tickets SEC-XX los implementa **Sonnet 5 u Opus 4.8** (nunca Fable), un ticket por pasada, con test que demuestre el cierre cuando aplique.

---

## Resumen ejecutivo

| Severidad | Cantidad | IDs |
|---|---|---|
| CRÍTICA | 1 | SEC-01 |
| ALTA | 4 | SEC-02, SEC-03, SEC-04, SEC-05 |
| MEDIA | 6 | SEC-06 … SEC-11 |
| BAJA | 5 | SEC-12 … SEC-16 |

La postura general es **buena para un refactor en curso**: SQL parametrizado, React sin XSS, uploads bien validados, links públicos con HMAC correcto, `.env` fuera de git. Los problemas graves son de **configuración y cobertura de autorización**, no de código inseguro per se — todos corregibles en tickets acotados.

---

## Área 1 — Autenticación y autorización (matriz endpoint vs middleware)

Middleware disponibles: `auth:sanctum`, `role:` (`EnsureRole`, alias en `backend/bootstrap/app.php:19`), `open.shift`. Se leyó la totalidad de `backend/routes/api.php` (437 líneas).

**Matriz resumida (grupo `auth:sanctum`, prefijo `/v1`):**

| Dominio | Rutas | `role:` | Scoping por tienda |
|---|---|---|---|
| Dashboard anomalías/export, planilla, postpago, usuarios, tiendas (CRUD), heatmap, cuadre-bitel, auditoría-bipay, asistencias-admin, postulaciones-admin, financieras, diagnóstico, comprobantes, facturación-config, config-comisiones, configuración empresa, agentes (CRUD y acciones) | ~90 rutas | ✅ `role:admin` | n/a (admin) |
| Historial, estadísticas, integrador-gestión | ~12 rutas | ✅ `role:admin,tienda` | ✅ en controlador |
| **Inventario (index/store/show/kardex/exports), chips, traslados equipos+chips, tickets, constancias, bipay saldo/transacciones/cajero, clientes-crm, crm/leads/temperatura, bitácora-stock (lecturas), reportes/{id} (show/update/destroy), ventas, clientes** | **~55 rutas** | ❌ ninguno | ⚠️ parcial o ausente |
| `agentes/select` (`routes/api.php:151`), `tiendas/select` (`:295`) | 2 | ❌ (por diseño, dropdowns) | — |

### Hallazgo SEC-03 — ALTA — Recursos operativos sin `role:` ni scoping consistente
- **Archivo:línea:** `backend/routes/api.php:159` (clientes), `:161-172` (inventario lecturas/altas), `:179` (ventas), `:317-322` (tickets), `:340-344` (chips), `:349-362` (traslados equipos y chips), `:365-370` (constancias), `:301-314` (bipay saldo/transacciones/cajero), `:406-407` (clientes-crm), `:267-276` (crm/leads/temperatura).
- **Descripción:** cualquier usuario autenticado (incluido rol agente/vendedor) puede listar inventario global con costos, mover chips, crear/gestionar traslados, ver tickets de todas las tiendas, generar constancias PDF de cualquier reporte/agente por id, consultar saldo Bipay y leer/escribir el CRM completo. La autorización queda delegada a scoping interno de cada controlador, que es **heterogéneo** (algunos filtran por `tienda_base`, otros no filtran nada). Riesgo: escalamiento horizontal entre tiendas y vertical de agente→operaciones.
- **Fix propuesto:** (1) definir matriz objetivo por dominio (admin / admin,tienda / todos) en un doc corto; (2) aplicar `role:admin,tienda` como mínimo a chips, traslados, inventario mutaciones, bipay-cajero, constancias; (3) unificar el scoping por tienda en un `trait ScopedPorTienda` o en `EnsureRole` extendido; (4) tests de 403 por rol para cada grupo.
- **Ticket:** **SEC-03** · Opus 4.8 · esfuerzo ALTO (transversal, ~15 archivos + tests).

### Hallazgo SEC-04 — ALTA — `dni/{dni}` y `ruc/{ruc}` permiten enumeración de PII
- **Archivo:línea:** `backend/routes/api.php:235` (`GET dni/{dni}` → `DniController@consultar`) y `:410` (`GET ruc/{ruc}` → `RucController@consultar`).
- **Descripción:** ambos lookups (proxy a RENIEC/SUNAT) están dentro de `auth:sanctum` pero **sin `role:` y sin `throttle`**. Un token de cualquier rol permite iterar DNIs 00000000-99999999 y cosechar nombres completos de ciudadanos (PII de terceros, no solo del negocio) usando la cuota/credencial del servicio contratado.
- **Fix propuesto:** añadir `->middleware(['role:admin,tienda', 'throttle:30,1'])` a ambas rutas; registrar en log auditable quién consulta qué DNI (sin volcar la respuesta completa); opcional: cache corto por documento para no re-facturar el proveedor.
- **Ticket:** **SEC-04** · Sonnet 5 · esfuerzo BAJO (2 líneas de ruta + 2 tests + log).

**Positivo verificado:** login con `throttle:10,1` y verify-pin `throttle:20,1` (`routes/api.php:61-62`); PIN comparado con `hash_equals` (`app/Models/Agente.php:86`, `AuthController.php:163`); `agentes/{agente}` (show) valida `tienda_base` internamente (comentario `routes/api.php:156`); el resto del CRUD de agentes es `role:admin` (`:157`).

---

## Área 2 — Inyección (SQL, XSS, mass assignment)

**Positivo verificado — SQL:** los usos de `DB::raw`/`whereRaw`/`orderByRaw` del backend usan literales fijos o bindings posicionales (`?`); no se encontró interpolación de input de usuario en SQL crudo. El único `orderByRaw` problemático histórico (`FIELD()` MySQL-only en `ComisionPlanController::index`) era de portabilidad, no de seguridad, y ya se corrigió (commit `38f82a5`).

**Positivo verificado — XSS:** el frontend React escapa por defecto; no hay `dangerouslySetInnerHTML` con datos de servidor sin sanitizar en `frontend/src/`. Los PDFs (DomPDF, constancias) renderizan con Blade escapado (`{{ }}`).

**Positivo verificado — mass assignment:** los modelos usan `$fillable` explícito y los controladores validan con `FormRequest`/`validate()` antes de asignar; no se encontró `$guarded = []` ni `->update($request->all())` sobre modelos sensibles.

*(Sin hallazgos con ticket en esta área; se mantiene como criterio de revisión en el checklist de PRs.)*

---

## Área 3 — Secretos (config, .env, logs, git)

### Hallazgo SEC-01 — CRÍTICA — API key del integrador hardcodeada como default en el repo
- **Archivo:línea:** `backend/config/services.php:41` — `'api_key' => env('INTEGRADOR_API_KEY', 'KyrO+-tomowrroland-skrillex-2026?-wazak-vegetta777')`.
- **Descripción:** la clave que autentica a los agentes extractores máquina-a-máquina (`/v1/integrador/*`, `routes/api.php:83-88`) está **commiteada en texto plano** como default de `env()`. Cualquiera con acceso al repo (o a un leak futuro del repo) puede inyectar saldos/morosidad/histórico Bitel falsos en producción si el `.env` del VPS no define la variable — y aunque la defina, la clave commiteada ya debe considerarse quemada porque coincide con la desplegada en las tiendas.
- **Fix propuesto:** (1) eliminar el default: `env('INTEGRADOR_API_KEY')` y fallar en arranque si falta (validación en un ServiceProvider o `config:check`); (2) **rotar la clave**: generar una nueva, actualizar `.env` del VPS y el `config.php` de cada agente en tienda (coordinar con el usuario — hay agentes desplegados); (3) verificar que `IntegradorController` rechace clave vacía/null explícitamente.
- **Ticket:** **SEC-01** · Opus 4.8 · esfuerzo MEDIO (código bajo, pero la rotación coordinada con agentes en tiendas es el trabajo real).

### Hallazgo SEC-08 — MEDIA — DNIs (PII) escritos en logs de aplicación
- **Archivo:línea:** `backend/app/Services/AgenteService.php:15` (`Log::info('agente.creado', [... 'dni' => $agente->dni])`); patrón repetido en flujos de asistencia y fraude de dispositivos (contexts con `dni` en `AsistenciaController`).
- **Descripción:** los logs (`storage/logs`, y stdout del contenedor → Dokploy) acumulan DNIs en texto plano sin política de retención. Los logs suelen tener menos control de acceso que la BD y se copian a backups.
- **Fix propuesto:** política única: en logs solo `agente_id`; donde el DNI sea imprescindible para trazabilidad, enmascarar (`****1234`). Añadir helper `LogSafe::dni()` y barrer los call-sites. Definir retención de logs (14-30 días) en el checklist de deploy.
- **Ticket:** **SEC-08** · Sonnet 5 · esfuerzo BAJO-MEDIO (grep + reemplazo + helper + test).

**Positivo verificado — git:** `.env` y `.env.*` están en `.gitignore` y **no aparecen en el historial de git** (verificado en la sesión de auditoría previa con `git log --all --diff-filter=A -- '*.env*'`: limpio). No hay otras credenciales hardcodeadas en `config/*.php` — el resto usa `env()` sin default sensible. Las claves de facturación viven en BD (`facturacion_config`) y no en el repo.

---

## Área 4 — Uploads (certificados, fotos, logos)

**Positivo verificado:** los tres flujos de subida están sólidos:
- **Certificados PFX** (`FacturacionConfigController::configureSunat`): validación de mime/extension, conversión PFX→PEM en servidor, almacenamiento fuera de `public/`, ruta `role:admin` (`routes/api.php:226`).
- **Fotos de asistencia/documentos de agente** (`AsistenciaController::markPhoto`, `AgenteDocumentoController::subir`): `validate(['image', 'mimes:...', 'max:...'])`, nombre generado por el servidor (no se usa el nombre original del cliente → sin path traversal), disco privado servido vía endpoint autenticado, documentos de agente tras `role:admin` (`routes/api.php:413-415`).
- **Logos** (`ConfiguracionController::updateLogo` + `LogoProcessorService`, 7 tests): validación de imagen, reprocesado (re-encode) que destruye payloads polyglot, `role:admin`.

No se encontró concatenación de input del usuario en rutas de filesystem (`storage_path(...$request...)`): negativo en el grep de la auditoría.

### Hallazgo SEC-13 — BAJA — Fotos de asistencia se suben por endpoint público sin verificación de tamaño agresiva por IP
- **Archivo:línea:** `backend/routes/api.php:77` (`POST /v1/attendance/mark-photo`, público, `throttle:60,1` compartido con el resto del terminal).
- **Descripción:** el terminal de asistencia es público por diseño (kiosco). 60 req/min por IP permite subir ~60 imágenes/min al disco del VPS — vector de llenado de disco lento, no de compromiso.
- **Fix propuesto:** throttle propio más bajo para `mark-photo` (p. ej. `throttle:10,1`), límite `max:2048` KB verificado (ya existe validación de imagen; confirmar el max), y el cron `limpiar-fotos` (ya existente, diario) como mitigación de retención.
- **Ticket:** **SEC-13** · Sonnet 5 · esfuerzo BAJO.

---

## Área 5 — Links públicos (CPE / WhatsApp)

**Positivo verificado — HMAC correcto:** `backend/app/Services/Facturacion/CpeLinkService.php:41` firma `hash_hmac('sha256', "cpe:{id}:{exp}", secret)` y valida con `hash_equals` + chequeo de expiración (`:63-74`). El id solo no basta: sin firma válida y no expirada, `ComprobanteColaPublicoController` devuelve 403/410 — **la enumeración de `/v1/cpe/{id}` no revela nada** (12 tests del ticket 008). QR de asistencia: mismo patrón (`AsistenciaController.php:230-231,669`) con ventana de bloques temporal. Token del integrador comparado por hash SHA-256 con `hash_equals` (`IntegradorController.php:420`).

### Hallazgo SEC-09 — MEDIA — Descarga pública re-proxy sin caché ni límite propio
- **Archivo:línea:** `backend/routes/api.php:95` (`GET /v1/cpe/{id}/descargar/{tipo}`) → `ComprobanteColaPublicoController::descargar`.
- **Descripción:** cada hit con firma válida re-descarga el PDF/XML desde la API externa de facturación (reproxy). Un link legítimo compartido por WhatsApp puede ser golpeado en bucle durante toda su ventana de validez (el `throttle:60,1` es por IP, trivial de rotar), consumiendo cuota/latencia del proveedor externo.
- **Fix propuesto:** cachear la descarga por `{id,tipo}` (storage local o `Cache::remember` con TTL ≥ ventana del link) tras la primera descarga exitosa; opcional contador de descargas por link con corte razonable (p. ej. 50).
- **Ticket:** **SEC-09** · Sonnet 5 · esfuerzo BAJO-MEDIO.

### Hallazgo SEC-14 — BAJA — Truncados de HMAC (32 hex en CPE, 16 hex en QR)
- **Archivo:línea:** `CpeLinkService.php:41` (`substr(..., 0, 32)` → 128 bits) y `AsistenciaController.php:230` (`substr(..., 0, 16)` → 64 bits).
- **Descripción:** 128 bits para el link CPE es sobrado. Los 64 bits del QR de asistencia son suficientes dado que la ventana temporal es de ±2 bloques y el endpoint tiene throttle, pero es el margen más justo del sistema.
- **Fix propuesto:** subir el QR a 24-32 hex (96-128 bits) — cambio de una constante en generación y validación simultáneamente (romperlo a medias invalida QRs en vuelo: desplegar en ventana muerta).
- **Ticket:** **SEC-14** · Sonnet 5 · esfuerzo BAJO.

---

## Área 6 — Infraestructura (headers, CORS, cookies, rate limits, Dokploy/Traefik)

### Hallazgo SEC-02 — ALTA — `debug_temporal` filtra el mensaje de QueryException en producción
- **Archivo:línea:** `backend/bootstrap/app.php:43` — `'debug_temporal' => $e->getMessage()` dentro del render de `QueryException` **que solo corre en producción** (`app()->isProduction()`, `:33`).
- **Descripción:** el propio comentario dice "TEMPORAL... quitar apenas se resuelva el bug" — sigue ahí. Todo error SQL en prod devuelve al cliente el mensaje interno del driver: nombres de tablas/columnas, fragmentos de SQL con valores bindeados, y a veces credenciales de conexión en errores de conexión. Es un mapa de la BD servido a cualquier cliente que provoque un 500.
- **Fix propuesto:** eliminar la línea 43 (el detalle ya se guarda en el log en `:34-38`, que es el canal correcto). Test: forzar QueryException en entorno "production" simulado y assertar que la respuesta solo contiene el mensaje genérico.
- **Ticket:** **SEC-02** · Sonnet 5 · esfuerzo TRIVIAL (1 línea + 1 test) — **prioridad inmediata por ratio riesgo/costo**.

### Hallazgo SEC-05 — ALTA — Sin headers de seguridad HTTP (CSP, HSTS, X-Frame-Options, etc.)
- **Archivo:línea:** `backend/bootstrap/app.php:17-22` (ningún middleware de headers registrado); no existe `app/Http/Middleware/SecurityHeaders.php`; no hay labels de headers en la config Traefik/Dokploy del servicio.
- **Descripción:** ni la API ni el frontend servido emiten `Strict-Transport-Security`, `X-Content-Type-Options`, `X-Frame-Options`/`frame-ancestors`, `Referrer-Policy` ni CSP. El SPA queda embebible en iframes (clickjacking sobre el panel admin), sin HSTS un downgrade a HTTP es posible en primer contacto, y sin CSP cualquier XSS futuro tiene blast radius completo.
- **Fix propuesto:** middleware `SecurityHeaders` global (API: `nosniff`, `frame-ancestors 'none'` vía CSP, `Referrer-Policy: no-referrer`) + headers en el server del frontend (nginx/Caddy del contenedor o labels Traefik): `HSTS max-age=31536000; includeSubDomains`, CSP inicial en modo `Report-Only` para el SPA (Vite + fuentes propias, sin inline salvo los que existan) y endurecer tras una semana. Documentar en el Dockerfile/labels.
- **Ticket:** **SEC-05** · Opus 4.8 · esfuerzo MEDIO (transversal backend+frontend+infra; la CSP del SPA requiere iteración).

### Hallazgo SEC-06 — MEDIA — CORS permite localhost con `supports_credentials` en producción
- **Archivo:línea:** `backend/config/cors.php:8-9` (`http://localhost:5173`, `http://localhost:3000`) + `:15` (`'supports_credentials' => true`).
- **Descripción:** la misma config sirve dev y prod. Cualquier página corriendo en el localhost de un usuario logueado (otra app de desarrollo, un tab malicioso apuntado a localhost) puede hacer requests con credenciales contra la API de producción. El riesgo real se mitiga porque la auth es Bearer token (no cookie), pero `supports_credentials:true` + orígenes localhost es una combinación que no debe llegar a prod.
- **Fix propuesto:** mover orígenes a env: `'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))` y en el `.env` del VPS dejar solo `https://app.kyrocodelabs.cloud`. Evaluar `supports_credentials => false` (la SPA usa Bearer, no cookies — verificar que nada dependa de cookies antes).
- **Ticket:** **SEC-06** · Sonnet 5 · esfuerzo BAJO.

### Hallazgo SEC-07 — MEDIA — Tokens Sanctum sin expiración
- **Archivo:línea:** `backend/app/Http/Controllers/Api/AuthController.php:43` (`createToken('api')` sin `expiresAt`) + `config/sanctum.php` (`'expiration' => null`, default).
- **Descripción:** un token filtrado (localStorage robado, log de proxy, dispositivo de tienda comprometido) es válido **para siempre** hasta logout manual. En un sistema con terminales compartidas en tiendas, es el escenario esperable, no el excepcional.
- **Fix propuesto:** `SANCTUM_EXPIRATION` / `'expiration' => 60*24*14` (14 días) o `expiresAt` en `createToken`; el frontend ya maneja 401→login. Complemento: comando programado `sanctum:prune-expired` en el scheduler.
- **Ticket:** **SEC-07** · Sonnet 5 · esfuerzo BAJO.

### Hallazgo SEC-10 — MEDIA — Sin throttle en exports y lookups costosos autenticados
- **Archivo:línea:** ejemplos — `routes/api.php:107` (dashboard/exportar), `:114` (historial/exportar), `:119` (bitacora-stock/exportar), `:139` (exportar-excel), `:167,169` (kardex/matriz export), `:256` (planilla export), `:275` (crm export), `:303,318,418,424` (bipay/tickets/neiry/asistencias export). Ninguno lleva `throttle`, y el rate limiter por defecto `api` (60/min) no está registrado como middleware global en `bootstrap/app.php` (Laravel 12 no lo aplica solo).
- **Descripción:** los exports generan Excel/CSV recorriendo tablas completas. Un cliente autenticado (o un frontend con bug de re-render) puede dispararlos en bucle y degradar la BD compartida con el legacy.
- **Fix propuesto:** `RateLimiter::for('exports', ...10/min por usuario)` en `AppServiceProvider` + `->middleware('throttle:exports')` en el grupo de exports; confirmar/registrar un limiter general para `api/*` autenticado (120/min por usuario).
- **Ticket:** **SEC-10** · Sonnet 5 · esfuerzo BAJO-MEDIO (agrupar rutas + limiter + 2 tests).

### Hallazgo SEC-11 — MEDIA — `agentes/select` expone id+nombre+DNI de toda la plantilla a cualquier autenticado
- **Archivo:línea:** `backend/routes/api.php:151-153` — closure que devuelve `['id','nombres','dni']` de todos los agentes ACTIVOS sin `role:`.
- **Descripción:** el DNI es PII y no es necesario para poblar un dropdown. Cualquier rol (incluido agente raso) descarga el padrón completo con DNIs.
- **Fix propuesto:** quitar `dni` del select (dejar `id`,`nombres`; si algún consumidor del front usa el DNI para display, enviar solo los 4 últimos dígitos calculados en servidor). Verificar consumidores en `frontend/src/` antes.
- **Ticket:** **SEC-11** · Sonnet 5 · esfuerzo BAJO.

### Hallazgo SEC-12 — BAJA — `/v1/health` expone `app.env` y nombre interno
- **Archivo:línea:** `backend/routes/api.php:53-57`.
- **Descripción:** endpoint público que confirma entorno (`production`) y nombre de la app — fingerprinting gratuito. Menor, pero innecesario.
- **Fix propuesto:** dejar solo `{'status':'ok'}`; Dokploy solo necesita el 200.
- **Ticket:** **SEC-12** · Sonnet 5 · esfuerzo TRIVIAL.

### Hallazgo SEC-15 — BAJA — `verify-pin` a 20 req/min permite fuerza bruta lenta de PINs de 4-6 dígitos
- **Archivo:línea:** `backend/routes/api.php:62`.
- **Descripción:** 20/min por IP = 28.8k intentos/día por IP; un PIN de 4 dígitos (10k combinaciones) cae en horas con pocas IPs. Mitigado porque `verify-pin` requiere contexto previo, pero el margen es corto.
- **Fix propuesto:** limiter compuesto por IP+identificador objetivo (p. ej. 5 intentos/min por DNI/usuario objetivo) + lockout incremental tras N fallos; el `hash_equals` ya evita timing.
- **Ticket:** **SEC-15** · Sonnet 5 · esfuerzo BAJO-MEDIO.

### Hallazgo SEC-16 — BAJA — Logout revoca solo el token actual; sin revocación global
- **Archivo:línea:** `backend/app/Http/Controllers/Api/AuthController.php` (método `logout` — borra `currentAccessToken()` únicamente); `config/sanctum.php:21-23` (stateful defaults de localhost, inocuo con Bearer pero conviene limpiar).
- **Descripción:** si un usuario sospecha compromiso (o un admin desactiva a un empleado), no hay forma de matar todas sus sesiones; los tokens viejos siguen vivos (agravado por SEC-07 mientras no expire nada).
- **Fix propuesto:** endpoint admin `POST usuarios/{id}/revocar-tokens` (`$usuario->tokens()->delete()`) + revocación automática al desactivar usuario/agente. Complementa a SEC-07.
- **Ticket:** **SEC-16** · Sonnet 5 · esfuerzo BAJO.

**Infra — positivo verificado:** TLS terminado por Traefik con certificados válidos (app y api en `kyrocodelabs.cloud`); el webhook de deploy de Dokploy usa token en path + validación de payload (anotado en 00-STATUS.md); SSH del VPS por clave, no password.

---

## Área 7 — PII (síntesis)

Cubierta transversalmente: enumeración RENIEC/SUNAT (**SEC-04**), DNIs en logs (**SEC-08**), padrón con DNIs en dropdown (**SEC-11**), fotos de asistencia/documentos de identidad — almacenamiento privado correcto (Área 4, positivo) con retención vía cron `limpiar-fotos` ya existente. Pendiente de política (no ticket de código): documento breve de retención de PII (fotos, logs, postulantes rechazados) para el usuario — se incluye como tarea del checklist.

---

## Cola de tickets priorizada (16)

| # | Ticket | Sev | Resumen | Modelo | Esfuerzo | Dependencias |
|---|---|---|---|---|---|---|
| 1 | SEC-02 | ALTA | Quitar `debug_temporal` de bootstrap/app.php:43 | Sonnet 5 | Trivial | — |
| 2 | SEC-01 | CRÍTICA | Eliminar default de INTEGRADOR_API_KEY + **rotación coordinada** | Opus 4.8 | Medio | coordinar con usuario (agentes en tiendas) |
| 3 | SEC-04 | ALTA | role+throttle en dni/{dni} y ruc/{ruc} | Sonnet 5 | Bajo | — |
| 4 | SEC-06 | MEDIA | CORS por env, solo dominio prod en VPS | Sonnet 5 | Bajo | — |
| 5 | SEC-07 | MEDIA | Expiración de tokens Sanctum + prune | Sonnet 5 | Bajo | — |
| 6 | SEC-05 | ALTA | SecurityHeaders + HSTS + CSP (Report-Only→enforce) | Opus 4.8 | Medio | deploy en 2 fases |
| 7 | SEC-03 | ALTA | Matriz role:/scoping en ~55 rutas operativas | Opus 4.8 | Alto | definir matriz con usuario |
| 8 | SEC-11 | MEDIA | Quitar DNI de agentes/select | Sonnet 5 | Bajo | revisar consumidores front |
| 9 | SEC-10 | MEDIA | Limiter de exports + limiter api general | Sonnet 5 | Bajo-Medio | — |
| 10 | SEC-08 | MEDIA | DNIs fuera de logs (helper + barrido) | Sonnet 5 | Bajo-Medio | — |
| 11 | SEC-09 | MEDIA | Caché de descarga pública CPE | Sonnet 5 | Bajo-Medio | — |
| 12 | SEC-16 | BAJA | Revocación global de tokens | Sonnet 5 | Bajo | idealmente tras SEC-07 |
| 13 | SEC-15 | BAJA | Limiter compuesto en verify-pin | Sonnet 5 | Bajo-Medio | — |
| 14 | SEC-12 | BAJA | Health sin env/app | Sonnet 5 | Trivial | verificar healthcheck Dokploy |
| 15 | SEC-13 | BAJA | Throttle propio mark-photo | Sonnet 5 | Bajo | — |
| 16 | SEC-14 | BAJA | HMAC QR 64→96+ bits | Sonnet 5 | Bajo | desplegar en ventana muerta |

**Criterio de orden:** primero lo trivial de alto impacto (SEC-02), luego la crítica (SEC-01, gated por coordinación), luego el resto de altas, medias por exposición, y bajas al final. SEC-03 es la más grande — puede subdividirse por dominio (chips/traslados → inventario → bipay/crm → constancias/tickets) si el ejecutor lo pide.

---

## Checklist de deploy VPS (post-tickets, orquestador + usuario)

1. **Antes de nada:** backup BD (`/root/backups/`, patrón ya usado en el deploy 2026-07-09).
2. **SEC-01:** generar clave nueva → `.env` del VPS (`INTEGRADOR_API_KEY=...`) → redeploy backend → actualizar `config.php` de cada agente en tienda **el mismo día** (ventana coordinada con el usuario; los agentes quedan sin reportar hasta actualizarse).
3. **SEC-06/SEC-07:** añadir `CORS_ALLOWED_ORIGINS` y `SANCTUM_EXPIRATION` al `.env` del VPS antes del redeploy que active esos cambios; avisar que todas las sesiones caducarán a los N días (comportamiento nuevo).
4. **SEC-05:** fase 1 = headers básicos + CSP `Report-Only` → revisar reportes/consola una semana → fase 2 = enforce. Verificar que el panel embebido de nada (no hay iframes legítimos conocidos) antes de `frame-ancestors 'none'`.
5. **SEC-02/04/10/11/12:** redeploy normal; smoke test: login, un export, un lookup DNI (debe responder con throttle activo), `/v1/health` devuelve solo status.
6. **SEC-14:** desplegar generación+validación del QR juntas fuera del horario de marcado (madrugada).
7. **Verificación final (solo lectura):** `curl -sI https://api...` → confirmar HSTS/nosniff/frame-ancestors; probar CORS desde origen no listado → bloqueado; token viejo pre-expiración → 401 tras el TTL.
8. **Política PII (doc, sin código):** retención de fotos de asistencia (cron existente), logs 14-30 días, postulantes rechazados, y quién puede consultar RENIEC/SUNAT.
9. **Rotación de logs:** confirmar `LOG_CHANNEL=daily` con `LOG_DAILY_DAYS` acotado en el `.env` del VPS.

---

## Positivas verificadas (para no re-auditar)

- **SQL parametrizado** en todo el backend; raw SQL solo con literales/bindings.
- **Sin XSS**: React escapa por defecto; sin `dangerouslySetInnerHTML` peligroso; Blade escapado en PDFs.
- **Mass assignment controlado**: `$fillable` + validación previa en todos los modelos sensibles.
- **Uploads sólidos**: mime validado, nombres server-side, discos privados, logos re-encodeados, certificados PFX solo admin.
- **HMAC correcto** en links CPE y QR: `hash_hmac('sha256')` + `hash_equals` + expiración (`CpeLinkService.php:41-74`); enumeración de `/v1/cpe/{id}` estéril sin firma.
- **Comparaciones constantes** para PIN, tokens de dispositivo y token del integrador (`hash_equals` en todos los call-sites encontrados).
- **Git limpio**: `.env` nunca commiteado; sin credenciales en historial (salvo SEC-01, que es config commiteada, no .env).
- **Login/PIN con throttle** desde el día uno (`routes/api.php:61-62`).
