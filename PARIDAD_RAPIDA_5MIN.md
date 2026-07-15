# Paridad rápida: proyecto B vs proyecto A

> **Corte urgente, 2026-07-12.** Comparación de alto nivel basada en árboles, nombres de controladores/páginas y rutas declaradas. No se comparó lógica línea por línea. **“Sí” o “parcial” significa equivalente estructural aparente, no paridad funcional demostrada.**

## Resumen ejecutivo

**B parece ser el proyecto real del SPA:** combina un backend Laravel API, un frontend React/Vite y una app Android/Capacitor. Su superficie visible es mucho mayor que A: aproximadamente **49 controladores API y 253 declaraciones de ruta** en B, frente a **7 controladores y 44 rutas** en A.

**Dictamen rápido:** A no es un reemplazo estructuralmente equivalente de B. Solo presenta cobertura reconocible —y en general parcial— para autenticación, asistencia, inventario, reportes y administración/gerencia. Numerosos módulos especializados de B no tienen ruta/controlador/página activa visible en A.

Además, la auditoría previa incluida en A indica que sus 44 callbacks MVC no eran despachables por una incompatibilidad de namespaces y que varias capacidades aparentes solo existen en `backup_gerencia_legacy/`. Por ello, las coincidencias de A de esta tabla no deben interpretarse como rutas operativas.

## Arquitectura y rutas

| Área | Proyecto B | Proyecto A | Paridad estructural rápida |
|---|---|---|---|
| Backend | Laravel, `backend/routes/api.php`, controladores `backend/app/Http/Controllers/Api`, Sanctum, middleware, services/jobs/tests | Front controller `index.php`, router propio `core/Router.php`, controladores en `app/Controllers`, sesión PHP y fallback legacy | **No**: arquitecturas y contratos de API muy distintos |
| Frontend | React/Vite SPA con unas 45 rutas, páginas y servicios API; Android/Capacitor para asistencia | Vistas PHP server-rendered en `app/Views`; no hay SPA equivalente | **No** |
| Superficie | ~49 controladores API / ~253 declaraciones `Route` | 7 controladores / ~44 rutas | **No** |

## Matriz de módulos visibles en B

| Módulo/capacidad de B | Evidencia visible en B | Equivalente visible en A | Estado |
|---|---|---|---|
| Login, sesión y logout | `/v1/auth/login`, `auth/me`, `auth/logout`, `LoginPage`, Sanctum | `/auth/login`, `AuthController`, sesión PHP, login/logout legacy | **Parcial** |
| Roles y permisos | 4 roles canónicos, aliases, `EnsureRole`, `Permisos`, guards y `RolRoute` | `RoleMiddleware` y configuración centrados en `admin`/`tienda` | **Parcial fuerte** |
| PIN, dispositivo y revocación | `verify-pin`, autorización de dispositivo, tokens y revocación | Validación PIN/token/dispositivo en modelos/API de seguridad, sin revocación multi-token equivalente | **Parcial** |
| Dashboard/control center | KPIs, anomalías, exportación y control center | Panel de gerencia | **Parcial** |
| Asistencia | Terminal, estado/marcación, QR, foto, GPS/presencia, consentimiento, control, liquidación y antifraude | Terminal, panel y procesamiento básico de asistencia | **Parcial** |
| App terminal móvil | Android/Capacitor, versión, descarga y carga de APK | No visible | **No** |
| Inventario general | CRUD, precios, stock real, restauración, capital/costos | Inventario, registro de stock, precios y ajustes básicos | **Parcial** |
| Matriz/Kardex/chips | Matriz, Kardex, stock estancado, chips y exportaciones | Sin equivalente activo claro; algunos conceptos aparecen en legado/auditoría | **Parcial/No** |
| Traslados | Equipos/chips, confirmaciones y constancias | No visible en MVC activo | **No** |
| Reportes | CRUD, borrador, detalle, edición/aprobación, historial y Excel | Reporte nuevo y modelo; historial limitado | **Parcial** |
| Agentes/personal | CRUD, ficha, seguridad, documentos, historial, RR. HH. | Gestión/detalle/historial de agentes en gerencia | **Parcial** |
| Usuarios y tiendas | CRUD y revocación de tokens | Rutas nominales/legado de usuarios y tiendas | **Parcial** |
| Estadísticas/mapa de calor | Ventas, productividad, rankings y mapa | Estadísticas de gerencia; sin mapa visible | **Parcial** |
| Bitácora/revisión de stock | Bitácora, corrección, KPIs, revisión | Bitácora y revisión de stock | **Sí estructural / no verificado funcionalmente** |
| Comisiones | Planes, recálculo y configuración | Configuración de comisiones nominal/legacy | **Parcial** |
| Clientes y ventas | Recursos API y páginas de clientes | Sin controladores/rutas dedicados; posible lógica embebida no verificada | **No/Parcial** |
| CRM/leads/temperatura | Dashboard, pipeline, interacciones y exportación | No visible en MVC activo | **No** |
| Facturación/comprobantes/CPE/SUNAT | Configuración, cola, emisión, descarga, NC/anulación y CPE público | Indicios de boletas solo en `backup_gerencia_legacy` | **No** |
| Tickets | Gestión e impresión pública | No visible | **No** |
| Bipay/cuadre Bitel/auditoría | Panel, cajero, cuadre y auditoría | No visible | **No** |
| Reporte BCP | Página y endpoints dedicados | No visible | **No** |
| Planilla/RR. HH./boletas/adelantos | Cálculo, ajustes, exportación y perfil RR. HH. | No visible como módulo activo | **No** |
| Postpago | Resumen, ventas y exportación | No visible | **No** |
| Postulaciones | Flujo público y administración | No visible | **No** |
| Financieras | Panel dedicado | No visible | **No** |
| Integrador Bitel | Saldo, morosidad, histórico y configuración | No visible | **No** |
| Configuración/diagnóstico | General, logo, facturación e integraciones | Configuración parcial/nominal; sin diagnóstico dedicado | **Parcial/No** |
| Consulta DNI/RUC | Endpoints dedicados | Consulta DNI en gerencia/legacy | **Parcial** |

## Rutas/páginas SPA de B sin equivalente visible en A

Postulaciones, tickets, CPE, clientes, CRM, traslados, Bipay/cuadre, reporte BCP, postpago, mapa de calor, revisión de fotos, planilla, financieras, configuración de facturación, diagnóstico e integrador. También faltan equivalentes completos para las variantes avanzadas de inventario, reportes y asistencia.

## Riesgos y observaciones prioritarias

1. **A no coincide con el sistema servido en producción.** La auditoría previa ya observó que `app.kyrocodelabs.cloud` entrega un SPA React; la estructura de B sí corresponde a ese patrón y la de A no.
2. **Brecha de superficie crítica.** ~253 rutas y 49 controladores API en B frente a ~44 rutas y 7 controladores en A; la ausencia no parece ser solo un cambio de nombres.
3. **Los equivalentes de A pueden no ser ejecutables.** La auditoría previa de A reporta callbacks MVC rotos por namespaces, rutas 404, vistas/métodos faltantes y capacidades que solo sobreviven en `backup_gerencia_legacy/`.
4. **Auth y autorización no son intercambiables.** B usa Sanctum/bearer tokens, throttling, cuatro roles canónicos y guards; A usa sesión PHP y dos roles principales. Migrar rutas directamente puede bloquear usuarios o sobreautorizar acciones.
5. **Paridad por nombre no prueba contrato ni datos.** No se verificaron payloads, esquemas, transacciones, ownership por tienda, comportamiento de endpoints, builds ni pruebas de integración.

## No verificado por tiempo

- Lógica interna de controladores/modelos y equivalencia de reglas de negocio.
- Contratos request/response entre cada servicio React y Laravel.
- Esquemas y migraciones de B contra el dump SQL de A.
- Ejecución real de rutas, pruebas, colas, tareas programadas e integraciones externas.
- Si alguna capacidad ausente está escondida dentro de `GerenciaController` o archivos legacy sin una ruta activa.
