# TICKET-010 — ComprobantesPage: paridad total con el legacy

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, frontend-design
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si el presupuesto no alcanza, pedir subdivisión ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Depende de:** TICKET-005, 007, 008.

## Contexto
`gerencia/comprobantes_emitidos.php` del legacy muestra el historial de comprobantes SUNAT con: estado de cola (pendiente/error/emitido con intentos), descarga PDF/XML/CDR, emitir NC, anular, reintento, y link público para WhatsApp. La `ComprobantesPage` del refactor existe con "reenviar" pero le faltan las acciones nuevas y los estados de cola ricos.

## Alcance
1. Ampliar `ComprobantesPage`: columna de estado de cola con badges glass semánticos (PENDIENTE ámbar, ACEPTADO verde, RECHAZADO/ERROR rojo con nº de intentos, ANULADO gris), filtros por tienda/estado/fecha/tipo.
2. Acciones por fila: descargar PDF/XML/CDR, emitir NC (modal con motivo), anular, reintentar ahora, copiar link WhatsApp (ticket 008). Todas con ConfirmDialog kyro (ticket 016) para las destructivas.
3. Detalle expandible o modal con el historial de intentos/errores de la cola (transparencia para el gerente).
4. Actualizar `services/*.api.ts` + hooks.

## Diseño
Tabla con el patrón legacy: thead uppercase pequeño con acentos neón, precios en amarillo, estados como badge-glass. Iconos semánticos (FileDown para PDF, FileCode XML, RotateCcw reintentar, MessageCircle WhatsApp) — nada genérico ni duplicado.

## Criterio de aceptación
Todas las acciones del legacy disponibles y funcionando contra backend local; estados de cola visibles con su intento; cero `confirm()` nativos; revisión visual coherente con la identidad kyro.
