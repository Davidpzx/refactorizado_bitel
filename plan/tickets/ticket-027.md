# TICKET-027 — QA funcional end-to-end de flujos críticos

- **Modelo asignado:** **Opus 4.8**
- **Skills obligatorias:** headroom, superpowers, agentbrowser (para los flujos vía UI)
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada: los 6 flujos listados, no una muestra. Si el presupuesto no alcanza, pedir división ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas` (oráculo de comportamiento esperado)
- **Ejecutar DESPUÉS de:** Fases 1–4 completas (todos los tickets 001–025 cerrados o descartados por decisión).

## Contexto
Cierre del plan de paridad: validar que los flujos de negocio críticos se comportan como el legacy (mismas reglas, mismos efectos en BD), con datos de prueba realistas.

## Flujos a validar (cada uno end-to-end, por UI cuando sea posible)
1. **Cuadre diario completo:** borrador autoguardado → venta con equipo (descuento de stock IMEI) + chip + otros_flujo → destino efectivo EN TIENDA vs LO ENTREGUÉ → guardado transaccional → verificar stock descontado y `observaciones` correcta según destino.
2. **Edición aprobada:** solicitar edición → aprobar (admin) → editar revirtiendo inventario y re-aplicando → auditoría en historial.
3. **Traslado con estados:** solicitud → aprobación → confirmación en destino; rechazo y cancelación; constancia PDF.
4. **Facturación SUNAT en beta:** encolar boleta desde una venta → cron drena → estado ACEPTADO (API beta/fake) → link público HMAC abre sin sesión → NC sobre el comprobante → descarga PDF/XML.
5. **Comisiones:** venta CUOTAS retiene comisión → confirmar desembolso la libera; recálculo por rango de productividad mensual coincide con el legacy para el mismo dataset.
6. **Asistencia:** marcación GPS dentro/fuera de geocerca, QR válido/expirado, excepción PERMISO genera deuda 540 min, salida automática nocturna (comando).

## Alcance
- Ejecutar cada flujo, comparar contra el comportamiento del legacy (mismo dataset sembrado en ambos si es viable, o contra las reglas documentadas en `00-inventario-legacy.md` §4).
- Registrar resultados en `plan/05-qa-funcional.md`: flujo, pasos, esperado vs obtenido, veredicto, bugs encontrados (cada bug con archivo:línea sospechosa si se identifica).
- Los bugs NO se corrigen aquí: se redactan como tickets nuevos en la cola.

## Criterio de aceptación
Los 6 flujos con veredicto y evidencia (salidas de BD/capturas); todo bug convertido en ticket bien formado; `plan/05-qa-funcional.md` completo.
