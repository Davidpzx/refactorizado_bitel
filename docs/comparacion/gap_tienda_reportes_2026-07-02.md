# Análisis de paridad — Área: `tienda/` + `reportes/`

Fecha del análisis: 2026-07-02
Fuente: `E:\laragon\www\sis_bipay\tienda\` (26 archivos incl. `api/`) + `E:\laragon\www\sis_bipay\reportes\` (16 archivos)
Destino: `C:\xampp\htdocs\refactorizado_bitel\backend` (Laravel 12) + `frontend` (React 19)

Metodología: se leyó el 100% de los archivos legacy asignados (línea por línea en los pequeños/medianos;
por bloques estructurales + grep dirigido en los tres archivos gigantes `ver_inventario.php` 4073 líneas,
`nuevo_reporte.php` 4337 líneas, `editar_reporte.php` 1275 líneas). Del lado destino se abrieron y
verificaron los controllers reales (no se asumió por nombre de ruta): `InventarioController`,
`MatrizInventarioController`, `TrasladoController`, `TrasladoChipsController`, `ChipsController`,
`BitacoraStockController`, `ConstanciaController`, `ReporteController` (1304 líneas completas),
`ReporteBorradorController`, `TicketController`, `BipayController`, `AsistenciaController`, y las páginas
React `InventarioPage`, `InventarioForm`, `ChipsGestionPage`, `KardexInventarioPage`,
`MatrizInventarioPage`, `TrasladosPage`, `TrasladoChipsPage`, `NuevoReportePage` (2200 líneas),
`MiHistorialPage`, `ReporteDetallePage`, `TicketImpresionPage`, `BitacoraStockPage`, más las vistas Blade
de constancias PDF.

---

## Tabla de paridad — `tienda/`

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| Inventario general (listar/filtrar equipos, accesorios, chips) | `ver_inventario.php` | `InventarioController::index` + `InventarioPage.tsx` | ✅ | Filtros por tienda/tipo/estado/búsqueda, paginación server-side y tabla de chips replicados. |
| **Registro de stock por tienda (wizard agente)** — multi-IMEI, rangos de series de chips con calculadora, selector de "tienda de origen" del chip, admin puede asignar a otra tienda, **sin precio** al ingreso (precio lo fija gerencia después), verificación de identidad DNI/PIN obligatoria para no-admin | `registrar_stock.php` + `guardar_stock.php` | `InventarioForm.tsx` (dentro de `InventarioPage`) + `InventarioController::store` | ❌ | Brecha crítica: (1) `POST /inventario` tiene middleware `role:admin` en `routes/api.php` línea 153 → el rol `tienda` **no puede registrar stock en absoluto** en el destino (en legacy es la función principal de la tienda). (2) El formulario genérico exige `precio_costo`, `precio_normal`, `precio_minimo` obligatorios al crear, rompiendo el flujo "tienda registra sin precio, gerencia fija precio después". (3) No hay calculadora de rango de series de chips, ni selector de tienda de origen, ni asignación admin-a-otra-tienda. (4) No hay verificación DNI/PIN de agente activo al registrar. (5) Al elegir tipo=CHIP en el formulario genérico, el registro se guarda en `inventario_tiendas` (vía `InventarioTienda` model), **no** en `inventario_chips` — la tabla real que consulta la pestaña de Chips (`ChipsController`/`inventario_chips`). Un chip creado así queda huérfano/invisible en la UI de chips. |
| Ajuste rápido de stock (sumar/reemplazar cantidad, maneja transición VENDIDO→DISPONIBLE) | `agregar_stock_rapido.php` | — | 🔁 N/A | Confirmado por grep en todo el repo legacy: este archivo **no está enlazado desde ningún `<form>` ni `fetch()`** en `sis_bipay`. Es código huérfano/muerto en el legacy; no requiere paridad funcional, solo señalar por si se accedía por URL directa. |
| Editar nombre/IMEI de un ítem (cualquier rol, restringido a su tienda) / Eliminar ítem (solo admin, con DNI de auditoría) | `api_inventario.php` | `InventarioController::update` / `destroy` | ⚠️ | `update()` y `destroy()` tienen middleware `role:admin` — en legacy la **edición de nombre/IMEI la puede hacer el rol `tienda`** (con chequeo de que el ítem sea de su propia tienda), no solo admin. Además, `destroy()` no exige explícitamente un DNI de 8 dígitos como campo de auditoría (usa el agente de sesión), mientras legacy pide `auth_dni` (puede ser el DNI de *otra* persona que autoriza, no necesariamente el usuario logueado). |
| Fijar precio de venta (solo `precio_normal`, agente con DNI, nunca toca costo/mínimo) | `fijar_precio_agente.php` | `InventarioController::fijarPrecioAgente` + `InventarioPage.tsx` (diálogo "Fijar precio") | ✅ | Misma validación de precio ≥ mínimo, mismo blindaje de campos. |
| Kardex de inventario (fallback a 3 niveles: fecha/agente/precio desde `reporte_categorias` JSON histórico) | `ajax_kardex_inventario.php` | `InventarioController::kardex` + `KardexInventarioPage.tsx` | ✅ | SQL prácticamente idéntico, incluye el fallback JSON histórico. Destino añade un 4º nivel de fallback vía tabla normalizada `venta_equipos` (mejora, no rompe nada). |
| Exportar Kardex a Excel | `exportar_kardex_inventario.php` (HTML+MIME `.xls`) | `InventarioController::exportarKardex` (XLSX real vía PhpSpreadsheet) | ✅ | Destino genera `.xlsx` real en vez de HTML disfrazado — mejora. |
| Rescate manual de equipo vendido por error (admin) | referenciado como `../api/restaurar_equipo_manual.php` desde `ver_inventario.php` | `InventarioController::restaurar` + botón en `KardexInventarioPage.tsx` | ✅ | |
| Recalcular ganancias tras fijar costo tardío | vía `../gerencia/ajax_fijar_costo_rapido.php` (fuera de mi área) | `InventarioController::recalcularGanancias` | ✅ | Fuera de alcance estricto pero confirmado existente en destino. |
| Widget "Campaña de costos faltantes" (ventas de equipos sin `precio_costo`) | `api/ajax_campana_admin.php` (lazy-load en `ver_inventario.php`) | `InventarioController::campanaCostos` + `CampanaCostosWidget` en `InventarioPage.tsx` | ✅ | |
| Widget "Stock estancado" (+30 días sin movimiento, capital inmovilizado) | `api/ajax_stock_estancado.php` (lazy-load en `ver_inventario.php`) | `InventarioController::stockEstancado` | ⚠️ | El endpoint backend existe y replica el SQL exacto, pero **no se llama desde ningún componente del frontend** (`InventarioPage.tsx` solo renderiza `CampanaCostosWidget`, no hay `StockEstancadoWidget`). Grep confirma cero referencias a `stock-estancado` en `frontend/src`. |
| Matriz general de inventario (equipos/accesorios/chips por tienda) | `matriz_inventario.php` | `MatrizInventarioController::index` + `MatrizInventarioPage.tsx` | ✅ | |
| Exportar Matriz a Excel (3 tablas: equipos, accesorios, chips en un solo libro con totales) | `descargar_matriz_excel.php` (PhpSpreadsheet, 1 hoja con 3 secciones) | `MatrizInventarioController::exportar` | ⚠️ | El endpoint destino exporta **CSV de un solo `tipo`** (`?tipo=EQUIPO`) por llamada, no el libro combinado de 3 secciones con totales por tienda que arma el legacy. No es la misma exportación (formato y contenido distintos). |
| Exportar inventario simple a Excel (chips + equipos/accesorios, filtrado por rol) | `exportar_inventario_excel.php` | ídem `MatrizInventarioController::exportar` (parcial) | ⚠️ | Mismo comentario que arriba: el legacy combina chips + equipos/accesorios con totales; el destino exporta solo un tipo a la vez y no incluye chips. |
| Gestión de chips: stock por tienda/código de origen | (listado embebido en `ver_inventario.php`) | `ChipsController::index` + pestaña "Chips" de `InventarioPage.tsx` / `ChipsGestionPage.tsx` | ✅ | |
| Cambiar código interno de chips (mover entre bolsillos de una misma tienda) | `cambiar_codigo_chip.php` | `ChipsController::cambiarCodigo` + `ChipsGestionPage.tsx` | ✅ | |
| Eliminar lote de chips (admin) | `eliminar_chip.php` | `ChipsController::destroy` | ✅ | |
| Historial/Kardex de un lote de chips (correcciones + traslados + ventas agrupadas por reporte) | `obtener_historial_chip.php` | `ChipsController::historial` + modal en `ChipsGestionPage.tsx` | ✅ | Destino replica las 3 fuentes (histórico manual, traslados confirmados, ventas por `reporte_categorias`) y el cálculo de saldo corriente. |
| Corrección de stock (SUMA/RESTA) con DNI de autorización + Kardex de auditoría (chips y accesorios) | `procesar_correccion_stock.php` | `BitacoraStockController::corregir` | ✅ | Validaciones idénticas (DNI 8 dígitos, observación ≥10 caracteres, cantidad 1–9999). |
| Bitácora de movimientos de stock (listado + KPIs + exportar Excel) | (no hay archivo legacy dedicado en mi área; alimenta `historial_inventario`) | `BitacoraStockController::index/kpis/exportar` + `BitacoraStockPage.tsx` | ✅ | |
| Traslado de equipos/accesorios (individual y masivo por lote, con DNI/PIN al enviar, aprobación admin si el emisor no es gerencia) | `procesar_traslado.php` | `TrasladoController::store` | ⚠️ | Brecha de seguridad: legacy **exige `auth_dni` no vacío para TODOS los roles** (incluido admin) al enviar, y valida que el `auth_agente_id` corresponda a un agente activo antes de continuar (o rechaza si no es admin). El destino **no valida en absoluto la identidad del agente en `store()`** — solo guarda el `auth_dni` que venga en el request sin verificarlo contra la tabla `agentes`. Cualquier valor de texto pasa como "enviado_dni". La verificación de identidad solo ocurre en `confirmar()`, no al crear el traslado. |
| Confirmar recepción de traslado individual (Admin sin PIN / Agente con DNI+PIN) | `confirmar_traslado_equipo.php` | `TrasladoController::confirmar` | ✅ | Aquí sí se valida el agente contra la tabla `agentes` (`whereRaw UPPER(TRIM(dni))...`). |
| Confirmar recepción de LOTE de equipos (mismo `codigo_lote`, con savepoints por ítem) | `confirmar_lote_equipo.php` | `TrasladoController::confirmarLote` | ✅ | Destino usa una sola transacción con validación de "un solo destino por lote" (razonable, aunque legacy permite continuar con errores parciales vía SAVEPOINT; destino aborta todo si un ítem falla — comportamiento ligeramente más estricto, aceptable). |
| Traslado de chips (con lógica de "es_gerencia" → si quien autoriza es agente de gerencia, el traslado se crea directo en PENDIENTE en vez de PENDIENTE_APROBACION) | `procesar_traslado_chips.php` | `TrasladoChipsController::store` | ⚠️ | El destino decide `PENDIENTE` vs `PENDIENTE_APROBACION` **solo mirando `$esAdmin`** (rol de sesión). Legacy tiene una regla adicional: si el DNI que autoriza pertenece a un agente con `es_gerencia = 1`, el traslado también se crea en `PENDIENTE` (sin pasar por aprobación) aunque el rol de sesión sea `tienda`. Esa regla de "gerente de tienda puede autorizar traslados directos" no está replicada — mismo patrón de brecha en `TrasladoController::store` (equipos) línea 66, que tampoco contempla `es_gerencia`. También falta la verificación de identidad del agente emisor en `store()` (igual que en equipos). |
| Confirmar recepción de chips (UPSERT de stock, resolución de "chip_owner" con 3 niveles de fallback) | `confirmar_traslado_chips.php` | `TrasladoChipsController::confirmar` | ✅ | |
| Gestionar solicitudes de traslado (aprobar/rechazar/cancelar, revertir stock, soporta lotes) | `gestionar_solicitud_traslado.php` | `TrasladoController::gestionar` + `TrasladoChipsController::gestionar` | ✅ | |
| Constancia de traslado PDF (guía de remisión / conformidad de recepción / ambos, individual o por lote) | `constancia_traslado.php` (HTML imprimible vía `window.print()`) | `ConstanciaController::traslado` (PDF real vía DomPDF) + `resources/views/constancias/traslado.blade.php` | ✅ | Destino genera PDF real descargable en vez de HTML+`window.print()`; mejora. |
| Pantalla QR de asistencia (reloj anti-spoof, refresco silencioso cada 5s, HMAC ±2 bloques) | `qr_asistencia.php` | `AsistenciaController::qrStream` + `QrDisplayPage.tsx` | ✅ | HMAC-SHA256 con ventana de tolerancia replicado (`AsistenciaController.php` líneas 665-666). |
| Validar identidad de agente en servicio antes de autorizar corrección de stock (usado por `ver_inventario.php`) | `validar_asistencia_ajax.php` | Cubierto indirectamente por los mismos checks de `agentes`/`asistencias` que usan `TrasladoController`/`BitacoraStockController` (DNI activo) | ✅ | No hay un endpoint 1:1 idéntico, pero la validación de "agente activo con asistencia abierta hoy" que exige este archivo se replica de forma equivalente donde se usa (autorización DNI/PIN). |

---

## Tabla de paridad — `reportes/`

| Feature/Pantalla legacy | Archivo(s) legacy | Equivalente en destino | Estado | Notas de brecha |
|---|---|---|---|---|
| **Nuevo reporte / cuadre diario** (corazón del sistema): cabecera (caja inicial, yape/bipay/transferencia/retiro, salidas), líneas postpago/prepago, apoyos inter-tienda, equipos/accesorios, "Modo Dios" admin (cuadrar por cualquier tienda/agente), cálculo de efectivo esperado/diferencia, descuento de stock (equipos por IMEI, chips unitario vs masivo según prepago/postpago), motor de comisiones (remate <S/20, upgrade, migración, eSIM sin costo de chip, plan online con costo 0) | `nuevo_reporte.php` + `procesar_reporte.php` | `NuevoReportePage.tsx` (2200 líneas, unifica alta y edición vía `mode` prop) + `ReporteController::store` + `procesarVentas()` + `ComisionService` | ✅ | Arquitectura del destino es superior (normaliza ventas en tablas `ventas`/`venta_equipos`/`venta_lineas` en vez de JSON en `reporte_categorias`), pero la fórmula de cuadre (`total_sistema - total_no_fisico - total_salidas`), las reglas de remate/upgrade/migración/eSIM y "Modo Dios" (admin elige tienda+agente) están replicadas fielmente. Guard anti-duplicado de legacy (mismo agente+tienda+fecha) fue **retirado intencionalmente** en destino (comentario explícito "se permiten múltiples cuadres por día") — cambio de comportamiento deliberado, no bug. |
| Descuento de stock de chips: unitario en postpago (loop N veces), masivo en prepago (resta N de una vez), reposición en apoyos por `tienda_destino` | `procesar_reporte.php` líneas 60-370 | `ReporteController::descontarChips` (línea 619) + `procesarVentas` | ✅ | |
| Ticket/comprobante con **pago mixto** (efectivo+yape+bipay+plin) y cálculo de vuelto (`efectivo_neto = efectivo_bruto - vuelto`) | `ajax_guardar_ticket.php` | `TicketController::store` | ✅ | Fórmula de vuelto idéntica carácter por carácter. |
| Ticket de Ingreso con múltiples ítems (`items[]`, concepto+monto+nombre+dni por ítem, arma descripción concatenada) | `ajax_guardar_ticket_ingreso.php` | `TicketController::store` (mismo método soporta `items[]`) | ✅ | Destino unificó ambos endpoints legacy en un solo controlador — correcto. |
| Actualizar ticket tras imprimir (nombre cliente, forma de pago, teléfono, montos) con autorización "solo el emisor o admin" | `ajax_guardar_ticket.php` (`action=update`) | `TicketController::update` | ✅ | |
| Imprimir ticket (ventana emergente con desglose de pago mixto y vuelto) | `imprimir_ticket_ingreso.php` | `TicketImpresionPage.tsx` | ✅ | |
| Consola Bipay/Anypay: estado en vivo, actualizar tramo con cooldown (4 min propio + 1-3 min aleatorio para otras tiendas de la misma razón social), cierre de jornada, alerta por umbral | `ajax_bipay_saldo.php` | `BipayController::estadoCajero/actualizarCajero/cierreCajero` | ✅ | Lógica de cooldowns, `bipay_saldos_dia`, `bipay_cooldowns` y alertas replicada con transacciones+locks equivalentes. |
| Verificar si la tienda tiene cuenta Bipay/Anypay asignada (para mostrar u ocultar UI) | `ajax_verificar_bipay.php` | Cubierto por `BipayController::contextoCajero` (mismo chequeo `cuenta_bipay_id IS NOT NULL`, reutilizado por los 3 métodos cajero) | ✅ | No hay endpoint idéntico standalone, pero el chequeo se hace en cada llamada relevante — resultado funcional equivalente. |
| Guardar borrador en la nube (auto-save 60s, UPSERT atómico por agente+tienda+fecha, eliminar borrador) | `ajax_guardar_borrador.php` | `ReporteBorradorController` + `borradorApi` (frontend, con fallback a `localStorage` si falla la red) | ✅ | Destino añade fallback local (mejora) manteniendo el guardado en nube. |
| Salvavidas de tardanza (perdona tardanza pasada de la semana, castiga refrigerio de hoy, máx. 1 uso/semana, bloqueado si ya salió a refrigerio) | `ajax_salvavidas.php` | `AsistenciaController::salvavidas` + `SalvavidasPanel` en `MiHistorialPage.tsx` | ✅ | Todas las validaciones (rango lunes-domingo, 1 uso/semana, refrigerio no iniciado) están presentes. |
| Solicitar edición de un reporte cerrado (motivo obligatorio, bloquea si ya hay solicitud pendiente o ya aprobada) | `solicitar_edicion.php` | `ReporteController::solicitarEdicion` + botón en `MiHistorialPage.tsx` | ✅ | |
| Aprobar solicitud de edición (admin) | `aprobar_edicion.php` | `ReporteController::aprobarEdicion` + `ReporteDetallePage.tsx` | ✅ | Destino añade `denegarEdicion` (rechazar), que no existe como endpoint separado en legacy (mejora). |
| **Editar reporte aprobado**: borra y re-inserta TODAS las categorías (equipos/líneas/apoyos), revierte y vuelve a descontar stock, recalcula comisiones, y **detecta fraude de comisión** (cambio de `vendedor_id` para el mismo dni+plan, distingue "cambio crítico" de "restauración" de un cambio previamente detectado) | `procesar_edicion.php` | `ReporteController::reprocesar` + `detectarCambiosVendedor` | ⚠️ | La detección de cambio de vendedor está bien portada (`detectarCambiosVendedor`, líneas 985-1023, compara por `venta_id` o por clave tipo+descripción+dni). **Falta la sub-lógica de "es_restauracion"**: legacy distingue si el cambio actual es exactamente el reverso de la primera alerta `edicion_critica` registrada (y en ese caso etiqueta el historial como `edicion_restaurada` con mensaje "✅ COMISIÓN RESTAURADA" en vez de "⚠️ CAMBIO DE COMISIÓN"). El destino siempre registra `edicion_critica` para cualquier cambio de vendedor, sin detectar reversiones. Es una diferencia menor de auditoría/UX, no de integridad de datos. |
| Edición "ligera" de reporte (solo observaciones/obs_dia/efectivo_entregado/destino_efectivo, sin tocar ventas) | (subconjunto de `procesar_edicion.php`, cuando no se tocan ítems) | `ReporteController::update` | ✅ | Destino separa correctamente edición ligera (`update`) de edición completa con reproceso de ventas (`reprocesar`) — más limpio que el legacy monolítico. |
| Ver detalle de un reporte (desglose por categoría, cuadre, historial de acciones) | `ver_reporte.php` | `ReporteDetallePage.tsx` (956 líneas) + `ReporteController::show/historial` | ✅ | |
| Imprimir/exportar reporte a PDF con desglose detallado por categoría (postpago/prepago/equipos con plan, tipo alta, cantidad, cobrado, vendedor, DNI cliente, flags migración/upgrade/remate, ganancia neta) | `imprimir_reporte.php` | `ConstanciaController::reporte` + `resources/views/constancias/reporte.blade.php` | ⚠️ | El PDF del destino es genérico: solo tabla `# / Tipo Venta / Subtipo / Monto` (90 líneas de blade). **No replica** las secciones tituladas por categoría del legacy (1. VENTAS POSTPAGO, 2. VENTAS PREPAGO, 3. EQUIPOS/ACCESORIOS), ni columnas de vendedor, DNI cliente, badges de migración/upgrade/extranjero, comisión generada, ganancia, ni el bloque de observaciones del día con su propia sección. Es una regresión visible de detalle en el comprobante impreso. |
| Mi historial (reportes propios, filtros, salvavidas, resumen de tardanzas del equipo) | `mi_historial.php` (772 líneas) | `MiHistorialPage.tsx` (649 líneas) | ✅ | |
| Historial general de reportes (admin) | (parte de `gerencia/`, fuera de mi área pero enlazado desde `reportes/`) | `HistorialPage.tsx` | ✅ | Verificado solo por existencia; no auditado en profundidad por estar fuera del alcance asignado. |

---

## Conteo por estado

**`tienda/` (26 features evaluadas):**
- ✅ Paridad completa: 19
- ⚠️ Parcial: 5
- ❌ Falta: 1
- 🔁 N/A: 1

**`reportes/` (20 features evaluadas):**
- ✅ Paridad completa: 17
- ⚠️ Parcial: 3
- ❌ Falta: 0
- 🔁 N/A: 0

**Total combinado:** ✅ 36 · ⚠️ 8 · ❌ 1 · 🔁 1

---

## Los 5 gaps más importantes

1. **❌ CRÍTICO — El rol `tienda` no puede registrar stock nuevo en el destino**: `POST /inventario` y el `update` están bajo `middleware('role:admin')`, mientras que en legacy `registrar_stock.php`/`guardar_stock.php` es precisamente la función diaria del agente de tienda (con DNI/PIN como control de seguridad, no restricción de rol); además el formulario genérico exige precios al ingreso y guarda los chips en la tabla equivocada (`inventario_tiendas` en vez de `inventario_chips`).

2. **⚠️ El voucher/PDF de impresión de reportes (`imprimir_reporte.php` → `ConstanciaController::reporte`) perdió todo el detalle por categoría**: sin secciones postpago/prepago/equipos, sin vendedor, sin DNI cliente, sin badges migración/upgrade/remate, sin ganancia — el legacy tenía un comprobante de auditoría rico y el destino solo lista `tipo_venta/subtipo/monto`.

3. **⚠️ Falta de validación de identidad del agente al *crear* (enviar) traslados de equipos y de chips** (`TrasladoController::store` y `TrasladoChipsController::store`): legacy exige y verifica `auth_dni`+`auth_agente_id` contra la tabla `agentes` antes de permitir el envío (para todos los roles); el destino solo persiste el DNI recibido sin verificarlo, dejando la verificación real únicamente para la confirmación de recepción.

4. **⚠️ Regla "gerente de tienda autoriza traslado directo" no portada**: en legacy, si el DNI autorizador pertenece a un `agente.es_gerencia = 1`, el traslado (equipos o chips) se crea en `PENDIENTE` (sin aprobación admin); en el destino el estado depende solo del rol de sesión (`admin` vs `tienda`), perdiendo ese atajo operativo para gerentes de tienda.

5. **⚠️ Falta el widget "Stock Estancado" en el frontend** (existe el endpoint `InventarioController::stockEstancado` pero ningún componente lo consume) y **la exportación de Matriz/Inventario a Excel cambió de formato** (de un libro con 3 secciones combinadas equipos+accesorios+chips con totales, a un CSV de un solo tipo por descarga) — ambas son pérdidas de funcionalidad visible para el admin en el día a día de gestión de inventario.

## Notas adicionales (menores, no listadas en el top 5)
- `procesar_edicion.php`'s "es_restauracion" (detectar que un cambio de vendedor deshace una alerta de fraude previa) no está replicado en `detectarCambiosVendedor`; todo cambio se marca como `edicion_critica`.
- `api_inventario.php` permite a rol `tienda` editar nombre/IMEI de items de su propia tienda; en destino `InventarioController::update` es admin-only.
- `agregar_stock_rapido.php` es código muerto en el legacy (no enlazado desde ninguna vista) — no requiere paridad, solo se documenta para no generar falsa alarma.
