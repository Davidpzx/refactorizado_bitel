# Plan 16 — Modelo de 4 roles (ambos sistemas)

**Aprobado por el usuario (2026-07-12).** Roles: **Administrador** (opera el sistema), **Gerente** (opera el negocio), **Jefe de Tienda** (opera su tienda), **Agente de Ventas** (opera lo suyo). Sin supervisor de zona.

**Principio anticorrupción (decisión del usuario):** todo lo que *modifica el registro de asistencia* (asistencia manual, corregir horario, aprobar fotos, faltas/permisos, tokens de emergencia, autorizar dispositivos) es SOLO admin/gerente — el jefe de tienda convive a diario con los agentes y no debe poder "ayudarlos". Misma lógica en: aprobar traslados, fijar precios, planilla.

## Matriz congelada (pestaña × rol)

| Módulo | Admin | Gerente | Jefe Tienda | Agente |
|---|---|---|---|---|
| Dashboard | global | global | su tienda | ❌ |
| Cuadres/Reportes lista | todas | todas | su tienda | ❌ |
| Nuevo Reporte (cuadre) | ✅ | ✅ | ✅ | ✅ el suyo |
| Mi Historial | — | — | — | ✅ |
| Historial completo | ✅ | ✅ | su tienda | ❌ |
| Inventario/Kardex/Matriz/Bitácora/Chips | ✅ | ✅ | su tienda (ve costos: flag `ver_costos` default ON) | ❌ |
| Traslados crear/recibir | ✅ | ✅ | ✅ | ❌ |
| Traslados APROBAR | ✅ | ✅ | ❌ | ❌ |
| Revisar Stock/Precios | ✅ | ✅ | ❌ | ❌ |
| CRM/Clientes | ✅ | ✅ | su tienda | ❌ |
| Ventas/Tickets | ✅ | ✅ | su tienda | ✅ crear los suyos |
| Asistencias VER | ✅ | ✅ | solo lectura | app: marcar |
| Asistencias MODIFICAR (manual/fotos/tokens/dispositivos/faltas) | ✅ | ✅ | ❌ | ❌ |
| Presencia/Monitor fraude | ✅ | ✅ | solo lectura | ❌ |
| Planilla/Liquidación | ✅ | ✅ | ❌ | ❌ |
| Comisiones (config planes) | ✅ | ✅ | ❌ | ✅ solo las suyas |
| Estadísticas/MapaCalor/Postpago | ✅ | ✅ | su tienda | ❌ |
| Bipay/CuadreBitel/BCP | ✅ | ✅ | su tienda | ❌ |
| Comprobantes (emitir CPE) | ✅ | ✅ | su tienda | ❌ |
| Financieras | ✅ | ✅ | ❌ | ❌ |
| Tiendas | ✅ | ✅ | ❌ | ❌ |
| Usuarios | todos | todos EXCEPTO crear Administradores | ❌ | ❌ |
| Perfil de Empresa | ✅ | ✅ | ❌ | ❌ |
| Config Facturación/SUNAT | ✅ | ❌ | ❌ | ❌ |
| Integrador Bitel | ✅ | ❌ | ❌ | ❌ |
| Diagnóstico | ✅ | ❌ | ❌ | ❌ |
| Postulaciones RRHH | ✅ | ✅ | ❌ | ❌ |

## Diseño técnico común
- Valores de rol: `administrador`, `gerente`, `jefe_tienda`, `agente`. **Migración retrocompatible**: `admin`→`administrador`, `tienda`→`jefe_tienda` (data migration + los middleware aceptan los alias viejos durante la transición para no romper sesiones/tokens vivos).
- Agente de Ventas: usuario vinculado a `agente_id` (ya existe la columna); su scoping es "solo sus filas", no "su tienda".
- Interno: roles como paquetes de permisos (el check es por permiso; el rol los agrupa) — permite flags tipo `ver_costos` sin crear roles nuevos.

## Tickets — Refactor (`refactorizado_bitel`)

| # | Ticket | Alcance | Modelo |
|---|---|---|---|
| R1 | Fundación: migración de roles + EnsureRole con jerarquía/alias + helper de permisos + factories/seeders | backend (migración data, middleware, User/Usuario model) — retrocompatible, suite verde | Opus 4.8 |
| R2 | Matriz de rutas: reetiquetar los `role:` de routes/api.php según la matriz congelada (admin→administrador,gerente; tienda→+jefe_tienda; nuevos grupos agente) + tests 403 por rol | backend routes + tests | Opus 4.8 |
| R3 | Scoping "solo lo mío" del agente: mis-reportes/mi-historial/sus comisiones/sus tickets filtrados por agente_id del usuario | backend controllers + tests | Sonnet 5 |
| R4 | Endpoints de asistencia que modifican (manual, photo-action, tokens, autorizar-dispositivo, faltas, corregir horario) a `role:administrador,gerente` + tests | backend + tests | Sonnet 5 |
| R5 | Frontend: sidebar/rutas por rol (AppLayout + AdminRoute→guards por rol), UsuariosPage con los 4 roles (gerente no crea administradores), ocultar acciones según matriz | frontend | Opus 4.8 |
| R6 | Login/`auth/me` expone rol nuevo + `agente_id`; QA integral por rol (crear 4 usuarios de prueba, recorrer matriz) | full + tests | Sonnet 5 |

## Tickets — Legacy (`sistema-rolando-salas`, producción viva — cambios con doble cuidado)

| # | Ticket | Alcance | Modelo |
|---|---|---|---|
| L1 | Fundación: columna/valores de rol + `require_rol([...])` helper central (hoy los checks son `$_SESSION['rol']==='admin'` dispersos) + mapeo retrocompatible | config/includes + data | Opus 4.8 |
| L2 | Gates por página según la matriz (gerencia/*.php: cada página declara sus roles al inicio) + menú del header filtrado por rol | PHP páginas + header | Sonnet 5 |
| L3 | Asistencias-modificar solo admin/gerente (panel_asistencias: modales manual/falta/corregir/tokens ocultos y endpoints ajax con gate) | gerencia + ajax | Sonnet 5 |
| L4 | Rol agente en legacy: vista "mi historial/mis comisiones" mínima (o decidir que el agente SOLO usa el refactor/app — RECOMENDADO: agente vive en el refactor, el legacy no le da login) | decisión + gates | — (decisión usuario) |

**Recomendación L4:** no darle login de agente al legacy — los agentes usan la app + el refactor. El legacy queda para admin/gerente/jefe mientras siga vivo. Menos superficie, menos mantenimiento doble.

**Orden:** R1→R2→(R3,R4 paralelo)→R5→R6, luego L1→(L2,L3 paralelo). Deploy del refactor al cerrar R6; deploy del legacy coordinado con el usuario (producción viva).
