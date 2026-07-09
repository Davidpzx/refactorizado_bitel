# TICKET-018 — (OPCIONAL — requiere aprobación DECISIÓN-002) Migración a `@phosphor-icons/react`

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, frontend-design
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada — migración TODO o nada (mezclar dos librerías de iconos es peor que cualquiera de las dos solas). Si el presupuesto no alcanza para ~45 archivos, pedir subdivisión por carpetas ANTES de empezar.
- **Repo:** refactor `C:\xampp\htdocs\refactorizado_bitel` (frontend)
- **Depende de:** TICKET-017 (mapeos ya corregidos) + confirmación explícita del usuario.

## Contexto
El legacy usa Phosphor Icons con **sistema de pesos** (regular/fill/bold) como lenguaje de estado: campana `ph ph-bell` sin notifs → `ph-fill ph-bell` dorada con notifs. lucide (actual) tiene un solo peso; el refactor compensa solo con color/badge. `@phosphor-icons/react` es paquete oficial con los mismos nombres del legacy y prop `weight="fill|bold|regular"` — port natural para paridad de textura total. ~50 iconos distintos en ~45 archivos.

## Alcance
1. Instalar `@phosphor-icons/react`; crear mapa lucide→phosphor (mayoría 1:1: Trash2→Trash, Pencil→PencilSimple, etc.).
2. Migrar TODOS los imports/usos; restaurar los pesos semánticos del legacy donde comunican estado (campana fill con notifs + `bell-shake`, candado fill en PIN, siren fill en anomalías).
3. Desinstalar `lucide-react`; verificar build + tamaño de bundle (tree-shaking de Phosphor funciona con imports nombrados).
4. Revisión visual de las pantallas principales (sidebar, dashboard, tablas) — el trazo Phosphor es levemente distinto; ajustar `size`/`weight` donde se vea desbalanceado.

## Criterio de aceptación
`lucide-react` fuera del package.json; build limpio; pesos fill/bold aplicados donde el legacy los usa; capturas antes/después de sidebar + dashboard en el PR.
