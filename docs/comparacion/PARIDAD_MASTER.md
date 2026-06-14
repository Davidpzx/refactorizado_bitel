# Reporte Maestro de Paridad — Legacy PHP → Sistema Nuevo (SIS-KYRO)

**Fecha:** 2026-06-14
**Método:** Multi-agente. Gemini inventarió el legacy (gerencia/ + tienda/ + reportes/ + api/ + cron/ + auth/, 131 PHP). Claude inventarió el nuevo (43 páginas React + 124 rutas Laravel) y cruzó ambos verificando contra el código real de los controllers. Codex no ejecutó (MCP no arrancó modo agéntico).

**Fuentes** (en este mismo directorio):
- `legacy_gerencia_gemini.md` (52 archivos)
- `legacy_tienda_reportes_gemini.md` (40 archivos)
- `legacy_api_cron_auth_gemini.md` (21 archivos)

Leyenda: ✅ paridad · ⚠️ parcial (existe pero le falta lógica/acciones) · ❌ falta · 🔁 N/A (utilidad one-off legacy)

---

## Resumen ejecutivo

La paridad es **alta** (~90%). El grueso de módulos (reportes, inventario, traslados, chips, asistencias-terminal, agentes, usuarios, tiendas, comisiones, planilla, bipay, BCP, financieras, CRM, postulantes, diagnóstico, dashboard, estadísticas) está migrado con equivalente página+endpoint.

**Gaps confirmados por inspección de código (accionables):**
1. ❌ **Eliminar ticket** — `TicketController` no tiene `destroy`; legacy `api/eliminar_ticket.php` sí. Falta `DELETE tickets/{id}`.
2. ⚠️ **Acciones granulares del panel de asistencias admin** — el legacy `gerencia/acciones_asistencia.php` maneja 6 acciones; el nuevo `editar` (PATCH asistencias/{id}) solo cubre horas (ingreso/salida/refrigerio) + omitió-refrigerio + observación. **Faltan**: registrar excepción (FALTA_INJUSTIFICADA / PERMISO / PERDONAR vía `estado`), aprobar horas extras (`horas_extras`), asignar minutos de refrigerio (`minutos_refrigerio_asignado`), y eliminar registro de asistencia.
3. ⚠️ **Subfiltros dinámicos del ranking** — `EstadisticasController` tiene `rankingAgentes` pero no hay equivalente a `api/obtener_subfiltros_ranking.php` (poblar dinámicamente el `<select>` de planes/tipos reales del período).
4. ⚠️ **Registro manual de asistencia completo** — el nuevo `registrar` crea ENTRADA/SALIDA básica; el legacy `crear_manual` permite día pasado completo con refrigerio (inicio/fin) y `motivo_manual` obligatorio.

**Gaps menores / a confirmar:** ver tablas por módulo (marcas ⚠️).

---

## 1. Autenticación
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| auth/login.php | POST v1/auth/login (Sanctum) | ✅ | Sesión MySQL legacy → token Sanctum |
| auth/logout.php | POST v1/auth/logout | ✅ | |
| (PIN mi_historial) | POST v1/auth/verify-pin | ✅ | |
| Reactivar agentes permiso vencido al login | scheduler `bitel:auto-retorno` | ✅ | Movido a cron |

## 2. Dashboard / Gerencia
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| panel_gerencia.php | DashboardPage + dashboard/kpis, dashboard/anomalias | ✅ | |
| (notificaciones sistema) | control-center + marcar-notificacion | ✅ | |
| historial_completo.php | HistorialPage + historial, historial/exportar | ✅ | |
| diagnostico_tiendas.php | DiagnosticoPage + diagnostico | ✅ | |
| autorizar_edicion.php | reportes/{id}/aprobar-edicion | ✅ | |
| marcar_entregado.php | reportes/{id}/destino-efectivo | ⚠️ | Verificar que "marcar entregado" mapee al cambio de destino |

## 3. Reportes / Cuadre diario
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| nuevo_reporte.php + procesar_reporte.php | NuevoReportePage + POST reportes | ✅ | Borrador cloud/local, modo dios admin, descuento stock |
| editar_reporte.php + procesar_edicion.php | EditarReportePage + reportes update / reprocesar | ✅ | Auditoría edicion_critica (cambio vendedor) — verificar |
| ajax_guardar_borrador.php | reportes/borrador (GET/POST/DELETE) | ✅ | |
| solicitar_edicion.php | reportes/{id}/solicitar-edicion | ✅ | |
| aprobar_edicion.php | reportes/{id}/aprobar-edicion | ✅ | |
| ver_reporte.php | ReporteDetallePage + reportes/{id} | ✅ | |
| imprimir_reporte.php | constancias/reporte/{id} (PDF) | ✅ | |
| mi_historial.php | MiHistorialPage | ✅ | Salvavidas, panel jefe de tienda |
| ajax_salvavidas.php | asistencias/salvavidas | ✅ | |
| eliminar_reporte.php | DELETE reportes/{id} | ✅ | |
| ajax_guardar_ticket.php / _ingreso.php | tickets (store/update) | ✅ | Pago mixto + vuelto |
| imprimir_ticket_ingreso.php | — | ⚠️ | Verificar vista de impresión térmica del ticket de ingreso |
| migrar_formato_ticket.php | — | 🔁 | Migración one-off, N/A |

## 4. Inventario / Stock
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| ver_inventario.php | InventarioPage + inventario (apiResource) | ✅ | |
| matriz_inventario.php + descargar_matriz_excel.php | MatrizInventarioPage + inventario/matriz + inventario/exportar | ✅ | |
| ajax_kardex_inventario.php + exportar_kardex | KardexInventarioPage + inventario/kardex + exportar-kardex | ✅ | |
| guardar_stock.php / registrar_stock.php | POST inventario | ⚠️ | Verificar alta multi-IMEI (equipos) y series_info JSON (chips) |
| agregar_stock_rapido.php | (InventarioForm / update) | ⚠️ | Verificar UPSERT de bolsillo de chips |
| actualizar_precio_rapido.php / fijar_precio_agente.php | inventario update | ✅ | precio_minimo validado |
| api_inventario.php (editar/eliminar) | inventario update / destroy | ✅ | |
| procesar_correccion_stock.php / admin_ajuste_inventario.php | bitacora-stock/corregir | ✅ | Kardex antes/después |
| ver_bitacora_stock.php | BitacoraStockPage + bitacora-stock + /kpis | ✅ | |
| api/ajax_stock_estancado.php | inventario/stock-estancado | ✅ | |
| api/ajax_campana_admin.php / fijar_precio.php / ajax_fijar_costo_rapido.php | inventario/campana-costos + reporte-categorias/{id}/fijar-costo | ✅ | |
| revisar_stock.php | RevisarStockPage + inventario/precios-pendientes | ✅ | |
| recalcular_ganancias.php | inventario/{id}/recalcular-ganancias | ✅ | |
| api/restaurar_equipo_manual.php | inventario/{id}/restaurar | ✅ | |
| exportar_inventario_excel.php | inventario/exportar | ✅ | |

## 5. Traslados (equipos y chips)
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| procesar_traslado.php | POST traslados | ✅ | PENDIENTE vs PENDIENTE_APROBACION por rol |
| confirmar_traslado_equipo.php / confirmar_lote_equipo.php | traslados/{id}/confirmar | ✅ | Verificar confirmación por lote |
| gestionar_solicitud_traslado.php | traslados/{id}/gestionar + traslados/pendientes-aprobacion | ✅ | aprobar/rechazar/cancelar |
| procesar_traslado_chips.php | POST traslados-chips | ✅ | |
| confirmar_traslado_chips.php | traslados-chips/{id}/confirmar | ✅ | |
| constancia_traslado.php | constancias/traslado | ✅ | Individual y lote |

## 6. Chips
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| (gestión chips) | ChipsGestionPage + chips (index/store) | ✅ | |
| cambiar_codigo_chip.php | chips/{id}/cambiar-codigo | ✅ | |
| eliminar_chip.php | DELETE chips/{id} | ✅ | |
| obtener_historial_chip.php | chips/{id}/historial | ✅ | Kardex 4 fuentes |
| inventario chips (matriz) | inventario-chips | ✅ | |

## 7. Asistencias
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| api/registrar_marcacion.php (GPS) | POST attendance/mark | ✅ | Geocerca ponderada |
| api/registrar_asistencia_qr.php | POST attendance/mark-qr | ✅ | HMAC ±10s |
| api/registrar_marcacion_foto.php | POST attendance/mark-photo | ✅ | requiere_revision |
| api/obtener_estado_asistencia.php / verificar_asistencia_hoy.php | GET attendance/status/{dni} | ✅ | |
| api/generar_qr_asistencia.php + tienda/qr_asistencia.php | attendance/qr-stream/{tienda_id} + QrDisplayPage | ✅ | |
| api/autorizar_dispositivo.php | POST autorizar-dispositivo | ✅ | huella kyro-hw |
| panel_asistencias.php | AsistenciasPage + asistencias (index) | ✅ | |
| revisar_fotos_asistencia.php | RevisarFotosPage + asistencias/fotos-pendientes + photo-action | ✅ | |
| exportar_asistencias_excel.php | asistencias/exportar | ✅ | |
| **acciones_asistencia.php** (6 acciones) | asistencias/{id} editar + aprobar + registrar | ⚠️ | **Faltan**: registrar_excepcion (estado FALTA/PERMISO/PERDONAR), aprobar_extras (horas_extras), asignar_refrigerio (minutos), eliminar_registro |
| acciones_asistencia: crear_manual | POST asistencias (registrar) | ⚠️ | Nuevo solo ENTRADA/SALIDA básica; falta día pasado completo con refrigerio + motivo obligatorio |
| admin_editar_asistencia.php | PATCH asistencias/{id} (editar) | ✅ | Horas/refrigerio/observación |

## 8. Agentes / Personal
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| gestionar_agentes.php / guardar_agente / editar_agente / eliminar_agente | AgentesPage + agentes (apiResource) | ✅ | |
| ver_agente.php / historial_agente.php | VerAgentePage + agentes/{id} + /ventas + /comisiones | ✅ | |
| api/editar_fechas_laborales.php | agentes/{id}/fechas-laborales | ✅ | |
| ajax_seguridad.php / api/verificar_token_activo.php | agentes/{id}/token-seguridad | ✅ | |
| certificado_agente.php | constancias/agente/{id} | ✅ | |
| exportar_excel_agentes_pro.php | agentes/exportar-ficha + agentes/exportar | ✅ | Ficha multi-hoja |
| consulta_dni.php | dni/{dni} | ✅ | RENIEC |
| aprobar_postulante.php | postulaciones/{id}/aprobar (+ index/show/update/destroy) | ✅ | |

## 9. Planilla / Comisiones / Boletas
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| planilla_agentes.php + ajax_planilla.php | PlanillaPage + planilla/{mes} + /exportar + /ajuste | ✅ | |
| configurar_comisiones / guardar_rangos_ajax / guardar_tarifas_ajax | ComisionesPage + comisiones-planes (CRUD) | ✅ | |
| comisiones_empresa.php | ComisionesPage | ✅ | |
| recalcular_comisiones_masivo.php | comisiones-planes/recalcular | ✅ | |
| imprimir_boleta.php / accion_boleta.php | constancias/boleta/{id} + constancias/boleta (crear/accion) | ✅ | pagar/eliminar boleta |

## 10. Bipay / BCP / Financieras
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| panel_bipay.php | PanelBipayPage + bipay/saldo + transacciones | ✅ | |
| reportes/ajax_bipay_saldo.php (estado/actualizar/cierre) | bipay/cajero/estado + actualizar + cierre | ✅ | Saldo compartido + cooldowns |
| (recarga/transferir/ajustar) | bipay/recarga + transferir + ajustar | ✅ | Nuevas en el sistema nuevo |
| reporte_bcp.php | ReporteBcpPage + reporte-bcp + /tiendas | ✅ | |
| panel_financieras.php | PanelFinancierasPage + financieras | ✅ | |
| confirmar_desembolso.php | financieras/{id}/confirmar-desembolso + revertir | ✅ | |

## 11. Estadísticas / Ranking
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| estadisticas_ventas.php | EstadisticasPage + estadisticas/ventas + productividad | ✅ | |
| api/obtener_ranking_agentes.php | estadisticas/ranking | ✅ | |
| **api/obtener_subfiltros_ranking.php** | — | ⚠️ | Falta endpoint de subfiltros dinámicos (planes/tipos reales del período) |

## 12. Configuración / Tiendas / Usuarios / Tickets / Clientes
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| configuracion_empresa.php | ConfiguracionPage + configuracion (show/update/logo) | ✅ | |
| tiendas.php / editar_tienda.php | TiendasPage + tiendas (apiResource) | ✅ | |
| usuarios.php / guardar_usuario / editar_usuario_ajax / eliminar_usuario | UsuariosPage + usuarios (apiResource) | ✅ | |
| tickets_emitidos.php | TicketsPage + tickets (index/show/store/update) | ✅ | |
| **api/eliminar_ticket.php** | — | ❌ | **Falta `DELETE tickets/{id}`** (TicketController sin destroy) |
| (clientes) | ClientesPage + clientes (apiResource) | ✅ | |
| (comprobantes/SUNAT) | ComprobantesPage + comprobantes + reenviar | ✅ | Nuevo (motor SUNAT) |
| (CRM) | CrmPage + crm/pipeline + leads | ✅ | Nuevo |
| api/obtener_notificaciones.php / marcar_notificacion.php | control-center + marcar-notificacion | ✅ | |

## 13. Cron / Jobs
| Legacy | Nuevo | Estado | Notas |
|---|---|---|---|
| cron/auto_retorno.php | scheduler bitel:auto-retorno (00:05) | ✅ | |
| cron/cron_salida_automatica.php | scheduler bitel:salida-automatica (23:00) | ✅ | |
| cron/limpiar_fotos_asistencia.php | scheduler bitel:limpiar-fotos (dom 02:15) | ✅ | Legacy ya obsoleto (fotos en BD) |

---

## Acciones recomendadas (orden de prioridad)

1. **Asistencias admin (⚠️ alto impacto operativo):** extender `editar`/agregar acciones para: estado de excepción (falta/permiso/perdonar), horas extras, minutos de refrigerio asignado, y eliminar registro. Y completar `registrar` (crear manual de día pasado con refrigerio + motivo obligatorio).
2. **Eliminar ticket (❌):** agregar `DELETE tickets/{id}` (admin) + `destroy` en TicketController + botón en TicketsPage.
3. **Subfiltros dinámicos de ranking (⚠️):** agregar `estadisticas/ranking/subfiltros` (o ampliar el endpoint) para poblar planes/tipos reales del período.
4. **Verificaciones puntuales (⚠️):** impresión de ticket de ingreso; alta multi-IMEI + series_info de chips en POST inventario; confirmación de traslado por lote; mapeo de "marcar entregado".

---

## Estado de implementación (2026-06-14)

**Resuelto en esta pasada:**
- ✅ **Asistencias admin granular** — `AsistenciaController@editar` ahora persiste `estado_asistencia` (falta/permiso/perdonar), `horas_extras`, `minutos_refrigerio_asignado` y `minutos_tardanza` (cada uno guardado por `Schema::hasColumn` por el drift legacy). Nuevo `eliminar` + `DELETE asistencias/{id}`.
- ✅ **Registro manual completo** — `AsistenciaController@registrar` admite `inicio_refrigerio`/`fin_refrigerio` (guardados por columna) + `motivo` y `hora_salida` en el alta.
- ✅ **Eliminar ticket** — `TicketController@destroy` (admin) + `DELETE tickets/{id}`.
- ✅ **Bug latente de tickets** — `TicketController@update` ahora solo escribe campos presentes (un PATCH parcial ya no borra nombre/pagos) y persiste `estado`, lo que hace funcional el botón "Anular" del front (antes era no-op).
- ✅ **Marcar entregado con auditoría** — `ReporteController@actualizarDestino` acepta `observacion` y registra el movimiento en `historial_reportes` (paridad `marcar_entregado.php`).

**Pendiente (menor ROI / mayor alcance, documentado):**
- ⚠️ **Impresión ticket de ingreso** — VERIFICADO que SÍ existe (`/tickets/imprimir/:id` → `TicketImpresionPage`). No era gap.
- ⚠️ **Subfiltros dinámicos de ranking** — el ranking nuevo (`estadisticas/productividad`) es de diseño más simple, sin filtros de categoría/subcategoría; implementarlos es una feature de UI completa, no solo un endpoint. Diferido.
- ⚠️ **Confirmar traslado por lote** — `confirmar(id)` es individual; el front puede iterar. Batch atómico diferido.
- ⚠️ **Alta multi-IMEI de equipos** — `inventario@store` crea 1 ítem por llamada; el front puede iterar IMEIs. Bulk diferido.
- ⚠️ **`series_info` de chips** — `chips@store` hace UPSERT + historial pero no guarda rangos de series (metadato informativo). Diferido.
