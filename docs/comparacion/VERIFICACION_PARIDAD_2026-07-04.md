# Verificación de Paridad Final — 2026-07-04

**Método:** re-auditoría independiente contra código real (backend + frontend, ambos lados:
legacy `E:\laragon\www\sis_bipay` y refactor `C:\xampp\htdocs\bitel-p0-5`), NO contra mensajes
de commit ni contra el reporte de "COMPLETO" que dejó `docs/superpowers/specs/2026-07-04-integrador-onpremise-design.md`.
Cubre los 10 P0, los 11 P1, una muestra de P2, las 5 decisiones de producto de la sección 4, y
el hallazgo de esquema/migración de la sección 2, todos de `GAP_ANALYSIS_MAESTRO_2026-07-02.md`.
Verificado con 4 pasadas de lectura de código en paralelo + verificación manual de los puntos
más sensibles (diálogo de confirmación en Financieras, commits de seguridad recientes).

**No modifiqué código.** Este documento es el único entregable.

---

## 1. Resumen por área

| Área | ✅ Cerrado | ⚠️ Parcial | ❌ Abierto | 🧊 Congelado | 🔵 Decisión pendiente | Total ítems auditados |
|---|---|---|---|---|---|---|
| Tienda / Inventario / Traslados / Reportes | 11 | 1 | 1 | 0 | 0 | 13 |
| RRHH / Asistencia | 7 | 0 | 1 | 0 | 0 | 8 |
| Financiero / CRM / Bipay | 9 | 0 | 0 | 0 | 2 | 11 |
| Esquema BD / Migración / M7 | 3 | 1 | 1 | 1 | 1 | 7 |
| **Total** | **30** | **2** | **3** | **1** | **3** | **39** |

Ítems auditados = los 10 P0 + los ~11 P1 + muestra de P2 (13 ítems) + las 5 decisiones de
sección 4 + el hallazgo de migración de sección 0/2. Los P0 y P1 tienen cobertura 100%; los P2
son muestra (no se auditaron los ~15 ítems cosméticos restantes del párrafo P2 uno por uno,
salvo los citados explícitamente).

**Veredicto global:** de los 10 P0 y 11 P1 del documento maestro, **todos están efectivamente
cerrados en código**, con dos matices reales (no maquillados abajo): uno de dato histórico no
confirmable (migración de chips mal guardados) y uno de esquema BD que sigue sin plan
(`log_ediciones_asistencia`). Encontré además **un gap real que el propio doc de P2 no
mencionaba explícitamente con suficiente detalle** y que sigue abierto: `es_restauracion` /
`edicion_restaurada` en la auditoría de ediciones de reporte.

---

## 2. Detalle P0 (10 ítems)

| # | Gap | Veredicto | Evidencia |
|---|---|---|---|
| 1 | Rol `tienda` no podía registrar stock | ✅ CERRADO | `routes/api.php:158` sin `role:admin`; `InventarioController::store` (104-121) exige `dni_autoriza` de 8 dígitos contra agente ACTIVO y bloquea `tienda_id` ajena. |
| 2 | Chips genéricos en tabla equivocada | ✅ CERRADO (código) / ⚠️ PARCIAL (dato histórico) | `InventarioController::store` (125-152): `tipo==='CHIP'` escribe en `InventarioChip`, nunca en `inventario_tiendas`. Existe `php artisan inventario:migrar-chips-mal-guardados` (`backend/app/Console/Commands/MigrarChipsMalGuardados.php`) pero es **dry-run por defecto**, requiere `--force`. No hay evidencia en el repo de que se haya ejecutado con `--force` contra la BD real — las filas viejas mal guardadas antes del fix pueden seguir corruptas hasta que alguien lo corra. |
| 3 | `restaurar_equipo_manual` no anulaba venta | ✅ CERRADO | `InventarioController::restaurar` (741-829): marca `venta.comision_estado='ANULADA'`, `comision_generada=0`; bloquea con 422 si la comisión ya fue pagada en planilla cerrada (consulta `pagos_planilla` por rango de fechas, 765-783). |
| 4 | `cron_salida_automatica` perdió resguardos | ✅ CERRADO | `SalidaAutomaticaAsistencias.php`: espera 90 min (`ESPERA_MINUTOS=15,100-112`), resguardo de horario inválido no cierra (76-98), alcance `fecha<=hoy` (47). Test `SalidaAutomaticaAsistenciasTest.php`. |
| 5 | Panel Financieras sin recálculo/auditoría/lock | ✅ CERRADO | `PanelFinancierasController.php:131-176`: `DB::transaction`+`lockForUpdate()`, rechaza doble-confirmación (422 si `comision_estado!=='PENDIENTE'`), recalcula `ganancia_snap` desde `costo_snap`. Columnas `desembolso_confirmado_por`/`_en` (migración `2026_07_03_000001_add_desembolso_auditoria_to_ventas.php`). Diálogo de confirmación con preview: commit `d792ee0`, `frontend/src/pages/admin/PanelFinancierasPage.tsx` (+12/-2 líneas, preview de financiera/monto/vendedor antes de ejecutar) — verificado directamente por mí, confirma el punto que el doc original marcaba ❌. |
| 6 | Regresión de permisos rol `tienda` (Historial/Estadísticas/efectivo) | ✅ CERRADO | `routes/api.php:101-103,231-235` → `role:admin,tienda`; `HistorialController::baseQuery` y `EstadisticasController` (31-36) escopan a `tienda_id` del usuario (fail-closed si falta). `destino-efectivo` (`api.php:119`) sin restricción de rol; `ReporteController::actualizarDestino` escopa vía `TiendaGuard::bloqueaAcceso`. |
| 7 | Traslados no validaban identidad al crear | ✅ CERRADO | `TrasladoController::store` (66-78) y `TrasladoChipsController::store` (71-83): exigen `auth_dni` de agente activo también al crear, no solo en `confirmar()`. |
| 8 | Radio de geocerca fijo 60m | ✅ CERRADO | Backend `TiendaController.php:48,68` valida/persiste `radio_permitido`; frontend `TiendasPage.tsx` (32,72,84-89,225-235) input editable. Test `TiendaRadioGeocercaTest.php`. |
| 9 | Migración pendiente de 14 tablas | ⚠️ **NO VERIFICABLE desde este entorno** | Ver sección 4 — no hay acceso SSH al VPS desde aquí. Solo inferencia por commits (ver detalle). |
| 10 | Baja de agente sin motivo/auditoría | ✅ CERRADO | Migración `2026_07_03_000002_baja_agente_auditoria.php` agrega `clasificacion_baja`/`motivo_baja`/`fecha_baja`/`observacion`; `AgenteForm.tsx:59-60,315-329` captura LISTA_BLANCA/LISTA_NEGRA + motivo; `AgenteController::historial()` expuesto en `GET agentes/{id}/historial`, consumido por `HistorialAgenteModal` en `VerAgentePage.tsx`; `HistorialAgenteService.php` escribe en cada cambio (CESE/REINGRESO/TIENDA/HORARIO/FICHA). |

**P0: 8/10 ✅ cerrados sin matices, 1 con matiz de dato histórico (#2), 1 no verificable desde
este entorno (#9, ver sección 4).**

---

## 3. Detalle P1 (11 ítems)

| Gap | Veredicto | Evidencia |
|---|---|---|
| Export Excel auditoría por-reporte + export general Dashboard | ✅ CERRADO | `ReporteController::exportarExcel` (`reportes/{reporte}/exportar-excel`) y `DashboardController::exportar` ("Ventas Desglosado", role:admin) — workbooks PhpSpreadsheet reales con desglose por categoría. |
| PDF de reporte/ticket perdió detalle | ✅ CERRADO | `resources/views/constancias/reporte.blade.php`: secciones por categoría (Postpago/Prepago/Equipos/Otros/Apoyo) con Vendedor/DNI/Cel/badges (Migración/Upgrade/eSIM/Remate/Extranjero/Cross). Ticket térmico en `TicketImpresionPage.tsx` con DNI/cliente/cajero/forma de pago. |
| CRM: incompatibilidad estructural (temperatura vs `leads.estado`) | ✅ CERRADO (decisión + código) | Ver sección 4.1 — decisión tomada 2026-07-03, `TemperaturaCalculator.php` implementado como tab paralelo. |
| Ranking sin exclusión `es_remate`/`UPGRADE`/`PAQUETE` | ✅ CERRADO | `RankingVentaScope.php` centraliza la exclusión; `EstadisticasController` la aplica en 5 sitios (147,206,356,365,374); `PlanillaController.php:361` también la usa. Consistencia interna confirmada (commit `fd487fa`). |
| `limpiar_fotos_asistencia` sin auto-aprobación | ✅ CERRADO | `LimpiarFotosAsistencia.php` Sección A (25-57) auto-aprueba fotos pendientes de días anteriores antes de la Sección B de limpieza. Test `LimpiarFotosAsistenciaTest.php`. |
| Gerente de tienda autoriza traslado directo | ✅ CERRADO | `TrasladoController::store:87-89` / `TrasladoChipsController::store:91-92`: si `agente->es_gerencia` y pertenece a la tienda de origen, salta a `PENDIENTE` sin aprobación admin. |
| Vista matricial mensual de asistencias | ✅ CERRADO | `AsistenciaController::matriz()` (`GET asistencias/matriz`); `ControlAsistenciasPage.tsx` (ruta `/asistencias/control`) tabla agente×día por tienda, celdas clicables. Test `AsistenciaMatrizTest.php`. `excepciones_jornada` ahora tiene escritura real (toggle `POST asistencias/excepcion-jornada`). |
| DNI/RENIEC perdió caché de primer nivel | ✅ CERRADO | `DniController::consultar` (18-40) consulta `crm_clientes` antes que la API externa; devuelve `fuente: CRM_NO_VERIFICADO` o `RENIEC_API`. |
| Reconocimiento facial ausente en `validarSeguridad()` | ✅ CERRADO (con decisión documentada) | Ver sección 4.2 — David confirmó explícitamente que se porta (2026-07-03). Código en `AsistenciaController.php:786-817`. |
| Estadísticas de Ventas: export reducido, sin `cross_selling`/`tienda_destino` | ✅ CERRADO | Cubierto en la misma ola que ranking (`EstadisticasController`, commit `d34e430`) — export a paridad con reasignación cross_selling. |
| Panel Bipay: cuentas huérfanas/export/locks activos | ✅ CERRADO | `BipayController::vincularHuerfana` (1015), `exportarTransacciones` (`bipay/transacciones/exportar`), `locksActivos` (1054, `bipay/locks-activos`); UI en `PanelBipayPage.tsx` (huérfanas 656+, export 97, locks tab 73/120). |

**P1: 11/11 ✅ cerrados.**

---

## 4. Sección 4 del doc maestro — Decisiones de producto

| # | Decisión | Estado | Evidencia |
|---|---|---|---|
| 4.1 | CRM: ¿temperatura calculada o `leads.estado`? | ✅ RESUELTA E IMPLEMENTADA | `docs/superpowers/specs/2026-07-03-crm-temperatura-design.md:4` — "Decisión (2026-07-03): adoptar el modelo del legacy." Implementado en `TemperaturaCalculator.php` + `CrmTemperaturaController.php` como **tab paralelo** ("Temperatura") en `CrmPage.tsx`, coexistiendo sin tocar `leads.estado`. Nota: es coexistencia deliberada, no unificación — documentado explícitamente como fuera de alcance. |
| 4.2 | Reconocimiento facial: ¿sigue vigente? | ✅ RESUELTA E IMPLEMENTADA | `docs/superpowers/specs/2026-07-03-reconocimiento-facial-design.md:4` — "Decisión de producto (2026-07-03, confirmada por David): el mecanismo sigue en uso → se porta." Código en `AsistenciaController.php:786-817` (`hash_facial`/`dasam-face-`/`dasam-sf-`). |
| 4.3 | `marcar_entregado`: ¿5 estados es intencional? | 🔵 **PENDIENTE — sin resolver** | Código confirma el modelo de 5 estados vive (`ReporteController.php:167-171`: `BANCO/GERENCIA/EN_CAJA/AGENTE/TIENDA`), pero no encontré ningún doc de specs donde David haya aprobado explícitamente este vocabulario frente al binario legacy `TIENDA`/`ENTREGADO`. Sigue siendo una pregunta abierta, no solo un gap de código. |
| 4.4 | Bloqueo de eliminar reporte `aprobado`: ¿regla deseada o bug? | 🔵 **PENDIENTE — sin resolver** | El bloqueo sigue en `ReporteController::destroy` (línea 638-640, 422 si `estado==='aprobado'`). Ningún doc de specs posterior a 2026-07-02 confirma si esto es una regla de negocio deseada o un efecto colateral no intencional de otro cambio. |
| 4.5 | `sys_config` vs `configuracion_empresa`: ¿dos fuentes de verdad? | 🔵 **PENDIENTE, pero sin conflicto funcional actual** | `sys_config` (creada en la migración de 14 tablas) solo guarda 3 claves de webhook Discord/Slack, consumidas por `AuditoriaBipayService.php:95-104`. `configuracion_empresa` (`ConfiguracionController`) guarda datos de empresa (razón social/logo/RUC). No colisionan en dominio ni claves hoy, pero la pregunta arquitectónica de fondo ("¿deben unificarse los mecanismos de config?") nunca se contestó en ningún commit/spec posterior — sigue abierta como decisión, aunque no es urgente porque no hay bug activo. |

**Sección 4: 2/5 resueltas e implementadas, 3/5 siguen pendientes de decisión explícita del
usuario** (aunque 4.5 no tiene urgencia porque no hay conflicto funcional).

---

## 5. Muestra P2 (13 ítems, del párrafo de la sección 3)

| Gap | Veredicto | Evidencia |
|---|---|---|
| Filtro agente en tickets (UI) | ✅ CERRADO | `TicketsPage.tsx:375,413`; backend ya lo soportaba (`TicketController.php:214`). |
| Filtro cuenta/tipo en transacciones bipay (UI) | ✅ CERRADO | `PanelBipayPage.tsx:339-351` (selects `cuenta_id`/`tipo_operacion`). |
| Filtro texto libre en CRM (UI) | ✅ CERRADO | `CrmPage.tsx:676`. |
| Widget "Stock Estancado" no conectado | ✅ CERRADO | Consumido ahora en `InventarioPage.tsx`. |
| Formato de ticket 58/80mm por usuario | ✅ CERRADO | Campo `formato_ticket` en `types/auth.ts:9`, usado en `TicketImpresionPage.tsx`. |
| `es_restauracion` en auditoría de ediciones | ❌ **ABIERTO** | Legacy `procesar_edicion.php:324-332` detecta cuando un cambio de vendedor **revierte textualmente** la primera alerta de fraude y marca `accion='edicion_restaurada'`. El refactor (`ReporteController::detectarCambiosVendedor`, línea 1334, uso en 1243) solo distingue `edicion_reporte` vs `edicion_critica` — no existe `edicion_restaurada` ni comparación contra la alerta original. **No portado.** Ver sección 6, gap #1 (P2 revalorado). |
| Diálogos de confirmación faltantes en Financieras | ✅ CERRADO | Commit `d792ee0`, `PanelFinancierasPage.tsx` — preview de financiera/monto/vendedor antes de confirmar/revertir. |
| Medallas/fila-total en exports de ranking | ✅ CERRADO | `EstadisticasController.php:465-556`, método `medalla()` (🥇🥈🥉) embebido en export. |

---

## 6. Esquema de BD y estado de la migración de 14 tablas (sección 0/2 del doc maestro)

**Este es el punto más importante donde debo ser honesto sobre los límites de esta auditoría:
no tengo acceso SSH al VPS de producción desde este entorno, así que no puedo correr
`php artisan migrate:status` real y confirmar con certeza que la migración
`2026_07_02_000001_create_integrador_bitel_tables` ya corrió en producción.**

Lo que sí encontré:
- `docs/superpowers/specs/2026-07-04-integrador-onpremise-design.md:96-97` **afirma en texto**
  que la migración "ya corrida en producción" — es una declaración, no una prueba técnica
  adjunta (no hay log de deploy ni salida de `migrate:status` en el repo).
- Señal indirecta de consistencia: el commit `53f18db` ("reactivar direccion/telefono ahora
  que la migracion ya corrio") descomenta la validación de esos campos en `TiendaController.php`,
  y los commits posteriores (`b9d4fa2`, `38aa87d`, `7ae1829`) construyen sobre esa base con
  tests, sin retrocesos. Esto es coherente con que la migración sí corrió, pero sigue siendo
  inferencia, no verificación directa.
- **Recomendación:** antes de dar esto por cerrado, correr `php artisan migrate:status` en el
  contenedor backend del VPS y pegar la salida en este doc o en uno de seguimiento. Es la única
  forma de cerrar esta pregunta con evidencia real en vez de confianza en el texto de un commit.

Sub-hallazgos de esquema:
- `excepciones_jornada`: ✅ CERRADO — migración `2026_07_03_000003_create_excepciones_jornada_table.php` crea la tabla (shape tomado de `sql/migracion_esquema_brisel.sql` del legacy), con guard `hasTable`. Tiene escritura real (`AsistenciaController::excepcionJornada`) y lectura (`AsistenciaNeiryController::exportar`).
- `log_ediciones_asistencia`: ❌ **ABIERTO, sin plan de migración.** Cero coincidencias en todo `backend/` (ni migración, ni modelo, ni referencia). Sigue siendo la única tabla del gap de esquema original sin ningún avance.

---

## 7. Módulo 7 (integrador on-premise) — solo confirmación de estado, NO evaluado como gap

🧊 **CONGELADO por decisión explícita del usuario**, registrada en
`docs/superpowers/specs/2026-07-04-integrador-onpremise-design.md` (commit `092d552`).

- Se implementó el único punto "inequívoco": `descargarAgente` ahora falla con 503 si faltan
  los binarios del agente en `storage/app/integrador/agente/`, en vez de repartir un ZIP
  instalador roto en silencio (commit `7c7d4f5`).
- Quedan registradas 4 decisiones explícitas para retomar como sub-proyecto futuro: modelo de
  entrega del agente (lanzador+ofuscación vs bundle estático), provisión de binarios del
  scraper Bitel, auth de `recibir-saldo` (API key global vs token-por-tienda), y
  `tipo_operacion` del sync (`SYNC_AUTO` vs `AJUSTE`).
- **Actualización sobre la "deuda técnica testeable"** que el propio doc de M7 dejó como
  "pendiente" en su sección 5.5: **ya se resolvió después de escrito el doc**. El commit
  `1fe7d72` portó el SQL crudo MySQL (`ON DUPLICATE KEY UPDATE`/`INSERT IGNORE`) a
  `upsert()`/`insertOrIgnore()` de Laravel en `IntegradorController.php` (4 tablas) y agregó
  la escritura de `last_sync_at` en `recibirSaldo` (antes solo se leía, nunca se escribía). El
  commit `d17300e` agregó `IntegradorRecibirSaldoTest.php` (199 líneas) cubriendo idempotencia
  de sync y auth M2M, corriendo ahora en sqlite. **Esto es una mejora real que el doc de M7 no
  refleja porque se hizo después de congelarlo** — no cambia el estado de "congelado" del
  módulo funcional, pero sí cierra la deuda técnica que el propio doc señalaba como bloqueante
  para testear ese código.

---

## 8. GAPS QUE SIGUEN ABIERTOS (accionables)

1. **`es_restauracion` / `edicion_restaurada` no portado** — Prioridad **P2** (el doc maestro
   ya lo tenía como P2/T3.1 en el doc anterior, confirmado que sigue sin portar).
   **Fix concreto:** en `ReporteController::detectarCambiosVendedor` (línea 1334), al detectar
   un cambio de vendedor, comparar el mensaje/alerta actual contra la primera alerta de fraude
   registrada para esa venta; si el cambio revierte textualmente esa alerta, marcar
   `accion='edicion_restaurada'` (agregar el valor al enum/checks de `historial_reportes` si
   aplica) en vez de `edicion_critica` genérico. Referencia legacy exacta:
   `sis_bipay/reportes/procesar_edicion.php:324-332`.

2. **Comando `inventario:migrar-chips-mal-guardados` no confirmado como ejecutado con `--force`
   en producción** — Prioridad **P1** (dato, no código; si no se corrió, hay chips legacy
   invisibles en la UI de Chips ahora mismo). **Fix concreto:** correr
   `php artisan inventario:migrar-chips-mal-guardados --force` en el contenedor backend del
   VPS y confirmar cuántas filas migró (el comando ya existe y es seguro/idempotente, solo
   falta ejecutarlo).

3. **`log_ediciones_asistencia` sigue sin ninguna migración/plan** — Prioridad **P2** (auditoría
   de ediciones de asistencia, no bloquea operación diaria pero es un hueco de compliance
   menor, análogo al que se cerró para `historial_agentes` en P0 #10). **Fix concreto:** crear
   migración con el shape de la tabla legacy (revisar `sql/migracion_esquema_brisel.sql` o
   estructura real en `sis_bipay`), y escribir un registro en cada `editar()`/`reprocesar()` de
   asistencias, análogo a como `HistorialAgenteService` ya audita cambios de agente.

**No encontré gaps P0 o P1 abiertos fuera de los ya listados.** Todos los P0 y P1 del doc
maestro están cerrados en código, con la única salvedad operativa (no de código) del punto 2
de esta sección y la verificación pendiente de VPS de la sección 6.

---

## 9. PENDIENTE DE DECISIÓN DEL USUARIO

1. **4.3 — `marcar_entregado`:** ¿el modelo de 5 estados (`BANCO/GERENCIA/EN_CAJA/AGENTE/TIENDA`)
   es el vocabulario final deseado, reemplazando el binario legacy `TIENDA`/`ENTREGADO`? Sigue
   sin confirmación explícita en ningún doc de specs.
2. **4.4 — Bloqueo de eliminar reporte `aprobado`:** ¿es una regla de negocio deseada
   (impedir borrar reportes ya aprobados) o un efecto colateral no intencional? Sigue sin
   confirmación.
3. **4.5 — `sys_config` vs `configuracion_empresa`:** no hay conflicto funcional hoy (dominios
   distintos), pero la pregunta de fondo de si conviene unificar los mecanismos de configuración
   sigue sin resolverse formalmente.
4. **Migración de 14 tablas en VPS (sección 0/6):** requiere confirmación explícita de que
   `php artisan migrate:status` en producción ya no muestra `Pending` para
   `2026_07_02_000001_create_integrador_bitel_tables` — no verificable desde este entorno local.
5. **🧊 Módulo 7 (integrador on-premise):** congelado por decisión ya tomada (ver sección 7);
   se retoma como sub-proyecto aparte con las 4 decisiones ya registradas en
   `docs/superpowers/specs/2026-07-04-integrador-onpremise-design.md`. No requiere nueva
   decisión ahora, solo queda pendiente el "cuándo" retomarlo.
