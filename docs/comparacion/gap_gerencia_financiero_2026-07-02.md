# Gap de paridad — gerencia/ financiero y reporting (sis_bipay legacy vs refactorizado_bitel)

Análisis READ-ONLY. Fuente de verdad: `E:\laragon\www\sis_bipay\gerencia\`. Destino: `C:\xampp\htdocs\refactorizado_bitel\` (backend Laravel 12 + frontend React 19). El doc viejo `docs\comparacion\PARIDAD_MASTER.md` (14-jun) se usó solo como pista y se confirmó **desactualizado**: subestima sistemáticamente varias brechas (marca ✅ simple donde el código actual muestra ⚠️/❌), y tiene puntos ciegos totales (no menciona `panel_postpago.php`, `crm_dashboard.php` como tal, `mapa_calor.php`).

## Alcance verificado

**Incluido** (financiero/reporting/dashboard de `gerencia/`): `cuadre_global.php`, `historial_completo.php`, `eliminar_reporte.php`, `autorizar_edicion.php`, `marcar_entregado.php`, `exportar_excel.php`, `panel_financieras.php`, `confirmar_desembolso.php`, `panel_bipay.php` (+ `ajax_reconcile_details.php`, `ajax_auditoria_ajuste.php`, `ajax_movimientos_dia.php`), `panel_postpago.php`, `reporte_bcp.php`, `tickets_emitidos.php`, `panel_gerencia.php`, `crm_dashboard.php` (+ `ajax_crm_dragdrop.php`, `ajax_resolver_conflicto.php`), `mapa_calor.php`, `api_heatmap_ventas.php`, `estadisticas_ventas.php`, `migrar_formato_ticket.php` (one-off).

**Excluido** tras leer el código real (dominio de otro analista, o resultó no ser lo que el nombre sugiere):
- **`accion_boleta.php` / `imprimir_boleta.php`**: pese al nombre, son la liquidación de **pago de sueldo/planilla** de un agente (tabla `pagos_planilla`), invocadas desde `ver_agente.php`. Dominio planilla/agentes. Se documentan igual abajo por transparencia (hallazgo de nomenclatura engañosa), con paridad ✅ confirmada contra `ConstanciaController`.
- **`aprobar_postulante.php`**: aprobación de postulantes a agente (inserta en tabla `agentes`, define horario/sueldo/PIN). 100% RR.HH./agentes.
- **`recalcular_ganancias.php`**: recalcula ganancia al fijar `precio_costo` tarde; invocado desde `fijar_precio.php` (inventario/costeo).

---

## 1. Cuadre Global BiPay

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Recarga BiPay declarada (reportes) vs movimientos scrapeados sin agente, por tienda, con diferencia y estado CUADRADO/DESCUADRADO | `cuadre_global.php` (112 líneas, completo) | `CuadreBitelController::global()` → `CuadreBitelService::cuadreGlobal()`; `CuadreBitelPage.tsx` tab "Cuadre global" | ✅ | Verificado línea por línea: lógica SQL idéntica (agrupar `reportes.recarga_bipay` por tienda vs `bitel_operaciones_detalle` con `codigo_personal IS NULL`, `ABS(monto)`). El propio controller documenta ser puerto explícito de este archivo. Paridad completa backend+frontend. |

## 2. Reportes — eliminar / autorizar edición / marcar entregado / exportar auditoría

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Eliminar reporte con rollback de stock (IMEIs a DISPONIBLE, borrar comisiones/categorías/salidas/historial), transaccional | `eliminar_reporte.php` (97 líneas) | `ReporteController::destroy()` + `revertirVentas()` (`DELETE /v1/reportes/{reporte}`) | ⚠️ parcial | (a) Legacy es admin-only estricto; destino usa `autorizarPropietarioOAdmin()` (admin **o el dueño**) — endpoint más permisivo que el legacy, aunque el frontend solo muestre el botón a admin. (b) Destino agrega bloqueo nuevo si `estado==='aprobado'` (regla nueva, no existía en legacy — confirmar con negocio si es deseada). (c) Destino además repone `inventario_chips` vía `venta_chip_movimientos` (mejora: legacy no revertía chips, solo equipos con IMEI). |
| `autorizar_edicion.php` (flujo viejo: admin baja `requiere_aprobacion`, `estado='borrador'`) | `autorizar_edicion.php` (28 líneas) | — | 🔁 N/A | **Confirmado código muerto en el propio legacy**: ningún link/form en todo `sis_bipay` lo invoca. Fue reemplazado en la práctica por el flujo vivo de abajo (solicitar/aprobar/denegar edición), que sí está completamente portado. |
| Flujo vivo Solicitar → Aprobar/Denegar edición (`estado_edicion` SOLICITADO→APROBADO), el que realmente usa la UI | Modales en `panel_gerencia.php`/`historial_completo.php` | `ReporteController::solicitarEdicion/aprobarEdicion/denegarEdicion` (rutas `reportes/{id}/{solicitar,aprobar,denegar}-edicion`) | ✅ | Paridad completa; destino agrega "Denegar" explícito (mejora). |
| Marcar efectivo ENTREGADO / revertir a TIENDA + registro en `historial_reportes`, permitido a **cualquier rol autenticado** (tienda incluida, según comentario explícito del legacy "ya no hay restricción de admin") | `marcar_entregado.php` (49 líneas) | `ReporteController::actualizarDestino()` (`PATCH reportes/{id}/destino-efectivo`), `abort_unless(rol==='admin')` | ⚠️ **regresión de permisos** | El destino restringe la acción solo a admin — el rol `tienda` **perdió la capacidad de marcar/revertir su propio efectivo** que sí tenía en legacy. Además el dominio de valores cambió de binario `TIENDA`/`ENTREGADO` a 5 códigos nuevos (`BANCO/GERENCIA/EN_CAJA/AGENTE/TIENDA`) persistidos directo en la columna — cambio de contrato de datos respecto a la convención documentada (`TIENDA`\|`ENTREGADO`). |
| Exportar Auditoría Excel de UN reporte individual (secciones postpago/prepago/equipos/servicios/apoyo/salidas/dinero digital/cuadre financiero/historial de ediciones), admin (todo) o tienda (su propio reporte) | `exportar_excel.php` (479 líneas) | — | ❌ **falta por completo** | No existe endpoint XLSX por-reporte en el destino. Lo más cercano, `GET /v1/constancias/reporte/{id}` (`ConstanciaController::reporte`), genera un **PDF** mucho más simple: sin desglose por categoría, sin `efectivo_neto`/`total_cajon`, sin dinero digital, sin historial de ediciones. El export masivo (`HistorialController::exportar`) tampoco sustituye esto (formato de "bloques" distinto, admin-only, agrega TODOS los reportes filtrados en vez de auditar uno solo). |

## 3. Panel Gerencia (Dashboard)

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| KPIs principales (Total General / Físico Esperado / Físico Declarado / Diferencia) | `panel_gerencia.php` | `DashboardController::kpis()` + `DashboardPage.tsx` | ✅ | Mismos campos, mismo filtro de rol. |
| Tarjetas Dinero Digital (Yape/Bipay/Transferencia) | `panel_gerencia.php` | mismo endpoint | ✅ | — |
| KPI "Ganancia Total del Periodo" (admin only) | `panel_gerencia.php` | `DashboardController::kpis()` → `ganancia_total` | ⚠️ **posible subestimación** | Legacy suma 7.5% fijo de comisión sobre categorías `pagos_recargas`+`paquetes`, además de `comision_generada`/`ganancia` embebidos. Destino solo suma `venta_equipos.ganancia_snap` + `venta_lineas.comision_unitaria*cantidad` — no se encontró lógica equivalente al 7.5% de recargas/paquetes en el nuevo modelo. El KPI podría estar **subestimando la ganancia real** frente al legacy; verificar con negocio. |
| Campanita de Anomalías (offcanvas, admin, diferencia≠0 o `estado='editado'` en 30 días) | `panel_gerencia.php` | `DashboardController::anomalias()` + botón en `DashboardPage.tsx` | ✅ | Query idéntica. |
| Filtros fecha + tienda (admin) | `panel_gerencia.php` | `dashboard/kpis` params | ✅ | — |
| Tabla "Últimos 5 reportes" con Ver/Excel/Eliminar/Editar según rol + semáforo (fila SOLICITADO, fila roja "fraude" por `edicion_critica`) | `panel_gerencia.php` | `DashboardController::kpis()` (`limit(5)`) + tabla en `DashboardPage.tsx` | ⚠️ parcial | Faltan: botón Excel individual por fila (brecha #2 de arriba), botón Eliminar en esta tabla (solo existe en `/historial`), resaltado de fila "fraude" por `edicion_critica` (destino solo maneja `isSolicitado`/`isNegative`). |
| Exportación Excel GENERAL del dashboard ("una fila por categoría/producto", `Reporte_Ventas_Desglosado.xls`), admin directo o jefe_tienda vía modal DNI+PIN | `panel_gerencia.php` | — | ❌ **falta por completo** | No existe ruta `dashboard/exportar` ni método equivalente. El único export vivo (`HistorialController::exportar`) usa un formato distinto y es admin-only. |
| Sub-rol "Jefe de Tienda" (`agentes.es_gerencia='jefe_tienda'`) + reautorización DNI+PIN para desbloquear exportación sin ser admin | `panel_gerencia.php`, `historial_completo.php` | — | ❌ **falta por completo** | El modelo de roles del destino (`EnsureRole`) solo contempla `admin`/`tienda`/`vendedor`. Existe `POST /v1/auth/verify-pin` pero no está conectado a ningún gate de exportación en el frontend. |
| Mensajes flash de éxito/estado | `panel_gerencia.php` | Toasts / estados de mutación React Query | ✅ | Mecanismo distinto, equivalente funcional. |

## 4. Historial Completo

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Listado paginado con filtros fecha/tienda(admin)/agente(admin), accesible a **ambos roles** (tienda ve solo su tienda) | `historial_completo.php` (1098 líneas) | `HistorialController::index/kpis` — rutas con `middleware('role:admin')` + `HistorialPage.tsx` | ❌ **brecha crítica de rol** | El rol `tienda` **no puede acceder en absoluto** (403). Cae a `/mi-historial` (`MiHistorialPage.tsx` → `reportes/mis-reportes`), que filtra por `usuario_id` **creador**, no por `tienda_id` — si una tienda tiene varios agentes, cada uno deja de ver los cuadres de sus compañeros. Esa página además mezcla asistencia/tardanzas y carece de Cuadre Bitel, export Excel, columna Ganancia y cambio de `destino_efectivo`. |
| Widget "Cuadre Bitel por rango" embebido al fondo de la pantalla (admin, ERP vs Bitel por categoría) | `historial_completo.php` | `CuadreBitelController::rango()` existe pero vive en pantalla separada (`/panel-bipay` tab "Rango") | ⚠️ parcial | Cálculo disponible pero desacoplado (cambio de UX, no de datos). |
| Exportación Excel "bloques por categoría" con portada/resumen y subtotales, admin o jefe_tienda vía PIN | `historial_completo.php` | `HistorialController::exportar()` (XLSX real, mismos 6 bloques) | ⚠️ parcial | (a) Estrictamente admin-only (pierde acceso jefe_tienda-vía-PIN). (b) Falta la portada/resumen de período+tienda+cantidad de cuadres. (c) Mejora técnica: `.xls` truco HTML → `.xlsx` real. |
| Botón/modal "Recibir Efectivo" en **cada fila** del historial | `historial_completo.php` | — | ❌ | `HistorialPage.tsx` solo muestra un badge de solo lectura; la única forma de cambiar destino es desde el Dashboard (solo últimos 5), por lo que reportes fuera de esos 5 no son corregibles desde Historial. |
| Acciones por fila: Ver(todos)/Excel individual(todos)/Eliminar(admin)/Editar + paginación | `historial_completo.php` | `HistorialPage.tsx` (Ver/Eliminar-admin/Editar/Aprobar/Denegar + paginación) | ⚠️ parcial | Falta el botón Excel individual por fila (brecha ya señalada) y el acceso del rol tienda a esta pantalla. |

## 5. Panel de Financieras y Desembolsos

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Acceso solo admin | `panel_financieras.php` | `role:admin` + check en controller | ✅ | Paridad completa. |
| Listado de ventas a cuotas | `panel_financieras.php` | `PanelFinancierasController::index()` | ✅ | Migrado a esquema normalizado `ventas`+`venta_equipos` (mejora vs JSON legacy). |
| Filtros mes / estado / financiera / tienda | `panel_financieras.php` | `index()` + UI React | ✅ | Idénticos, incluye `whereIn(['PENDIENTE','APROBADA'])` cuando el filtro es "TODAS". |
| Tarjetas resumen (Pendiente/Confirmado/Total) | `panel_financieras.php` | `index()` totales | ✅ | Mismos 3 KPIs. |
| Badge de alerta inmediata "hay pendientes" | `panel_financieras.php` | `PanelFinancierasPage.tsx` | ⚠️ | Destino solo alerta si el pendiente tiene ≥30 días; se perdió el aviso inmediato de pendientes recientes. |
| Columnas "Precio Venta" e "Inicial (Tienda)" en la tabla | `panel_financieras.php` | — | ❌ | El backend ni siquiera selecciona `precio_venta`/`efectivo_inicial`; se perdieron 2 de 3 columnas monetarias. |
| Columna Modelo/IMEI + DNI cliente | `panel_financieras.php` | tabla React | ⚠️ | Solo se muestra el nombre del producto; falta IMEI y DNI del cliente. |
| Diálogo de confirmación con preview antes de "Confirmar" | `panel_financieras.php` (JS `confirm()`) | `PanelFinancierasPage.tsx` | ❌ | Sin diálogo antes de la acción irreversible; inconsistente con el resto de la app. |
| Confirmar desembolso: libera comisión (fallback `config_comisiones.EQUIPO_ESTANDAR`, S/5) | `confirmar_desembolso.php` | `confirmarDesembolso()` | ✅ | Mismo fallback, misma tabla, cubierto por test automatizado. |
| Bloqueo transaccional/atomicidad (`FOR UPDATE`) | `confirmar_desembolso.php` | `confirmarDesembolso()` | ⚠️ | Sin transacción ni lock — ventana de carrera ante doble POST simultáneo. |
| Recalcular "ganancia final" al confirmar (costo actualizado) | `confirmar_desembolso.php` | — | ❌ | Nunca recalcula/persiste `ganancia_snap`; queda congelada con el valor original. |
| Auditoría de quién/cuándo confirmó | `confirmar_desembolso.php` | — | ❌ | No existen columnas `desembolso_confirmado_por`/`_en`; se pierde trazabilidad. |
| Revertir desembolso (nuevo) | — (no existía) | `revertirDesembolso()` | 🔁 | Mejora agregada. |
| Alertas de antigüedad de pendientes (≥15/≥30 días) | — (no existía) | `PanelFinancierasPage.tsx` | 🔁 | Mejora agregada. |

## 6. Estadísticas de Ventas (1303 líneas, leído completo)

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Control de acceso admin + tienda (jefe de tienda ve solo la suya) | `estadisticas_ventas.php` | rutas `estadisticas/*` con `role:admin` únicamente; menú oculto para tienda | ❌ | El rol `tienda` **no tiene ningún acceso** en el destino, a diferencia del legacy. |
| Filtro TIPO DE VENTA sobre "Top Agentes" | `estadisticas_ventas.php` | — | ❌ | No existe filtro equivalente; lo más cercano (`categoria` del tab Ranking) es un concepto heredado de otro archivo legacy. |
| Reasignación de venta a `tienda_destino` en apoyo inter-tienda (`cross_selling`) | `estadisticas_ventas.php` | `EstadisticasController` | ❌ | Nunca usa `ventas.tienda_destino`/`cross_selling` pese a existir en el esquema; rompe el caso de apoyo entre tiendas. |
| Tabla por tienda: columnas EQ. CUOTAS / EQ. CONTADO separadas | `estadisticas_ventas.php` | `por_tienda.equipos` (combinado) | ❌ | Solo el bloque `totales` (KPIs) separa cuotas/contado. |
| Tabla por tienda: todas las tiendas (con ceros) + fila TOTAL GLOBAL | `estadisticas_ventas.php` | tab "Por Tienda" | ⚠️ | Backend usa `GROUP BY` (solo tiendas con ventas); front no calcula fila de total. |
| Popover de desglose por plan (Postpago/Chip) | `estadisticas_ventas.php` | — | ⚠️ | Destino solo muestra el total, sin desglose. |
| "Top Agentes" sin límite | `estadisticas_ventas.php` | `productividad()` | ⚠️ | Destino aplica `limit(30)`. |
| "Top 10 Accesorios más vendidos" | `estadisticas_ventas.php` | `ventas()` | ❌ | El JSON de respuesta no incluye `top_accesorios`; el frontend no lo renderiza. |
| Barra de proporción (%) en tops | `estadisticas_ventas.php` | tab "Top Productos" | ⚠️ | Datos correctos, sin barra visual. |
| Export Excel: ranking por tienda con EQ.CUOTAS/CONTADO, medallas, fila TOTAL | `estadisticas_ventas.php` | hoja "Tiendas" | ❌ | Faltan columnas separadas, medallas y fila total. |
| Export Excel: hojas "Top Equipos"/"Top Accesorios"/"Top Planes" | `estadisticas_ventas.php` | — | ❌ | Ausentes por completo (solo Resumen/Tiendas/Agentes). |
| Export Excel: hoja "Agentes" con columnas dinámicas por plan de chip | `estadisticas_ventas.php` | hoja "Agentes" | ❌ | Columnas fijas; es la brecha más grande del export. |
| Formato `.xls` HTML → `.xlsx` real | `estadisticas_ventas.php` | `exportar()` | 🔁 | Mejora técnica. |

## 7. CRM Dashboard

**Hallazgo arquitectónico central**: el CRM legacy y el del destino son **dos modelos de datos incompatibles, no migrados entre sí**. Legacy calcula una "temperatura" (Frío/Caliente/Upselling/Neutro) **en vivo** con reglas heurísticas sobre `crm_clientes`/`crm_interacciones` (interacción &lt;48h sin rechazo → Caliente; rechazo por evaluación crediticia → Frío); el drag&drop legacy ni siquiera mueve un estado real, sino que inserta/desfasa interacciones para que la regla recalculada dé el resultado deseado. El destino reemplazó esto por un pipeline de ventas convencional (tabla `leads` nueva, estado manual NUEVO/CONTACTADO/INTERESADO/CONVERTIDO/PERDIDO), sin ningún concepto de temperatura ni las reglas heurísticas.

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Dashboard CRM: KPIs + gráficos | `crm_dashboard.php` | `LeadController::dashboard()` + `CrmPage` tab Analytics | ⚠️ | Gira en torno a `leads.estado/fuente`, no a `tipo_operacion`/interacciones crudas del legacy. |
| Filtros globales (fecha, tienda, agente libre, tipo operación, búsqueda DNI/celular) | `crm_dashboard.php` | `LeadController::index/dashboard/pipeline` | ⚠️ | Faltan filtro de texto libre de agente, tipo de operación y búsqueda DNI/celular. |
| Bandeja propia del rol `tienda` (auto-filtrada server-side) | `crm_dashboard.php` | rutas `crm/*`, `leads` sin `role:` middleware | ❌ | Cualquier usuario autenticado puede pedir `/v1/leads` sin restricción por agente/tienda propia. |
| Vista Kanban por Temperatura calculada dinámicamente | `crm_dashboard.php` + `crmCalcularTemperatura()` | `CrmPage` Kanban de 5 columnas por `estado` | ❌ **estructural — mayor gap del área CRM** | Dos modelos de datos totalmente distintos. No existe lead scoring temporal ni reglas heurísticas Caliente/Frío en el backend Laravel. El Kanban visual existe pero sobre taxonomía y lógica de negocio completamente diferentes. |
| Drag & Drop real (HTML5) | `crm_dashboard.php` / `ajax_crm_dragdrop.php` | `CrmPage` `LeadCard` con `<select>` "Mover a..." | ⚠️ | Cambio de estado vía selector, no arrastrar-soltar. |
| Alertas de conflicto de atribución captador vs. vendedor (banner + `log_resolucion_atribucion`) | `crm_dashboard.php` + `ajax_resolver_conflicto.php` | Backend: `AuditoriaBipayController::resolverConflicto` (confirmado 1:1, ver sección 12) | ⚠️ **reconciliado** | El backend de re-atribución SÍ está portado 1:1, pero vive en el módulo Bipay/Auditoría, no en CRM. Falta confirmar/implementar el **banner de alerta** dentro del dashboard CRM que dispare hacia esa ruta (no se encontró en `CrmPage.tsx`). |
| Modal "Registrar Cliente" (RENIEC + `crm_clientes`, regla portabilidad→Bitel) | `crm_dashboard.php` | `DniController::consultar` + `ClienteCrmController::buscar/guardar` | ⚠️ (relocado) | Lógica de backend ~1:1, pero solo expuesta desde "Nuevo Cuadre", no desde el módulo CRM/Leads. |
| Modal "Redacción WhatsApp" con plantillas editables | `crm_dashboard.php` | `LeadCard` con link directo `wa.me` | ⚠️ | Se perdió el compositor de mensaje con plantillas. |
| Historial de interacciones por lead | (inline en legacy) | `LeadController::interacciones/agregarInteraccion` + hooks React | ⚠️ | Backend y hooks existen pero no conectados a ninguna UI visible en `CrmPage.tsx`. |

## 8. Mapa de Calor

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Filtro por categoría de operación + modo "datos de prueba" | `mapa_calor.php` | `MapaCalorPage.tsx` tab Geográfico | ⚠️ | Solo filtro de fechas; sin selector de categoría ni modo demo. |
| Heatmap real de densidad (`leaflet.heat`) | `mapa_calor.php` | `TabGeografico` (círculos de radio/color proporcional) | ⚠️ | Visualmente distinto, intensidad relativa equivalente. |
| Modo temporal/animado (slider día a día + play/pause) | `mapa_calor.php` | — | ❌ | No hay slider ni reproducción automática. |
| Calendario anual + grid Horario | — (no existía) | `MapaCalorController::calendario/horario` + tabs nuevos | 🔁 | Funcionalidad nueva, mejora sobre el alcance original. |
| Permiso solo admin | `mapa_calor.php`/`api_heatmap_ventas.php` | rutas `heatmap/*` con `role:admin` | ✅ | Paridad completa. |

## 9. Reporte BCP

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Formulario "Enviar Reporte del Turno" (tienda) | `reporte_bcp.php` | `ReporteBcpPage.tsx` + `ReporteBcpController::store` | ✅ | Mismos campos. Bloqueo admin solo en frontend. |
| Control de acceso `tiene_bcp` (403 si tienda no autorizada) | `reporte_bcp.php` | — | ❌ | No existe chequeo en controller, ruta ni frontend. Cualquier tienda puede enviar reportes BCP sin autorización. |
| "Mi Historial BCP" (vista tienda) | `reporte_bcp.php` | `ReporteBcpPage.tsx` | ❌ | `enabled: esAdmin` en el query — tienda nunca ve su propio historial. |
| Filtro admin por tienda | `reporte_bcp.php` | `ReporteBcpController::index` soporta el parámetro | ⚠️ | Backend listo (con endpoint `reporte-bcp/tiendas`) pero frontend no renderiza el selector. |
| Alertas de tiendas con &lt;200 operaciones/día | `reporte_bcp.php` | `ControlCenterController::alertasBcp` | ✅ | Cobertura equivalente, UI distinta. |
| Tabla admin agrupada por tienda+fecha con subtotales | `reporte_bcp.php` | tabla plana | ⚠️ | Sin agrupación visual ni subtotales. |
| Botón "Eliminar reporte BCP" | `reporte_bcp.php` | — | 🔁 N/A | Confirmado código muerto en legacy (nunca tuvo handler/endpoint). |

## 10. Boleta de Planilla (fuera de alcance real — nomenclatura engañosa, documentado por transparencia)

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Crear boleta de pago de planilla + imprimir | `imprimir_boleta.php` | `ConstanciaController::crearBoleta`/`boleta()` (PDF) | ✅ | Mismos campos y tabla `pagos_planilla`; `window.print()` HTML → PDF real (DomPDF). Dominio real: planilla, no boletas de venta. |
| Marcar boleta PAGADO / eliminar | `accion_boleta.php` | `ConstanciaController::accionBoleta` | ✅ | Acciones calcadas. |

## 11. Tickets Emitidos

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Listado (admin todo, tienda solo suyo) | `tickets_emitidos.php` | `TicketController::index` + `TicketsPage.tsx` | ✅ | Destino agrega paginación real (legacy solo `LIMIT 500`). |
| Filtro por agente/cajero | `tickets_emitidos.php` | Backend soporta `agente_id` | ⚠️ | Sin selector en la UI — filtro inalcanzable desde el frontend. |
| Filtro por N° de ticket exacto | `tickets_emitidos.php` | — | ❌ | No existe en backend ni frontend. |
| Filtro cliente (nombre) y DNI/celular por separado | `tickets_emitidos.php` | un solo input combinado (OR) | ⚠️ | Ya no se pueden filtrar de forma independiente. |
| Exportación a Excel | `tickets_emitidos.php` | `TicketController::exportar` (.xlsx real) | ✅ | Mismas columnas y fila de total. |
| Reimpresión de ticket | `tickets_emitidos.php` | `TicketImpresionPage.tsx` | ✅ | Mismo layout de recibo. |
| Selector de tamaño de impresión térmica 58mm/80mm por usuario | `tickets_emitidos.php` + `migrar_formato_ticket.php` | `TicketImpresionPage.tsx` fijo a 80mm | ❌ | Columna/migración `formato_ticket` no existe en destino; se perdió la preferencia por usuario. |
| Eliminar ticket (hard delete, admin) | `tickets_emitidos.php` → `api/eliminar_ticket.php` | `DELETE /v1/tickets/{id}` existe pero sin consumidor | ⚠️ | Frontend cambió la acción de "eliminar" a "anular" (`PATCH estado=ANULADO`); confirmar con negocio si es un reemplazo intencional. |
| Migración one-off `usuarios.formato_ticket` | `migrar_formato_ticket.php` | — | ❌ | Feature de negocio que habilitaba (tamaño de impresión por usuario) no portada; ver fila anterior. |

## 12. Panel Bipay

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Cuentas Bipay/Anypay — jerarquía Madre→Hijo, saldos duales, "Efectivo del día" | `panel_bipay.php` | `BipayController::saldo()` + tab "Saldos" | ⚠️ | Falta agrupación visual Madre→Hijo y el widget "Efectivo del día". |
| Cuentas Huérfanas (auto-registradas sin madre) | `panel_bipay.php` | — | ❌ | No hay filtro/etiqueta ni sección dedicada. |
| Vincular Cuenta Huérfana → convertir a MADRE | `panel_bipay.php` | — | ❌ | No existe endpoint ni acción equivalente. |
| Alta de cuenta | `panel_bipay.php` | `POST bipay/cuentas` (`crearCuenta`) | ⚠️ | Faltan campos `razon_social` y `activa` en validación/insert y en el formulario React. |
| Edición de cuenta | `panel_bipay.php` | `PUT bipay/cuentas/{id}` | ⚠️ | Mismo hueco: `razon_social`/`activa` no se leen/escriben. |
| Ajuste manual de saldo | `panel_bipay.php` | `POST bipay/ajustar` | ⚠️ | Legacy exige motivo solo si cambia el saldo; destino lo exige siempre (`min:5`) — más estricto que el original. |
| Eliminar cuenta | `ajax_eliminar_cuenta.php` | `DELETE bipay/cuentas/{id}` | ⚠️ | Legacy desvincula hijos/tiendas y borra; destino **bloquea** el borrado si hay subcuentas/transacciones — comportamiento distinto. |
| Locks Activos (tiendas operando) | `panel_bipay.php` | — | ❌ | No hay panel admin equivalente a "cooldowns activos" (el concepto interno `bipay_cooldowns` existe pero sin vista dedicada). |
| Declaraciones del Día (todas las tiendas, estado CERRADO/OPERANDO/SIN DECL.) | `panel_bipay.php` | `ControlCenterController::alertasBipay()` | ⚠️ | Solo sobrevive como sub-widget de alertas; no hay tabla completa admin con estado de declaración/cierre de todas las tiendas. |
| Control Diario BiPay vs ERP (cuadre por tienda/categoría, apoyo, cola histórico) | `panel_bipay.php` | `CuadreBitelController::panel` + tab "Cuadre diario" | ✅ | Port 1:1; incluso agrega `DELETE cuadre-bitel/apoyos` (deshacer apoyo, nuevo). |
| Auditoría de Cierre — listado, comparar, justificar | `panel_bipay.php`, `ajax_reconcile_details.php`, `ajax_auditoria_ajuste.php` | `AuditoriaBipayController` (index/detalles/ajustar) | ⚠️ | Falta mostrar "Ajustado por: {admin}" + observación cuando `estado='AJUSTADO'` (no hay join a `usuarios`). Resto con paridad completa; además se agregó "Ejecutar cruce del día" manual. |
| Movimientos del Día por agente | `panel_bipay.php`, `ajax_movimientos_dia.php` | `CuadreBitelController::movimientosDia` | ✅ | Paridad completa (categorías, `extraerCodigoPersonal`, chips de filtro, subtotales). |
| Historial de Transacciones — filtros + export Excel | `panel_bipay.php` | `BipayController::transacciones` + tab "Transacciones" | ⚠️ | Faltan filtros de Cuenta y Tipo de operación en la UI; **no existe exportación a Excel** (a diferencia de `?export=excel` legacy); no se replica detalle especial de `AJUSTE_MANUAL`. |
| Modal Recarga de cuenta | `panel_bipay.php` | `POST bipay/recarga` | ✅ | Paridad completa. |
| Modal Transferencia entre cuentas | `panel_bipay.php` | `POST bipay/transferir` | ⚠️ | Legacy resta el monto íntegro de `saldo_actual` sin tocar desglose bipay/anypay; destino descuenta primero de bipay y el remanente de anypay — el desglose por plataforma puede diferir del legacy. |
| Configuración de Alertas Webhook (Discord/Slack) | `panel_bipay.php` | `AuditoriaBipayController::webhookConfig/guardarWebhook` | ✅ | Port fiel; destino mejora con anti-spam y respeto de cierres. |
| Auto-refresh silencioso cada 120s | `panel_bipay.php` | — | 🔁 | Diferencia de arquitectura (SPA con React Query), no crítico. |
| "Notas de Crédito (NC)" | (mencionado en la consigna original) | — | 🔁 N/A | **Confirmado tras leer `panel_bipay.php` completo (1789 líneas): no existe tal sección** en el legacy. Aparenta ser confusión con otro módulo del ERP; no aplica. |

## 13. Panel Postpago / Morosidad

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| KPIs Altas/Churn/En Mora/Deuda por tienda y período + detalle por línea + "Actualizar morosidad" on-demand | `panel_postpago.php` (358 líneas, completo) | `IntegradorController::morosidad/solicitarExtraccion` + tab **"Morosidad"** dentro de `CuadreBitelPage.tsx` (⚠️ NO en `PostpagoPage.tsx`, ver nota de nomenclatura abajo) | ⚠️ | (a) Falta KPI explícito "Total Altas". (b) Falta agrupación por tienda con subtotales expandibles — destino muestra tabla plana de líneas (máx. 1000). (c) Definición de "en mora" **difiere**: legacy = `estado_bloqueo IN (BLOQUEO_1,BLOQUEO_2)` y no dado de baja; destino = "Suspendidas" (`estado_linea='SUSPENDIDO'`), columna/fuente/concepto distinto. (d) El listado destino solo viene de `lineas_morosidad`, sin cruzar con `clientes_estado` como legacy para churn/bloqueo por línea. |
| **Nota de nomenclatura importante** | — | `PostpagoController.php` + `PostpagoPage.tsx` | 🔁 | El "Postpago" del destino es una feature **distinta**: ventas/activaciones/portabilidades/comisiones postpago (tablas `ventas`/`venta_lineas`), sin relación con el churn/morosidad del legacy `panel_postpago.php`. El verdadero equivalente es el tab "Morosidad" de arriba. `PARIDAD_MASTER.md` no menciona `panel_postpago.php` en absoluto (punto ciego confirmado). |

---

## Hallazgos transversales

1. **CRM: incompatibilidad estructural de modelos de datos** (sección 7) — el gap más profundo de todo el análisis. No es "falta una pantalla": el concepto de "temperatura calculada dinámicamente" del legacy no tiene ningún equivalente en el modelo `leads.estado` del destino, y las reglas de negocio (interacción &lt;48h, rechazo crediticio) no existen en el backend nuevo.
2. **`PARIDAD_MASTER.md` subestima sistemáticamente las brechas** — confirmado en los 8 sub-análisis: marca ✅ simple donde el código actual muestra ⚠️/❌ en el detalle (financieras, estadísticas, BCP, tickets, CRM, panel_gerencia, historial, bipay). No usarlo sin re-verificar contra código.
3. **Patrón recurrente "backend lo soporta, frontend no lo expone"**: filtro de tienda en BCP, filtro de agente en tickets, filtros de cuenta/tipo en transacciones Bipay, historial de interacciones de leads. Brecha barata de cerrar comparada con lógica de negocio faltante en backend.
4. **Regresiones de permisos detectadas** (no solo features faltantes, sino restricciones NUEVAS no presentes en legacy): rol `tienda` perdió la capacidad de marcar/revertir efectivo entregado (sección 2); rol `tienda` perdió el acceso completo a Historial e Estadísticas (secciones 4 y 6); eliminación de reporte ahora bloqueada si `estado==='aprobado'` (sección 2).
5. **Nombres de archivo legacy engañosos**: `accion_boleta.php`/`imprimir_boleta.php` no son boletas de venta sino planilla; esto probablemente indujo a error en el alcance original de la tarea (ya corregido en este documento).
6. **Exportación de auditoría por-reporte individual (`exportar_excel.php`) no tiene ningún equivalente en el destino** — ni el PDF de `ConstanciaController::reporte` ni el Excel masivo de `HistorialController::exportar` cubren este caso de uso puntual, usado tanto en Dashboard como en Historial.

---

## Conteo de features por estado

(Conteo exacto por grep sobre las filas de datos de las tablas numeradas 1–13; se excluyen encabezados de tabla y las secciones de notas/resumen)

| Estado | Cantidad |
|---|---|
| ✅ Paridad completa | 24 |
| ⚠️ Parcial | 39 |
| ❌ Falta por completo | 28 |
| 🔁 N/A / mejora nueva / fuera de alcance | 9 |
| **Total features evaluadas** | **~100** |

## Los 5 gaps más grandes/importantes

1. **CRM: reemplazo total del modelo de datos** — el Kanban de "temperatura" calculada dinámicamente (Caliente/Frío/Upselling/Neutro por reglas heurísticas de interacción/rechazo) no existe en el destino; fue sustituido por un pipeline de ventas genérico (`leads.estado`) sin ninguna de las reglas de negocio originales ni las alertas de conflicto de atribución captador/vendedor visibles en el dashboard CRM.
2. **Exportación de auditoría por-reporte (`exportar_excel.php`) y exportación general del Dashboard (`Reporte_Ventas_Desglosado.xls`) no tienen ningún equivalente en el destino** — se perdieron 2 de los 3 mecanismos de exportación Excel que tenía el legacy en el flujo diario de gerencia.
3. **Regresión de permisos del rol `tienda`**: perdió acceso a Historial Completo (cae a una vista incompleta de "mis reportes"), a Estadísticas de Ventas, y ya no puede marcar/revertir su propio efectivo entregado — tres capacidades que sí tenía en el legacy.
4. **Panel Financieras: sin recálculo de ganancia ni auditoría de quién/cuándo confirmó el desembolso**, y sin transacción/lock atómico — riesgo de doble-confirmación y pérdida de trazabilidad financiera sobre desembolsos de financieras.
5. **Estadísticas de Ventas: exportación Excel muy reducida** (faltan hojas de Top Equipos/Accesorios/Planes y columnas dinámicas por plan de chip en la hoja de Agentes) **y sin la reasignación `cross_selling`/`tienda_destino`** en ningún reporte, rompiendo el cálculo correcto para tiendas con apoyo inter-tienda.

## Pendiente de verificación adicional (no bloqueante, no se profundizó por límite de tiempo/presupuesto de esta sesión)

- Cuerpo completo de `ReporteController::revertirVentas()` — se confirmó que existe y es transaccional, pero no se comparó campo a campo contra la lógica de `eliminar_reporte.php`.
- Confirmar si `CrmPage.tsx` consume o no la ruta `auditoria-bipay/resolver-conflicto` para el banner de conflictos (backend confirmado, UI del banner específico de CRM no confirmada).
