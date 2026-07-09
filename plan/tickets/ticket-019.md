# TICKET-019 — Modo claro: sidebar azul corporativo Bitel + acentos dorados

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **frontend-design**
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada.
- **Repo:** refactor `C:\xampp\htdocs\refactorizado_bitel` (`frontend/src/index.css`, `AppLayout.tsx`)

## Contexto
En modo claro, el legacy pinta el **sidebar azul corporativo Bitel `rgba(0,53,128,0.95)` con texto blanco** y tabs/links activos dorados `#ffc200` sobre fondo `#f0f4f8` — es EL rasgo corporativo del tema claro. El refactor usa sidebar blanco glass (correcto pero sin identidad). El resto del modo claro del refactor (`premium-surface`, glass claro) es una mejora direccional válida y se mantiene.

## Alcance
1. En modo claro (`html:not(.dark)` o equivalente del proyecto): sidebar con fondo `rgba(0,53,128,0.95)` + blur, texto/iconos blancos, link activo con borde izquierdo 3px dorado + texto dorado, secciones del menú con el mismo tratamiento dorado del dark, tarjeta de usuario y badges legibles sobre azul.
2. Mantener el resto del tema claro como está (cards, tablas, fondos) — solo el sidebar y sus acentos cambian.
3. Verificar contraste AA de texto blanco/dorado sobre el azul (`#003580`) y estados hover.
4. Probar el toggle luna/sol en vivo: sin flashes ni estados intermedios rotos.

## Criterio de aceptación
Capturas del sidebar claro vs la referencia legacy (capturas FireShot con tema claro si existen, o el CSS de `estilos.css` como fuente); contraste verificado; dark mode intacto.
