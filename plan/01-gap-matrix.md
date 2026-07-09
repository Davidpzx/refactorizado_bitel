# 01 — Matriz de brechas (Gap Analysis)

**Fecha:** 2026-07-08 · **Autor:** orquestador (consolidación de los 4 informes de Fase 0).
**Fuentes:** `00-inventario-legacy.md`, `00-inventario-refactorizado.md`, `00-inventario-diseno.md`, `00-informe-arquitectura.md`.

**Nota de conflicto resuelto:** el inventario de diseño (titan) marcaba T1.2/T1.3/T2.5 como "pendiente UI" citando el handoff; el inventario del refactorizado (dev3) los verificó **cerrados contra código** (tests + commits). Prevalece dev3: esos ítems pasan a "verificar/pulir", no "construir".

Leyenda Estado: `OK` paridad de código lograda · `FALTA-TOTAL` · `FALTA-PARCIAL` · `SOLO-DISEÑO` existe pero pierde identidad visual · `VERIFICAR` estado desconocido, requiere chequeo antes de construir · `CONGELADO` decisión explícita de posponer.

| Módulo | ¿Legacy? | ¿Refactorizado? | Estado | Tablas BD involucradas | Diseño | Complejidad | Dependencias |
|---|---|---|---|---|---|---|---|
| Autenticación + PIN + dispositivo | Sí | Sí (Sanctum, verify-pin, fingerprint) | OK | usuarios, agentes | Replicar (modal PIN legacy → ver fila "Modal PIN") | — | — |
| Cuadre diario (reportes) | Sí | Sí (ReporteController completo, modo Dios, tests) | SOLO-DISEÑO | reportes, reporte_categorias→ventas/venta_lineas, reporte_salidas | **Replicar tal cual** captura 004 (pantalla más rica del legacy) | Alta (UI) | Normalizador detalle |
| Borradores | Sí | Sí | OK | reportes_borradores | — | — | — |
| Tickets de venta | Sí | Sí | SOLO-DISEÑO (confirm() al anular) | tickets_emitidos | Replicar | Baja | ConfirmDialog |
| **Facturación electrónica SUNAT** | Sí (API externa, multi-emisor por tienda, cola con backoff, cert PFX→PEM, SOL, links HMAC, NC/anulación, 4 formatos impresión) | Parcial (Greenter global por .env, ComprobanteController, job) | **FALTA-PARCIAL — brecha #1 del proyecto** | facturacion_config (NO existe), comprobantes_cola equiv. (NO existe), comprobantes, sys_config | Replicar wizard "gerente no técnico" (c21a531) | **Alta** | **DECISIÓN-001** (API externa vs Greenter), BD primero |
| Config. facturación (UI) | Sí (`configuracion_facturacion.php` rediseñada) | **Sin ruta en App.tsx** | FALTA-TOTAL (UI) | facturacion_config | Replicar/mejorar wizard | Media | Backend config SUNAT |
| Links públicos CPE (WhatsApp) | Sí (HMAC, sin sesión) | No detectado | FALTA-TOTAL | sys_config (secret) | Replicar | Media | Emisión SUNAT |
| Sync logo empresa → API facturación | Sí (30b54c5) | No | FALTA-TOTAL | configuracion_empresa | — | Baja | Config SUNAT |
| Logo pipeline (flood-fill fondo, 829f2d1) | Sí | Dudoso (ConfiguracionPage tiene logo, ¿sin procesado?) | VERIFICAR | configuracion_empresa | Mantener | Baja | — |
| Inventario equipos/chips/kardex | Sí | Sí (+ matriz, mejoras) | OK | inventario_tiendas, inventario_chips, historial_inventario | Mantener mejoras | — | — |
| Traslados equipos+chips | Sí | Sí (5 tests) | SOLO-DISEÑO (confirm()) | traslados_stock, traslados_chips | Replicar | Baja | ConfirmDialog |
| Precios / ganancias | Sí | Sí (recalcular, precio-agente) | SOLO-DISEÑO | inventario_tiendas | Replicar chips por tienda + botón Fijar índigo (captura 007) | Baja | — |
| Ajuste maestro inventario (T3.2) | Sí (`admin_ajuste_inventario.php`) | **Desconocido** | VERIFICAR | inventario_tiendas, historial_inventario | Replicar | Media | — |
| Dashboard / gerencia | Sí | Sí | OK (**referente del port visual**) | — | No tocar | — | — |
| Comisiones (rangos, tarifas) | Sí | Sí (editores UI verificados) | OK | comisiones_planes, config_comisiones, comisiones_rangos | Pulir con kyro-table | — | — |
| **Comisiones Empresa** (`comisiones_empresa.php`) | Sí | **Sin ruta ni página detectada** | VERIFICAR→posible FALTA | comisiones_planes | Replicar (icono Building2) | Media | — |
| Financieras (cuotas) | Sí | Sí (confirmar/revertir + lock) | SOLO-DISEÑO | reportes/ventas (comision_estado) | Replicar 3 KPI hairline + badges Krece/PayJoy (captura 021) | Baja | ConfirmDialog |
| RRHH / agentes | Sí | Sí (boletas, RRHH, documentos, adelantos — verificado) | SOLO-DISEÑO | agentes, historial_agentes, adelantos, pagos_planilla | Replicar hairlines de color por card + botonera multicolor (captura 013) | Baja | — |
| Onboarding público RRHH | Sí (`public_onboarding.php`) | Parcial (`PostulacionPublicaPage` cubre postulación; ficha RRHH dudosa) | VERIFICAR | postulantes_temp | Replicar | Baja | — |
| Planilla / boletas | Sí | Sí (CD08, ajustes) | OK | pagos_planilla, planilla_ajustes | Replicar | — | — |
| Asistencias (GPS/QR/foto/salvavidas) | Sí | Sí (completo, ~1400 líneas + crons) | SOLO-DISEÑO (tabs vs rutas) | asistencias, excepciones_jornada, log_ediciones_asistencia | Presentar como pestañas (PageTabs) | Baja | — |
| Modal PIN autorización (DNI+PIN jerárquico) | Sí (rasgo de identidad) | Flujo backend existe (verify-pin); UI sin tratamiento legacy | VERIFICAR + SOLO-DISEÑO | agentes | Replicar (candado índigo, PIN letter-spacing 8px) | Baja | — |
| Token activo GET (T3.5) | Sí | Desconocido | VERIFICAR | agentes | — | Baja | — |
| Recálculo masivo operativo (T3.6) | Sí | Desconocido | VERIFICAR | comisiones | — | Media | — |
| Multi-IMEI / series_info (T3.7) | Sí | Desconocido | VERIFICAR | inventario_chips | — | Media | — |
| Exports CSV vs Excel (T4.1) | Sí (Excel real) | Desconocido | VERIFICAR | — | — | Baja | — |
| Bipay / Anypay | Sí | Sí (auditoría nocturna, webhooks) | SOLO-DISEÑO (confirm()) | cuentas_bipay etc. (equiv. verificado por gap_db) | Replicar | Baja | ConfirmDialog |
| Cuadre global / tesorería | Sí | Sí (CuadreBitelService) | OK | tesoreria_clasificacion, auditoria_cierres | Replicar | — | — |
| Integrador Bipay (agente local) | Sí | Parcial **por decisión** | CONGELADO (spec 2026-07-04, 4 decisiones abiertas) | integrador_credenciales, bitel_* | — | Alta | Sub-proyecto futuro |
| Postpago / morosidad | Sí | Sí | OK | lineas_morosidad, solicitudes_extraccion | Replicar | — | — |
| CRM | Sí | Sí (pipeline + temperatura) | OK | leads, crm_clientes, crm_interacciones | Replicar (acento púrpura menú) | — | — |
| Estadísticas / ranking | Sí | Sí | OK | — | Verificar charts en vivo | — | QA visual |
| Mapa de calor | Sí | Sí | SOLO-DISEÑO (icono menú) | — | Replicar | Baja | Iconografía |
| Reporte BCP | Sí | Sí | OK | reportes_bcp | Replicar (tinte sky) | — | — |
| Empresa / branding | Sí | Sí (ConfiguracionPage) | FALTA-PARCIAL (ver logo pipeline y sync) | configuracion_empresa | Mantener | Baja | — |
| Consultas RUC/DNI | Sí | Sí | OK | crm_clientes (caché) | — | — | — |
| Usuarios / tiendas | Sí | Sí | SOLO-DISEÑO (confirm()) | usuarios, tiendas | Replicar | Baja | ConfirmDialog |
| **UI transversal: confirms nativos** | SweetAlert2 dark 100% | ~30 `confirm()` nativos | SOLO-DISEÑO — **ruptura de identidad #1** | — | Replicar (ConfirmDialog kyro) | Media (volumen) | — |
| **UI transversal: iconografía** | Phosphor semántico | lucide con 10 mapeos malos en sidebar + logo `Users` genérico | SOLO-DISEÑO | — | Corregir mapeos (mín.) o migrar a Phosphor (DECISIÓN-002) | Baja/Media | — |
| UI transversal: modo claro | Sidebar azul Bitel + tabs dorados | Sidebar blanco glass | SOLO-DISEÑO | — | Replicar rasgo corporativo | Baja | — |
| Cron / scheduler (operación) | cron_runner embebido + crons | Scheduler Laravel + 5 comandos | VERIFICAR (matriz operativa: schedule:run, workers, locks) | — | — | Media | Deploy |
| Migraciones en VPS + migrar-chips | — | Pendiente **operativo** (no verificable local) | VERIFICAR (única pendiente real según dev3) | todas | — | Baja | Acceso SSH |

## Decisiones abiertas (bloquean tickets marcados)

- **DECISIÓN-001 (SUNAT):** ¿portar el cliente de la **API externa** del legacy (paridad real: multi-emisor probado en producción, configure-sunat, PFX→PEM, logo sync) o completar **Greenter** local hasta multi-emisor? **Recomendación del orquestador: portar el cliente de la API externa** — es lo que el negocio usa hoy, la inversión de esta semana fue ahí, y Greenter duplica lo que la API ya resuelve (certificados, XML, CDR). Greenter queda como implementación alternativa desactivada.
- **DECISIÓN-002 (iconos):** Opción B (corregir 10 mapeos en lucide, ~15 líneas) se hace SÍ o SÍ como quick-win; Opción A (migrar a `@phosphor-icons/react`, paridad de textura total con pesos fill/bold) queda opcional a confirmación del usuario.
- **DECISIÓN-003 (config):** ownership por parámetro entre `configuracion_empresa` / `sys_config` / `.env` (hoy coexisten sin conflicto; congelar el criterio por escrito).
- Heredadas sin cambio: 4.3 vocabulario 5 estados `marcar_entregado`; 4.4 bloqueo de eliminar reporte aprobado (consolidado como regla de facto con test).
