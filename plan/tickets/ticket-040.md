# TICKET-040 — Shell de la app: toggle de tema, notificaciones duplicadas, sidebar sobrecargado

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **frontend-design**
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` (frontend/) · legacy `E:\laragon\www\sistema-rolando-salas` (`includes/header.php` + cualquier página para ver el shell en vivo)
- **Origen:** feedback directo del usuario viendo producción (app.kyrocodelabs.cloud, 2026-07-09) — su veredicto manda sobre el QA en papel.

## Quejas literales del usuario
1. "el botón para cambiar a modo claro o oscuro está mal" — el toggle luna/sol dentro de la tarjeta de usuario del sidebar es poco visible/confuso.
2. "las notificaciones si ya está arriba porque sale abajo también, no tiene caso" — hay un badge/campana de Anomalías arriba a la derecha Y un botón "Notificaciones (20)" al fondo del sidebar: duplicado.
3. "el sidebar tiene un montón de pestañas" — la lista es plana y larguísima (CRM, Precios, Historial, Clientes, Tiendas, Usuarios, Personal, Asistencias, Planilla, Tickets, Comisiones, Financieras, ... ~20 entradas), difícil de escanear.

## Alcance
1. **Toggle de tema:** revisar cómo lo presenta el legacy y moverlo/rediseñarlo a un lugar obvio y estándar (ej. icono sol/luna en la cabecera superior junto a las acciones globales), con affordance clara del estado actual. Un solo lugar, no dos.
2. **Notificaciones:** UNA sola entrada. Decidir con criterio: mantener la campana del header (patrón estándar) y eliminar el botón inferior del sidebar, migrando su contador. El panel "Centro de Control" se abre desde esa única campana.
3. **Sidebar:** agrupar las entradas en secciones colapsables con encabezado (el legacy agrupa con headers de sección — replicar su agrupación: ver `includes/header.php` del legacy que ya tiene grupos como GERENCIA/ADMINISTRACIÓN/REPORTES etc.). Respetar roles (admin ve todo, tienda menos). Recordar estado colapsado en localStorage. Mantener los accents existentes (CRM púrpura, etc.).

## Criterio de aceptación
Captura del shell resultante comparada contra el legacy; una sola entrada de notificaciones; toggle de tema visible y obvio; sidebar agrupado y escaneable; `tsc`+`vite build` limpios; navegación intacta (todas las rutas siguen accesibles).
