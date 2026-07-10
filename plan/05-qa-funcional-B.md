# QA funcional end-to-end — MITAD B (TICKET-027)

- **Modelo:** Opus 4.8 · **Alcance de esta mitad:** Flujos **4 (Facturación SUNAT beta)**, **5 (Comisiones)**, **6 (Asistencia)**.
- **Entorno:** SQLite `:memory:` vía PHPUnit (RefreshDatabase); API SUNAT mockeada con `Http::fake` en las suites Feature (patrón de `ProcesarColaComprobantesTest`/`ComprobanteColaPublicoTest`). Criterio = **comportamiento** (efectos en BD, reglas, estados) contra el legacy `E:\laragon\www\sistema-rolando-salas`, no píxeles.
- **Oráculo de reglas:** `plan/00-inventario-legacy.md` §4 + código legacy citado por archivo:línea.
- **Fecha de ejecución:** 2026-07-09.

## Resumen ejecutivo

| Flujo | Tests verdes | Veredicto | Bugs |
|-------|-------------|-----------|------|
| 4 — Facturación SUNAT beta | 131 | ✅ **PASA** | 0 |
| 5 — Comisiones (CUOTAS + escalonado) | 28 | ⚠️ **PASA con observación** | 1 (MEDIO) |
| 6 — Asistencia (GPS/QR/PERMISO/salida auto) | 45 + 1 efímero | ✅ **PASA** | 0 |

**Total: 205 tests ejecutados, 205 verdes, 0 rojos.** Un único hallazgo sustantivo (BUG-027B-01, MEDIO) en la interacción retención-CUOTAS ↔ planilla-de-equipos; el resto es fiel al legacy y en asistencia hay una **mejora** sobre el legacy (salida automática ya no pisa excepciones).

> Nota de método: los tests efímeros creados para evidencia se ejecutaron y se **borraron** al terminar. No se dejó ningún archivo nuevo en `tests/`. No se tocaron archivos de la mitad A. Backend/HTTP no se dejaron corriendo (todo vía PHPUnit `:memory:`).

---

## FLUJO 4 — Facturación SUNAT en beta (end-to-end)

**Regla legacy (§4):** emisión en 2 pasos (crear + `send-sunat`) contra API Laravel externa, vía **cola asíncrona** (`comprobantes_cola`) drenada por cron con **backoff exponencial**; multi-emisor por tienda; **link público HMAC** para WhatsApp; NC/anulación; descarga PDF/XML/CDR.

### Pasos ejecutados y esperado vs obtenido

| # | Paso | Esperado (legacy) | Obtenido | ✓ |
|---|------|-------------------|----------|---|
| 1 | Encolar boleta desde venta | Fila `PENDIENTE` en `comprobantes_cola`; sin emisor queda en `PENDIENTE` sin gastar intento | Idéntico (`EmitirAhoraTest`: "sin emisor configurado queda encolada en pendiente") | ✓ |
| 2 | Cron drena (`facturacion:procesar-cola`) | 2 pasos; feliz → `ACEPTADO`; token/serie del **emisor** pisan el payload | `ProcesadorColaComprobantes::documento()` fuerza `company_id/branch_id/serie/ubigeo` desde `FacturacionConfig` (`ProcesarColaComprobantes.php:157-179`); test "el paso 1 manda el token del emisor y la serie de la config no la del payload" | ✓ |
| 3 | Fallo transitorio (5xx / estado desconocido) | Vuelve a `ERROR` con **backoff exponencial**, no se descarta; backoff vencido reintenta sin gastar intento nuevo | `registrarError()` + `proximo_intento_at`; tests de backoff, agotamiento de intentos e idempotencia | ✓ |
| 4 | Rechazo definitivo (4xx / SUNAT paso 2) | `RECHAZADA`, sin reintento | Test "error 4xx rechaza la fila sin reintentar" / "sunat rechaza en el paso 2" | ✓ |
| 5 | Reanudar `send-sunat` | Guardar `api_doc_id` para no recrear el documento | `emitir()` persiste `api_doc_id` (`:91-93`); test "reanuda en el paso 2 sin recrear el documento" | ✓ |
| 6 | Doble número (índice único) | Nunca perder el hecho fiscal; se guarda ACEPTADO sin serie y se grita en logs | `aceptar()` captura `UniqueConstraintViolationException` (`:114-132`) | ✓ |
| 7 | **Link público HMAC** abre sin sesión | HMAC-SHA256 truncado sobre `cpe:{id}:{exp}`, `exp` en claro, `hash_equals`, expira | `CpeLinkService::token/verificar` (`CpeLinkService.php:39-75`); `ComprobanteColaPublicoController::autorizado` → 403 si firma/exp inválidos, sin `auth:sanctum` | ✓ |
| 8 | **NC** sobre el comprobante | Solo boleta/factura aceptada; `cod_motivo` ∈ {01,02,07}; tipo doc afectado 03(boleta)/01(factura); anti-duplicado | `ComprobanteColaController::notaCredito` (`:91-160`): valida motivo, referencia al original, bloquea NC duplicada | ✓ |
| 9 | Anular (solo boletas) | Factura se afecta con NC, no se anula; solo boletas aceptadas | `anular()` (`:171-197`) rechaza no-boletas y no-aceptadas con 422 | ✓ |
| 10 | Descargas PDF/XML/CDR | PDF inline (iframe), XML/CDR attachment; solo si `api_doc_id` + estado ACEPTADO/ANULADO | `ComprobanteColaPublicoController::descargar` (`:59-109`) | ✓ |
| 11 | Bandera **beta** (greenter apagado) | Emisión por greenter responde 410 mientras el flag esté apagado | Test `EmitirAhoraTest`: "la emision por greenter responde 410 mientras la bandera este apagada" | ✓ |
| 12 | Multi-emisor + configurar SUNAT | Resuelve config por tienda; sube cert PFX→PEM + credenciales SOL; secretos nunca en logs ni disco público | `ConfigurarSunatTest` (30+ casos): "resuelve la config de la tienda indicada multi emisor", "ningun secreto llega a los logs", "el certificado nunca se guarda en el disco publico" | ✓ |

### Evidencia

```
php artisan test --filter='ProcesarColaComprobantes|ComprobanteCola|ComprobanteCorrelativo|EmitirAhora|ConfigurarSunat|SincronizarLogo'
Tests: 131 passed (404 assertions)
```

### Veredicto Flujo 4: ✅ **PASA**

Emisión asíncrona, backoff, idempotencia, HMAC público, NC, anulación, descargas y multi-emisor son **fieles 1:1 al legacy**. La bandera beta bloquea correctamente el segundo camino (greenter) con 410. **Sin bugs.**

---

## FLUJO 5 — Comisiones (retención CUOTAS + escalonado por productividad)

**Regla legacy (§4 línea 146):** la comisión por venta depende del **acumulado mensual** del agente (rangos escalonados en `config_comisiones`/`comisiones_rangos`); upgrades/remates/prepago con reglas propias; **ventas a CUOTAS con comisión retenida hasta confirmar desembolso** (`confirmar_desembolso.php` la libera).

### Pasos ejecutados y esperado vs obtenido

| # | Paso | Esperado (legacy) | Obtenido | ✓ |
|---|------|-------------------|----------|---|
| 1 | Venta EQUIPO **CUOTAS** genera comisión | Comisión **diferida (0)** al registrar; `comision_estado=PENDIENTE` | `ComisionService::calcularComisionVenta` → `EQUIPO + tipo_pago=CUOTAS ⇒ 0.0` (`ComisionService.php:42-44`) | ✓ |
| 2 | Confirmar desembolso **libera** | `PENDIENTE → APROBADA`, fija `comision_generada` desde `EQUIPO_ESTANDAR` (default S/5), audita quién/cuándo | `PanelFinancierasController::confirmarDesembolso` (`:135-179`) | ✓ |
| 3 | Doble confirmación | 422 sin cambios | Test "doble confirmacion rechaza con 422" | ✓ |
| 4 | Revertir desembolso | `APROBADA → PENDIENTE`, `comision_generada=0` | `revertirDesembolso` (`:183-207`) | ✓ |
| 5 | Solo admin | 403 para tienda/vendedor | Test "solo admin puede confirmar desembolso" | ✓ |
| 6 | Recálculo escalonado **PLAN** por productividad mensual | Posición mensual del agente → `montoPorRango(rango_desde/hasta)` | `PlanillaController::calcularComisionesPlanes` + `montoPorRango` (`:349-438`); test "planilla aplica rangos mensuales de plan y equipo" espera `comision_planes=30.0` (10 el 1º + 20 el 2º) | ✓ |
| 7 | Recálculo operativo (bipay/krece/payjoy/recargas) | Rango por monto (`comisiones_rangos.monto_min/max`); recargas por % | `ComisionOperativaService::gananciaPorRango/gananciaRecargas` (`:66-90`) | ✓ |
| 8 | Recálculo masivo retroactivo | Actualiza ventas y líneas con tarifas actuales | Test "recalculo masivo actualiza ventas y lineas" | ✓ |
| 9 | Restaurar vendedor original | Auditoría como "edición restaurada" | `ReporteRestauracionComisionTest` (2 casos) | ✓ |

### Evidencia

```
php artisan test --filter='ComisionesEmpresaParidad|PanelFinancierasDesembolso|ReporteRestauracionComision'  → 12 passed (55 assertions)
php artisan test --filter='Planilla|PhaseBBusinessParity'                                                     → 16 passed (51 assertions)
```

### ⚠️ Observación → BUG-027B-01 (ver borradores de ticket)

El **corazón** del flujo 5 (retención CUOTAS) funciona a nivel del registro `ventas` y del Panel de Financieras. **Pero** el pago real en **planilla** de la comisión de equipos (`PlanillaController::calcularComisionesEquipo`, `:315-343`) **cuenta y paga los equipos CUOTAS aún PENDIENTES**: filtra solo `comision_estado != 'ANULADA'` (`:324`), y como un equipo pendiente tiene `comision_generada=0`, cae al `montoPorRango(...)`/fallback → **paga la comisión de un equipo cuyo desembolso todavía no se confirmó**. Verificado con los datos del propio `PhaseBBusinessParityTest` (equipo `comision_generada=0` ⇒ planilla paga el rango 7.0). Esto contradice la regla documentada "comisión retenida hasta confirmar desembolso". Además hay una **asimetría interna**: pendiente paga el escalonado (`montoPorRango`, puede ser > EQUIPO_ESTANDAR) y aprobado paga `EQUIPO_ESTANDAR` plano — confirmar el desembolso puede **reducir** la comisión pagada. Severidad **MEDIA** porque falta confirmar si el legacy `planilla_agentes.php` (PASO B EQUIPOS, `comision_agente ?: 5.00`, sin filtro de estado) tiene el mismo comportamiento — de ser así sería paridad de un defecto heredado, no una regresión.

### Veredicto Flujo 5: ⚠️ **PASA con observación** — reglas de cálculo escalonado y liberación por desembolso correctas; retención no efectiva en la planilla de equipos (BUG-027B-01, a confirmar contra legacy).

---

## FLUJO 6 — Asistencia (GPS / QR / PERMISO / salida automática)

**Regla legacy (§4 línea 149):** geocerca con precisión ponderada + anti-spoof; **QR HMAC rotativo 5s (ventana ±10s)**; **PERMISO genera deuda de 540 min**, FALTA_INJUSTIFICADA sin deuda de minutos, PERDONAR limpia; "salvavidas" semanal; salida automática nocturna por cron; fotos Base64 zero-retention.

### Pasos ejecutados y esperado vs obtenido

| # | Paso | Esperado (legacy) | Obtenido | ✓ |
|---|------|-------------------|----------|---|
| 1 | Marcación GPS dentro de geocerca | Registra entrada con tienda/horario del agente | Test "gps registra entrada con tienda seleccionada y horario del agente" | ✓ |
| 2 | GPS débil / fuera de geocerca | Rechaza y **registra el intento**; sugiere QR | Test "rechaza gps debil y registra el intento"; `AsistenciaController.php:157-190` | ✓ |
| 3 | Token de emergencia | Válido permite marcar fuera de rango; inválido **no** omite GPS | Tests "token emergencia valido/invalido" | ✓ |
| 4 | **QR válido** | Bloque 5s; HMAC-SHA256 `AST\|tienda\|bloque`; corresponde a la sede | `AsistenciaController.php:230-251`; test "qr debe corresponder a la tienda seleccionada" | ✓ |
| 5 | **QR expirado** | Ventana `±2 bloques (±10s)`; fuera → 422 "QR expirado" | `abs(bloqueActual - bloqueQr) > 2 ⇒ 422` (`:231-232`); generación con `ttl = 5 - (ts%5)` (`:671`) | ✓ |
| 6 | **Excepción PERMISO** | Inserta asistencia con **`minutos_deuda=540`** (9h) | `AsistenciaController::registrarExcepcion` (`:2069`): `PERMISO ? 540 : 0`. **Verificado en BD** con test efímero (assertDatabaseHas `minutos_deuda=540`) | ✓ |
| 7 | Excepción FALTA_INJUSTIFICADA | `minutos_deuda=0` | Verificado en BD (efímero) | ✓ |
| 8 | PERDONAR | Borra registro negativo del día | `:2044-2056`; verificado en BD (efímero) | ✓ |
| 9 | No duplica el día | 422 si ya existe asistencia esa fecha | `:2063-2064`; verificado en BD (efímero) | ✓ |
| 10 | **Salida automática nocturna** (`bitel:salida-automatica`) | Cierra `fecha<=hoy` tras **+90 min** de la salida programada; maneja turnos nocturnos; **no pisa** PERMISO/FALTA | `SalidaAutomaticaAsistencias` (`ESPERA_MINUTOS=90`, excluye `estado_asistencia IN (PERMISO,FALTA_INJUSTIFICADA)`); 7 tests verdes incl. "turno nocturno no se cierra prematuramente", "no pisa excepcion permiso" | ✓ |
| 11 | Fotos zero-retention | Auto-aprueba foto pendiente de día anterior y **borra archivo** | `LimpiarFotosAsistenciaTest` (3 casos) | ✓ |
| 12 | Radio de geocerca configurable | Entero > 0; CRUD tienda | `TiendaRadioGeocercaTest` (4 casos) | ✓ |
| 13 | Reactivación por permiso vencido | Agente inactivo por permiso largo vencido se reactiva al marcar; cesado no | Tests correspondientes en `AsistenciaTest` | ✓ |

### Evidencia

```
php artisan test --filter='Asistencia|ExcepcionJornada|SalidaAutomatica|TiendaRadioGeocerca|AutoRetorno'  → 45 passed (164 assertions)
# Test efímero PERMISO 540 (creado, ejecutado, borrado):
Tests: 1 passed (7 assertions)  → PERMISO=540, FALTA=0, no-duplica=422, PERDONAR limpia
```

### Nota de cobertura → OBS-027B-02

La ruta `POST /asistencias/excepcion` (`registrarExcepcion`, la que aplica la deuda de 540 min) **no tenía test Feature dedicado** en la suite permanente — su comportamiento se validó aquí con un test efímero. Se recomienda promover ese test a la suite (ver borrador OBS-027B-02). No es un bug de comportamiento: el código es correcto.

### Mejora sobre el legacy (no es bug)

`SalidaAutomaticaAsistencias` **previene en origen** que el auto-cierre pise las excepciones PERMISO/FALTA (excluyéndolas en el `WHERE`), eliminando la necesidad del script legacy `cron/reparar_excepciones_pisadas.php`. El comando de reparación (`RepararExcepcionesPisadas`) se conserva solo para datos ya dañados.

### Veredicto Flujo 6: ✅ **PASA** — GPS/geocerca, QR con ventana ±10s, PERMISO 540 min, salida automática nocturna y zero-retention de fotos fieles al legacy (con una mejora). **Sin bugs de comportamiento.**

---

## Borradores de ticket (bugs NO corregidos aquí)

### BUG-027B-01 — La retención de comisión CUOTAS no bloquea el pago en la planilla de equipos

- **Severidad:** MEDIA (confirmar contra legacy antes de subir a ALTA).
- **Módulo:** Comisiones / Planilla.
- **Archivo:línea:** `backend/app/Http/Controllers/Api/PlanillaController.php:315-343` (método `calcularComisionesEquipo`), en concreto el filtro `where('ventas.comision_estado', '!=', 'ANULADA')` (`:324`) y la caída a `montoPorRango(...)` cuando `comision_generada = 0` (`:337-339`). Relacionado: `PanelFinancierasController.php:160-170` (fija `comision_generada = EQUIPO_ESTANDAR` plano al aprobar).
- **Descripción:** Un equipo vendido a **CUOTAS** en estado `PENDIENTE` tiene `comision_generada = 0` (retención correcta al registrar, `ComisionService.php:42-44`). Sin embargo, la planilla lo **cuenta y le paga** la comisión de equipo vía `montoPorRango`/fallback, porque solo excluye `ANULADA`. Resultado: **se paga la comisión de un equipo cuyo desembolso aún no fue confirmado**, contradiciendo la regla §4 "comisión retenida hasta confirmar desembolso".
- **Escenario reproducible:** Agente con 1 equipo CUOTAS del mes en `PENDIENTE` + rango `EQUIPO` configurado (p. ej. `monto=7`). `GET /api/v1/planilla/{mes}` devuelve `comision_equipo = 7.0` para ese agente aunque nunca se confirmó el desembolso. (Mismo dataset que `PhaseBBusinessParityTest::test_planilla_aplica_rangos_mensuales_de_plan_y_equipo`, donde el equipo con `comision_generada=0` ya paga 7.0.)
- **Asimetría adicional:** pendiente paga el **escalonado** (`montoPorRango`, puede ser > `EQUIPO_ESTANDAR`), pero al aprobar el desembolso `comision_generada` queda en `EQUIPO_ESTANDAR` **plano** (`PanelFinancierasController.php:160`) → confirmar el desembolso puede **reducir** la comisión pagada respecto a tenerla pendiente. Es contraintuitivo y probablemente no intencional.
- **A verificar antes de corregir (paridad):** revisar `E:\laragon\www\sistema-rolando-salas\gerencia\planilla_agentes.php` PASO B EQUIPOS (`comision_agente ?: 5.00`, sin filtro de `comision_estado`) y `procesar_reporte.php`/`confirmar_desembolso.php`: determinar si el legacy también paga los CUOTAS pendientes (en cuyo caso es un defecto **heredado** y la decisión es si mantener paridad o corregir ambos) o si en el legacy el `equipos_accesorios.detalle` de un CUOTAS pendiente no existe/tiene comisión 0 hasta el desembolso.
- **Fix sugerido (si se decide corregir):** en `calcularComisionesEquipo`, excluir del cálculo los equipos con `venta_equipos.tipo_pago = 'CUOTAS'` y `comision_estado = 'PENDIENTE'` (o contar solo `APROBADA`/no-CUOTAS); y unificar el criterio pendiente-vs-aprobado para que el monto liberado use el mismo escalonado, no un plano distinto.

### OBS-027B-02 — Falta test Feature permanente para la deuda PERMISO de 540 min

- **Severidad:** BAJA (deuda de calidad, no de comportamiento).
- **Módulo:** Asistencia / tests.
- **Archivo:línea:** ruta `backend/routes/api.php:431` (`POST asistencias/excepcion` → `AsistenciaController::registrarExcepcion`, `:2027-2088`). No hay test en `tests/Feature/` que ejerza esta ruta. `ExcepcionJornadaTest` cubre otra ruta (`excepcion-jornada`, toggle comodín) y no toca la deuda de 540.
- **Descripción:** La regla crítica "PERMISO ⇒ `minutos_deuda=540`, FALTA ⇒ 0, PERDONAR limpia, no-duplica día" solo se validó en este QA con un test efímero (borrado). Debe existir un test permanente para blindarla contra regresiones.
- **Acción sugerida:** promover el test efímero de este QA a `tests/Feature/RegistrarExcepcionAsistenciaTest.php` (admin-only 403, PERMISO→540, FALTA→0, duplicado→422, PERDONAR→borra).

---

## Cierre

- **Flujo 4:** ✅ PASA (131 tests).
- **Flujo 5:** ⚠️ PASA con observación → **BUG-027B-01** (MEDIO, a confirmar paridad).
- **Flujo 6:** ✅ PASA (45+1 tests) + 1 mejora sobre legacy → **OBS-027B-02** (BAJA, cobertura).
- **Estado del entorno:** sin procesos dejados corriendo (todo PHPUnit `:memory:`); ningún archivo nuevo en `tests/` (efímeros borrados); no se tocaron archivos de la mitad A; sin commit/push.
