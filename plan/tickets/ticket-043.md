# TICKET-043 — Arquitectura de navegación: consolidar rutas explotadas en páginas con pestañas (paridad de menú 1:1 con el legacy)

- **Modelo asignado:** Sonnet 5 (razonamiento alto)
- **Skills obligatorias:** headroom, superpowers, **frontend-design**
- **Regla 0.3:** si el mapeo revela demasiadas consolidaciones para una pasada, DETENERSE tras producir el mapeo y proponer división.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` (frontend/) · legacy `E:\laragon\www\sistema-rolando-salas` (`includes/header.php` = fuente de verdad del menú)
- **Origen:** feedback del usuario (2026-07-09): "no solo lo visual — las funcionalidades de las pestañas: lo separó en un montón de pestañas y no como en el legacy; el legacy es más intuitivo". El problema es la ARQUITECTURA DE INFORMACIÓN, no solo el estilo.

## Principio
El sidebar del refactor debe tener **exactamente las mismas entradas que el menú del legacy** (mismos nombres, mismo orden, mismos grupos). Todo lo que el refactor separó en rutas propias que en el legacy vive DENTRO de una página, se consolida como **pestañas internas** de su página madre (patrón `PageTabs`/`AsistenciasTabs` ya existente, ticket-024). Menos entradas, más profundidad — como el legacy.

## Alcance
1. **Mapeo primero** (entregarlo aunque se pida división): tabla `entrada del menú legacy (header.php)` ↔ `ruta(s) del refactor (NAV_ITEMS de AppLayout)`. Detectar: (a) entradas del refactor que NO existen como entrada en el legacy → candidatas a consolidarse como tab de otra página o quitarse del menú; (b) entradas del legacy sin equivalente en el menú del refactor → faltantes.
2. **Consolidar**: por cada grupo de rutas del refactor que en el legacy es UNA página con pestañas internas, montar la página madre con `PageTabs` (las rutas viejas siguen funcionando como deep-links — redirigen o renderizan la página madre con la tab activa). Ejemplos esperados (verificar contra el mapeo, no asumir): Inventario/Matriz/Bitácora/Kardex; CRM y sus vistas; Comprobantes/Facturación; Estadísticas/Productividad/Mapa de Calor; Chips/Diagnóstico.
3. **El sidebar final** queda con la lista corta del legacy. Los accents por sección se conservan.
4. NO romper permisos por rol (admin/tienda) ni ninguna ruta existente (deep-links intactos).

## Criterio de aceptación
Tabla de mapeo en `plan/07-mapa-navegacion.md`; sidebar con el mismo número y nombres de entradas que el legacy (por rol); toda funcionalidad previa accesible vía tabs; `tsc`+`build` limpios; navegación verificada.
