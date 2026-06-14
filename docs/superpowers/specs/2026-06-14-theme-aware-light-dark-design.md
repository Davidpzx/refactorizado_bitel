# Diseño: Tokens theme-aware + barrido visual claro/oscuro

**Fecha:** 2026-06-14
**Autor:** Claude (diseño) → Codex (implementación)
**Repo:** `refactorizado_bitel/frontend`

## Problema (causa raíz)

El design system se construyó **dark-first**. Conviven dos sistemas de color:

1. **Tokens `kyro-*`** (`bg-kyro-panel`, `text-kyro-text`, `.kyro-card`, `.kyro-input`,
   `.kyro-table-head`, `.kyro-glass`) → definidos en `src/index.css` con valores **fijos
   oscuros** (`--color-kyro-panel: #18181b`). **No cambian** según el tema.
2. **Clases Tailwind estándar** (`bg-white`, `text-gray-900`) → SÍ se invierten en oscuro
   vía overrides `html.dark .bg-white { ... }`.

Resultado: cualquier componente que use tokens `kyro-*` queda **oscuro en modo claro**.
Visible en Dashboard (tarjetas KPI negras sobre fondo claro), Estadísticas, Inventario,
Reportes, inputs y cabeceras de tabla.

## Decisiones del usuario

- **Estética modo claro:** Glassy premium (blanco translúcido + blur + gradiente superior +
  halo de color), reutilizando el lenguaje de `.premium-kpi`/`.premium-surface` que ya existe
  y es theme-aware.
- **Alcance:** Fundación de tokens + **barrido visual completo** de todas las páginas en
  ambos temas (no solo Dashboard).

## Solución

### 1. Fundación — `src/index.css`

Hacer los tokens `kyro-*` theme-aware: valores claros por defecto (`:root`) y oscuros bajo
`html.dark`. Como `bg-kyro-panel` compila a `var(--color-kyro-panel)`, sobreescribir la
variable por tema corrige cada componente sin tocar JSX.

**Paleta clara (`:root` override; los `@theme` defaults se quedan como fallback oscuro o se
mueven a `html.dark`):**

| Token | Claro | Oscuro (actual, se preserva) |
|-------|-------|------------------------------|
| `--color-kyro-base` | `#f8fafc` | `#09090b` |
| `--color-kyro-panel` | `rgba(255,255,255,0.78)` | `#18181b` |
| `--color-kyro-card` | `rgba(255,255,255,0.78)` | `#1c1c1f` |
| `--color-kyro-elevated` | `#ffffff` | `#27272a` |
| `--color-kyro-border` | `rgba(203,213,225,0.72)` | `rgba(255,255,255,0.08)` |
| `--color-kyro-border-input` | `rgba(203,213,225,0.9)` | `#3f3f46` |
| `--color-kyro-text` | `#0f172a` | `#f4f4f5` |
| `--color-kyro-body` | `#1e293b` | `#e4e4e7` |
| `--color-kyro-muted` | `#64748b` | `#a1a1aa` |
| `--color-kyro-subtle` | `#94a3b8` | `#52525b` |

Oro/indigo/semánticos/KPI **no cambian** (sirven en ambos temas).

**Implementación recomendada en Tailwind v4:** mantener `@theme` declarando los nombres de
token (para que existan las utilities). Definir los valores **claros** en `:root` y los
**oscuros** en `html.dark`. Ejemplo:

```css
:root {
  --color-kyro-panel: rgba(255, 255, 255, 0.78);
  --color-kyro-text:  #0f172a;
  /* ...resto claros... */
}
html.dark {
  --color-kyro-panel: #18181b;
  --color-kyro-text:  #f4f4f5;
  /* ...resto oscuros... */
}
```

### 2. Clases de componente con variante clara glassy + override oscuro

- **`.kyro-card`** → claro: `background: rgba(255,255,255,0.78)`; `border: 1px solid
  rgba(203,213,225,0.72)`; `backdrop-filter: blur(18px)`; `box-shadow: 0 18px 45px -32px
  rgba(15,23,42,0.55)`; añadir línea-gradiente superior (`::before` indigo→oro→transparent,
  como `.premium-surface`). Oscuro (`html.dark`): conservar `background:#18181b` + sombra
  actual.
- **`.kyro-input`** → claro: `background:#ffffff`; `color:#0f172a`; `border:1px solid
  rgba(203,213,225,0.9)`; placeholder slate-400. Oscuro: conservar actual (`#09090b`).
- **`.kyro-table-head th`** → claro: `background: rgba(248,250,252,0.82)`; `color:#64748b`;
  borde slate. Oscuro: conservar actual.
- **`.kyro-glass`** → claro: `background: rgba(255,255,255,0.75)` + blur + borde claro;
  oscuro: conservar actual.

### 3. Barrido por componentes

- **`src/pages/DashboardPage.tsx`**: `KpiCard` y `KpiCardDiferencia` usan `bg-kyro-panel`
  (sólido, sin blur). Migrar a `.premium-kpi` (glass theme-aware ya existente) conservando:
  borde-izquierdo de color (`border-l-4 border-l-kpi-*`), la línea-gradiente superior, el
  halo, y los `colorClass` de los valores. La tabla "Últimos Reportes" ya usa
  `bg-white/80 dark:bg-zinc-900/65` → ya es theme-aware, dejar.
- **Resto de páginas** (`EstadisticasPage`, `InventarioPage`, `ReportesPage`,
  `KardexInventarioPage`, `MatrizInventarioPage`, `ReporteBcpPage`, y cualquier página bajo
  `src/pages/**`): usan `.kyro-card` + tokens `kyro-*` → se corrigen con la fundación.
  **Revisar cada una** buscando colores **hardcodeados** que no tengan par claro/oscuro:
  - Textos tipo `text-slate-700`, `text-slate-500`, `text-blue-700`, `text-purple-700`,
    `text-orange-700`, `text-green-700` dentro de tablas/tarjetas → verificar contraste en
    AMBOS temas (en oscuro ya hay overrides `html.dark .text-*-700`, confirmar que aplican).
  - Fondos `bg-gray-800`, `bg-zinc-*`, `bg-slate-*` sólidos sin variante.
  - `border-*` sin par.
  Donde falte, añadir `dark:` variant o usar token `kyro-*`.

## Restricciones

- **No romper layout.** Solo color/superficie/contraste. No cambiar grids, spacing,
  estructura de componentes (salvo migrar `bg-kyro-panel`→`.premium-kpi` en las 2 KpiCard).
- **No tocar backend.**
- Mantener la identidad: oro `#ffc200` para activos, indigo `#6366f1` para acentos.
- Modo oscuro debe quedar **igual o mejor** que ahora (no degradar).

## Verificación (obligatoria)

Playwright sobre VPS `https://app.kyrocodelabs.cloud` (admin: `adminprueba@gmail.com` /
`adminadmin`), en **ambos temas**, capturando screenshots de: Dashboard, Estadísticas,
Inventario, Reportes. El toggle de tema está en el header (icono sol/luna). Confirmar:
- Modo claro: tarjetas blancas glassy, texto oscuro legible, sin "islas negras".
- Modo oscuro: sin regresiones.
- Contraste de texto AA en ambos.

## Archivos

- `src/index.css` (fundación + clases componente) — núcleo del cambio
- `src/pages/DashboardPage.tsx` (KpiCard → premium-kpi)
- Páginas con hardcodeos sueltos detectados en el barrido (lista a confirmar por Codex)
