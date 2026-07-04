# Spec — Módulo 5: CRM con temperatura calculada (paridad legacy)

Fecha: 2026-07-03
Estado: DISEÑO FINAL (decisión de producto ya tomada por el usuario, sin opciones abiertas)

## Decisión de producto

El gap analysis (`docs/comparacion/gap_gerencia_financiero_2026-07-02.md`, sección 7 "CRM Dashboard")
marca como **el gap estructural más profundo del área CRM**: el legacy calcula una "temperatura" del
cliente (Caliente / Frío / Upselling / Neutro) dinámicamente con reglas heurísticas sobre
`crm_interacciones`, mientras el refactor solo tiene el pipeline manual `leads.estado` (NUEVO →
CONTACTADO → INTERESADO → CONVERTIDO/PERDIDO), que no tiene ningún concepto de temperatura ni de
scoring temporal.

**Decisión (2026-07-03): adoptar el modelo del legacy.** La temperatura se **calcula** (campo derivado,
nunca se setea a mano) a partir de `crm_clientes` + `crm_interacciones` — las mismas tablas que ya
existen en producción desde la migración `2026_07_02_000001_create_integrador_bitel_tables.php` y que
ya alimenta `ClienteCrmController` (el "Cliente Activo" del cuadre). El pipeline `leads.estado` **no se
elimina** — sigue siendo el mecanismo de seguimiento manual de vendedores (ver "Convivencia" más abajo)
— pero deja de ser el candidato a modelar "temperatura del cliente".

## Reglas heurísticas EXACTAS (fuente: `E:\laragon\www\sis_bipay\gerencia\crm_dashboard.php:230-299`,
función `crmCalcularTemperatura(PDO $pdo, string $dni): array`)

Se evalúan **en este orden**; la primera que matchea gana (return temprano, no hay combinación de
condiciones):

### 1. Caliente 🟢 — interés reciente sin rechazo
```sql
SELECT COUNT(*)
FROM crm_interacciones i
JOIN crm_clientes c ON c.id = i.cliente_id
WHERE c.dni = :dni
  AND i.motivo_rechazo IS NULL
  AND i.fecha_hora >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
```
Si `COUNT > 0` → **Caliente**. Cualquier interacción sin motivo de rechazo en las últimas 48 horas
"calienta" al cliente, sin importar el tipo de operación.

### 2. Frío 🔴 — rechazo crediticio (histórico, sin ventana de tiempo)
```sql
SELECT COUNT(*)
FROM crm_interacciones i
JOIN crm_clientes c ON c.id = i.cliente_id
WHERE c.dni = :dni
  AND i.motivo_rechazo = 'Evaluación Crediticia'
```
Si `COUNT >= 1` → **Frío**. A diferencia de la regla 1, esta NO tiene ventana temporal: un solo rechazo
por evaluación crediticia en cualquier momento de la historia marca al cliente como Frío permanentemente
(salvo que la regla 1 —una interacción reciente sin rechazo— la sobreescriba, porque se evalúa primero).

### 3. Upselling 🟡 — consulta Prepago antigua (oportunidad de migrar a plan)
```sql
SELECT COUNT(*)
FROM crm_interacciones i
JOIN crm_clientes c ON c.id = i.cliente_id
WHERE c.dni = :dni
  AND i.tipo_operacion LIKE '%Prepago%'
  AND i.fecha_hora <= DATE_SUB(NOW(), INTERVAL 30 DAY)
```
Si `COUNT > 0` → **Upselling**. Un cliente que consultó/operó Prepago hace más de 30 días es candidato a
ofrecerle un plan Postpago.

### 4. Neutro ⚪ — default
Si ninguna de las 3 reglas anteriores matchea → **Neutro**. Incluye clientes sin ninguna interacción
registrada.

### Colores (paridad visual con el legacy, para los badges del frontend)

| Etiqueta   | bg                        | text      | border                     |
|------------|---------------------------|-----------|----------------------------|
| Caliente   | `rgba(34,197,94,.15)`     | `#4ade80` | `rgba(34,197,94,.35)`      |
| Frío       | `rgba(239,68,68,.15)`     | `#f87171` | `rgba(239,68,68,.35)`      |
| Upselling  | `rgba(251,191,36,.15)`    | `#fbbf24` | `rgba(251,191,36,.35)`     |
| Neutro     | `rgba(148,163,184,.12)`   | `#94a3b8` | `rgba(148,163,184,.25)`    |

El legacy captura los errores de cada query con `try/catch` silencioso (si la tabla no existe, sigue a la
regla siguiente); el refactor no necesita ese guard porque las tablas ya están garantizadas por la
migración `2026_07_02_000001` — se omite ese `try/catch` por ser código muerto en el destino.

## Modelo de datos

**No se necesita ninguna migración nueva.** `crm_clientes` y `crm_interacciones` (creadas en
`2026_07_02_000001_create_integrador_bitel_tables.php`, ya corridas en producción) tienen todas las
columnas que las 3 reglas necesitan: `crm_interacciones.motivo_rechazo`, `.fecha_hora`,
`.tipo_operacion`, y el FK lógico `crm_interacciones.cliente_id → crm_clientes.id`.

## Backend

### `App\Services\Crm\TemperaturaCalculator` (nuevo)

Clase de servicio con un único método público, sin estado, para que sea reusable desde el controller de
listado y desde tests unitarios sin necesidad de HTTP:

```php
final class TemperaturaCalculator
{
    public const COLORES = [
        'Caliente'  => ['bg' => 'rgba(34,197,94,.15)',   'text' => '#4ade80', 'border' => 'rgba(34,197,94,.35)'],
        'Frío'      => ['bg' => 'rgba(239,68,68,.15)',   'text' => '#f87171', 'border' => 'rgba(239,68,68,.35)'],
        'Upselling' => ['bg' => 'rgba(251,191,36,.15)',  'text' => '#fbbf24', 'border' => 'rgba(251,191,36,.35)'],
        'Neutro'    => ['bg' => 'rgba(148,163,184,.12)', 'text' => '#94a3b8', 'border' => 'rgba(148,163,184,.25)'],
    ];

    public function calcular(string $dni): array // ['etiqueta','bg','text','border']
}
```

Implementación: 3 queries `DB::table('crm_interacciones')->join('crm_clientes', ...)` en el orden exacto
de arriba, cada una con `->where('c.dni', $dni)` y el resto de condiciones de la regla; `exists()` en vez
de `COUNT(*) > 0` (equivalente, más barato). Devuelve en el primer match.

### `App\Http\Controllers\Api\CrmTemperaturaController` (nuevo)

- `GET /v1/crm/temperatura` → `index(Request $request)`. Lista paginada de `crm_interacciones` (join
  `crm_clientes`), cada fila enriquecida con la temperatura **del cliente** (memoizada por DNI dentro del
  mismo request, igual que el legacy `$temperaturas_crm[$dni_temp]` en `crm_dashboard.php:827-833`, para
  no recalcular 3 queries por cada interacción del mismo cliente). Filtros soportados: `tienda_codigo`,
  `dni`, `desde`/`hasta` (sobre `fecha_hora`), `temperatura` (post-filtro en PHP tras calcular, ya que no
  es una columna). Orden `fecha_hora DESC`. Paginación `per_page` (default 50, como `ClienteCrmController`
  y `LeadController`).
- `GET /v1/crm/temperatura/{dni}` → `porDni(string $dni)`. Devuelve solo el objeto de temperatura
  (`etiqueta/bg/text/border`) para un DNI — pensado para reusarse donde se necesite un badge puntual (p.
  ej. futuro: mostrarlo en el chip de "Cliente Activo" del cuadre) sin traer la lista completa.

Ambas rutas dentro del grupo `auth:sanctum` existente (mismo nivel que `clientes-crm`), sin restricción
de rol adicional — igual que el resto de endpoints de CRM (`leads`, `crm/dashboard`, `crm/pipeline`).

### Fix P1 — caché de primer nivel en `DniController::consultar`

El legacy (`api/consultar_dni.php:13-39`) consulta **primero** `crm_clientes` por DNI (sin llamar a la API
externa ni pasar por ningún caché en memoria) antes de caer a `apis.net.pe`. El refactor
(`DniController::consultar`) solo tiene el caché de aplicación (`Cache::get("reniec_dni_{$dni}")`, TTL 7
días) y va directo a la API externa si no hay entrada cacheada — perdió el nivel de caché "gratis" que
usa datos que la propia empresa ya capturó vía el CRM.

Se agrega, **antes** del `Cache::get`, una consulta a `crm_clientes`:

```php
$local = DB::table('crm_clientes')->where('dni', $dni)->first(['nombres', 'apellidos']);
if ($local) {
    $ap = explode(' ', trim($local->apellidos), 2);
    return response()->json([
        'nombres'          => $local->nombres,
        'apellido_paterno' => $ap[0] ?? '',
        'apellido_materno' => $ap[1] ?? '',
        'numero_documento' => $dni,
        'tipo_documento'   => 1,
        'nombre_completo'  => trim($local->nombres . ' ' . $local->apellidos),
        'fuente'           => 'cache_local',
    ]);
}
```

Se mantiene la forma de respuesta normalizada que ya usa `DniController` (snake_case,
`nombre_completo` calculado) en vez de replicar el formato ad-hoc del legacy, para no romper el
contrato ya consumido por el frontend actual. Se agrega el campo `fuente: 'cache_local'` (paridad con
el legacy, que también lo manda) — el resto de las respuestas (Laravel Cache / API externa) no llevan
ese campo hoy y no se les agrega, para no tocar código no relacionado con este fix.

## Frontend

### `frontend/src/types/crm.ts` — nuevos tipos

```ts
export type TemperaturaEtiqueta = 'Caliente' | 'Frío' | 'Upselling' | 'Neutro'

export interface Temperatura {
  etiqueta: TemperaturaEtiqueta
  bg: string
  text: string
  border: string
}

export interface CrmInteraccionTemp {
  id: number
  cliente_id: number
  dni: string
  nombres: string
  apellidos: string
  telefono: string | null
  tienda_codigo: string
  agente_nombre: string
  tipo_operacion: string
  producto_interes: string | null
  motivo_rechazo: string | null
  fecha_hora: string
  temperatura: Temperatura
}

export interface CrmTemperaturaFiltros {
  tienda_codigo?: string
  dni?: string
  desde?: string
  hasta?: string
}
```

### `frontend/src/services/crm.api.ts` — nuevo bloque

```ts
temperatura: {
  list: (params?: CrmTemperaturaFiltros & { per_page?: number }): Promise<{ data: CrmInteraccionTemp[]; total: number }> =>
    api.get('/v1/crm/temperatura', { params }).then(r => r.data),
  porDni: (dni: string): Promise<Temperatura> =>
    api.get(`/v1/crm/temperatura/${dni}`).then(r => r.data),
},
```

### `frontend/src/hooks/useCrm.ts` — nuevo hook

`useCrmTemperatura(params?)` — `useQuery` con `queryKey: ['crm-temperatura', params]`, `staleTime: 60_000`
(mismo patrón que `useCrmDashboard`/`usePipeline`).

### `CrmPage.tsx` — tercer tab "Temperatura"

Se agrega un tab `{ id: 'temperatura', label: 'Temperatura' }` a `CRM_TABS`, entre "Pipeline Kanban" y
"Analytics". Contenido: un Kanban de 4 columnas (Neutro, Upselling, Caliente, Frío — mismo orden visual
que el legacy `crm_dashboard.php:909-956`), cada tarjeta = una interacción (`CrmInteraccionTemp`), con:

- Nombre + DNI del cliente.
- Badge de temperatura usando los colores devueltos por el backend (`style` inline con `bg/text/border`,
  igual mecanismo que el legacy — no hay estos colores en la paleta Tailwind del proyecto, se usan tal
  cual vienen del backend para no aproximar valores).
- Tienda + agente + fecha/hora de la interacción.
- Producto de interés y motivo de rechazo si existen (igual que las columnas ocultas de la tabla legacy
  que alimentan la tarjeta Kanban).
- Botón WhatsApp si hay teléfono (`wa.me/51{telefono}`), mismo patrón que `LeadCard`.

Filtros arriba del Kanban: selector de tienda (reusa `TIENDAS`/`filtroTienda` ya existente en la página)
y rango de fechas (reusa el patrón de `CrmAnalytics`). Sin drag&drop (fuera de alcance — ver "Fuera de
alcance").

`npx tsc --noEmit` debe quedar limpio tras el cambio.

## Convivencia `leads.estado` ↔️ temperatura calculada

No se toca físicamente `leads` ni `leads.estado`: siguen existiendo, `LeadController` y el tab "Pipeline
Kanban" continúan funcionando exactamente igual que hoy. Son dos dominios de datos separados y sin FK
entre sí:

- `leads` → vinculado a `clientes` (tabla del sistema nuevo), es un pipeline de seguimiento manual que el
  vendedor mueve a mano (`NUEVO → CONTACTADO → INTERESADO → CONVERTIDO/PERDIDO`). Sigue siendo útil como
  checklist de trabajo del vendedor — el legacy no tiene un equivalente 1:1 de esto, es una mejora del
  refactor que se conserva.
- `crm_clientes`/`crm_interacciones` → dominio propio heredado 1:1 del CRM legacy de sis_bipay, indexado
  por `dni` (no por `cliente_id` de `clientes`), alimentado por `ClienteCrmController::guardar` (que ya
  se usa desde "Nuevo Cuadre"). La temperatura es un campo **calculado sobre este dominio**, nunca sobre
  `leads`.

Ambos tabs coexisten en `CrmPage.tsx` sin intentar unificarse: unificar los dos modelos (p. ej. vincular
`leads.cliente_id` con `crm_clientes.dni`) es un cambio de alcance mucho mayor, no pedido por el usuario,
y el propio gap analysis ya señala que son "dos modelos de datos totalmente distintos" — este spec cierra
el gap agregando el que falta, no fusionando ambos.

## Plan de tests (TDD)

`backend/tests/Feature/CrmTemperaturaTest.php` (nuevo), con `RefreshDatabase`:

1. `test_calculador_devuelve_caliente_con_interaccion_reciente_sin_rechazo` — interacción con
   `motivo_rechazo = null`, `fecha_hora = now()->subHours(10)` → `Caliente`.
2. `test_calculador_devuelve_neutro_si_interaccion_reciente_supera_48_horas` — `fecha_hora =
   now()->subHours(49)`, sin rechazo, sin otras interacciones → `Neutro` (no debe colar como Caliente).
3. `test_calculador_devuelve_frio_con_rechazo_evaluacion_crediticia_antiguo` — interacción con
   `motivo_rechazo = 'Evaluación Crediticia'` y `fecha_hora` de hace 6 meses → `Frío` (sin ventana de
   tiempo, fija el caso "histórico, sin ventana").
4. `test_calculador_prioriza_caliente_sobre_frio_si_ambas_condiciones_matchean` — mismo cliente con una
   interacción de rechazo crediticio antigua Y una interacción reciente sin rechazo → `Caliente` (fija el
   orden de evaluación: la regla 1 gana aunque la 2 también matchee).
5. `test_calculador_devuelve_upselling_con_consulta_prepago_de_mas_de_30_dias` — interacción
   `tipo_operacion = 'Prepago'`, `fecha_hora = now()->subDays(31)`, sin rechazo ni interacción reciente →
   `Upselling`.
6. `test_calculador_no_marca_upselling_si_consulta_prepago_es_reciente` — `tipo_operacion = 'Prepago'`,
   `fecha_hora = now()->subDays(10)` → no debe ser interpretada como interacción "sin rechazo" que
   caliente (porque supera 48h) ni como Upselling (no supera 30 días) → `Neutro`.
7. `test_calculador_devuelve_neutro_sin_interacciones` — cliente en `crm_clientes` sin ninguna fila en
   `crm_interacciones` → `Neutro`.
8. `test_calculador_devuelve_neutro_si_dni_no_existe` — DNI que no está ni en `crm_clientes` → `Neutro`
   (comportamiento por defecto, no error).
9. `test_endpoint_temperatura_sin_autenticar_devuelve_401`.
10. `test_endpoint_lista_temperatura_devuelve_estructura_y_filtra_por_tienda`.
11. `test_endpoint_temperatura_por_dni_devuelve_solo_el_objeto_temperatura`.

`backend/tests/Feature/DniControllerTest.php` (nuevo, cubre el fix P1):

12. `test_consultar_dni_usa_cache_local_de_crm_clientes_antes_de_llamar_api_externa` — insertar fila en
    `crm_clientes`, mockear `Http::fake()` para que la llamada externa **falle si se invoca** (o
    aserto `Http::assertNothingSent()`), pedir `GET /v1/dni/{dni}` → debe responder con los datos de
    `crm_clientes` y `fuente: 'cache_local'`, sin tocar la red.
13. `test_consultar_dni_sin_cache_local_cae_a_api_externa` (regresión, ya cubierto implícitamente pero se
    fija explícito) — DNI no presente en `crm_clientes` → sigue llamando a la API externa como hoy.

## Fuera de alcance

- Drag & drop real en el Kanban de temperatura (el legacy lo tiene vía `ajax_crm_dragdrop.php`, pero ahí
  arrastrar mueve una interacción que no tiene "estado" propio que persistir — la temperatura no se puede
  "arrastrar a mano" porque es calculada; se documenta como no aplicable, no como pendiente).
- Compositor de plantillas de WhatsApp (`crm_dashboard.php` modal "Redacción WhatsApp") — gap ya
  registrado por separado en el análisis, no relacionado con temperatura.
- Alertas de conflicto de atribución captador/vendedor (`log_resolucion_atribucion`,
  `crm_dashboard.php:301-337`) — dominio de comisiones, no de temperatura; tiene su propia tabla ya
  migrada pero ningún consumidor en el refactor todavía. Fuera de este spec.
- Unificación de `leads` con `crm_clientes` — ver "Convivencia" arriba.
