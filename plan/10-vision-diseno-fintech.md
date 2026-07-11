# Visión de diseño — "Centro de Operaciones" (premium oscuro, tipo panel de control fintech)

## Diagnóstico (por qué se ve "ERP genérico" hoy)
El sistema ya tiene tokens de una identidad "Ultra Dark Premium" (`frontend/src/index.css`): base `#09090b`, dorado `#ffc200`, indigo `#6366f1`, glass (`.kyro-glass`), sombras de card marcadas. El problema no son los tokens — es que los **componentes no explotan esa base**: tablas, tarjetas KPI, badges y formularios siguen el layout por defecto de Bootstrap/Tailwind sin jerarquía propia (mismo tamaño de fuente en todo, bordes uniformes de 1px en todos lados, KPI cards que son solo un número + label sin contexto, iconos flotando sin agrupación visual). Eso es lo que lee como "genérico": la identidad está en las variables, no en el diseño de las pantallas.

## Referencia de dirección: no cream/serif, no negro+un-acento-random, no broadsheet de rayitas
La dirección es **panel de control de operaciones** — piénsalo como la sala de monitoreo de una agencia Bitel: mucho dato en vivo, jerarquía estricta entre "lo que importa ahora" y "el detalle de soporte", sensación de precisión y control, no de formulario administrativo.

### Referencia visual aportada por el usuario (dashboard "Aivora")
El usuario compartió una captura de un dashboard SaaS oscuro que confirma y afina la dirección. Lo que se toma de ahí (adaptado, no clonado — la paleta y el contenido siguen siendo los del negocio Bitel/Mundo Android, no violeta genérico de SaaS):
- **KPI cards con sparkline embebido**: cada card no es solo número + label — trae una mini-gráfica de tendencia (línea suave) integrada abajo del número, y un delta ("+3 este mes", "+12% vs mes anterior") en verde/rojo junto al label. Esto es lo que hoy falta más — nuestras KPI cards son número plano.
- **Glass real con halo de fondo**: fondo de app no 100% plano — un halo/gradiente radial muy sutil (radial-gradient oscuro con un tinte de color de marca al 4-8% opacity) detrás de los paneles, para que el glassmorphism (`.kyro-glass` ya existe) tenga contra qué desenfocar. Hoy el fondo es un negro plano `#09090b` sin ese halo, por eso el glass no se nota.
- **Radios generosos y consistentes**: cards con esquinas más redondeadas (16-20px) y padding más aireado que hoy — parte de por qué se ve "denso tipo hoja de cálculo" es el padding ajustado.
- **Avatares apilados** (`avatar stack`) para "quién está involucrado" — aplicable donde el legacy ya muestra agentes/usuarios en listas (tickets, traslados, planilla) en vez de solo nombres en texto.
- **Barra superior con búsqueda prominente**: search bar centrada arriba con placeholder específico del dominio (ej. "Buscar agente, reporte, ticket…") en vez de un buscador chico perdido en un canto.
- Mantener el dorado (`#ffc200`) como el único acento cálido reservado a dinero — en la referencia el acento es violeta parejo para todo; aquí NO se adopta el violeta como color de marca, se adopta la *técnica* (glass + halo + sparkline + radios), la paleta sigue siendo la de Bitel/Mundo Android ya definida arriba.

## Modo claro (mejorar, no solo oscurecer el dark)
Hoy el modo claro (`:root` en `index.css`) es plano: blanco `#ffffff`/`#f8fafc`, sin el mismo cuidado que el dark. Aplicar la misma técnica adaptada a claro:
- Halo de fondo: radial-gradient muy sutil con el dorado o indigo al 3-5% sobre `--color-kyro-base` claro (`#f8fafc`), para que `.kyro-glass` en claro (hoy `rgba(255,255,255,0.78)`) tenga el mismo efecto de profundidad que en oscuro.
- Mismas KPI cards con sparkline — la línea de tendencia en claro usa el trazo en el color semántico (success/danger) sobre fondo blanco, con sombra suave (`--shadow-kyro-card` ya existe, pero está calibrada para oscuro — en claro necesita una versión con opacidad menor, ej. `0 4px 20px -4px rgb(0 0 0 / 0.08)`, la actual `0.5` de opacidad se ve sucia sobre blanco).
- Mismos radios generosos y mismo padding que en oscuro — la mejora de layout no es exclusiva del dark.
- El dorado sigue siendo el acento de dinero también en claro; evitar que en fondo blanco el dorado pierda contraste — usar `--color-kyro-gold-ink` (`#1e293b`, ya existe) para texto sobre superficies doradas claras.

## Tokens (parten de lo que YA existe, se afinan, no se reinventan)
- **Color** (mantener/afinar los 6 ya existentes, no agregar más):
  - `--color-kyro-base` `#09090b` — fondo de app, casi negro puro (ya existe)
  - `--color-kyro-panel` `#18181b` — superficie de paneles/sidebar (ya existe)
  - `--color-kyro-elevated` `#27272a` — cards/modales elevados (ya existe)
  - `--color-kyro-gold` `#ffc200` — **acento único de "atención/acción positiva"** (dinero, ganancia, CTA primario) — reservarlo, no decorar con él
  - `--color-kyro-indigo` `#6366f1` — acento secundario para estados "informativo/en proceso" (no compite con el dorado)
  - Estados semánticos ya existen (success/danger/warning/info) — mantener, no crear paleta nueva
- **Tipografía** (definir 2 roles, hoy todo usa la misma fuente de sistema sin escala):
  - **Display/dato**: una fuente tabular con peso variable para números grandes (KPIs, montos) — ej. familia con `font-variant-numeric: tabular-nums` y tracking ligeramente negativo en tamaños grandes, para que los montos en soles se lean como "panel de trading", no como texto de formulario.
  - **Cuerpo/UI**: la sans actual está bien para labels/menús — pero necesita una escala tipográfica explícita (12/13 caption, 14 body, 16 subtítulo, 20-28 dato destacado) en vez del tamaño único actual.
- **Layout**: cards con jerarquía real — separar "número protagonista" de "contexto de soporte" (variación %, comparación con periodo anterior) en vez de KPI = número + label plano. Bordes solo donde separan secciones, no en cada celda (menos ruido de grilla tipo hoja de cálculo).
- **Firma/signature**: los montos en soles (ganancia, saldo, comisión) se tratan como el elemento protagonista de cada pantalla — tipografía tabular grande, color dorado reservado SOLO para eso (ganancia/dinero positivo), con micro-tendencia (↑/↓ + %) al lado. Es el "latido" del sistema: en cualquier pantalla, el dinero se reconoce de un vistazo porque solo el dinero usa ese tratamiento.

## Qué NO hacer
- No agregar una paleta nueva de colores "de marca" — ya existe una identidad, se refina.
- No mover a modo claro con azul genérico corporate — la dirección elegida es oscura.
- No decorar con el dorado cosas que no son dinero/CTA principal (evitar que pierda significado).
- No tocar aún: esto es la VISIÓN, no la ejecución. Ningún ticket se dispara sin que el usuario revise este documento primero.

## Siguiente paso (pendiente de que titan quede libre de SEC-01)
Delegar a titan, **modelo Fable 5, razonamiento BAJO** (es auditoría/planificación pantalla-por-pantalla contra esta visión, no implementación — regla del proyecto: Fable nunca implementa código): recorrer cada pantalla del refactor (dashboard, cuadre, inventario, CRM, planilla, facturación, etc.) y producir, por pantalla, tickets concretos de qué cambiar para alinearla con esta visión (jerarquía de KPI, escala tipográfica, uso del dorado reservado a dinero, limpieza de bordes). Salida: `plan/11-tickets-diseno-fintech.md`. La implementación de esos tickets, cuando se aprueben, es Sonnet 5 / Opus 4.8 según complejidad — nunca Fable.
