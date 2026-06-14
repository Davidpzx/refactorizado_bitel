# Inventario legacy: tienda/ + reportes/ (Gemini)

### reportes/ajax_bipay_saldo.php
- **Propósito**: Endpoint AJAX para consultar/actualizar saldos Bipay/Anypay por tienda, con saldo compartido entre sucursales de una misma razón social.
- **Acciones**: `accion=estado` (poll saldos en vivo + cierre + cooldown + tiendas asociadas); `accion=actualizar` (registra tramo de saldo, cooldown 4 min tienda actual + 1-3 min aleatorio a las demás, escribe bipay_saldos_dia/cuentas_bipay/transacciones_bipay); `accion=cierre` (registra cierre, sincroniza saldo vivo, limpia cooldown).
- **Lógica**: cuentas_bipay compartidas; cooldowns por tienda (bipay_cooldowns); alertas por umbral; persistencia opcional del saldo no enviado.

### reportes/ajax_guardar_borrador.php
- **Propósito**: Autoguardado del formulario de nuevo reporte.
- **Acciones**: POST UPSERT en reportes_borradores (clave agente_id+tienda_id+fecha, payload en datos_json); GET ?eliminar=1.
- **Lógica**: INSERT ... ON DUPLICATE KEY UPDATE atómico; acepta form-data o JSON.

### reportes/ajax_guardar_ticket.php
- **Propósito**: Crear/actualizar tickets de venta individuales (popup impresión).
- **Acciones**: POST crear (inserta tickets_emitidos, calcula pago mixto y vuelto); action=update (actualiza cliente, forma de pago, desglose).
- **Lógica**: auto-migración de tabla/columnas pago mixto; cálculo de vuelto; salida JSON robusta (ob_start + _json_salir_tkt).

### reportes/ajax_guardar_ticket_ingreso.php
- **Propósito**: Tickets de ingresos varios (recargas/servicios) desde modal.
- **Acciones**: POST con array items consolidado en un ticket.
- **Lógica**: agrega items en una descripción/monto; comparte auto-migración y pago mixto; salida JSON robusta.

### reportes/ajax_salvavidas.php
- **Propósito**: Compensar una tardanza pasada usando refrigerio del día.
- **Acciones**: POST asistencia_id + minutos.
- **Lógica**: solo tardanzas de la semana actual, 1 vez/semana, debe tener ingreso pero no salida a refrigerio; transacción: pone a cero minutos_tardanza pasados + bandera comodin_usado/min_comodin en el registro de hoy.

### reportes/aprobar_edicion.php
- **Propósito**: Admin aprueba solicitud de edición de reporte.
- **Acciones**: POST reporte_id (desde panel_gerencia).
- **Lógica**: solo admin; estado_edicion='APROBADO'; auditoría en historial_reportes.

### reportes/editar_reporte.php
- **Propósito**: Formulario complejo para editar un cuadre enviado/aprobado.
- **Acciones**: form POST a procesar_edicion.php; agregarLinea(postpago/prepago); agregarEquipoStock(); addVentaExterna(); addSalida(); agregarOtroFlujo(); imprimirTicket(); abrirTicketIngreso().
- **Lógica**: acceso solo admin o estado_edicion='APROBADO'; precarga reporte original; JS masivo: filas dinámicas, calcular() totales/comisiones en tiempo real, calcularComision() por tipo de plan/migración/upgrade, búsqueda de stock, validación precio mínimo, AJAX tickets.

### reportes/imprimir_reporte.php
- **Propósito**: Vista de impresión A4 de un cuadre finalizado.
- **Acciones**: window.print() al cargar.
- **Lógica**: consulta categorías/salidas/historial; tablas estructuradas; CSS @media print.

### reportes/imprimir_ticket_ingreso.php
- **Propósito**: Impresión de ticket de ingreso para térmica.
- **Acciones**: window.print() si ?print=1; botón Imprimir manual.
- **Lógica**: térmica 58/80mm; -webkit-text-stroke para negrita sin gris; logo en Base64.

### reportes/mi_historial.php
- **Propósito**: Agente consulta su historial de asistencias y comisiones; vista de jefe de tienda.
- **Acciones**: form GET (DNI + fechas, protegido por PIN); usarSalvavidas() → ajax_salvavidas.php; link ver_reporte.php.
- **Lógica**: autorización por PIN/token de sesión; roles (agente ve solo el suyo, jefe ve su equipo, admin todos); cálculo de descuentos por tardanza/falta; recálculo de comisiones por rangos de productividad (config_comisiones) excluyendo upgrades/prepago; panel de jefe con resumen de equipo.

### reportes/nuevo_reporte.php
- **Propósito**: Formulario principal de cuadre diario del agente.
- **Acciones**: form POST a procesar_reporte.php; guardarBorrador(); cargarBorrador(); filas dinámicas; panel Bipay/Anypay (actualizar/cerrar).
- **Lógica**: borrador híbrido cloud/local cada 60s; cálculos JS en tiempo real; descuento visual de stock de chips + bloqueo si negativo; integración Bipay/Anypay en vivo; "Modo Dios" admin (registrar a nombre de otra tienda).

### reportes/procesar_edicion.php
- **Propósito**: Backend que guarda modificaciones de un reporte editado.
- **Lógica**: transacción: revierte impacto en inventario (devuelve stock), limpia categorías/salidas, re-aplica datos nuevos y descuenta stock; auditoría de cambio de vendedor como 'edicion_critica'; cierra estado_edicion='CERRADO'.

### reportes/procesar_reporte.php
- **Propósito**: Backend que guarda un nuevo cuadre.
- **Lógica**: previene duplicados (agente+tienda+fecha); transacción integral; inserta reportes + reporte_categorias (JSON) + reporte_salidas; descuenta inventario_tiendas/inventario_chips, revierte si stock insuficiente; limpia borrador.

### reportes/solicitar_edicion.php
- **Propósito**: Agente solicita editar un reporte cerrado.
- **Lógica**: reporte_id + motivo_edicion; estado_edicion='SOLICITADO'; registra en historial_reportes para aprobación.

### reportes/ver_reporte.php
- **Propósito**: Vista solo-lectura de un cuadre (pantalla).
- **Acciones**: link imprimir_reporte.php; link exportar_excel.php; volver.
- **Lógica**: modo restringido oculta financiero si no es su tienda/admin; presentación Bootstrap.

### tienda/actualizar_precio_rapido.php
- **Propósito**: Admin actualiza precio_costo y precio_minimo de un ítem.
- **Lógica**: solo admin; UPDATE inventario_tiendas.

### tienda/agregar_stock_rapido.php
- **Propósito**: Añadir cantidad rápida a stock existente (chips o equipos/accesorios).
- **Lógica**: CHIP → UPSERT bolsillo por tienda+origen; INVENTARIO → reactiva VENDIDO→DISPONIBLE o suma cantidad; auditoría historial_inventario.

### tienda/ajax_kardex_inventario.php
- **Propósito**: Historial de movimientos (Kardex) de un ítem.
- **Lógica**: visibilidad por rol; SQL con COALESCE de 3 fuentes (inventario_tiendas, historial_inventario, JSON reporte_categorias); extrae precio venta y si fue a cuotas.

### tienda/api/ajax_campana_admin.php
- **Propósito**: Admin busca ventas de equipos sin precio de costo.
- **Lógica**: reporte_categorias tipo='equipos_accesorios' con costo_al_registrar/ganancia nulo o 0; devuelve conteo + HTML offcanvas para ingresar costos.

### tienda/api/ajax_stock_estancado.php
- **Propósito**: Admin identifica productos sin vender >30 días.
- **Lógica**: inventario_tiendas DISPONIBLE con fecha_registro >30 días; calcula capital inmovilizado (cantidad*precio_costo); HTML tabla.

### tienda/api_inventario.php
- **Propósito**: API editar/eliminar ítems de inventario.
- **Acciones**: accion=editar (nombre+IMEI, agente solo su tienda); accion=eliminar (admin, requiere DNI autorización).
- **Lógica**: permisos por rol; auditoría detallada en historial_inventario.

### tienda/cambiar_codigo_chip.php
- **Propósito**: Mover lote de chips de un código origen a otro en la misma tienda.
- **Lógica**: transacción; decrementa bolsillo origen; UPSERT bolsillo destino.

### tienda/confirmar_lote_equipo.php
- **Propósito**: Confirmar recepción de un lote completo de equipos/accesorios.
- **Lógica**: array traslados_ids; transacción con SAVEPOINTs por ítem; misma lógica que confirmar_traslado_equipo; rollback parcial por ítem y reporta errores.

### tienda/confirmar_traslado_chips.php
- **Propósito**: Tienda destino confirma recepción de traslado de chips.
- **Lógica**: requiere DNI/PIN si no admin; solo admin o tienda destino; UPSERT stock destino; estado='CONFIRMADO'; auditoría.

### tienda/confirmar_traslado_equipo.php
- **Propósito**: Tienda destino confirma recepción de equipo/accesorio.
- **Lógica**: borra registro origen (estaba TRASLADO), crea destino DISPONIBLE (fusiona accesorios iguales), actualiza traslados_stock.producto_id, estado='CONFIRMADO'.

### tienda/constancia_traslado.php
- **Propósito**: Documento A4 comprobante de traslado.
- **Lógica**: soporta equipos y chips; individual (id) o lote; nombres de agentes envía/recibe con fallback por DNI; formato oficial imprimible.

### tienda/descargar_matriz_excel.php
- **Propósito**: Exporta matriz de inventario a .xlsx (PhpSpreadsheet).
- **Lógica**: pivota equipos/accesorios/chips (filas=producto/origen, columnas=tienda); totales fila/columna; formato.

### tienda/eliminar_chip.php
- **Propósito**: Admin elimina un bolsillo completo de chips.
- **Lógica**: solo admin; DELETE inventario_chips por id.

### tienda/exportar_inventario_excel.php
- **Propósito**: Exporta inventario a .xls (tabla HTML).
- **Lógica**: SQL dinámico filtro tienda/tipo; cabeceras Content-Type ms-excel.

### tienda/exportar_kardex_inventario.php
- **Propósito**: Exporta Kardex a .xls (tabla HTML).
- **Lógica**: reutiliza SQL de ajax_kardex; filtros tienda/tipo/estado/búsqueda; precio costo solo admin/gerencia.

### tienda/fijar_precio_agente.php
- **Propósito**: Agente fija precio_normal de venta.
- **Lógica**: prohibido a admin; requiere DNI; valida propiedad del producto; valida precio_normal >= precio_minimo; solo actualiza precio_normal.

### tienda/gestionar_solicitud_traslado.php
- **Propósito**: Admin aprueba/rechaza/cancela solicitudes de traslado.
- **Acciones**: aprobar (PENDIENTE_APROBACION→PENDIENTE); rechazar (RECHAZADO + revierte stock); cancelar (CANCELADO + revierte stock).
- **Lógica**: solo admin; maneja lotes por codigo_lote; reversión de stock.

### tienda/guardar_stock.php
- **Propósito**: Backend del formulario de registro de nuevo stock.
- **Lógica**: requiere DNI si no admin; EQUIPO → 1 registro por IMEI; ACCESORIO → 1 registro con cantidad; CHIP → UPSERT bolsillo + series_info JSON.

### tienda/matriz_inventario.php
- **Propósito**: Matriz visual de stock (tiendas=columnas, productos=filas).
- **Acciones**: link descargar_matriz_excel.php.
- **Lógica**: pivota 3 categorías en PHP; 3 tablas con sticky headers; totales fila/columna.

### tienda/obtener_historial_chip.php
- **Propósito**: Kardex detallado de un lote de chips.
- **Lógica**: línea de tiempo de 4 fuentes (correcciones, traslados entrantes/salientes, ventas JSON); saldo corriente anterior/nuevo.

### tienda/procesar_correccion_stock.php
- **Propósito**: Ajustes manuales de stock (suma/resta) con auditoría.
- **Lógica**: requiere DNI; transacción; valida stock suficiente en RESTA; actualiza inventario_chips/tiendas; auditoría con Kardex antes/después.

### tienda/procesar_traslado.php
- **Propósito**: Inicia traslado de equipos/accesorios.
- **Lógica**: admin y agente autorizan con credenciales; admin/gerencia→'PENDIENTE', agente→'PENDIENTE_APROBACION'; lotes o individual; stock parcial crea registro TRASLADO; inserta traslados_stock.

### tienda/procesar_traslado_chips.php
- **Propósito**: Inicia traslado de chips entre tiendas.
- **Lógica**: misma autorización; 2 pasos (INSERT traslados_chips pendiente + UPDATE stock con WHERE stock_actual>=? anti-carrera, borra registro si falla).

### tienda/qr_asistencia.php
- **Propósito**: QR dinámico para registrar asistencia.
- **Lógica**: img → api/generar_qr_asistencia.php (HMAC); refresco cada 5s; reloj anti-spoof; modo admin selector de tienda.

### tienda/validar_asistencia_ajax.php
- **Propósito**: Verifica si un agente (DNI) marcó asistencia hoy.
- **Lógica**: agente activo por DNI; registro de hoy con ingreso pero sin salida; autoriza acciones de jornada (correcciones de stock).

### tienda/ver_inventario.php
- **Propósito**: Página principal de inventario de tienda.
- **Acciones**: filtros tienda/tipo; validarYGuardarPrecios() (admin); guardarPrecioNormal() (agente); abrirModalTraslado(); abrirModalEditar() (admin); eliminarItem() (admin); validarYEnviar() (corrección rápida); confirmarRecepcionChip/Equipo().
- **Lógica**: dashboard central (tablas chips/equipos/accesorios); visibilidad por rol; paginación server-side; AJAX a tienda/ y tienda/api/; lazy load de widgets admin (stock estancado, campana costos).
