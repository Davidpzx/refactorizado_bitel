# TICKET-026 — QA visual: verificación en vivo pantalla por pantalla (agentbrowser)

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **agentbrowser** (obligatoria — es la esencia del ticket), frontend-design (criterio de evaluación)
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada: TODAS las pantallas de la tabla §3 del inventario de diseño, no una muestra. Si el presupuesto no alcanza, pedir división por bloques de pantallas ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` (levantar backend + Vite) · legacy `E:\laragon\www\sistema-rolando-salas` (levantar en Laragon)
- **Referencias:** `plan/00-inventario-diseno.md` §3 (tabla pantalla por pantalla) + las 33 capturas FireShot en `C:\xampp\htdocs\refactor_principal\legacy\*.png` (29 aún no revisadas una a una).
- **Ejecutar DESPUÉS de:** tickets de Fase 3 (016, 017, 019–024) cerrados.

## Contexto
El inventario de diseño se hizo 100% por código + 4 capturas (los servidores estaban caídos); toda la columna "Parcial\*" quedó sin validar pixel-a-pixel. Este ticket cierra ese hueco: comparación en vivo, pantalla por pantalla, botón por botón — paridad real de diseño y de funciones, no solo de código.

## Alcance
1. Levantar AMBOS sistemas en local (legacy: Laragon; refactor: `php artisan serve` + `npm run dev`) con datos de prueba equivalentes.
2. Con agentbrowser, recorrer cada fila de la tabla §3: capturar refactor, comparar contra la captura FireShot y/o el legacy vivo, y reclasificar fidelidad (fiel / mejorada / degradada / genérica / faltante).
3. Revisar también comportamiento: botones que faltan, flujos que difieren, estados vacíos, contadores vivos.
4. Producir `plan/04-qa-visual.md`: tabla con veredicto por pantalla + captura + lista puntual de desviaciones (archivo/componente sugerido por fix).
5. Los fixes NO se hacen aquí: cada desviación se convierte en entrada de una lista priorizada (las triviales agrupadas en un ticket "polish" único que este QA redacta al final siguiendo el formato de la cola).

## Criterio de aceptación
`plan/04-qa-visual.md` con veredicto para el 100% de las pantallas de la tabla §3; cero pantallas sin captura del refactor; ticket "polish" redactado si hay desviaciones menores; ninguna pantalla queda clasificada "degradada" o "genérica" sin ticket de fix asociado.
