# Inventario legacy: api/ + cron/ + auth/ (Gemini)

## api/
- **autorizar_dispositivo.php** — POST. Valida/registra huella `kyro-hw-` de dispositivo por agente. status ok/require_pin/error. DNI embebido en hash; primer dispositivo auto-registra; cambio requiere PIN; log_fraude_dispositivo. Tablas: agentes, log_fraude_dispositivo.
- **editar_fechas_laborales.php** — POST (admin). agente_id + fecha_ingreso/prueba_inicio/prueba_fin. Idempotente (añade columnas). Tabla: agentes.
- **eliminar_ticket.php** — POST (admin). id ticket → DELETE tickets_emitidos.
- **generar_qr_asistencia.php** — GET. PNG de QR dinámico (cambia cada 5s). Token `AST|TIENDA|BLOQUE|HMAC` sha256 con QR_SECRET_KEY. admin puede pasar tienda_codigo. Cache no-store.
- **marcar_notificacion.php** — POST (admin). id + accion(leido/borrar) → sys_notificaciones.
- **obtener_estado_asistencia.php** — GET. dni+tienda_id → estado marcaciones del día + radio_permitido/accuracy GPS + siguiente marcación. Sin sesión (terminal). detectarTipoMarcacion.
- **obtener_notificaciones.php** — GET (admin). total + items pendientes de sys_notificaciones. Resiliente si tabla no existe.
- **obtener_ranking_agentes.php** — GET (admin/tienda). Ranking ventas por categoria(todo/equipos/postpago/chips)+subcategoria+fechas+tienda. Extrae vendedor_id de JSON detalle; excluye upgrade/remate/paquete; chips por tipo RECUPERO/NUEVO o plan::.
- **obtener_subfiltros_ranking.php** — GET. Genera opciones de subfiltro DINÁMICAS desde reporte_categorias del período (planes/tipos reales con conteo). Agrupa chips por Tipo y Plan.
- **registrar_asistencia_qr.php** — POST. Valida HMAC del QR (±10s); hora oficial de hora_intento_gps si <60s; anti-colisión; detectarTipoMarcacion; metodo='QR'.
- **registrar_marcacion.php** — POST. Marcación GPS unificada (4 tipos). Geocerca con precisión ponderada (distancia-accuracy<=radio_efectivo; radio más estricto si accuracy<20m). Anti-spoof velocidad >200km/h. Valida hash_dispositivo o token_emergencia. Calcula tardanza/deuda/extras. Si mala señal → qr_disponible:true.
- **registrar_marcacion_foto.php** — POST. Asistencia con foto Base64 (último recurso). requiere_revision=1, metodo='FOTO'. Redimensiona a 1024px/JPEG<150KB en LONGTEXT foto_marcacion. Auto-limpieza a 7 días.
- **restaurar_equipo_manual.php** — POST (admin). id → revierte VENDIDO→DISPONIBLE en inventario_tiendas; auditoría RESCATE_MANUAL.
- **verificar_asistencia_hoy.php** — GET. dni → next_tipo + horas marcadas. Sin sesión.
- **verificar_token_activo.php** — GET (admin). id agente → tiene_token + token/tipo/expiracion (diario/permanente por año 2099).

## auth/
- **login.php** — POST/GET. Valida email+password (password_verify) contra usuarios. MysqlSessionHandler (sys_sesiones). Cookies seguras. Reactiva agentes con permiso_largo vencido al loguear admin. Redirige a panel_gerencia.
- **logout.php** — Destruye sesión (sys_sesiones), limpia cookie.

## cron/
- **auto_retorno.php** — CLI diario 00:00. Reactiva agentes INACTIVO+permiso_largo+fecha_retorno<=hoy → ACTIVO. Auditoría historial_agentes.
- **cron_salida_automatica.php** — CLI diario ~23:00. Cierra turnos con ingreso sin salida → hora_salida programada o NOW; estado CIERRE_AUTO; notifica sys_notificaciones.
- **limpiar_fotos_asistencia.php** — CLI semanal. Borra fotos >7 días (obsoleto: ahora se guardan Base64 en BD, no archivos). Limpia foto_marcacion=NULL.
