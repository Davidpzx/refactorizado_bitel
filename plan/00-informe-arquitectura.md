# Informe de brechas estructurales Legacy -> Nuevo

Fecha: 2026-07-08  
Alcance: auditoria por muestreo representativo de estructura y codigo, mas lectura de `docs/comparacion/GAP_ANALYSIS_MAESTRO_2026-07-02.md` y `docs/comparacion/GAPS_PENDIENTES_v2.md`. No es un inventario exhaustivo linea por linea.

## 1. Patrones y convenciones del sistema nuevo

### Backend Laravel 12

| Aspecto | Convencion observada | Evidencia / criterio |
|---|---|---|
| Entrada HTTP | API REST versionada bajo `routes/api.php`, prefijo `/api/v1`, respuestas JSON. | Controllers en `app/Http/Controllers/Api/*Controller.php`. |
| Autenticacion | Laravel Sanctum con bearer token. Login revoca tokens previos `name=api`; frontend guarda `auth_token` en `localStorage`. | `AuthController`, `src/services/api.ts`, `src/store/authStore.ts`. |
| Autorizacion | Middleware `auth:sanctum`, `role:admin,tienda`; `tienda` se asimila a `vendedor` en `EnsureRole`. Algunas reglas finas viven dentro del controller. | `EnsureRole`, rutas `role:*`, validaciones de tienda propia en `IntegradorController`. |
| Validacion | Mezcla de `Request::validate()` inline y `FormRequest` para flujos mas estructurados. | `StoreAgenteRequest`, `ComprobanteController`, `IntegradorController`. |
| Capas | Hay `Models`, `Services`, `Jobs`, `Console/Commands`, `Http/Requests`, `Middleware`. Los controllers aun contienen bastante logica de dominio y SQL directo. | `GreenterService`, `CuadreBitelService`, `AuditoriaBipayService`, `ComisionOperativaService`, controllers con `DB::table`. |
| Persistencia | Eloquent para entidades principales; `DB::table`/SQL crudo para compatibilidad legacy, reportes y cruces. Migraciones idempotentes con `Schema::hasTable/hasColumn`. | `Reporte`, `Comprobante`, migraciones `2026_*`, `IntegradorController`. |
| Procesos async | Jobs Laravel para cola (`EnviarComprobanteSunat`) y scheduler en `routes/console.php` para comandos recurrentes. | `app/Jobs`, `app/Console/Commands`, `Schedule::command`. |
| Errores | 422 por validacion, 403 por permisos, 400 por payload invalido, 500 con log en integraciones. No hay envelope unico global. | Controllers API. |
| Archivos | `Storage` para XML/CDR SUNAT; logos aun pueden vivir en BD como base64. | `GreenterService`, `ConfiguracionController`. |

### Frontend React 19 + TypeScript + Vite

| Aspecto | Convencion observada | Implicacion para nuevas piezas |
|---|---|---|
| Rutas | `App.tsx` centraliza rutas con `React.lazy`, `ProtectedRoute`, `AdminRoute`, `AppLayout`. | Toda nueva pagina debe registrarse ahi y respetar segmentacion publica/autenticada/admin. |
| Data fetching | `@tanstack/react-query` esta instalado y se provee con `QueryClientProvider`; existen hooks por dominio (`useInventario`, `useReportes`, etc.). | Preferir hooks por dominio para consultas/mutaciones reutilizables. |
| API client | Axios unico en `src/services/api.ts`, base URL por `VITE_API_BASE_URL`, bearer token via interceptor. | No crear clientes HTTP paralelos ni fetch ad hoc salvo casos muy puntuales. |
| Servicios | `src/services/*.api.ts` agrupa endpoints por dominio y tipos TS asociados. | Nuevos modulos deben agregar `services/<dominio>.api.ts` y tipos en `src/types` si son compartidos. |
| Estado global | Zustand solo para auth; el resto tiende a estado local + React Query. | No meter estado de negocio global si puede vivir en cache de queries. |
| UI | Paginas en `src/pages/<dominio>`, componentes compartidos en `src/components`, controles base en `src/components/ui`. | Mantener paginas finas y extraer paneles/dialogos repetibles. |

## 2. Brechas estructurales

### Resumen ejecutivo de brechas

| Pieza legacy | Estado en nuevo | Brecha arquitectonica |
|---|---|---|
| Facturacion electronica SUNAT multi-emisor | Existe `GreenterService`, `ComprobanteController`, `EnviarComprobanteSunat`, `config/sunat.php`. | El nuevo es global por `.env`; no modela `facturacion_config` por tienda/emisor, `company_id/branch_id`, configuracion editable, ni carga segura de certificados por UI. |
| Cola legacy de comprobantes | Nuevo usa Job Laravel sobre tabla `comprobantes`. | Falta mapear semantica completa de `comprobantes_cola`: payload fiscal, max intentos, proximo intento, api_doc_id, archivos PDF/XML, estados retryable/no retryable. |
| Cron PHP procedural | Nuevo tiene scheduler Laravel y comandos. | Varias reglas legacy deben vivir como `Console\Command` + `Schedule`, no como scripts PHP sueltos ni auto-migraciones dentro de cron. |
| Integrador Bipay cifrado | Nuevo tiene `IntegradorController`, tokens hash, `Crypt::encryptString`, endpoints M2M. | Controller concentra demasiado dominio; compatibilidad con credenciales legacy AES-256-GCM requiere migracion/bridge explicito si se importan datos cifrados. |
| `reporte_categorias.detalle` JSON | Nuevo conserva consultas JSON en varios puntos, pero tambien tiene tablas normalizadas (`ventas`, `venta_items`, `venta_lineas`, `venta_equipos`). | No hay frontera unica para normalizar objeto vs array; riesgo de reintroducir parsing disperso y reglas inconsistentes. |
| Sesiones PHP y middleware de asistencia | Nuevo usa tokens; `open.shift` cubre parte del requisito. | Las reglas de sesion legacy con redireccion y asistencia diaria deben traducirse a middleware/API, no replicarse en React. |
| Configuracion dinamica legacy | Nuevo tiene `configuracion_empresa`, `sys_config` pendiente y `.env`. | Riesgo de dos o tres fuentes de verdad (`configuracion_empresa`, `sys_config`, `.env`) para parametros operativos. |

### Detalle por pieza critica

| Legacy / necesidad | Donde debe vivir en el nuevo | Convencion a seguir | Que NO hacer |
|---|---|---|---|
| SUNAT: configuracion por tienda/emisor (`facturacion_config`, `company_id`, `branch_id`, series, IGV, modo). | `database/migrations` para tabla `facturacion_configuraciones` o similar; `app/Models/FacturacionConfig`; `app/Http/Controllers/Api/FacturacionConfigController`; `app/Services/Sunat/*`. | Resolver config por tienda con fallback global, igual que legacy, pero con modelo Laravel y validacion API. Inyectar config al servicio, no leer siempre `config('sunat')`. | No meter multiples emisores en `.env`; no duplicar datos fiscales en `configuracion_empresa`; no hardcodear series en controller. |
| SUNAT: certificados PFX/PEM y credenciales SOL. | Endpoint admin con upload en controller dedicado; almacenamiento en `storage/app/private/sunat/<emisor>`; conversion/validacion en service; password/clave SOL cifradas si se persisten. | Usar `Storage`, validacion `file/mimes`, permisos admin, logs sin secretos. Si se acepta PFX antiguo, convertir con OpenSSL o `openssl_pkcs12_read` en capa service. | No guardar certificados en `public`; no guardar passwords en texto plano; no procesar certificados desde React; no loggear nombres de clave/secretos completos. |
| SUNAT: emision y cola. | `ComprobanteController` crea intencion; `EnviarComprobanteSunat` ejecuta; service arma XML/envia; tabla `comprobantes` o nueva tabla de cola si se requiere payload fiscal congelado. | Mantener request web rapido e idempotente; job con backoff; estados claros (`PENDIENTE`, `ENVIADO`, `ACEPTADO`, `RECHAZADO`, `ANULADO`); correlativo por serie con lock/transaccion. | No llamar SUNAT sincronicamente desde el request; no recalcular payload fiscal mutable en cada retry sin snapshot; no mezclar tickets internos con CPE oficial sin tipo explicito. |
| Nota de credito / anulaciones SUNAT. | `ComprobanteController` o controller dedicado `NotasCreditoController`; `GreenterService` extendido con builder de notas; migraciones para referencias al comprobante afectado. | Reutilizar cola/job y relaciones Eloquent; validar que comprobante afectado exista y este aceptado. | No anular solo la venta local si el CPE ya fue aceptado; no crear notas sin vinculo fiscal. |
| Cron legacy (`cron_salida_automatica`, `limpiar_fotos`, `auto_retorno`, auditoria Bipay, cola comprobantes). | `app/Console/Commands/*` + `routes/console.php`; deploy con `php artisan schedule:run` y workers. | Cada cron debe ser idempotente, con `withoutOverlapping`, timezone `America/Lima`, logs y metricas minimas. Crear/alterar tablas solo por migracion. | No conservar scripts en `cron/*.php`; no invocar endpoints HTTP internos como cron; no hacer `CREATE TABLE` dentro de comandos nuevos. |
| Integrador Bipay: credenciales cifradas legacy AES-256-GCM. | Si se migra data legacy: comando one-off `php artisan bitel:migrar-integrador-credenciales` que lea con `INTEGRADOR_KEY` y re-cifre con `Crypt`; luego eliminar dependencia. | Nuevo runtime debe usar `Crypt`/`APP_KEY`; token de agente solo hash en BD; API key M2M desde `services.integrador.api_key`. | No intentar descifrar AES legacy en cada request normal; no persistir token de agente en claro; no devolver password Bitel salvo al agente autenticado por token valido. |
| Integrador Bipay: dominio M2M, colas, morosidad, historico. | Extraer de `IntegradorController` a services: `IntegradorCredentialService`, `BitelSyncIngestService`, `BitelHistoricoQueueService`; mantener controller como adaptador HTTP. | Transacciones DB, `upsert`, `insertOrIgnore`, validacion timestamp/API key, limites por tienda/rol. | No seguir creciendo un controller monolitico; no permitir que React construya contratos M2M; no confiar solo en `channel_code` enviado por agente. |
| `reporte_categorias.detalle` objeto o array. | Crear helper/service unico, por ejemplo `ReporteDetalleNormalizer`, usado por reportes, inventario, ranking, planilla, financieras y exports. | Regla obligatoria: `decode -> if object wrap as one-item array -> validar keys -> operar array -> al guardar preservar forma solo si compatibilidad lo exige`. Para nuevas features, preferir tablas normalizadas `ventas/*`. | No hacer `isset($detalle[0])` copiado en cada controller; no asumir que siempre es array; no crear nuevas consultas JSON si la data ya existe normalizada. |
| BD legacy sin equivalente claro. | Migraciones auditadas en `database/migrations`; modelos solo si tendran dominio estable. | Basarse en docs de gaps: revisar `facturacion_config`, `comprobantes_cola`, `log_ediciones_asistencia`, `excepciones_jornada`, tablas integrador pendientes, `sys_config`. | No auto-migrar desde requests; no crear tablas con nombres legacy si el dominio nuevo ya tiene una entidad mejor sin plan de mapeo. |
| Sesiones PHP legacy con rol `admin/tienda` y asistencia obligatoria. | Sanctum + middleware Laravel (`EnsureRole`, `RequireOpenShift`) + endpoints `/auth/me`; React solo consume permisos. | Backend decide permisos y scope de tienda; frontend oculta accesos pero no es fuente de seguridad. | No portar `$_SESSION` ni redirecciones server-rendered; no resolver permisos solo en `AdminRoute`; no confiar en `tienda_id` enviado por cliente. |
| Subida de documentos/certificados/logos. | Controllers dedicados + `Storage`; tablas con metadata; policies/role middleware. | Separar archivos publicos (logos) de privados (certificados, DNI, contratos). Limites de tamano y MIME. | No guardar certificados como base64 en `configuracion_empresa`; no poner privados bajo `public`; no mezclar certificado SUNAT con documentos de agente. |

## 3. Riesgos tecnicos de migracion a paridad

| Riesgo | Impacto | Mitigacion estructural |
|---|---|---|
| Tablas legacy sin equivalente o migraciones no corridas. | Features que compilan pero fallan en produccion; datos huerfanos o doble fuente. | Antes de integrar features, auditar migraciones aplicadas y decidir ownership por tabla. Las 14 tablas del integrador documentadas como pendientes deben tratarse como prerequisito operativo. |
| `facturacion_config` y `comprobantes_cola` no modeladas en Laravel. | SUNAT puede funcionar para un emisor global, pero no alcanza paridad multi-emisor/por tienda. | Introducir modelo de emisor/configuracion y snapshot fiscal por comprobante antes de activar CPE real en multiples tiendas. |
| Sesiones PHP vs tokens. | Perdida de reglas implicitas: asistencia obligatoria, tienda propia, exenciones por gerente/jefe/admin. | Traducir reglas a middleware y policies del backend. React debe ser solo UX. |
| JSON semiestructurado en `reporte_categorias.detalle`. | Bugs silenciosos en comisiones, ranking, financieras, inventario y exports cuando el root alterna objeto/array. | Centralizar normalizacion y escribir tests de casos: objeto unico, array multiple, JSON invalido, campos faltantes. |
| Cron y colas. | Jobs no ejecutados si falta `schedule:run` o worker; reprocesos duplicados si no hay idempotencia. | Definir matriz operativa: scheduler, workers, colas, logs, retries. Usar locks y claves unicas donde haya importacion/sync. |
| Certificados y credenciales. | Exposicion de PFX/PEM, clave SOL, password Bitel, API keys. | Almacenamiento privado, cifrado en reposo para secretos, redaccion de logs, endpoints admin auditados. |
| Integrador Bipay y claves de cifrado. | Credenciales legacy cifradas con `INTEGRADOR_KEY` no son legibles por `Crypt(APP_KEY)` sin migracion. | Migracion controlada de secretos: descifrar una vez con clave legacy y re-cifrar con `Crypt`; documentar rollback. |
| Controllers con demasiado SQL directo. | Dificulta pruebas, reuso y consistencia entre pantallas. | Para nuevas piezas de dominio critico, crear services pequenos y tests unitarios/feature. |
| Correlativos SUNAT sin lock robusto. | Doble numero en concurrencia. | Generar correlativos dentro de transaccion con bloqueo por serie/emisor, o tabla dedicada de secuencias. |
| Archivos privados en despliegue. | Redeploy puede borrar certificados/agentes si se guardan en path no persistente. | Usar volumen persistente para `storage/app/private` y provisionar binarios del agente en `storage/app/integrador/agente`. |

## 4. Recomendacion de secuencia de integracion

| Orden | Bloque | Por que minimiza retrabajo |
|---:|---|---|
| 1 | Congelar decisiones de dominio: `sys_config` vs `configuracion_empresa`, modelo final SUNAT, compatibilidad de cifrado integrador, uso futuro de `reporte_categorias`. | Evita construir pantallas contra fuentes de verdad que luego se descartan. |
| 2 | Estado de BD y migraciones: aplicar/verificar migraciones pendientes, mapear tablas legacy sin destino, crear migraciones faltantes para facturacion/configuracion. | Sin esquema estable, controllers y UI quedan fragiles. |
| 3 | Normalizador de `reporte_categorias.detalle` + tests. | Es una dependencia transversal de reportes, inventario, comisiones, ranking, financieras y exports. |
| 4 | Cron/scheduler operativo: comandos, schedule, workers, logs y locks. | Muchos modulos dependen de procesos fuera del request: SUNAT, asistencia, auditoria Bipay, historicos. |
| 5 | Integrador Bipay hardening: extraer services, migrar credenciales si aplica, validar agente ZIP/binarios, cerrar contrato M2M. | Reduce riesgo de secretos y estabiliza datos para auditoria, morosidad y cuadre Bitel. |
| 6 | SUNAT multi-emisor completo: configuracion por tienda/emisor, certificados privados, correlativos, job con snapshot fiscal, UI admin. | Requiere BD, archivos, colas y decision de dominio ya cerradas. |
| 7 | Paridad funcional por modulos de negocio: financiero/reportes, asistencia/RRHH, tienda/inventario, CRM. | Con infraestructura estable, las pantallas consumen APIs consistentes y se evita duplicar parsing/cron/permisos. |
| 8 | Exports/PDFs y pulido de UX. | Dependen de datos ya normalizados y reglas de permisos definitivas. |

Regla de arquitectura para la paridad: portar comportamiento legacy no significa portar forma legacy. En el nuevo, las reglas de negocio viven en services/comandos/jobs/middleware; los controllers adaptan HTTP; React consume servicios tipados; y los scripts PHP procedurales no deben reaparecer como islas dentro de Laravel.
