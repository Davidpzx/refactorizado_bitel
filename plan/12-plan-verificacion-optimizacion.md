# Plan de verificación funcional y optimización

Alcance: Laravel 12 (`backend/`), React 19 + Vite (`frontend/`) y producción en `https://refactor.kyrocodelabs.cloud`. Los 638 tests Feature verdes son la línea base, pero no sustituyen la verificación con navegador, MySQL, archivos, dispositivos y terceros. Los casos que mutan dinero, stock, planilla o SUNAT deben usar una tienda/día de prueba identificables y guardar evidencia (captura, request/response e ID). Antes: backup, ventana, credenciales admin/tienda, agente/dispositivo de prueba y SUNAT en homologación; no emitir documentos fiscales reales sin autorización.

## SECCIÓN A — VERIFICACIÓN FUNCIONAL END-TO-END

Leyenda: **Feature existente** cubre HTTP/BD en SQLite; **E2E nuevo** automatiza navegador contra staging; **manual** es imprescindible cuando intervienen hardware, geolocalización, impresión, archivos o terceros.

### P0-01. Login por rol admin/tienda

- **Base real:** `backend/routes/api.php:60-63`, `frontend/src/pages/auth/LoginPage.tsx`, `backend/tests/Feature/AuthTest.php`, `SecurityParityTest.php`.
- **Pasos:** (1) login admin, recarga y acceso a dashboard, usuarios, configuración, SUNAT y cola; (2) logout y reutilización fallida del token; (3) login tienda y acceso a cuadre, inventario e historial propios; (4) por URL y API intentar áreas admin y recursos de otra tienda; (5) contraseña errónea y usuario inactivo.
- **Éxito:** admin ve administración; tienda queda limitada en UI y API; prohibido devuelve 403, anónimo 401 y logout invalida sesión.
- **Automatización:** **Feature existente** para login/me/logout/autorización; **E2E nuevo** para menú, redirecciones, recarga y URL directa; **manual** para cookie/token y cabeceras del dominio.

### P0-02. Cuadre diario completo y cierre de caja

- **Base real:** `backend/routes/api.php:123-146`, `frontend/src/pages/reportes/NuevoReportePage.tsx`, `ReporteDetallePage.tsx`, pruebas `ReporteStoreParityTest.php`, `ReporteBorradorTest.php`, `ReporteActualizarDestinoTest.php`.
- **Pasos:** (1) con turno abierto iniciar reporte, guardar borrador, recargar y recuperarlo; (2) registrar equipo contado/cuotas, chip/prepago, postpago/plan, servicio/recarga y apoyo si aplica; (3) agregar salidas, medios de pago, caja inicial, efectivo entregado y destino; (4) cerrar; (5) contrastar total calculado, esperado, diferencia, comisiones y stock; (6) como admin revisar categorías y fijar costo; (7) solicitar/aprobar edición, reprocesar y revisar historial; (8) intentar operar con turno cerrado.
- **Éxito:** cierre único; aritmética, diferencia, stock y comisión correctos; borrador eliminado; historial completo; `open.shift` bloquea fuera de turno.
- **Automatización:** **Feature existente** cubre store, borrador, comisión, reproceso, stock, edición y destino; **E2E nuevo** data-driven del formulario; **manual** para impresión, redondeos visibles y corrida MySQL.

### P0-03. Inventario: alta, traslado y kardex

- **Base real:** `backend/routes/api.php:160-178,349-355`, `frontend/src/pages/inventario/InventarioPage.tsx`, `frontend/src/pages/traslados/TrasladosPage.tsx`, `KardexInventarioPage.tsx`; pruebas `Inventario*Test.php`, `Traslado*Test.php`, `MatrizInventarioTest.php`, `BitacoraStockTiendaAccesoTest.php`.
- **Pasos:** (1) alta de accesorio, equipo con IMEI y lote multi-IMEI; (2) alta tienda con DNI autorizador sin poder elegir otra tienda; (3) traslado individual/lote y comprobación de estado en tránsito/no vendible; (4) aprobación y confirmación desde destino; (5) rechazo/cancelación y devolución; (6) búsqueda del ítem en matriz, bitácora y kardex; (7) intento de venta en tránsito.
- **Éxito:** sin duplicación/pérdida; origen, destino, cantidad e identidades correctos; confirmar mueve una vez, cancelar restaura y kardex conserva trazabilidad.
- **Automatización:** **Feature existente** para alta, scoping, identidad y confirmación; **E2E nuevo** alta→traslado→confirmación→kardex; **manual** para IMEI, constancia y cruce MySQL.

### P0-04. Chips

- **Base real:** `backend/routes/api.php:340-345,358-362`, `frontend/src/pages/inventario/ChipsGestionPage.tsx`, `frontend/src/pages/traslados/TrasladoChipsPage.tsx`; pruebas `InventarioChipStoreTest.php`, `ChipsCambiarCodigoAuthTest.php`, `TrasladoChips*Test.php`.
- **Pasos:** alta y segunda alta acumulativa; cambio de código por propietario; traslado con DNI, aprobación y confirmación; venta desde cuadre; reproceso/eliminación y reposición; historial y ajuste admin.
- **Éxito:** cantidades exactas, sin lotes duplicados, tienda ajena bloqueada, descuento del origen correcto y reposición exacta.
- **Automatización:** **Feature existente** para alta/autorización/traslado y reversión parcial; **E2E nuevo** del ciclo; **manual** para conciliación y doble confirmación concurrente.

### P0-05. Facturación SUNAT: configuración, emisión y cola

- **Base real:** `backend/routes/api.php:204-231`, `frontend/src/pages/admin/ConfiguracionFacturacionPage.tsx`, `frontend/src/pages/comprobantes/ComprobantesPage.tsx`; pruebas `FacturacionConfig*Test.php`, `ConfigurarSunatTest.php`, `EmitirAhoraTest.php`, `ProcesarColaComprobantesTest.php`, `ComprobanteCola*Test.php`.
- **Pasos:** (1) crear config global/tienda y validar URL, token, series, company/branch, certificado y SOL en homologación; (2) emitir boleta y factura; (3) observar transición de cola, correlativo y respuesta SUNAT; (4) provocar fallo controlado y comprobar backoff/reintento sin duplicar; (5) PDF/XML y enlace público firmado; (6) nota de crédito/anulación permitida; (7) confirmar scheduler de Dokploy y logs sin secretos.
- **Éxito:** precedencia tienda/global, correlativo único, idempotencia, snapshot fiscal estable, recuperación de cola, estado local=proveedor, descargas válidas y firma inválida/expirada rechazada.
- **Automatización:** amplia **Feature existente** con tercero simulado; **E2E nuevo** de UI/cola/descargas; **manual** contra homologación, certificado, cron y CPE visual.

### P0-06. Asistencias: terminal QR

- **Base real:** `backend/routes/api.php:73-79,421-436`, `frontend/src/pages/asistencias/TerminalAsistenciaPage.tsx`, `QrDisplayPage.tsx`; pruebas `AsistenciaTest.php`, `AsistenciaFacialTest.php`, `FraudeDispositivosTest.php`, `TurnoCorridoTest.php`.
- **Pasos:** mostrar QR y rotación; móvil autorizado con DNI/PIN, cámara y GPS; entrada, refrigerio y salida; QR ajeno/vencido, GPS fuera de radio/débil y dispositivo no autorizado; fallback foto/token; revisión admin de tardanza, método, distancia, foto y fraude.
- **Éxito:** secuencia no saltable/duplicable; validación server-side de tienda/QR/GPS/dispositivo; minutos correctos y fallbacks auditados.
- **Automatización:** **Feature existente** de secuencia/GPS/QR/foto/facial/fraude; **E2E nuevo** con permisos simulados; **solo manual** para cámara, GPS, lectura y permisos reales.

### P1-07. Planilla y liquidación

- **Base real:** `backend/routes/api.php:248,256-259`, `frontend/src/pages/planilla/PlanillaPage.tsx`, `HistorialLiquidacionPage.tsx`; pruebas `PlanillaTest.php`, `PlanillaOnlineTest.php`, `HistorialLiquidacionTest.php`, `PlanillaExportarExcelTest.php`.
- **Pasos:** mes cerrado conocido; contrastar sueldo, comisiones, tardanzas, descuentos, adelantos, ajustes y neto; guardar ajuste/recalcular; reset controlado; liquidación de asistencias; export y restricción de tienda.
- **Éxito:** componentes trazables, neto exacto, ajuste sin duplicar, reset/recalcular idempotente, UI=XLSX y fechas del mes correcto.
- **Automatización:** **Feature existente** base/online/permisos/XLSX; **Feature nuevo** de fórmula integral y **E2E nuevo** de edición; **manual** para conciliación contable.

### P1-08. CRM y leads

- **Base real:** `backend/routes/api.php:267-276,406-407`, `frontend/src/pages/crm/CrmPage.tsx`; pruebas `LeadTest.php`, `CrmTemperaturaTest.php`, `DniControllerTest.php`.
- **Pasos:** crear/buscar cliente y lead; asignar agente/tienda/fuente; cambiar pipeline y añadir interacciones; revisar dashboard; casos caliente/neutro/frío/upselling; filtros y export; forzar tienda ajena.
- **Éxito:** registros únicos, contadores coherentes, temperatura según regla, export=filtro y aislamiento por tienda.
- **Automatización:** **Feature existente** de CRUD, aislamiento, temperatura y export; **E2E nuevo** de estados/gráficos/filtros; **manual** para UX y XLSX.

### P1-09. Integrador Bitel

- **Base real:** `backend/routes/api.php:83-88,378-385`, `frontend/src/pages/admin/IntegradorPage.tsx`; pruebas `IntegradorRecibirSaldoTest.php`, `IntegradorDescargarAgenteTest.php`.
- **Pasos:** credenciales y agente; extracción de saldo, movimientos, morosidad e histórico; comprobar `last_sync_at`; repetir payload; regenerar token; desactivar; revisar logs/tiempos sin secretos.
- **Éxito:** autenticación rechaza clave inválida, payload idempotente, datos=fuente, sync sólo avanza en éxito y rotación/desactivación son inmediatas.
- **Automatización:** **Feature existente** de recepción/idempotencia/API key/ZIP; **Feature nuevo** de rotación/toggle/histórico; **manual** con agente local, Bitel y red VPS.

### P1-10. Exports Excel/PDF

- **Base real:** rutas `exportar`/`constancias` en `backend/routes/api.php:107,114,119,139,154,167-169,256,264,275,280,303,318,365-370,418,424`; pruebas `*Export*Test.php`, `ReporteExportarExcelTest.php`, `Constancia*Test.php`.
- **Pasos:** con filtros y datos conocidos exportar dashboard, historial, inventario/kardex/matriz, reporte, planilla, asistencias/Neiry, CRM, postpago, Bipay, tickets y ficha; abrir XLSX en Excel/LibreOffice y PDF en navegador; revisar MIME, hojas, acentos, fechas, moneda, logo, saltos y permisos.
- **Éxito:** archivos válidos, datos/totales iguales a UI/BD, filtros/scoping respetados y PDF sin cortes críticos.
- **Automatización:** mucha **Feature existente**; contrato nuevo por export faltante y **E2E nuevo** de descarga/MIME; **manual** para render, impresión y compatibilidad.

### P1-11. Onboarding público RRHH y aprobación

- **Base real:** `backend/routes/api.php:69-70,325-329`, `frontend/src/pages/PostulacionPublicaPage.tsx`, `frontend/src/pages/admin/PostulacionesPage.tsx`; pruebas `PostulacionPublicaTest.php`, `PostulanteAprobacionTest.php`.
- **Pasos:** `/postular` anónimo móvil/escritorio; formulario completo; JPG/WEBP perfil y JPG/PDF DNI cerca de límites; envío; DNI pendiente duplicado; revisión/edición admin; aprobación con datos laborales/PIN; verificar agente, documentos y segunda aprobación.
- **Éxito:** no requiere sesión, valida/conserva todo, 409 amigable al duplicado, documentos visibles, un solo agente y nada sensible público.
- **Automatización:** **Feature existente** de archivos/copias/aprobación; **E2E nuevo** multipart público→admin; **manual** para cámara, preview, accesibilidad y red lenta.

### P2-12. Cierre Bipay/caja financiera

- **Base real:** `backend/routes/api.php:286-318`, `frontend/src/pages/bipay/PanelBipayPage.tsx`; pruebas `BipayCajeroTest.php`, `BipayAdminTest.php`, `BipayPanelAvanzadoTest.php`.
- **Pasos:** saldo inicial, operaciones, saldo teórico/real, cierre, alertas y export; comprobar reflejo en cuadre y restricción de cuentas ajenas.
- **Éxito:** saldo inicial + operaciones = final, cierre no duplica, alertas coherentes y scoping correcto.
- **Automatización:** **Feature existente** sustancial; **E2E nuevo** de consola/cierre; **manual** para conciliación real.

**Orden:** P0 antes del pase, P1 antes de aceptación y P2 si Bipay integra el cierre. Registrar fecha, rol, tienda, dispositivo, IDs, resultado, evidencia y defecto. Salida: cero críticos/altos abiertos en P0, cero diferencias monetarias/stock sin explicar y reejecución verde de flujos afectados.

## SECCIÓN B — OPTIMIZACIONES

Los tamaños son la foto de `frontend/dist` auditada. Validar índices con `EXPLAIN ANALYZE` y datos MySQL de staging: SQLite no demuestra el plan de producción.

| ID | Hallazgo verificable | Impacto | Fix concreto | Esfuerzo | Modelo |
|---|---|---:|---|---:|---|
| **OPT-01** | **N+1 al revertir ventas/chips.** `ReporteController.php:1087-1112`: por venta consulta movimientos y por movimiento hace `increment`; ~1+V+M queries. | **Alto** | Un `whereIn` de movimientos, agrupar por `inventario_chip_id`, actualizar una vez por lote y borrar con un `whereIn`; conservar locks/transacción y test de presupuesto de queries. | M, 1-2 d | **Opus 4.8** |
| **OPT-02** | **N+1 al confirmar lote.** `TrasladoController.php:393-397,413-441`: consulta/bloquea cada inventario y vuelve a buscar accesorio destino por ítem. | **Alto** | Bloquear orígenes con `whereIn`, indexarlos en memoria y agrupar accesorios por destino/nombre/tipo/estado; orden determinista de locks y prueba concurrente MySQL. | L, 2-4 d | **Opus 4.8** |
| **OPT-03** | **N+1 al cancelar lote.** `TrasladoController.php:532-538` ejecuta un update por producto. | **Medio** | `whereIn('id', pluck('producto_id'))->update(...)` único dentro de la transacción. | S, 2-4 h | **Sonnet 5** |
| **OPT-04** | **Índice de reportes incompleto.** Listado filtra tienda/estado/fecha y ordena fecha/id (`ReporteController.php:38-45`); migración sólo `(agente_id,fecha)` y `tienda_id` (`2026_06_07_200000_create_core_tables.php:63-64`). | **Alto** | Medir `(tienda_id,fecha,id)` y `(tienda_id,estado,fecha,id)`; para admin evaluar `(estado,fecha,id)`. Conservar sólo los que `EXPLAIN ANALYZE` justifique. | M, 1 d | **Opus 4.8** |
| **OPT-05** | **Inventario tiene índices simples para consultas compuestas.** `InventarioController.php:52-63,87-105` usa tienda+estado; `TrasladoController.php:303-306,436-441` usa tienda+producto+tipo+estado; migración sólo índices separados (`create_core_tables.php:148-149`). | **Alto** | Índices `(tienda_id,estado,tipo)` y `(tienda_id,producto_nombre,tipo,estado)` tras medir cardinalidad/longitud; evaluar unicidad del agregado. | M, 1 d | **Opus 4.8** |
| **OPT-06** | **Traslados sin índice compuesto.** Accesos lote+estado en `TrasladoController.php:393-397,513-514,533-538`; migración sólo índices aislados (`2026_06_11_000001_create_traslados_tables.php:54-57,86-88`). | **Medio** | `(codigo_lote,estado,id)` y medir `(tienda_origen,estado,created_at)`/`(tienda_destino,estado,created_at)` en stock y chips. | M, 1 d | **Sonnet 5** |
| **OPT-07** | **Chips sin paginación.** `ChipsController.php:22-34` y `TrasladoChipsController.php:309-323` terminan en `get()`. | **Medio** | Paginación server-side, máximo 200; adaptar UI y crear endpoint compacto filtrado para selects de cuadre; Feature de límite/scoping. | M, 1-2 d | **Sonnet 5** |
| **OPT-08** | **Temperatura CRM carga todos los candidatos.** `CrmTemperaturaController.php:45-57` hace `get()`, calcula y pagina en PHP. | **Alto** | Materializar temperatura/última interacción o expresarla en SQL y paginar en MySQL; backfill. Contención: rango obligatorio y máximo de candidatos. | L, 3-5 d | **Opus 4.8** |
| **OPT-09** | **Interacciones de lead sin paginar.** `LeadController.php:102-107`; `show` ya limita 20 (`:52-57`). | **Medio** | Cursor por `(fecha,id)`, máximo 100 y “cargar más”; índice `(lead_id,fecha,id)` (hoy simples en `2026_06_07_154517_create_interacciones_crm_table.php:23-24`). | S-M, 0.5-1 d | **Sonnet 5** |
| **OPT-10** | **Caching backend ausente salvo DNI/RUC.** Cache sólo en `DniController.php:52,93` y `RucController.php:36,63`; config empresa (`ConfiguracionController.php:19-46`), fiscal (`FacturacionConfigController.php:33-40`), planes (`ComisionPlanController.php:16-22`), vendedores (`ReporteController.php:396-405`) y tiendas públicas (`PostulanteController.php:283-289`) consultan siempre. | **Medio** | Claves versionadas/TTL 5-15 min e invalidación en mutaciones; nunca cachear secretos serializados ni respuestas sin scoping. Medir hit ratio. | M, 1-2 d | **Opus 4.8** |
| **OPT-11** | **Bundle inicial pesado.** `App.tsx:4-8` importa guards, `AppLayout` y login síncronos; `AppLayout.tsx:8` importa 39 iconos. `dist/assets/index-F07ABdco.js:1` pesa 384,099 B; `vite.config.ts:26-33` no separa Phosphor. | **Alto** | Lazy del shell privado y guards; verificar tree-shaking/subpaths o chunk estable de iconos; visualizer en CI y presupuesto gzip/brotli; comparar waterfall/Lighthouse. | M, 1-2 d | **Sonnet 5** |
| **OPT-12** | **Chunk monolítico de gráficos.** `vite.config.ts:30` fuerza Recharts; `vendor-charts-BrCw0Y5k.js:1` pesa 393,929 B; imports estáticos en `CrmPage.tsx:21-27`, `EstadisticasPage.tsx:3-6`, `PostpagoPage.tsx:3-7`. | **Medio** | Quitar manualChunk monolítico o lazy-load del gráfico/tab; medir gzip y duplicación por ruta. | M, 1 d | **Sonnet 5** |
| **OPT-13** | **jsQR anticipado.** Import estático en `TerminalAsistenciaPage.tsx:1-3`; chunk 150,848 B. | **Bajo** | `import('jsqr')` al activar escaneo y precarga al conceder cámara; medir entrada y primer scan. | S, 2-4 h | **Sonnet 5** |
| **OPT-14** | **Catálogos frontend vencen a 30 s.** Default `App.tsx:56-58`; config fiscal sin override (`useFacturacionConfig.ts:5-8`); formularios piden 500 agentes/200 tiendas sin override (`UsuariosPage.tsx:70-81`, `BitacoraStockPage.tsx:85-88`). | **Medio** | Hooks select compartidos con endpoint compacto, `staleTime` 5-15 min, `gcTime` e invalidación tras mutar; no aplicarlo a cola/asistencias/saldos vivos. | S-M, 0.5-1 d | **Sonnet 5** |
| **OPT-15** | **Logo base64 en BD/API.** `2026_07_09_000002_create_configuracion_empresa_table.php:27-38` usa `longText`; `ConfiguracionController.php:38-46` devuelve el base64 y `AppLayout.tsx:124-128,218-223` lo pide. Aunque redimensiona (`ConfiguracionController.php:83-96`), base64 agrega ~33% y no aprovecha cache HTTP. | **Medio** | Storage/S3, ruta/hash y URL con `Cache-Control: immutable`+ETag; endpoint de metadatos sin binario y migración con fallback legacy. | M, 1-2 d | **Sonnet 5** |
| **OPT-16** | **Assets huérfanos.** Sólo `favicon.svg` está referenciado; `src/assets/hero.png`, `vite.svg`, `react.svg` y `public/icons.svg` no tienen referencias (13,057/8,710/4,126/5,055 B). | **Bajo** | Eliminar tras confirmar templates externos; chequeo de assets huérfanos. Mantener favicon (`frontend/index.html:5`). | XS, <1 h | **Sonnet 5** |

**Orden:** primera ola OPT-01/02/04/05/08/11; segunda OPT-03/06/07/09/10/12/14/15; higiene OPT-13/16. Capturar antes/después queries/request, p50/p95, memoria, filas examinadas, tamaño gzip, LCP e INP. Backend: SQLite + MySQL staging, `EXPLAIN ANALYZE` y tests de presupuesto de queries. Frontend: artefactos gzip/brotli y Lighthouse/WebPageTest en login, onboarding, dashboard y terminal.
