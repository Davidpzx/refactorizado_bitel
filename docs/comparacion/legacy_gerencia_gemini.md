# Inventario legacy: gerencia/ (Gemini)

### accion_boleta.php
- **Propósito**: Procesa acciones de pago o eliminación de una boleta de planilla desde `ver_agente.php`.
- **Botones/Acciones/Formularios**:
    - Link: `accion_boleta.php?action=pagar&id_pago=...`: Marca una boleta como 'PAGADO'.
    - Link: `accion_boleta.php?action=eliminar&id_pago=...`: Elimina el registro de la boleta de la tabla `pagos_planilla`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Recibe el `id_pago`, `agente_id`, y el rango de fechas (`fi`, `ff`) vía `$_GET`.
    - Actualiza o elimina un registro en la tabla `pagos_planilla`.
    - Redirige de vuelta al perfil del agente (`ver_agente.php`) preservando el contexto.

### acciones_asistencia.php
- **Propósito**: Endpoint centralizado para manejar múltiples acciones administrativas sobre los registros de asistencia desde el `panel_asistencias.php`.
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - `accion=registrar_excepcion`: Registra una ausencia (`FALTA_INJUSTIFICADA`), permiso (`PERMISO`), o anula un registro negativo (`PERDONAR`). Espera `agente_id`, `fecha`, `estado`.
    - `accion=forzar_cierre`: Establece manualmente la hora de salida de una jornada. Espera `asistencia_id`, `hora_salida`.
    - `accion=aprobar_extras`: Aprueba una cantidad de horas extra para un registro. Espera `asistencia_id`, `horas_extras`.
    - `accion=asignar_refrigerio`: Asigna minutos de refrigerio a un agente de medio tiempo que trabajó turno completo. Espera `asistencia_id`, `minutos_refrigerio_asignado`.
    - `accion=eliminar_registro`: Elimina un registro de asistencia. Espera `asistencia_id`.
    - `accion=crear_manual`: Crea un registro de asistencia completo para un día pasado. Espera `agente_id`, `fecha`, `hora_ingreso`, y opcionalmente `inicio_refrigerio`, `fin_refrigerio`, `hora_salida`, y un `motivo_manual` obligatorio.
- **Lógica de negocio clave**:
    - Requiere rol de `admin` y peticiones `POST`.
    - Redirige al `panel_asistencias.php` con mensajes de estado, preservando los filtros de fecha y agente.
    - **Excepciones**: `PERMISO` genera una deuda de 540 minutos (9h) para forzar recuperación. `FALTA_INJUSTIFICADA` no genera deuda de minutos (el descuento se aplica en la boleta). `PERDONAR` elimina registros negativos.
    - **Forzar Cierre**: Valida estrictamente el formato de hora. Calcula `minutos_deuda` si la salida es temprana o `minutos_extra` si es tardía respecto a la hora oficial.
    - **Crear Manual**: Previene duplicados. Calcula `minutos_tardanza` (por ingreso y por exceso de refrigerio) y `minutos_deuda` (por salida temprana) basándose en el horario oficial del agente. Añade una nota de auditoría.

### admin_ajuste_inventario.php
- **Propósito**: Endpoint y UI para que el admin realice un ajuste maestro de inventario (chips o equipos/accesorios) a un conteo físico real.
- **Botones/Acciones/Formularios**:
    - Formulario (GET): Muestra tablas con el stock actual de Chips y Equipos/Accesorios, con inputs para la cantidad real y botones "Aplicar" por cada ítem.
    - AJAX (POST): Invocado por el botón "Aplicar" (`.btn-ajuste`).
        - Parámetros: `tipo` (CHIP/ACC), `inventario_id`, `tienda_codigo`, `cantidad_real`, `observacion`.
        - Procesa el ajuste y devuelve un JSON con el resultado.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Utiliza una transacción de base de datos para garantizar la consistencia.
    - El stock del producto en `inventario_chips` o `inventario_tiendas` se sobreescribe con la `cantidad_real`.
    - Registra cada ajuste en la tabla `historial_inventario` con la acción 'SUMA' o 'RESTA' (basado en la diferencia), la cantidad ajustada, el stock anterior/nuevo y la observación proporcionada por el admin.

### admin_editar_asistencia.php
- **Propósito**: Endpoint para procesar la edición maestra de un registro de asistencia.
- **Botones/Acciones/Formularios**: (Endpoint POST puro, sin UI)
    - Procesa un formulario enviado desde `panel_asistencias.php`.
    - Parámetros: `asistencia_id`, `fecha`, `hora_ingreso`, `inicio_refrigerio`, `fin_refrigerio`, `hora_salida`, `omitio_refrigerio`, `observacion_admin`, y filtros para el redirect.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Utiliza una transacción de base de datos.
    - Recalcula `minutos_tardanza` (considerando ingreso y retorno de refrigerio, y el uso del "comodín" o salvavidas).
    - Recalcula `minutos_deuda` si la salida es temprana, pero no acredita horas extra.
    - Actualiza el registro en la tabla `asistencias` con todos los valores nuevos y calculados.
    - Redirige de vuelta al panel de asistencias.

### aprobar_postulante.php
- **Propósito**: Procesa la aprobación o rechazo de un postulante desde la tabla de "Registros de Datos Pendientes" en `gestionar_agentes.php`.
- **Botones/Acciones/Formularios**:
    - Formulario `accion=aprobar`: Inserta los datos del `postulantes_temp` en la tabla `agentes`, creando un nuevo agente activo. Espera datos complementarios del modal (horario, sueldo, tienda, rol, PIN, etc.).
    - Formulario `accion=rechazar`: Actualiza el estado del postulante a `RECHAZADO` y guarda las notas del admin. Espera `postulante_id` y `notas_admin`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin` y peticiones `POST`.
    - **Aprobación**: Transfiere toda la información recopilada en el formulario de onboarding (`postulantes_temp`) a la tabla `agentes`. Asigna un rol, horario, sueldo base y tienda. Si el PIN no se especifica, se autogenera con los últimos 4 dígitos del DNI.
    - **Rol Administrativo**: Si el rol es 'Administrativo', la `tienda_base` se fija como 'ADMIN' y `es_gerencia` como 0.
    - **Rechazo**: Simplemente actualiza el estado del registro temporal y lo saca de la lista de pendientes.

### autorizar_edicion.php
- **Propósito**: Permite a un gerente autorizar la solicitud de edición de un reporte de ventas que un agente ha marcado como necesitando corrección.
- **Botones/Acciones/Formularios**:
    - Link: `autorizar_edicion.php?id=...`: Invocado desde un botón en el `panel_gerencia.php`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Recibe el `reporte_id` vía `$_GET`.
    - Actualiza el reporte en la tabla `reportes`, poniendo el `estado` a 'borrador' y `requiere_aprobacion` a 0.
    - Registra la acción de aprobación en `historial_reportes` para auditoría.
    - Redirige al `panel_gerencia.php`.

### ajax_fijar_costo_rapido.php
- **Propósito**: Endpoint AJAX para actualizar rápidamente el precio de costo de un ítem y recalcular su ganancia.
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - Invocado desde `ver_inventario.php`.
    - Parámetros POST: `rc_id` (reporte\_categorias ID), `precio_costo`, `precio_venta`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Lee el `detalle` JSON de `reporte_categorias`.
    - Actualiza el `costo_al_registrar` y recalcula la `ganancia`.
    - Prioriza el `precio_venta` del POST, pero tiene fallbacks (`precio_normal_agente`, `precio_total`) si no se envía.
    - Guarda el JSON modificado en la base de datos.
    - Devuelve un JSON con el estado de la operación.

### ajax_planilla.php
- **Propósito**: Endpoint AJAX para guardar campos editables de la planilla mensual de agentes.
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - Invocado desde `planilla_agentes.php` al editar una celda.
    - `accion=reset_comisiones`: Resetea las comisiones a su cálculo automático.
    - (Default): Guarda un campo específico (`dias_trabajados`, `comision_jefe`, `notas`, etc.).
        - Parámetros POST: `agente_id`, `mes`, `campo`, `valor`.
        - Si se guarda un campo de comisión y `set_override` es `true`, activa una bandera para ignorar el cálculo automático.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Valida que el `campo` a modificar esté en una lista blanca de campos permitidos.
    - Usa `INSERT ... ON DUPLICATE KEY UPDATE` en `planilla_ajustes` para persistir los cambios.
    - La bandera `override_comisiones` permite al admin fijar montos de comisión manualmente, ignorando los cálculos del sistema para ese mes.

### ajax_seguridad.php
- **Propósito**: Endpoint AJAX para gestionar la seguridad de las cuentas de agentes (tokens de emergencia, desvinculación de dispositivos).
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - `accion=token`:
        - `tipo=diario`: Genera un token de 6 dígitos que expira a medianoche.
        - `tipo=permanente`: Genera un token que no expira.
        - `tipo=revocar`: Elimina el token activo.
    - `accion=reset`: Desvincula el celular de un agente (`hash_dispositivo = NULL`) para permitir un nuevo registro.
    - `accion=sancion`: Modifica el "banco de deudas" (`deuda_dias`) de un agente.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - `token`: Genera un token aleatorio y lo guarda en la tabla `agentes` con su respectiva fecha de expiración.
    - `reset`: Limpia el hash del dispositivo y cualquier token de emergencia, permitiendo al agente volver a vincular un celular en la página de asistencia.
    - `sancion`: Aumenta o disminuye la cuenta de días de deuda que un agente debe compensar.

### certificado_agente.php
- **Propósito**: Genera una constancia de trabajo imprimible en formato A4 para un agente específico.
- **Botones/Acciones/Formularios**:
    - Botón `Imprimir / Guardar PDF`: Invoca la función `window.print()` del navegador.
    - Link `Volver al perfil`: Regresa a `ver_agente.php`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Recibe el `id` del agente vía `$_GET`.
    - Obtiene los datos del agente desde la tabla `agentes` y los datos de la empresa desde `configuracion_empresa`.
    - Genera dinámicamente el texto del certificado, indicando si el colaborador sigue activo o la fecha de cese.
    - El diseño está hecho con CSS `@page` para una impresión limpia en A4.

### comisiones_empresa.php
- **Propósito**: Panel de administración para el CRUD de los planes de comisiones de la empresa y la configuración de ganancias operativas.
- **Botones/Acciones/Formularios**:
    - Botón `Crear Nuevo Plan`: Abre un modal para registrar un nuevo plan en `comisiones_planes`.
    - Botón `Editar` por cada plan: Abre un modal para modificar un plan existente.
    - Botón `Recálculo Masivo de Planes`: Abre un modal para recalcular las comisiones de planes (postpago/prepago) en un rango de fechas.
    - Botón `Guardar Tarifas`: Guarda las ganancias de servicios operativos (Recargas, Bipay, etc.) en `config_comisiones`.
    - Botón `Recálculo Masivo` (servicios): Abre un modal para recalcular las ganancias de servicios operativos en un rango de fechas.
    - Botones para `Agregar Rango` y `Guardar Rangos` para servicios como Bipay, Krece, y Payjoy.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - **CRUD de Planes**: Permite crear y editar registros en la tabla `comisiones_planes`, que define las comisiones para diferentes tipos de altas (Línea Nueva, Portabilidad) y tipos de clientes (DNI, Extranjero).
    - **Recálculo Masivo**: Invoca a `recalcular_comisiones_masivo.php` para actualizar las ganancias en reportes históricos según las tarifas actuales, afectando solo un rango de fechas específico.
    - **Gestión de Tarifas Operativas**:
        - Guarda porcentajes (recargas) o montos fijos (servicios) en `config_comisiones`.
        - Permite definir comisiones escalonadas por rangos de monto para servicios como Bipay, Krece y Payjoy, guardándolos en `comisiones_rangos` vía `guardar_rangos_ajax.php`.

### configuracion_empresa.php
- **Propósito**: Panel para que el administrador configure los datos generales de la empresa.
- **Botones/Acciones/Formularios**:
    - Formulario de guardado: Envía un `POST` con todos los datos de la empresa.
        - Incluye `razon_social`, `nombre_comercial`, `ruc`, `gerente_general`, `direccion`, etc.
        - Incluye un campo `input type="file"` (`logo_file`) para subir un nuevo logo.
    - Botón `Guardar Cambios`: Realiza el `submit` del formulario.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Al recibir el `POST`, actualiza el único registro (con `id=1`) en la tabla `configuracion_empresa`.
    - **Subida de Logo**: Si se sube un nuevo archivo de logo, valida el tipo (PNG, JPG, WebP) y tamaño (máx 2MB), lo guarda en el directorio `includes/` con un nombre único, y actualiza la ruta en la base de datos.
    - Muestra mensajes de éxito o error tras la operación.

### configurar_comisiones.php
- **Propósito**: Panel para configurar la estrategia de comisiones por rangos de ventas para planes y equipos.
- **Botones/Acciones/Formularios**:
    - Botón `Agregar Rango`: Añade dinámicamente una nueva fila a la tabla de rangos (para Planes o Equipos).
    - Botón `GUARDAR ESTRATEGIA DE COMISIONES`: Envía el formulario con todos los rangos definidos.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Usa una estrategia `DELETE` + `INSERT` para actualizar los rangos en la tabla `config_comisiones`.
    - Define comisiones escalonadas: la comisión pagada a un agente por una venta depende de cuántas ventas de ese tipo (PLAN o EQUIPO) ha acumulado en el mes.
    - Por ejemplo, de la 1ra a la 5ta venta de plan se paga X, de la 6ta a la 10ma se paga Y, etc.

### confirmar_desembolso.php
- **Propósito**: Endpoint AJAX para confirmar que una financiera ha pagado el desembolso de una venta a crédito.
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - Invocado desde el `panel_financieras.php`.
    - Parámetros POST: `reporte_categoria_id`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Usa una transacción con `FOR UPDATE` para evitar race conditions.
    - Cambia el `comision_estado` de 'PENDIENTE' a 'APROBADA' en `reporte_categorias`.
    - **Libera la comisión**: Calcula y actualiza la `comision_agente` en el `detalle` JSON, que estaba en 0. La comisión se basa en la configuración de la empresa (`config_comisiones`).
    - Recalcula la `ganancia` final de la venta si el `costo_al_registrar` ya está disponible.
    - Registra quién y cuándo se confirmó el desembolso (`desembolso_confirmado_por`, `desembolso_confirmado_en`).

### consulta_dni.php
- **Propósito**: Endpoint para consultar datos de una persona a partir de su DNI usando una API externa.
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - Invocado desde `gestionar_agentes.php` al registrar un nuevo agente.
    - Parámetros GET: `dni`.
- **Lógica de negocio clave**:
    - Requiere que el usuario esté logueado.
    - Valida que el DNI tenga 8 dígitos.
    - Realiza una petición cURL a la API `api-codart.cgrt.org` con un token de autorización hardcodeado.
    - Devuelve la respuesta JSON de la API directamente al cliente.

### diagnostico_tiendas.php
- **Propósito**: Página de diagnóstico solo para administradores para verificar la consistencia de datos entre las tablas `tiendas`, `usuarios` e `inventario_chips`.
- **Botones/Acciones/Formularios**: Ninguno, es una página de solo lectura.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Muestra el contenido de la sesión actual (`$_SESSION`).
    - Lista todos los registros de la tabla `tiendas`.
    - Lista los `tienda_id` de todos los usuarios en la tabla `usuarios`.
    - Muestra un resumen del `inventario_chips`, incluyendo `tienda_codigo` y `tienda_origen`.
    - Útil para depurar problemas de asignación de tiendas y stock.

### editar_agente.php
- **Propósito**: Endpoint para procesar la edición de los datos de un agente desde `gestionar_agentes.php`.
- **Botones/Acciones/Formularios**: (Endpoint POST puro, sin UI)
    - Procesa el formulario del modal de edición de agente.
    - Parámetros POST: Todos los campos del agente (`id`, `nombres`, `tienda_base`, `hora_ingreso`, `estado`, etc.).
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Si el `estado` se cambia a 'INACTIVO', se guardan campos adicionales como `clasificacion_baja` ('LISTA_BLANCA'/'LISTA_NEGRA'), `motivo_baja`, y `fecha_baja`.
    - Si el estado es 'ACTIVO', se limpian todos los campos relacionados a la baja.
    - **Permiso Largo**: Una opción especial de "baja" en 'LISTA_BLANCA' que indica que el agente regresará en una `fecha_retorno`.
    - **Historial**: Si el estado del agente cambia, se inserta un nuevo registro en `historial_agentes` para auditoría.
    - **Rol Administrativo**: Si el rol se establece a 'Administrativo', la tienda se fija como 'ADMIN'.
    - **Rol Jefe de Tienda**: Preserva el string 'jefe\_tienda' en la columna `es_gerencia`.

### editar_tienda.php
- **Propósito**: Muestra un formulario para editar los detalles de una sede (tienda) y procesa la actualización.
- **Botones/Acciones/Formularios**:
    - Formulario de edición:
        - Inputs para `codigo`, `nombre` (ubicación), `latitud`, `longitud`, y `radio_permitido`.
    - Botón `CAPTURAR MI UBICACIÓN ACTUAL`: Usa la API de Geolocalización del navegador para autocompletar la latitud y longitud.
    - Botón `GUARDAR CAMBIOS`: Envía el formulario con un `POST`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Al recibir el `POST`, actualiza el registro correspondiente en la tabla `tiendas`.
    - Verifica que el nuevo `codigo` de tienda no esté duplicado antes de guardar.
    - Redirige de vuelta a `tiendas.php` con un mensaje de éxito.

### editar_usuario_ajax.php
- **Propósito**: Endpoint AJAX para editar los detalles de un usuario, como su contraseña o permisos.
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - Invocado desde el modal de edición en `usuarios.php`.
    - Parámetros POST: `usuario_id`, `tiene_bcp` (permiso para módulo BCP), `nueva_password` (opcional), `cuenta_bipay_id` (opcional), `formato_ticket`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Si se proporciona una `nueva_password`, la hashea y la actualiza en la tabla `usuarios`.
    - Actualiza el flag `tiene_bcp` para conceder o revocar acceso al módulo BCP.
    - Actualiza el `formato_ticket` ('58' o '80') para la impresión térmica.
    - Si se asigna una `cuenta_bipay_id` a un usuario de tienda, actualiza la tabla `tiendas` para vincular esa tienda con la cuenta Bipay.

### eliminar_agente.php
- **Propósito**: Elimina permanentemente un registro de agente.
- **Botones/Acciones/Formularios**:
    - Link: `eliminar_agente.php?id=...`: Invocado desde `gestionar_agentes.php`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Recibe el `id` del agente vía `$_GET`.
    - Ejecuta un `DELETE` en la tabla `agentes`.
    - Maneja errores de integridad referencial (`PDOException` con código `23000`), redirigiendo con un mensaje de error si el agente tiene datos asociados (reportes, boletas, etc.) que impiden su eliminación.

### eliminar_reporte.php
- **Propósito**: Elimina un reporte de ventas y revierte el stock de los productos vendidos en ese reporte.
- **Botones/Acciones/Formularios**:
    - Link: `eliminar_reporte.php?id=...`: Invocado desde `panel_gerencia.php` o `historial_completo.php`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Utiliza una transacción de base de datos para asegurar que todas las operaciones se completen o ninguna.
    - **Rollback de Stock**:
        1.  Extrae los IMEIs de los equipos vendidos del `detalle` JSON en `reporte_categorias`.
        2.  Actualiza el estado de esos equipos a 'DISPONIBLE' en `inventario_tiendas`.
    - **Limpieza de Datos**:
        1.  Elimina registros asociados en `comisiones` (si existe), `reporte_categorias`, `reporte_salidas`, y `historial_reportes`.
        2.  Finalmente, elimina el registro principal de la tabla `reportes`.

### eliminar_usuario.php
- **Propósito**: Elimina un usuario y reasigna sus registros (reportes, historial) al administrador que realiza la acción.
- **Botones/Acciones/Formularios**:
    - Link: `eliminar_usuario.php?id=...`: Invocado desde `usuarios.php`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Prohíbe que un admin se elimine a sí mismo.
    - Usa una transacción para garantizar la integridad de los datos.
    - **Reasigna propiedad**: Antes de eliminar, actualiza las tablas `reportes`, `historial_reportes`, e `inventario_tiendas` para transferir la autoría de los registros del usuario eliminado al ID del administrador actual (`$_SESSION['user_id']`).
    - Finalmente, elimina el registro de la tabla `usuarios`.

### estadisticas_ventas.php
- **Propósito**: Muestra un dashboard de estadísticas de ventas con rankings de tiendas y productos.
- **Botones/Acciones/Formularios**:
    - Formulario de filtros: Permite filtrar por `tienda`, `tipo_venta` (postpago, prepago, equipos), y rango de `fechas`.
    - Botón `Excel`: Exporta los datos de la vista actual a un archivo Excel (.xls).
- **Lógica de negocio clave**:
    - Acceso para `admin` y `tienda`. El rol `tienda` solo ve datos de su propia tienda.
    - **Ranking de Tiendas**: Calcula y muestra un ranking de tiendas basado en el total de ventas, desglosado por categorías (Postpago, Prepago, Eq. Cuotas, Eq. Contado, Accesorios).
    - **Ranking de Agentes**: Calcula y muestra un ranking de vendedores individuales basado en sus ventas totales dentro del período y filtros seleccionados.
    - **Top Productos**: Muestra los 10 equipos, accesorios y planes postpago más vendidos.
    - **Lógica de Conteo**: Itera sobre los `detalle` JSON de `reporte_categorias` para sumar las ventas, respetando la lógica de `cross_selling` (apoyo inter-tienda) y excluyendo ventas no comisionables como `upgrades`.

### exportar_asistencias_excel.php
- **Propósito**: Genera y descarga un reporte de asistencias en formato Excel (.xls).
- **Botones/Acciones/Formularios**:
    - Link: `exportar_asistencias_excel.php?fecha_desde=...&fecha_hasta=...`: Invocado desde `panel_asistencias.php`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Acepta filtros de `fecha_desde`, `fecha_hasta`, y `agente_id` vía `$_GET`.
    - Consulta la tabla `asistencias` con los filtros aplicados.
    - Genera una tabla HTML y la sirve con las cabeceras `Content-Type: application/vnd.ms-excel`, lo que fuerza la descarga de un archivo .xls.
    - Incluye totales de minutos de tardanza y deuda al final del reporte.

### exportar_excel.php
- **Propósito**: Genera un reporte detallado de un único cuadre de ventas en formato Excel (.xls).
- **Botones/Acciones/Formularios**:
    - Link: `exportar_excel.php?id=...`: Invocado desde `panel_gerencia.php` o `historial_completo.php`.
- **Lógica de negocio clave**:
    - El `admin` tiene acceso total. El rol `tienda` solo puede descargar reportes de su propia tienda.
    - Recibe el `reporte_id` vía `$_GET`.
    - Obtiene todos los datos del reporte, incluyendo cabecera, categorías, salidas y pagos digitales.
    - Formatea los datos en varias secciones dentro de una tabla HTML (Postpago, Prepago, Equipos, Salidas, Cuadre Financiero, etc.).
    - Sirve la tabla HTML con las cabeceras de Excel para forzar la descarga.

### exportar_excel_agentes_pro.php
- **Propósito**: Genera un archivo Excel (.xlsx) avanzado con la lista completa del personal y fichas individuales detalladas en hojas separadas.
- **Botones/Acciones/Formularios**:
    - Link: `exportar_excel_agentes_pro.php`: Invocado desde `gestionar_agentes.php`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Utiliza la librería `PhpOffice/PhpSpreadsheet` para crear un archivo .xlsx nativo.
    - **Hoja 1 ("Personal")**: Crea una lista general de todos los agentes con sus datos laborales básicos. Incluye un hipervínculo en cada fila que lleva a la hoja de ficha individual de ese agente.
    - **Hojas Individuales**: Por cada agente, crea una nueva hoja de cálculo.
        - Obtiene datos tanto de la tabla `agentes` como de la tabla `postulantes_temp` para construir una ficha completa.
        - La ficha incluye datos personales, de contacto, de salud, previsionales, carga familiar, formación académica, experiencia laboral y contactos de emergencia.
        - Los datos almacenados en formato JSON (como carga familiar) se decodifican y se presentan en formato legible.

### fijar_precio.php
- **Propósito**: Endpoint AJAX para fijar los precios (costo, mínimo, normal) de un producto en el inventario y recalcular retroactivamente las ganancias en ventas pasadas.
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - Invocado desde el botón "Fijar" en `revisar_stock.php`.
    - Parámetros POST: `id` (inventario\_tiendas ID), `precio_costo`, `precio_minimo`, `precio_normal`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Actualiza los campos `precio_costo`, `precio_minimo`, y `precio_normal` en la tabla `inventario_tiendas`.
    - **Recalculador Retroactivo**: Busca todas las ventas históricas de ese producto (por IMEI o por nombre) en `reporte_categorias`.
    - Para cada venta encontrada, actualiza el `detalle` JSON, recalculando el campo `ganancia` con el nuevo costo (`precio_venta_registrado - nuevo_costo`).
    - Devuelve un JSON indicando el éxito de la operación y cuántas ventas históricas fueron actualizadas.

### gestionar_agentes.php
- **Propósito**: Panel central para la gestión de personal, incluyendo el registro de nuevos agentes, la visualización de la lista de personal y la gestión de postulantes.
- **Botones/Acciones/Formularios**:
    - Formulario `Registrar Nuevo Agente`: Envía un POST a `guardar_agente.php`.
    - Botones en tabla de postulantes: `Ver Ficha`, `Aprobar`, `Rechazar`. `Aprobar` y `Rechazar` envían un POST a `aprobar_postulante.php`.
    - Botones en tabla de agentes: `Ver Perfil` (link a `ver_agente.php`), `Editar` (abre modal de edición), `Gestión de Acceso` (abre modal para tokens), `Registrar Falta/Permiso`, `Desvincular Celular`, `Eliminar` (link a `eliminar_agente.php`).
    - Botones `Copiar URL`: Copian al portapapeles las URLs de los formularios públicos de registro y asistencia.
    - Botón `Exportar Excel`: Genera una descarga .xls de la lista de agentes.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - **Registro**: El formulario de registro usa una API de DNI para autocompletar el nombre. El PIN de seguridad se autogenera si se deja en blanco.
    - **Gestión de Postulantes**: Muestra los registros de `postulantes_temp` con estado 'PENDIENTE' y permite al admin revisarlos, aprobarlos (lo que los convierte en agentes) o rechazarlos.
    - **Lista de Agentes**: Muestra todos los agentes de la tabla `agentes` con sus datos clave y acciones rápidas. Incluye filtros por nombre/DNI y tienda.
    - **Modales de Acción**: Usa modales de Bootstrap para editar agentes, gestionar acceso con tokens (vía `ajax_seguridad.php`), y registrar faltas (vía `acciones_asistencia.php`).

### guardar_agente.php
- **Propósito**: Endpoint para procesar el formulario de registro de un nuevo agente desde `gestionar_agentes.php`.
- **Botones/Acciones/Formularios**: (Endpoint POST puro, sin UI)
    - Procesa el formulario de registro.
    - Parámetros POST: `dni`, `nombres`, `tienda_base`, `hora_ingreso`, etc.
- **Lógica de negocio clave**:
    - Inserta un nuevo registro en la tabla `agentes`.
    - Si el `pin_seguridad` no se proporciona, lo autogenera usando los últimos 4 dígitos del DNI.
    - Si el rol seleccionado es 'Administrativo', la `tienda_base` se guarda como 'ADMIN'.
    - Redirige de vuelta a `gestionar_agentes.php` con un mensaje de éxito.

### guardar_rangos_ajax.php
- **Propósito**: Endpoint AJAX para guardar la configuración de comisiones por rangos de monto para servicios como Bipay, Krece y Payjoy.
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - Invocado desde `comisiones_empresa.php` al hacer clic en "Guardar Rangos".
    - Parámetros POST: `tipo_servicio` (e.g., 'bipay'), `rangos` (un array JSON con los rangos).
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Utiliza una transacción con una estrategia `DELETE` + `INSERT` para reemplazar todos los rangos del `tipo_servicio` especificado en la tabla `comisiones_rangos`.
    - Valida que los rangos no se solapen y que los montos sean válidos.
    - No afecta reportes históricos, solo la configuración para futuras comisiones.

### guardar_tarifas_ajax.php
- **Propósito**: Endpoint AJAX para guardar las tarifas operativas generales (ganancia por recargas, Bipay, Krece, Payjoy).
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - Invocado desde el botón "Guardar Tarifas" en `comisiones_empresa.php`.
    - Parámetros POST: `ganancia_recargas`, `ganancia_bipay`, `ganancia_krece`, `ganancia_payjoy`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Usa `INSERT ... ON DUPLICATE KEY UPDATE` (o una lógica similar de check+insert/update) para guardar cada tarifa en la tabla `config_comisiones`.
    - Esta acción solo modifica la configuración y no recalcula las ganancias en reportes históricos.

### guardar_usuario.php
- **Propósito**: Endpoint para procesar el formulario de registro de un nuevo usuario del sistema (no un agente de ventas).
- **Botones/Acciones/Formularios**: (Endpoint POST puro, sin UI)
    - Procesa el formulario de "Registrar Nuevo Agente" (mal nombrado, debería ser "Usuario") en `usuarios.php`.
    - Parámetros POST: `nombre`, `email`, `password`, `rol`, `tienda_id`, `tiene_bcp`, `cuenta_bipay_id`, `formato_ticket`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Hashea la contraseña antes de guardarla.
    - Inserta un nuevo registro en la tabla `usuarios`.
    - Si el rol es 'admin', la `tienda_id` se asigna a 'CENTRAL' por defecto.
    - Si se asigna una `cuenta_bipay_id` a un usuario de tienda, actualiza la tabla `tiendas` para vincularla.

### historial_agente.php
- **Propósito**: Muestra un reporte detallado del rendimiento y asistencia de un agente para un rango de fechas, con enfoque en el cálculo para la liquidación.
- **Botones/Acciones/Formularios**:
    - Formulario de filtro de fechas.
    - Botón `Descargar PDF`: Invoca la función `window.print()` con CSS optimizado para impresión.
    - Link `Volver al Resumen`: Regresa a `ver_agente.php`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - **Cálculo de Comisiones**: Replica la lógica de `ver_agente.php`, calculando las comisiones de planes y equipos basadas en rangos de productividad mensual.
    - **Cálculo de Descuentos**: Calcula el descuento total por tardanzas (S/ 1.00 por minuto), faltas (doble del valor día), y deuda de horas.
    - **Balance de Horas**: Muestra un desglose del "banco de horas", donde las horas extra pueden compensar la deuda de horas.
    - Muestra un listado detallado de todas las marcaciones de asistencia y de todas las ventas que generaron comisión en el período.

### historial_completo.php
- **Propósito**: Panel avanzado para administradores y jefes de tienda para ver, filtrar y exportar un historial completo de todos los reportes de ventas.
- **Botones/Acciones/Formularios**:
    - Formulario de filtros: Permite filtrar por rango de fechas, tienda, agente, etc.
    - Botón `Exportar`: Descarga un reporte en formato Excel (.xls). Para Jefes de Tienda, requiere autorización por PIN.
    - Botones de acción por cada reporte: `Ver Detalle`, `Exportar Excel Individual`, `Eliminar`, y `Solicitar/Aprobar Edición`.
- **Lógica de negocio clave**:
    - El `admin` ve todo. El rol `tienda` solo puede ver reportes de su propia tienda.
    - **Seguridad de Exportación**: Los Jefes de Tienda deben autenticarse con su DNI y PIN de seguridad a través de un modal para poder descargar el reporte general.
    - **Exportación en Bloques**: El export a Excel agrupa todas las ventas del período por categorías (Postpago, Prepago, Equipos, etc.) en secciones separadas, en lugar de por reporte diario.
    - **Gestión de Ediciones**: Permite a los agentes solicitar la edición de un reporte cerrado y a los admins aprobar dichas solicitudes. Los reportes con solicitud pendiente o edición aprobada se resaltan visualmente.

### imprimir_boleta.php
- **Propósito**: Procesa la creación de una boleta de pago y genera una vista imprimible de la misma.
- **Botones/Acciones/Formularios**:
    - (Endpoint POST): Procesa la creación de una boleta desde `ver_agente.php`. Guarda el registro en `pagos_planilla` y redirige a la vista de impresión.
    - (Vista GET): Muestra la boleta para ser impresa.
        - Botón `Imprimir`: Llama a `window.print()`.
        - Botón `Cerrar Pestaña`: Cierra la ventana/pestaña actual.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Cuando se recibe un `POST`, inserta los detalles de la liquidación (sueldo, bonos, descuentos, total) en la tabla `pagos_planilla` con estado 'PENDIENTE'.
    - Cuando se accede con un `id_pago` vía `GET`, obtiene los datos de la boleta y del agente y los renderiza en un formato de boleta de pago estándar, lista para imprimir.

### marcar_entregado.php
- **Propósito**: Endpoint para actualizar el estado del efectivo de un reporte (marcarlo como entregado/depositado o revertirlo a "en tienda").
- **Botones/Acciones/Formularios**: (Endpoint POST puro, sin UI)
    - Procesa el formulario del modal "Estado del Efectivo" en `panel_gerencia.php` y `historial_completo.php`.
    - Parámetros POST: `reporte_id`, `accion_destino`, `observacion_gerencia`.
- **Lógica de negocio clave**:
    - Accesible tanto por `admin` como por `tienda`.
    - Actualiza el campo `destino_efectivo` en la tabla `reportes` a 'ENTREGADO' o lo revierte a 'TIENDA'.
    - Inserta un registro en `historial_reportes` para auditar quién hizo el cambio, cuándo y por qué (usando la observación).

### migrar_formato_ticket.php
- **Propósito**: Script de migración de un solo uso para añadir la columna `formato_ticket` a la tabla `usuarios`.
- **Botones/Acciones/Formularios**: Ninguno. Se ejecuta al acceder a la URL directamente.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Es idempotente: verifica si la columna ya existe antes de intentar agregarla.
    - Si la columna no existe, la añade con `ALTER TABLE`, especificando el tipo `ENUM('58','80')` y un valor por defecto de '80'.
    - Muestra un mensaje indicando que el script debe ser eliminado después de su uso.

### panel_asistencias.php
- **Propósito**: Panel principal para la gestión de asistencias, mostrando un listado de registros y proveyendo acciones administrativas.
- **Botones/Acciones/Formularios**:
    - Formulario de filtros: por `agente_id` y rango de fechas.
    - Botón `Descargar PDF`: Imprime la vista actual.
    - Botón `Exportar Excel`: Link a `exportar_asistencias_excel.php`.
    - Botón `Revisar Fotos`: Link a `revisar_fotos_asistencia.php`.
    - Botón `Registrar Falta/Permiso`: Abre un modal que envía un POST a `acciones_asistencia.php`.
    - Botón `Asistencia Manual`: Abre un modal para crear un registro manual, que envía un POST a `acciones_asistencia.php`.
    - Botones por fila: `Editar` (abre modal de edición), `Forzar` (cierre de jornada), `Aprobar` (horas extra), `Eliminar`. Todos estos invocan `acciones_asistencia.php` vía modales o JS.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Muestra un listado detallado de los registros de `asistencias` según los filtros.
    - Para cada registro, muestra la jornada, el estado, y un desglose del balance de horas (tardanzas, deudas, extras).
    - Incluye un "Monitor de Fraude de Dispositivos" que muestra los intentos de marcación con un celular que no corresponde al agente.

### panel_bipay.php
- **Propósito**: Panel de administración para gestionar las cuentas Bipay/Anypay, realizar recargas, transferencias y ver el historial de transacciones.
- **Botones/Acciones/Formularios**:
    - `Recargar Cuenta`: Abre modal para añadir saldo a una cuenta madre. POST con `accion=recarga`.
    - `Transferir`: Abre modal para mover saldo de una cuenta madre a una hija. POST con `accion=transferencia`.
    - `Ajustar`: Abre modal para corregir manualmente el saldo de una cuenta. POST con `accion=ajuste`.
    - `Nueva Cuenta`: Abre modal para crear una cuenta (madre o hija). POST con `accion=nueva_cuenta`.
    - `Editar` / `Eliminar` por cuenta: Modifica o borra una cuenta. POST con `accion=editar_cuenta` o `accion=eliminar_cuenta`.
    - Formulario de filtros para el historial de transacciones.
    - Botón `Exportar Excel` del historial.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Muestra un resumen de todas las cuentas "Madre" y sus cuentas "Hijo" asociadas, con saldos desglosados (Bipay/Anypay/Total).
    - Muestra las declaraciones de saldo que las tiendas han hecho en el día.
    - Muestra los "locks" activos (tiendas que están operando con una cuenta en ese momento).
    - Proporciona un historial de todas las transacciones con filtros.
    - Todas las operaciones (recargas, transferencias, ajustes) se registran en la tabla `transacciones_bipay` para auditoría.

### panel_financieras.php
- **Propósito**: Panel para gestionar y confirmar los desembolsos de ventas realizadas a crédito a través de financieras.
- **Botones/Acciones/Formularios**:
    - Formulario de filtros: por `financiera`, `tienda`, `estado` ('PENDIENTE'/'APROBADA'), y `mes`.
    - Botón `Confirmar` por cada venta pendiente: Invoca un `fetch` a `confirmar_desembolso.php`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Muestra un listado de todas las ventas de equipos/accesorios a crédito (`tipo_pago='CUOTAS'`).
    - Las ventas aparecen como 'PENDIENTE' hasta que un admin confirma que la financiera ha desembolsado el dinero.
    - Al confirmar, el estado cambia a 'APROBADA', y se libera la comisión para el agente que realizó la venta.
    - Muestra KPIs con los totales pendientes de cobro y los ya confirmados para el mes seleccionado.

### panel_gerencia.php
- **Propósito**: Dashboard principal para el gerente/administrador, mostrando un resumen de los últimos reportes y acceso a otros módulos.
- **Botones/Acciones/Formularios**:
    - Formulario de filtros: por `tienda` y rango de fechas.
    - Botones de acceso a otros módulos: `Tiendas`, `Usuarios`, `Registrar Cuadre`.
    - Campanita de `Alertas`: Abre un `Offcanvas` con reportes que tienen anomalías (diferencias de caja o ediciones no aprobadas).
    - Botón `Exportar Excel`: Exporta los reportes filtrados a un archivo `.xls`.
    - Acciones por cada reporte: `Ver Detalle`, `Exportar Excel Individual`, `Eliminar`, y `Solicitar/Aprobar Edición`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Muestra un resumen financiero del período filtrado (Total Sistema, Efectivo Esperado, Físico Entregado, Diferencia).
    - Muestra una tabla con los últimos 5 reportes de ventas (o los filtrados).
    - La "Campanita" de anomalías consulta los reportes donde `diferencia <> 0` o `estado = 'editado'` para una revisión rápida.
    - Permite al admin aprobar solicitudes de edición de reportes hechas por los agentes.

### planilla_agentes.php
- **Propósito**: Muestra una planilla maestra de sueldos y comisiones para todos los agentes, siguiendo un formato similar al modelo CD08 de SUNAT.
- **Botones/Acciones/Formularios**:
    - Selector de mes para filtrar la planilla.
    - Botón `Exportar Excel`: Genera un archivo `.xls` con la planilla del mes seleccionado.
    - Celdas editables: Permite al admin modificar campos como `dias_trabajados`, `comision_jefe`, `retencion_uniforme`, etc. Cada cambio se guarda automáticamente vía AJAX a `ajax_planilla.php`.
    - Botón `Restaurar comisiones a valores auto-calculados` (🔄): Revierte las comisiones a los valores calculados por el sistema si fueron modificados manualmente.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - **Cálculos automáticos**:
        - **Comisiones**: Calcula automáticamente las comisiones por planes, equipos y servicios online, usando la misma lógica que `ver_agente.php`.
        - **Asistencia**: Calcula automáticamente los descuentos por tardanzas y faltas basándose en los registros de `asistencias`.
    - **Sobrescritura manual (Override)**: Si un admin edita un campo de comisión, se activa una bandera (`override_comisiones`) que le dice al sistema que use ese valor manual en lugar del automático para ese agente y mes.
    - **Fórmulas de Planilla**: Calcula dinámicamente el `Sueldo por Días Laborados`, `Total Remuneración`, `Total Descuentos` y `Total a Pagar` según las reglas del modelo CD08.

### recalcular_comisiones_masivo.php
- **Propósito**: Endpoint AJAX para recalcular masivamente las comisiones de servicios operativos o planes móviles en reportes históricos.
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - Invocado desde los modales de "Recálculo Masivo" en `comisiones_empresa.php`.
    - Parámetros POST: `fecha_desde`, `fecha_hasta`, y las nuevas tarifas. Un flag `solo_planes` distingue si se deben recalcular solo planes móviles o también servicios operativos.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Es una herramienta de corrección retroactiva; actualiza los montos de ganancia en `reporte_categorias` para un rango de fechas específico.
    - **Servicios Operativos**: Aplica la nueva tarifa (porcentaje o por rango) a las ventas de recargas, Bipay, etc.
    - **Planes Móviles**: Itera sobre los `detalle` JSON, identifica el plan y recalcula la `comision_generada` de cada línea usando las tarifas actuales de `comisiones_planes`, y luego actualiza el `monto` total de la categoría.

### recalcular_ganancias.php
- **Propósito**: Endpoint AJAX para recalcular retroactivamente la ganancia de un producto en todas sus ventas pasadas, después de que se ha fijado su precio de costo.
- **Botones/Acciones/Formularios**: (Endpoint AJAX puro, sin UI)
    - Invocado desde `fijar_precio.php`.
    - Parámetros POST: `producto_id`, `nuevo_costo`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Busca todas las ventas históricas del producto (por IMEI o nombre) en `reporte_categorias`.
    - Para cada venta encontrada, actualiza el `detalle` JSON, recalculando el campo `ganancia` como `precio_venta_registrado - nuevo_costo`.
    - Añade campos de auditoría (`costo_recalculado`, `recalculado_en`) al JSON.

### reporte_bcp.php
- **Propósito**: Módulo para el registro y visualización de reportes de operaciones de Agentes BCP.
- **Botones/Acciones/Formularios**:
    - **Vista Tienda**:
        - Formulario de envío de reporte BCP. POST con `accion=enviar_bcp`.
        - Muestra un historial de los reportes enviados por ese usuario.
    - **Vista Admin**:
        - Formulario de filtros por fecha y tienda.
        - "Campanita" de alertas que muestra las tiendas que no alcanzaron la meta de 200 operaciones en el día.
        - Tabla con todos los reportes, agrupados por tienda y fecha, con subtotales diarios.
        - Botón `Eliminar` por cada reporte.
- **Lógica de negocio clave**:
    - El acceso está restringido a usuarios con el flag `tiene_bcp=1` o a administradores.
    - Las tiendas envían un reporte por cada turno (mañana/tarde), registrando la cantidad de operaciones y los montos que quedan en efectivo y tarjeta.
    - El admin puede ver un panorama global, con totales de efectivo y tarjeta para el período filtrado, y recibe alertas si una tienda no cumple la meta diaria de 200 operaciones.

### revisar_fotos_asistencia.php
- **Propósito**: Panel para que el administrador revise y valide las marcaciones de asistencia realizadas mediante foto.
- **Botones/Acciones/Formularios**:
    - Formulario de filtros por rango de fechas y estado (pendientes/aprobadas).
    - Botón `Aprobar`: Confirma la marcación, borra la foto de la base de datos para ahorrar espacio, y quita el flag `requiere_revision`.
    - Botón `Anular`: Elimina el registro de asistencia de la base de datos.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Muestra las marcaciones con `metodo_marcacion = 'FOTO'`.
    - Las fotos se guardan en formato Base64 en la columna `foto_marcacion`.
    - El flujo de aprobación/anulación se maneja vía AJAX, actualizando la UI sin recargar la página.
    - La política de "Zero-Retention" elimina la foto de la base de datos una vez aprobada para mantener la base de datos ligera.

### revisar_stock.php
- **Propósito**: Panel para que el administrador fije los precios de costo, mínimo y normal de los productos que aún no los tienen definidos.
- **Botones/Acciones/Formularios**:
    - Formulario de filtros por `tienda` y `tipo` de producto (Equipo/Accesorio).
    - Por cada producto listado, hay inputs para `Costo`, `P. Mínimo`, `P. Normal` y un botón `Fijar`.
    - Botón `Fijar`: Invoca vía AJAX a `fijar_precio.php` para guardar los precios y recalcular ganancias retroactivas.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Lista únicamente los productos en estado 'DISPONIBLE' cuyo `precio_costo`, `precio_minimo` o `precio_normal` es cero o nulo.
    - La vista está agrupada por tienda y tipo de producto para facilitar la gestión.
    - Al fijar un precio, la fila del producto se desvanece y elimina de la lista de pendientes.

### tickets_emitidos.php
- **Propósito**: Historial de todos los tickets de venta genéricos emitidos, con opciones de filtrado y reimpresión.
- **Botones/Acciones/Formularios**:
    - Formulario de filtros avanzado: por tienda, agente, fecha, N° ticket, cliente, DNI, forma de pago y descripción.
    - Botón `Exportar Excel`: Descarga el historial filtrado como un archivo `.xls`.
    - Botón `Re-impr.` por cada ticket: Abre una nueva ventana con una recreación del ticket térmico, lista para imprimir.
    - Botón `Elim.` por cada ticket (solo admin): Elimina el registro del ticket vía AJAX a `api/eliminar_ticket.php`.
- **Lógica de negocio clave**:
    - `Admin` ve todos los tickets. `Tienda` solo ve los de su propia tienda.
    - La función de reimpresión genera dinámicamente el HTML del ticket con los datos del registro y un CSS optimizado para impresoras térmicas de 58mm u 80mm (según la configuración del usuario).
    - Permite buscar por múltiples criterios para encontrar tickets específicos.

### tiendas.php
- **Propósito**: Panel para que el administrador gestione las sedes o tiendas de la empresa.
- **Botones/Acciones/Formularios**:
    - Formulario `Nueva Sede`: Permite registrar una nueva tienda con su código, ubicación y coordenadas GPS opcionales. Envía un `POST` a la misma página.
    - Botón `Configurar Sede`: Link a `editar_tienda.php` para modificar una tienda existente.
    - Botón `Eliminar Sede`: Link a `eliminar_tienda.php` para borrar una tienda.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - Al registrar una nueva tienda, verifica que el `codigo` no esté duplicado.
    - Muestra un listado de todas las tiendas registradas, indicando si tienen GPS configurado.

### usuarios.php
- **Propósito**: Panel para que el administrador gestione los usuarios del sistema (cajeros, otros admins).
- **Botones/Acciones/Formularios**:
    - Formulario `Registrar Nuevo Agente` (Usuario): Envía un `POST` a `guardar_usuario.php`.
    - Botón `Editar` por cada usuario: Abre un modal para cambiar la contraseña, el permiso de BCP, la cuenta Bipay asignada y el formato de ticket. El guardado se hace vía AJAX a `editar_usuario_ajax.php`.
    - Botón `Eliminar`: Link a `eliminar_usuario.php`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - El formulario de creación permite asignar un rol (`admin` o `tienda`), una tienda, y si el usuario tendrá acceso al módulo BCP.
    - Si a un usuario de rol `tienda` se le asigna una cuenta Bipay, se actualiza la tabla `tiendas` para vincular esa tienda con la cuenta.
    - El modal de edición permite resetear contraseñas y ajustar permisos de forma granular sin recargar la página.

### ver_agente.php
- **Propósito**: Muestra un perfil completo y un resumen de liquidación para un agente específico en un rango de fechas.
- **Botones/Acciones/Formularios**:
    - Formulario de filtro de fechas.
    - Botones de acción: `Volver`, `Editar Ficha`, `Certificado`, `Dispositivo`, `Historial`.
    - Formulario `Registrar Adelanto`: Permite registrar un adelanto de sueldo para el agente.
    - Botón `NUEVA BOLETA`: Abre un modal para confirmar y generar la boleta de pago, que envía un `POST` a `imprimir_boleta.php`.
- **Lógica de negocio clave**:
    - Requiere rol de `admin`.
    - **Cálculo de Liquidación**:
        - **Comisiones**: Calcula las comisiones por planes y equipos basándose en rangos de productividad mensual.
        - **Descuentos**: Calcula descuentos por tardanzas, faltas y deudas de horas basándose en los registros de `asistencias`.
        - **Bonos**: Suma las comisiones y las horas extra aprobadas.
        - **Adelantos**: Resta los adelantos registrados en el período.
    - **Ficha de Datos**: Muestra una vista completa de los datos personales y laborales del agente, combinando información de las tablas `agentes` y `postulantes_temp`.
    - Permite generar boletas de pago, registrar adelantos y ver el historial de liquidaciones y adelantos para el período seleccionado.

### ver_bitacora_stock.php
- **Propósito**: Vista de auditoría detallada de todos los movimientos de inventario (ingresos iniciales, ajustes, correcciones, traslados, ventas).
- **Botones/Acciones/Formularios**:
    - Formulario de filtros: por rango de fechas, tienda, tipo de acción, agente y categoría de producto.
    - Botón `Exportar Excel`: Genera un reporte `.xls` muy detallado con todos los movimientos agrupados por tipo.
- **Lógica de negocio clave**:
    - El `admin` ve todo. El rol `tienda` solo ve los movimientos de su propia tienda.
    - **Agregación de Datos**: Combina datos de múltiples tablas para dar una visión unificada:
        - `historial_inventario` (ajustes manuales).
        - `inventario_tiendas` (registros de ingreso inicial).
        - `reporte_categorias` (salidas por venta).
        - `traslados_stock` y `traslados_chips` (movimientos entre tiendas).
    - Muestra KPIs de resumen (total movimientos, entradas, salidas, balance neto).
    - La vista principal y la exportación a Excel separan los movimientos en secciones claras para facilitar el análisis.