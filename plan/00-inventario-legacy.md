# 00 — Inventario consolidado del sistema LEGACY (sistema-rolando-salas)

**Fuente:** `E:\laragon\www\sistema-rolando-salas` (ERP PHP puro + MySQL PDO, agencia Bitel — Mundo Android Technology E.I.R.L, RUC 20607842842).
**Fecha de este inventario:** 2026-07-08. **Base previa:** los 3 docs Gemini en `docs/comparacion/` (estado ~2026-07-02), validados por muestreo — siguen vigentes; este doc los consolida y añade lo que NO cubrían.
**Nota de contexto:** el repo actual fue clonado de `sis_bipay` el 2026-07-07 (commit `95d5364`); todo el historial git visible es del 07–08 de julio. Los docs Gemini describen la base `sis_bipay`, que sigue siendo el grueso del código.
**Nota de skills:** las skills `headroom` y `superpowers` NO están disponibles en este entorno; se continuó sin ellas.

Roles: `$_SESSION['rol']` = `'admin'` (ve todo) | `'tienda'` (solo su tienda). Sesiones PHP en MySQL (`sys_sesiones`, handler propio). Login por email+password en `usuarios`.

---

## 1. Módulos / funcionalidades

| Módulo | Archivos clave | Qué hace | Rol |
|---|---|---|---|
| **Autenticación** | `auth/login.php`, `auth/logout.php`, `config/auth.php`, `config/session_handler.php` | Login email+password (`password_verify`), sesiones en MySQL, reactivación de agentes con permiso largo vencido | ambos |
| **Cuadre diario (reportes de venta)** | `reportes/nuevo_reporte.php`, `procesar_reporte.php`, `editar_reporte.php`, `procesar_edicion.php`, `ver_reporte.php`, `imprimir_reporte.php`, `solicitar_edicion.php`, `aprobar_edicion.php` | Formulario de cuadre del agente (postpago/prepago/equipos/salidas/otros flujos), guardado transaccional con descuento de stock, flujo de solicitud/aprobación de edición, "Modo Dios" admin | tienda (admin edita/aprueba) |
| **Borradores** | `reportes/ajax_guardar_borrador.php` | Autoguardado híbrido cloud/local cada 60s en `reportes_borradores` (UPSERT por agente+tienda+fecha, `datos_json`) | tienda |
| **Tickets de venta** | `reportes/ajax_guardar_ticket.php`, `ajax_guardar_ticket_ingreso.php`, `imprimir_ticket_ingreso.php`, `gerencia/tickets_emitidos.php`, `api/eliminar_ticket.php` | Tickets térmicos 58/80mm, pago mixto con vuelto, historial con reimpresión | ambos |
| **Facturación electrónica SUNAT** | `config/facturacion_config.php`, `facturacion_client.php`, `facturacion_cola.php`, `cpe_links.php`; `reportes/ajax_encolar_comprobante.php`, `ajax_emitir_ahora.php`, `ajax_link_cpe.php`, `cpe_publico.php`, `imprimir_comprobante.php`; `gerencia/configuracion_facturacion.php`, `ajax_configurar_sunat.php`, `ajax_sync_logo_facturacion.php`, `comprobantes_emitidos.php`, `emitir_nc.php`, `anular_boleta.php`, `descargar_comprobante.php`; `cron/procesar_cola_comprobantes.php` | Emisión de boletas/facturas/NC contra API Laravel externa en 2 pasos (crear + send-sunat), vía **cola asíncrona** (`comprobantes_cola`) drenada por cron con backoff; multi-emisor por tienda (`facturacion_config`); link público HMAC para WhatsApp | admin configura; tienda encola |
| **Inventario tiendas** | `tienda/ver_inventario.php`, `registrar_stock.php`, `guardar_stock.php`, `matriz_inventario.php`, `agregar_stock_rapido.php`, `procesar_correccion_stock.php`, `api_inventario.php`, `ajax_kardex_inventario.php`, `obtener_historial_chip.php`, `cambiar_codigo_chip.php`, `eliminar_chip.php` | Stock de equipos (por IMEI), accesorios (por cantidad) y chips (bolsillos por tienda+origen); Kardex multi-fuente; correcciones con auditoría | ambos (visibilidad por rol) |
| **Traslados de stock** | `tienda/procesar_traslado.php`, `procesar_traslado_chips.php`, `confirmar_traslado_equipo.php`, `confirmar_traslado_chips.php`, `confirmar_lote_equipo.php`, `gestionar_solicitud_traslado.php`, `constancia_traslado.php` | Traslados entre tiendas con estados (PENDIENTE_APROBACION→PENDIENTE→CONFIRMADO / RECHAZADO / CANCELADO), lotes, constancia A4 | ambos |
| **Precios y ganancias** | `gerencia/revisar_stock.php`, `fijar_precio.php`, `recalcular_ganancias.php`, `ajax_fijar_costo_rapido.php`; `tienda/fijar_precio_agente.php`, `actualizar_precio_rapido.php` | Fijación de costo/mínimo/normal; recálculo retroactivo de ganancia en el JSON `detalle` de ventas históricas | admin (agente solo `precio_normal`) |
| **Gerencia / dashboard** | `gerencia/panel_gerencia.php`, `historial_completo.php`, `exportar_excel.php`, `eliminar_reporte.php`, `marcar_entregado.php`, `autorizar_edicion.php` | Dashboard financiero, alertas de anomalías (diferencia≠0, ediciones), export Excel, eliminación con rollback de stock | admin (tienda limitado) |
| **Comisiones** | `gerencia/comisiones_empresa.php`, `configurar_comisiones.php`, `guardar_rangos_ajax.php`, `guardar_tarifas_ajax.php`, `recalcular_comisiones_masivo.php` | Planes de comisión (`comisiones_planes`), rangos escalonados por productividad mensual (`config_comisiones`, `comisiones_rangos`), recálculo masivo retroactivo | admin |
| **Financieras (cuotas)** | `gerencia/panel_financieras.php`, `confirmar_desembolso.php` | Ventas a crédito: comisión retenida hasta confirmar desembolso de la financiera (`comision_estado` PENDIENTE→APROBADA) | admin |
| **RRHH / agentes** | `gerencia/gestionar_agentes.php`, `guardar_agente.php`, `editar_agente.php`, `eliminar_agente.php`, `aprobar_postulante.php`, `ver_agente.php`, `historial_agente.php`, `certificado_agente.php`, `exportar_excel_agentes_pro.php`, `ajax_subir_doc_agente.php`; `public_onboarding.php` | Alta/baja de agentes, onboarding público (`postulantes_temp`), fichas, liquidaciones, certificados | admin (onboarding público) |
| **Planilla y boletas** | `gerencia/planilla_agentes.php`, `ajax_planilla.php`, `imprimir_boleta.php`, `accion_boleta.php`, `anular_boleta.php` | Planilla mensual estilo CD08 con overrides manuales (`planilla_ajustes`), boletas de pago (`pagos_planilla`), adelantos | admin |
| **Asistencias** | `asistencia.php`, `procesar_asistencia.php`, `api/registrar_marcacion.php`, `registrar_asistencia.php`, `registrar_asistencia_qr.php`, `registrar_marcacion_foto.php`, `generar_qr_asistencia.php`, `obtener_estado_asistencia.php`, `autorizar_dispositivo.php`; `gerencia/panel_asistencias.php`, `acciones_asistencia.php`, `admin_editar_asistencia.php`, `control_asistencias.php`, `revisar_fotos_asistencia.php`, `ajax_excepcion_jornada.php`; `tienda/qr_asistencia.php`; `reportes/ajax_salvavidas.php` | Marcación GPS (geocerca ponderada, anti-spoof), QR HMAC rotativo 5s, foto Base64 como último recurso, huella de dispositivo con PIN/tokens de emergencia, tardanzas/deudas/extras, "salvavidas" semanal, excepciones (PERMISO/FALTA/PERDONAR) | terminal sin sesión + admin |
| **Seguridad de agentes** | `gerencia/ajax_seguridad.php`, `api/verificar_pin_agente.php`, `api/verificar_token_activo.php`, `validar_autorizacion.php`, `admin/_reset_hashes_dispositivo.php` | Tokens de emergencia (diario/permanente), desvinculación de dispositivo, autorización por DNI+PIN con jerarquía admin>gerente>agente | admin / interno |
| **Bipay / Anypay** | `gerencia/panel_bipay.php`, `ajax_eliminar_cuenta.php`; `reportes/ajax_bipay_saldo.php`, `ajax_verificar_bipay.php` | Cuentas madre/hijo con saldo compartido por razón social, recargas/transferencias/ajustes, cooldowns por tienda, declaraciones de saldo diarias | admin (tienda declara) |
| **Integrador Bipay (agente local)** | `bitel_bipay_integrador_completo/`, `api/recibir_saldo.php`, `agente_config.php`, `agente_codigo.php`, `descargar_agente.php`, `_build_paquete_agente.php`; `gerencia/configuracion_integrador.php`; `config/integrador_crypto.php` | Agente PHP que corre en la PC de cada tienda, scrapea saldo/movimientos Bitel y los inyecta al ERP; se distribuye como ZIP con núcleo cifrado + ofuscación + marca de agua; credenciales cifradas AES-256-GCM (`integrador_credenciales`) | admin / máquina-a-máquina |
| **Auditoría / cuadre Bipay** | `includes/auditoria_helper.php`, `webhook_helper.php`; `cron/cron_auditoria_nocturna.php`; `gerencia/ajax_reconcile_details.php`, `ajax_resolver_conflicto.php`, `ajax_auditoria_ajuste.php`, `ajax_movimientos_dia.php` | Cruce declarado vs. operado por tienda/fecha (`auditoria_cierres`, con bloqueo humano), alertas Discord/Slack por descuadre | admin / cron |
| **Cuadre global / tesorería** | `gerencia/cuadre_global.php`, `includes/cuadre_tesoreria_helper.php` | Cuadre diario ERP vs. movimientos Bitel por tienda; clasificación de operaciones (`tesoreria_clasificacion`: efecto efectivo/digital POS/NEG/CERO, origen ERP/BITEL) | admin |
| **Postpago / morosidad** | `gerencia/panel_postpago.php`; `api/recibir_morosidad.php`, `recibir_bitel_historico.php`, `solicitar_bitel_historico.php`, `solicitar_extraccion.php` | KPIs de altas/churn/mora, reportes de deudas on-demand vía agente local (`solicitudes_extraccion`, `lineas_morosidad`, `bitel_operaciones_detalle`) | admin |
| **CRM** | `gerencia/crm_dashboard.php`, `ajax_crm_dragdrop.php`, `ajax_crm_clientes_filtrados.php`, `ajax_historial_cliente.php`, `ajax_registrar_contacto.php`, `ajax_guardar_campania.php`, `exportar_crm_excel.php`; `api/buscar_cliente_crm.php`, `guardar_cliente_crm.php` | Pipeline de clientes con drag&drop, interacciones, campañas (`crm_clientes`, `crm_interacciones`, `crm_ventas_detalle`) | admin/gerente/tienda |
| **Estadísticas** | `gerencia/estadisticas_ventas.php`, `mapa_calor.php`, `api_heatmap_ventas.php`; `api/obtener_ranking_agentes.php`, `obtener_subfiltros_ranking.php` | Rankings de tiendas/agentes/productos, heatmap de ventas, subfiltros dinámicos desde el JSON `detalle` | ambos |
| **BCP** | `gerencia/reporte_bcp.php` | Reportes de operaciones Agente BCP por turno, meta diaria 200 ops, gated por flag `usuarios.tiene_bcp` | flag + admin |
| **Empresa / branding** | `gerencia/configuracion_empresa.php`, `config/empresa_context.php`, `config/logo_helpers.php` | Identidad de empresa en `configuracion_empresa` (id=1), helpers `empresa()`/`empresa_logo_base64()` inyectados desde `database.php`; logo con recorte de fondo automático | admin |
| **Consultas RUC/DNI** | `ApisConsumirRUCYDNI/` (consulta-dni-ajax, consultar-ruc-ajax, ruc.php, simple_html_dom), `api/consultar_dni.php`, `gerencia/consulta_dni.php` | Autocompletado de datos por DNI (API externa con token) y RUC (scraping) | logueados |
| **Usuarios y tiendas** | `gerencia/usuarios.php`, `guardar_usuario.php`, `editar_usuario_ajax.php`, `eliminar_usuario.php`, `tiendas.php`, `editar_tienda.php`, `eliminar_tienda.php`, `diagnostico_tiendas.php` | CRUD usuarios (rol, tienda, BCP, cuenta Bipay, formato ticket) y sedes (GPS + radio) | admin |
| **Utilidades dev/demo (raíz)** | `seed_demo.php`, `cleanup_demo.php`, `seed_bitel_reportes.php`, `diag_cuadre_sim.php`, `reniec_test.php`, `api/db_check.php`, `gerencia/migrar_formato_ticket.php` | Seeds/diagnósticos de un solo uso — candidatos a NO migrar | dev |

---

## 2. Tablas de BD

Fuente: dump histórico `DB.sql` (borrado en `63dac3a`; recuperado de git en `95d5364`) + auto-migraciones `CREATE TABLE IF NOT EXISTS` en código + `sql/*.sql`. ★ = crítica.

**Núcleo de ventas:**
- ★ `reportes` — cabecera del cuadre diario (agente+tienda+fecha, totales, `destino_efectivo`, `diferencia`, `estado`, `estado_edicion`, `observaciones`)
- ★ `reporte_categorias` — líneas por categoría con **JSON `detalle`** (objeto `{}` o array `[{}]` — normalizar siempre) y `otros_flujo` JSON; `comision_estado`, `costo_al_registrar`, `ganancia`
- `reporte_salidas` — salidas de dinero del cuadre
- ★ `reportes_borradores` — autoguardado (UNIQUE agente+tienda+fecha, `datos_json`)
- `historial_reportes` — auditoría de ediciones/aprobaciones/entregas
- `tickets_emitidos` — tickets de venta genéricos (pago mixto, vuelto)
- `reporte_ventas_equipos`, `productos_catalogo` — apoyo de ventas/catálogo

**Inventario:**
- ★ `inventario_tiendas` — equipos (IMEI) y accesorios (cantidad); precios costo/mínimo/normal; estados DISPONIBLE/VENDIDO/TRASLADO
- ★ `inventario_chips` — bolsillos de chips por tienda+origen (`series_info` JSON)
- `historial_inventario` — auditoría de ajustes/correcciones
- `traslados_stock`, `traslados_chips` — traslados con estados y lotes

**Usuarios / tiendas / RRHH:**
- ★ `usuarios` — login del sistema (rol admin/tienda, `tiene_bcp`, `formato_ticket`, tienda)
- ★ `tiendas` — sedes con GPS (`latitud/longitud/radio_permitido`), `cuenta_bipay_id`
- ★ `agentes` — personal de venta (DNI, PIN hasheado, `hash_dispositivo`, tokens de emergencia, horario, sueldo, `es_gerencia`, bajas lista blanca/negra, `deuda_dias`)
- `postulantes_temp` — onboarding público pendiente de aprobación
- `historial_agentes` — auditoría de cambios de estado
- ★ `asistencias` — marcaciones (tardanza/deuda/extras, `metodo_marcacion` GPS/QR/FOTO, `foto_marcacion` LONGTEXT Base64, `requiere_revision`, comodín)
- `asistencia_intentos_fallidos`, `log_fraude_dispositivo` — antifraude
- `excepciones_jornada` (sql/), `log_ediciones_asistencia` (sql/) — excepciones y auditoría de ediciones
- `pagos_planilla` — boletas de pago; `planilla_ajustes` — overrides de planilla; `adelantos`

**Comisiones:**
- `comisiones_planes`, `config_comisiones`, `comisiones_rangos` — tarifas, rangos escalonados

**Bipay / Bitel:**
- ★ `cuentas_bipay` — cuentas madre/hijo con saldos; `tiendas_cuentas_bipay` — vínculos
- `transacciones_bipay`, `bipay_saldos_dia`, `bipay_cooldowns`
- ★ `auditoria_cierres` — cuadre por tienda/fecha con flag `bloqueado` (cierre humano que el cron respeta)
- `bitel_movimientos_diarios`, `bitel_operaciones_detalle` (+col `codigo_personal`), `lineas_morosidad`, `clientes_estado`, `solicitudes_extraccion`
- `integrador_credenciales` (sql/) — credenciales Bitel cifradas AES-256-GCM
- `tesoreria_clasificacion` (auto-migrada) — clasificación de operaciones para cuadre

**Facturación electrónica (auto-migradas):**
- ★ `facturacion_config` — por tienda o global: `base_url`, `api_token`, RUC, series B001/F001, IGV, modo beta/producción, `company_id`/`branch_id` (multi-emisor)
- ★ `comprobantes_cola` — cola de emisión (payload JSON, estado pendiente/error/emitido, intentos/max_intentos con backoff)

**Otros:**
- ★ `configuracion_empresa` — identidad de la empresa (id=1, `logo_ruta` data-URI)
- `sys_config` — clave/valor (secret HMAC de links CPE, webhooks Discord/Slack)
- `sys_sesiones`, `sys_notificaciones`
- `reportes_bcp`, `crm_clientes`, `crm_interacciones`, `crm_ventas_detalle`, `okr_metas`

---

## 3. Endpoints y jobs

### api/ (una línea c/u)
- `autorizar_dispositivo.php` — valida/registra huella de dispositivo por agente; cambio requiere PIN; log de fraude
- `registrar_marcacion.php` — marcación GPS unificada (geocerca ponderada, anti-spoof >200km/h, hash dispositivo/token)
- `registrar_asistencia.php` — marcación GPS entrada/salida final (refrigerio sigue en `procesar_asistencia.php`)
- `registrar_asistencia_qr.php` — marcación vía QR HMAC (ventana ±10s)
- `registrar_marcacion_foto.php` — marcación con foto Base64, `requiere_revision=1`
- `generar_qr_asistencia.php` — PNG de QR dinámico (token `AST|TIENDA|BLOQUE|HMAC`, rota cada 5s)
- `obtener_estado_asistencia.php` / `verificar_asistencia_hoy.php` — estado de marcaciones del día (sin sesión, terminal)
- `verificar_pin_agente.php` — verifica PIN hasheado de agente (firma de acciones)
- `verificar_token_activo.php` — consulta token de emergencia de un agente (admin)
- `editar_fechas_laborales.php` — admin edita fecha ingreso/periodo de prueba
- `eliminar_ticket.php` — admin borra ticket emitido
- `marcar_notificacion.php` / `obtener_notificaciones.php` — sistema de notificaciones (`sys_notificaciones`)
- `obtener_ranking_agentes.php` / `obtener_subfiltros_ranking.php` — rankings de ventas desde JSON `detalle`
- `restaurar_equipo_manual.php` — admin revierte VENDIDO→DISPONIBLE (RESCATE_MANUAL)
- `consultar_dni.php` — proxy consulta DNI (API externa)
- `buscar_cliente_crm.php` / `guardar_cliente_crm.php` — CRM desde el formulario de venta
- `recibir_saldo.php` — **máquina-a-máquina**: agente local inyecta saldo/movimientos Bitel (API key)
- `recibir_bitel_historico.php` / `recibir_morosidad.php` — agente local entrega históricos/deudas encolados
- `solicitar_bitel_historico.php` / `solicitar_extraccion.php` — admin encola solicitudes al agente local
- `agente_config.php` / `agente_codigo.php` — entregan config y código ofuscado del agente local a cambio de token (sin sesión)
- `descargar_agente.php` + `_build_paquete_agente.php` — genera ZIP del agente (núcleo cifrado, ofuscación, marca de agua)
- `db_check.php` — diagnóstico dev (SHOW COLUMNS)

### cron/
- `procesar_cola_comprobantes.php` — **worker SUNAT cada minuto**: drena `comprobantes_cola` contra la API Laravel, backoff exponencial, `--dry-run/--id/--limit`
- `cron_auditoria_nocturna.php` — fuerza cruce Bipay de todas las tiendas al fin del día
- `cron_salida_automatica.php` — cierra turnos abiertos ~23:00 (estado CIERRE_AUTO)
- `auto_retorno.php` — reactiva agentes con permiso largo vencido (00:00)
- `limpiar_fotos_asistencia.php` — purga fotos de marcación >7 días
- `reparar_excepciones_pisadas.php` — reparación one-shot CLI (dry-run por defecto) de excepciones pisadas por el autocierre
- `config/cron_runner.php` — **cron embebido**: ejecuta tareas al abrir `panel_asistencias.php`, máx. 1 vez/30 min (sin crontab)

### Raíz (terminal de asistencia, sin sesión)
- `asistencia.php` (terminal), `procesar_asistencia.php` (refrigerio/turno extendido), `verificar_agente_ajax.php`, `verificar_estado_ajax.php`, `validar_autorizacion.php` (DNI+PIN jerárquico), `public_onboarding.php` (postulación pública)

---

## 4. Reglas de negocio relevantes

- **JSON `detalle`** en `reporte_categorias`: puede ser objeto `{}` o array `[{}]` — **siempre normalizar al leer**. Contiene precios, comisiones, IMEIs, vendedor_id, flags cross_selling/upgrade.
- **`otros_flujo`**: JSON `{monto, motivo, comision_agente}` en `reporte_categorias`.
- **Cuadre de caja**: `reportes.observaciones` almacena `obs_cuadre_entregado` u `obs_cuadre_caja` según `destino_efectivo` ('ENTREGADO' vs 'TIENDA'). `marcar_entregado.php` cambia el destino con auditoría.
- **Duplicados**: un solo cuadre por agente+tienda+fecha; guardado transaccional que descuenta stock y revierte si es insuficiente; la edición revierte inventario y re-aplica.
- **Borradores**: UPSERT atómico; se limpia al procesar el reporte.
- **Comisiones escalonadas**: la comisión por venta depende del acumulado mensual del agente (rangos en `config_comisiones`/`comisiones_rangos`); upgrades/remates/prepago excluidos; ventas a CUOTAS con comisión retenida hasta confirmar desembolso (`confirmar_desembolso.php` la libera recalculando el JSON).
- **Recálculo retroactivo**: fijar costo recalcula `ganancia` en todas las ventas históricas del producto (por IMEI o nombre).
- **Planilla**: cálculo automático de comisiones y descuentos (tardanza S/1/min, falta = doble valor día, deuda de horas) con override manual por bandera `override_comisiones`.
- **Asistencia**: geocerca con precisión ponderada; PERMISO genera deuda de 540 min, FALTA_INJUSTIFICADA descuenta en boleta, PERDONAR limpia; "salvavidas" 1 vez/semana compensa tardanza con refrigerio; fotos Base64 se borran al aprobar (zero-retention).
- **`auditoria_cierres` con bloqueo**: si `bloqueado=1` para tienda+fecha, ni el cron nocturno ni los cruces automáticos recalculan (respeto al cierre humano).
- **Bipay compartido**: cuentas por razón social compartidas entre sucursales, cooldown 4 min tienda actual + 1–3 min aleatorio a las demás tras actualizar saldo.
- **Facturación**: el request web **solo encola** (`comprobantes_cola`); la emisión real la hace el cron (o `ajax_emitir_ahora` síncrono con la misma semántica — nunca se pierde una fila). Serie/company/branch salen de `facturacion_config` por tienda.
- **Auto-migraciones**: patrón generalizado `CREATE TABLE IF NOT EXISTS` / `ALTER TABLE` try-catch en runtime (facturación, tesorería, tickets, sys_config, postulantes) — el esquema se crea solo.

---

## 5. Integraciones externas

| Integración | Archivos | Detalle |
|---|---|---|
| **API facturación SUNAT (Laravel)** | `config/facturacion_client.php`, `facturacion_config.php`, `gerencia/ajax_configurar_sunat.php` | Emisión 2 pasos (POST crear + POST `{id}/send-sunat`); endpoints `/api/v1/boletas`, `/invoices`, `/notas-credito`; token Bearer por config; `configure-sunat` sube certificado + credenciales SOL para activar producción; descarga PDF/XML/CDR |
| **APIs RUC/DNI** | `ApisConsumirRUCYDNI/`, `api/consultar_dni.php`, `gerencia/consulta_dni.php` | DNI vía API externa (`api-codart.cgrt.org`, token hardcodeado); RUC vía scraping (`simple_html_dom`) |
| **Integrador Bipay/Bitel (agente local)** | `bitel_bipay_integrador_completo/` (agente + servidor), `api/recibir_*`, `agente_*`, `config/integrador_crypto.php` | Agente en la PC de la tienda envía saldo/movimientos al ERP con API key; distribución con núcleo cifrado y marca de agua; credenciales AES-256-GCM (clave por env `INTEGRADOR_KEY` o `config/integrador_key.php` no versionado) |
| **BCP** | `gerencia/reporte_bcp.php` | Solo registro manual de operaciones de Agente BCP (no hay API) |
| **Discord/Slack** | `includes/webhook_helper.php` | Webhooks de alerta por descuadre Bipay (URL en `sys_config`) |
| **Links públicos CPE** | `config/cpe_links.php`, `reportes/cpe_publico.php` | Link HMAC firmado (secret en `sys_config`) para que el cliente vea su comprobante por WhatsApp sin sesión |

---

## 6. Pantallas (una línea c/u; endpoints AJAX ya listados arriba)

### gerencia/
- `panel_gerencia.php` — dashboard principal: resumen financiero, últimos cuadres, campanita de anomalías
- `historial_completo.php` — historial filtrable/exportable de todos los cuadres (export con PIN para jefes)
- `panel_asistencias.php` — gestión de asistencias con acciones admin y monitor de fraude
- `control_asistencias.php` — vista matricial mensual de asistencias
- `revisar_fotos_asistencia.php` — aprobación de marcaciones por foto
- `gestionar_agentes.php` — panel de personal: registro, postulantes, accesos
- `ver_agente.php` — perfil + liquidación del agente (comisiones, descuentos, boletas, adelantos)
- `historial_agente.php` — detalle de rendimiento/asistencia para liquidación
- `certificado_agente.php` — constancia de trabajo imprimible A4
- `planilla_agentes.php` — planilla mensual editable estilo CD08
- `imprimir_boleta.php` — creación + vista imprimible de boleta de pago
- `comisiones_empresa.php` — CRUD de planes de comisión y tarifas operativas
- `configurar_comisiones.php` — rangos escalonados de comisión por productividad
- `panel_financieras.php` — desembolsos de ventas a crédito pendientes/aprobados
- `panel_bipay.php` — cuentas Bipay madre/hijo, recargas, transferencias, historial
- `cuadre_global.php` — cuadre diario ERP vs. Bitel por tienda (tesorería)
- `panel_postpago.php` — KPIs postpago: altas, churn, morosidad
- `crm_dashboard.php` — pipeline CRM con drag&drop, campañas
- `mapa_calor.php` — heatmap de ventas
- `estadisticas_ventas.php` — rankings de tiendas/agentes/productos con export
- `reporte_bcp.php` — registro y panel de operaciones BCP
- `tickets_emitidos.php` — historial de tickets con reimpresión térmica
- `comprobantes_emitidos.php` — historial de comprobantes SUNAT (estado de cola, descarga PDF/XML/CDR, NC, anulación)
- `configuracion_facturacion.php` — configuración de facturación por tienda (rediseñada para gerente no técnico)
- `configuracion_integrador.php` — credenciales del integrador Bipay por tienda (admin o la propia tienda)
- `configuracion_empresa.php` — perfil de empresa (identidad + logo)
- `usuarios.php` — CRUD de usuarios del sistema
- `tiendas.php` / `editar_tienda.php` — CRUD de sedes con GPS
- `revisar_stock.php` — fijación de precios pendientes
- `ver_bitacora_stock.php` — auditoría unificada de movimientos de inventario
- `admin_ajuste_inventario.php` — ajuste maestro a conteo físico
- `diagnostico_tiendas.php` — diagnóstico de consistencia tiendas/usuarios/chips
- (exports: `exportar_excel*.php`, `exportar_asistencias_*.php`, `exportar_control_asistencias.php`, `exportar_crm_excel.php` — descargas Excel de sus paneles)

### tienda/
- `ver_inventario.php` — dashboard central de inventario (chips/equipos/accesorios, widgets admin)
- `registrar_stock.php` — formulario de registro de nuevo stock
- `matriz_inventario.php` — matriz stock tiendas×productos
- `qr_asistencia.php` — pantalla de QR dinámico para marcación
- `constancia_traslado.php` — comprobante A4 de traslado
- (resto de tienda/ son endpoints de proceso, ya en secciones 1 y 3)

### reportes/
- `nuevo_reporte.php` — formulario principal de cuadre diario del agente
- `editar_reporte.php` — edición de cuadre enviado (admin o aprobado)
- `ver_reporte.php` — vista solo-lectura de un cuadre
- `imprimir_reporte.php` — impresión A4 del cuadre
- `imprimir_ticket_ingreso.php` — ticket térmico de ingresos varios
- `imprimir_comprobante.php` — comprobante SUNAT en A4/a5/80mm/ticket
- `cpe_publico.php` — vista pública del comprobante (link HMAC, sin sesión)
- `mi_historial.php` — historial personal del agente / vista de jefe de tienda (DNI+PIN)

### Raíz
- `asistencia.php` — terminal público de marcación GPS
- `public_onboarding.php` — formulario público de postulación
- `index.php` — redirect a login o panel

---

## 7. DELTA post-2026-07-02 (lo que los docs previos NO cubren)

### 7a. Commits nuevos (2026-07-07 y 07-08) — funcionalidad estrictamente nueva

| Commit | Qué añade |
|---|---|
| `95d5364` (07-07) | Base clonada de `sis_bipay` para el cliente nuevo |
| `135e8ac`, `658917e`, `42a32e4` | Rebranding: Vitaltel/Brisel-Maquera → **Mundo Android Technology E.I.R.L** (RUC 20607842842); rotación de credenciales/URLs de deployment (Dokploy, `mundoandroid.kyrocodelabs.cloud`; BD por env vars `DB_HOST/NAME/USER/PASS`) |
| `63dac3a` | **Dumps `DB.sql`/`DB-sin-bipay.sql` eliminados del repo** (BD nueva creada limpia en el VPS) — el esquema histórico solo vive en git |
| `dcf7e3e` | **Multi-emisor real**: `company_id`/`branch_id`/serie se pasan desde `facturacion_config` al payload de la API (antes hardcodeado); toca `config/facturacion_client.php` y cola |
| `c21a531` | `configuracion_facturacion.php` rediseñada para un **gerente no técnico** (wizard simplificado) |
| `a500e49` | `gerencia/ajax_configurar_sunat.php` (nuevo, 193 líneas): handler para **activar producción** — sube certificado digital + credenciales SOL a la API (`configure-sunat`), multipart |
| `dd68718` | **Conversión automática PFX→PEM**: `_cert_es_pem()` detecta por marcador `-----BEGIN`; `_convertir_pfx_a_pem()` usa OpenSSL CLI con `-legacy` (los PFX viejos de SUNAT usan RC2/3DES que OpenSSL 3.x rechaza); temporales limpiados |
| `30b54c5` | `gerencia/ajax_sync_logo_facturacion.php` (nuevo): **sincroniza el logo de `configuracion_empresa` con la company de la API** de facturación (PUT companies / multipart) |
| `829f2d1` | `config/logo_helpers.php` (nuevo): `procesar_logo_upload()` — **quita fondo sólido por flood-fill desde las esquinas** (distancia de color, tolerancia 50), resize máx. 400px, devuelve data-URI PNG transparente |
| `3ed34e9` | `configuracion_empresa.php` pasa el logo subido por `procesar_logo_upload` antes de guardarlo en BD |
| `cdd83c1`, `1c00225`, `a3d7672` | Logo real en sidebar, terminal de asistencia, login y tickets (fallback a SVG); `logo-bitel.png` eliminado, queda `logo-rolando.jpeg` |

Contexto útil (de memoria de sesiones previas): la API de facturación tiene el logo del PDF oficial hardcodeado (ignora `company.logo_path`) — el sync de logo cubre los datos de la company pero no cambia el PDF.

### 7b. Módulos que ya existían en la base pero los docs Gemini NO documentaron

Los 3 docs Gemini cubren gerencia parcialmente (~45 de 85 archivos), tienda/reportes casi completo, y api/cron/auth parcial. Faltaba (consolidado en las secciones 1–6 de este doc):

- **Toda la facturación electrónica SUNAT**: `config/facturacion_{config,client,cola}.php`, `cpe_links.php`, cola `comprobantes_cola`, cron `procesar_cola_comprobantes.php`, `ajax_encolar_comprobante`/`ajax_emitir_ahora`/`ajax_link_cpe`/`cpe_publico`/`imprimir_comprobante`, y en gerencia `comprobantes_emitidos`/`emitir_nc`/`anular_boleta`/`descargar_comprobante`/`configuracion_facturacion`
- **Integrador Bipay cifrado**: `integrador_crypto.php` (AES-256-GCM), `configuracion_integrador.php`, distribución del agente (`descargar_agente`, `agente_codigo/config`, `_build_paquete_agente` con ZIP cifrado/ofuscado), `recibir_saldo/historico/morosidad`, `solicitar_*`
- **CRM completo** (`crm_dashboard` + 6 endpoints ajax + api/buscar/guardar_cliente_crm)
- **Cuadre global / tesorería** (`cuadre_global.php`, `cuadre_tesoreria_helper.php`, `tesoreria_clasificacion`)
- **Auditoría Bipay con bloqueo** (`auditoria_helper.php`, `cron_auditoria_nocturna`, `ajax_reconcile_details`/`resolver_conflicto`/`auditoria_ajuste`/`movimientos_dia`, webhooks Discord/Slack)
- **Panel postpago / morosidad** (`panel_postpago.php`, `lineas_morosidad`, `solicitudes_extraccion`)
- **Mapa de calor** (`mapa_calor.php`, `api_heatmap_ventas.php`)
- **Empresa context + certificados/onboarding** (`empresa_context.php`, `public_onboarding.php`, `validar_autorizacion.php`)
- **Cron embebido** (`config/cron_runner.php`) y crons `cron_auditoria_nocturna`/`reparar_excepciones_pisadas`
- **Control asistencias matricial** y exports Neiry (`control_asistencias.php`, `exportar_asistencias_neiry.php`)

---

## 8. PENDIENTE

- Los endpoints AJAX de gerencia `ajax_auditoria_ajuste`, `ajax_crm_*`, `ajax_movimientos_dia`, `ajax_reconcile_details`, `ajax_resolver_conflicto`, `ajax_excepcion_jornada`, `ajax_guardar_campania`, `ajax_historial_cliente`, `ajax_registrar_contacto`, `ajax_subir_doc_agente` y `anular_boleta` se describieron por nombre + módulo al que sirven, sin lectura línea a línea (bajo riesgo: son CRUD/ajax de paneles ya descritos).
- Columnas exactas de cada tabla del dump histórico no se transcribieron (recuperables con `git show 95d5364:DB.sql`).
- El detalle visual de las pantallas queda para el agente de UI (fuera de alcance por instrucción).
