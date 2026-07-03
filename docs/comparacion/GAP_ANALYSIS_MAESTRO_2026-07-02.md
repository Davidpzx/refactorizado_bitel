# Gap Analysis Maestro — sis_bipay (fuente) → refactorizado_bitel (destino)

**Fecha:** 2026-07-02
**Método:** 4 subagentes en paralelo (lectura de código real, no inferencia por nombre) +
verificación directa de esquema de BD por SSH contra el VPS de producción.
**Reemplaza a:** `PARIDAD_MASTER.md` (14-jun) — confirmado desactualizado y con sesgo
sistemático a marcar ✅ donde el código actual muestra ⚠️/❌.

Fuentes crudas de este documento (mismo directorio, no borrar — contienen el detalle completo por feature):
- `gap_gerencia_financiero_2026-07-02.md` — reportes/cuadre, financieras, bipay, BCP, boletas, dashboard, CRM, heatmap.
- `gap_gerencia_rrhh_stock_2026-07-02.md` — agentes, asistencias-admin, usuarios, tiendas, comisiones, planilla, inventario-admin.
- `gap_tienda_reportes_2026-07-02.md` — inventario tienda, chips, traslados, nuevo_reporte/cuadre diario, tickets.
- `gap_api_cron_auth_2026-07-02.md` — endpoints API, crons, auth, pilares (churn/morosidad/heatmap/histórico).
- `gap_db_schema_2026-07-02.md` — comparación de tablas de BD entre `sis-bipay` y `migracion`.

**Fuera de alcance de este análisis (por decisión explícita del usuario):** el módulo del
integrador/agente on-premise (`bitel_bipay_integrador_completo/`, `descargar_agente.php`,
`agente_codigo.php`, `lanzador.php`) se aborda como sub-proyecto aparte, al final.

---

## 0. HALLAZGO MÁS IMPORTANTE — antes de leer nada más

**La migración `2026_07_02_000001_create_integrador_bitel_tables` existe en el código pero
nunca se corrió en el VPS (`php artisan migrate:status` → `Pending`).** Crea 14 tablas:
`integrador_credenciales`, `bitel_movimientos_diarios`, `bitel_operaciones_detalle`,
`bitel_apoyos`, `bitel_historico_queue`, `solicitudes_extraccion`, `clientes_estado`,
`lineas_morosidad`, `tesoreria_clasificacion`, `auditoria_cierres`, `sys_config`,
`log_resolucion_atribucion`, `crm_clientes`, `crm_interacciones`. También hay una migración
menor pendiente: `2026_06_20_000001_add_direccion_telefono_to_tiendas`.

**Impacto real confirmado:** varios controllers YA ESCRITOS referencian estas tablas y
**fallarían en producción ahora mismo** si se invocan:
- `ClienteCrmController` (RENIEC + caché + regla portabilidad) → usa `crm_clientes`/`crm_interacciones`.
- `AuditoriaBipayController` / `AuditoriaBipayService` (cierre nocturno, webhooks) → usa `auditoria_cierres`, `log_resolucion_atribucion`, `sys_config`.
- `IntegradorController` (morosidad/churn on-demand, recibir-saldo, credenciales) → usa las 14 tablas.

Los subagentes de gap analysis calificaron varias de estas features como "✅ paridad
completa" evaluando solo el código — **sin poder saber que la tabla no existe en el VPS**.
Ese ✅ es correcto a nivel de código, pero la feature está **actualmente rota en producción**.

**Acción recomendada — NO ejecutada, requiere tu confirmación explícita** (es una migración
de esquema sobre la BD de producción): correr `php artisan migrate` en el contenedor backend
del VPS. Es aditiva (solo `CREATE TABLE`, no toca tablas existentes), pero aun así es una
escritura sobre BD compartida y debe confirmarse antes de ejecutar. Esto resolvería de un
solo golpe una fracción importante de los gaps de "Pilares" y CRM listados abajo, sin
necesitar ninguna línea de código nueva.

---

## 1. Totales consolidados (221 features evaluadas)

| Área | ✅ Completo | ⚠️ Parcial | ❌ Falta | 🔁 N/A | Total |
|---|---|---|---|---|---|
| Gerencia financiero/reporting | 24 | 39 | 28 | 9 | ~100 |
| Gerencia RRHH/stock-admin | 31 | 4 | 3 | 1 | 39 |
| Tienda + Reportes | 36 | 8 | 1 | 1 | 46 |
| API + Cron + Auth | 21 | 8 | 0 | 7 | 36 |
| **Total** | **112** | **59** | **32** | **18** | **221** |

Paridad cruda: ~51% ✅. Pero **el área más portada (RRHH/stock, 79% ✅) es la que menos se
usa a diario para plata**; el área con más plata en juego (financiero/reporting) es la que
menos paridad tiene (24% ✅, 67% ⚠️+❌). La prioridad no debe seguir el % de paridad, sino el
impacto de negocio.

## 2. Gap de esquema de BD (verificado por SSH, VPS producción)

Ambas bases en el mismo MySQL del VPS (`briselmaquerabitel-dbbitelbris-btkuij`). `sis-bipay`
(fuente, 52 tablas) vs `migracion` (destino, 56 tablas antes de migrar lo pendiente).

- **14 tablas se resuelven solas al correr la migración pendiente** (sección 0).
- **2 tablas realmente sin plan de migración conocido:** `log_ediciones_asistencia` (auditoría
  de ediciones de asistencia) y `excepciones_jornada` (ya tiene consumidor de LECTURA en
  `AsistenciaNeiryController` pero ninguna tabla creada — verificar si vive con otro nombre o
  si de verdad falta la migración).
- Confirmar si `sys_config` reemplaza o convive con el mecanismo de config actual del refactor
  (`ConfiguracionController`/tabla `configuracion_empresa`) — evitar dos fuentes de verdad.

## 3. Gaps priorizados por impacto de negocio (no por conteo)

### P0 — Bloquean operación diaria o corrompen datos/plata (arreglar primero)

| # | Gap | Área | Por qué es P0 |
|---|---|---|---|
| 1 | Rol `tienda` no puede registrar stock nuevo (`POST /inventario` es `role:admin`) | Tienda | Es la tarea diaria del agente de tienda en legacy; ahora mismo bloqueada. |
| 2 | Chips creados vía formulario genérico se guardan en `inventario_tiendas` en vez de `inventario_chips` | Tienda | Corrupción de datos silenciosa — el chip queda invisible en la UI de Chips. |
| 3 | `restaurar_equipo_manual` no anula la venta asociada | API/Inventario | El equipo vuelve a stock pero la venta sigue contando en reportes/comisiones — comisión pagada sobre venta "revertida". |
| 4 | `cron_salida_automatica` perdió espera de 90min + resguardo de horario inválido + alcance a días anteriores | Cron/Asistencia | Riesgo de cerrar turnos en curso o con horario corrupto, especialmente turnos nocturnos. |
| 5 | Panel Financieras sin recálculo de ganancia, sin auditoría de quién/cuándo confirmó desembolso, sin lock transaccional | Financiero | Riesgo de doble-confirmación y ganancia congelada con costo viejo. |
| 6 | Regresión de permisos: rol `tienda` perdió Historial Completo, Estadísticas de Ventas, y marcar/revertir su propio efectivo entregado | Financiero/Reportes | Estas 3 capacidades existían en legacy; hoy el cajero de tienda no puede hacer su trabajo normal en esas pantallas. |
| 7 | Traslados (equipos y chips) no validan identidad del agente emisor al crear, solo al confirmar recepción | Tienda | Hueco de auditoría/seguridad — cualquier texto pasa como DNI autorizante al enviar. |
| 8 | Radio de geocerca por tienda no editable (fijo 60m) | RRHH | Config crítica para que el marcado de asistencia GPS sea correcto por tienda. |
| 9 | Migración pendiente de 14 tablas (sección 0) — bloquea CRM, auditoría de cierre nocturno, pilares churn/morosidad | Transversal | Ver sección 0. Posiblemente el fix más barato de todo este documento. |
| 10 | Baja de agente sin motivo/clasificación + sin historial de auditoría (`historial_agentes` solo se escribe, nunca se lee) | RRHH | Compliance — no se puede documentar por qué se dio de baja a alguien. |

### P1 — Importantes, no bloquean el día a día pero son pérdida de funcionalidad real

- Exportación de auditoría Excel por-reporte (`exportar_excel.php`) y exportación general del Dashboard: sin equivalente.
- PDF de impresión de reporte y de ticket: perdieron todo el detalle por categoría/vendedor/DNI/badges.
- CRM: incompatibilidad estructural (temperatura calculada vs pipeline `leads.estado` manual) — requiere decisión de producto, no solo código (ver sección 4).
- Ranking de agentes no excluye `es_remate`/`UPGRADE`/`PAQUETE` (sí lo hacen Planilla/Postpago en el mismo backend — inconsistencia interna).
- `limpiar_fotos_asistencia` sin auto-aprobación de fotos pendientes de días anteriores (Sección A).
- Regla "gerente de tienda autoriza traslado directo sin aprobación admin" no portada.
- Vista matricial mensual de asistencias (`control_asistencias.php`) no portada; `excepciones_jornada` no tiene escritura.
- DNI/RENIEC perdió el primer nivel de caché (consulta directa a `crm_clientes` antes de la API externa) — depende también de la migración pendiente.
- Reconocimiento facial (`hash_facial`/`dasam-face-`) ausente en `validarSeguridad()` — **confirmar con negocio si sigue vigente** antes de portarlo.
- Estadísticas de Ventas: export Excel reducido, sin reasignación `cross_selling`/`tienda_destino`.
- Panel Bipay: cuentas huérfanas sin UI, sin exportación de historial de transacciones, sin vista de "locks activos".

### P2 — Menores / cosméticos

Filtros de UI donde el backend ya soporta el parámetro (agente en tickets, cuenta/tipo en
transacciones bipay, texto libre en CRM), widget "Stock Estancado" no conectado en frontend,
formato de ticket 58/80mm por usuario, "es_restauracion" en auditoría de ediciones, diálogos
de confirmación faltantes en Financieras, medallas/fila-total en exports.

## 4. Requiere decisión de producto antes de poder planificarse (no son bugs, son ambigüedad)

1. **CRM**: ¿se quiere recuperar el modelo de "temperatura calculada" del legacy (reglas
   heurísticas Caliente/Frío/Upselling sobre interacciones), o el pipeline `leads.estado`
   manual del refactor es el rumbo deseado y el legacy se abandona? Son incompatibles, no se
   puede "portar" sin elegir.
2. **Reconocimiento facial en asistencia** (`hash_facial`): ¿sigue en uso por algún cliente/tienda o fue descontinuado?
3. **`marcar_entregado` — dominio de valores**: legacy es binario `TIENDA`/`ENTREGADO`; destino ya usa 5 estados (`BANCO/GERENCIA/EN_CAJA/AGENTE/TIENDA`). ¿Es un cambio de negocio intencional a mantener, o hay que decidir el vocabulario final?
4. **Bloqueo nuevo de eliminar reporte si `estado==='aprobado'`**: no existía en legacy — ¿regla de negocio deseada o error de implementación?
5. **`sys_config` vs `configuracion_empresa`**: evitar dos mecanismos de configuración paralelos.

## 5. Propuesta de descomposición en módulos de implementación

Dado el tamaño (~91 gaps reales entre ⚠️ y ❌), cada módulo abajo es su propio ciclo
spec → plan → Codex implementa → verificación, en este orden sugerido:

1. **Módulo 0 (operación, no código):** correr la migración pendiente + confirmar las 5
   decisiones de producto de la sección 4. Desbloquea la evaluación real de varios "gaps".
2. **Módulo 1 — Integridad de datos y permisos críticos** (los 10 ítems P0, menos el 9 que es el Módulo 0).
3. **Módulo 2 — Financiero/Reportes**: exports Excel/PDF faltantes, Panel Financieras, Historial/Estadísticas (acceso rol tienda).
4. **Módulo 3 — Asistencia/RRHH**: crons, matriz mensual, baja de agente + auditoría, geocerca.
5. **Módulo 4 — Tienda/Inventario/Traslados**: validación de identidad, regla gerente-de-tienda, widgets no conectados.
6. **Módulo 5 — CRM**: bloqueado hasta resolver la decisión de producto (sección 4.1).
7. **Módulo 6 — Bipay/Panel avanzado**: cuentas huérfanas, exports de transacciones, locks activos.
8. **Módulo 7 (al final, por decisión previa) — Integrador/agente on-premise.**

Cada módulo se brainstorming→spec→plan individualmente antes de tocar código, siguiendo el
mismo proceso ya usado en `sis_bipay` para el fix de `nelexa/zip` y el núcleo cifrado del agente.
