# TICKET-023 — Precios (RevisarStock): agrupación por tienda + botón "Fijar" índigo

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **frontend-design**
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` (`RevisarStockPage`) · legacy `E:\laragon\www\sistema-rolando-salas` (`gerencia/revisar_stock.php`)
- **Referencia visual:** captura `C:\xampp\htdocs\refactor_principal\legacy\...007*.png`

## Contexto
La pantalla de precios pendientes del legacy (captura 007): tabla **agrupada por tienda** con divisores de sección (fondo `rgba(255,194,0,0.04)`, texto dorado uppercase), chips de tipo (PUNDA/ACCESORIO), botón **"Fijar" glass índigo por fila**, y badge contador rojo pulsante en el menú (el refactor ya tiene el badge vivo). Funcionalidad completa en el refactor (fijar costo, campana de costos, precios pendientes); es fidelidad visual.

## Alcance
1. Agrupar la tabla por tienda con los divisores dorados del legacy.
2. Chips de tipo de producto y botón "Fijar" índigo glass por fila (variant `glassIndigo`).
3. Precios en amarillo (patrón del sistema).
4. El icono del menú lo corrige el ticket 017 (coordinar, no duplicar).

## Criterio de aceptación
Comparación lado a lado contra la captura 007; agrupación por tienda visible con divisores dorados; flujo de fijar precio probado sin regresión.
