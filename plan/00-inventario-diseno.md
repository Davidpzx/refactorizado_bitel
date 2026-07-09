# 00 — Inventario de Diseño: Legacy (VITALTEL/sis_bipay) vs Refactorizado (SIS-KYRO)

> **Fecha:** 2026-07-08 · **Agente:** inventario visual (cuenta titan)
> **Fuentes usadas:** capturas reales FireShot del legacy corriendo (`C:\xampp\htdocs\refactor_principal\legacy\*.png`, 33 imágenes), código legacy (`E:\laragon\www\sistema-rolando-salas\includes\header.php`, `footer.php`, `estilos.css` — 1377 líneas de tema), código refactor (`frontend/src/index.css`, `AppLayout.tsx`, `PageHeader.tsx`, `ui/button.tsx`, `ui/dialog.tsx`, `DashboardPage.tsx`, censo completo de imports de iconos).
> **Nota de entorno:** las skills `headroom`, `superpowers`, `frontend-design` y `agentbrowser` **no existen en este entorno** (skills disponibles: dataviz, verify, code-review, etc.). Se trabajó con código + capturas. Ningún servidor local respondió (puertos 80/3000/5173/8000 → sin respuesta), así que no hubo comparación en vivo.

---

## 1. Identidad visual del LEGACY — tokens concretos

Identidad: **"Ultra Dark Premium"** — base Tailwind Zinc, acento dorado Bitel, secundario índigo, toques cyan neón. Definida casi por completo en `includes/estilos.css` + estilos inline en `header.php`.

### 1.1 Paleta (hex exactos del código)

| Token | Valor | Uso |
|---|---|---|
| Fondo body | `#09090b` (Zinc 950) + radial-gradients índigo `rgba(99,102,241,.04)` y dorado `rgba(255,194,0,.04)` fijos | fondo global dark |
| Panel / card | `#18181b` (Zinc 900), borde `rgba(255,255,255,0.08)` | `.card`, `.glass-panel` |
| Elevated / hover | `#27272a` (Zinc 800) | sidebar-link hover/active |
| Borde input | `#3f3f46` (Zinc 700) | inputs estilo Stripe |
| Texto principal | `#f4f4f5` / body `#e4e4e7` / muted `#a1a1aa` | Zinc 100/200/400 |
| **Acento primario** | `#ffc200` (dorado Bitel) | logo, sección activa del menú, botón principal de acción, avatar |
| **Acento secundario** | `#6366f1` (Indigo 500), gradiente botón `#6366f1→#4f46e5` | focus ring, btn-primary, PIN modal |
| Cyan neón | `#22d3ee` / `#06b6d4` | links "ver", encabezados de tabla, botones glass-cyan |
| Semánticos | success `#22c55e`/`#4ade80`, danger `#ef4444`/`#f87171`, warning `#f59e0b`/`#fbbf24`, purple `#8b5cf6`/`#c084fc`, sky `#38bdf8` | badges glass, alertas, diferencias +/− |
| Modo claro | fondo `#f0f4f8`, **sidebar azul corporativo Bitel `rgba(0,53,128,0.95)`**, tabs activos `#ffc200` | tema alterno |

### 1.2 Tipografía
- **Display:** `Orbitron` 700 — SOLO para el logo/marca del sidebar (`.nav-logo-dasam`, 1.6rem, color `#ffc200`, letter-spacing 1px).
- **UI:** `Inter` 400/500/600/800 en todo lo demás.
- Títulos de sección del menú: 0.70rem, weight 800, uppercase, letter-spacing 1.5px, **borde izquierdo 3px `#ffc200`** + gradiente `rgba(255,194,0,0.1)→transparent`.
- Encabezados de tabla: uppercase, ~0.68–0.72rem, letter-spacing amplio, colores neón por columna (cyan/ámbar/verde).
- Códigos de tienda: monospace (`Courier New`), badge `#1e293b`/`#e2e8f0` (`.badge-codigo-tienda`).

### 1.3 Radios y sombras
| Elemento | Radio | Sombra |
|---|---|---|
| Cards/paneles | `12px` | `0 4px 20px -2px rgba(0,0,0,0.5)` |
| Sidebar (flotante) | `16px` | — (glass: `rgba(9,9,11,0.6)` + `backdrop-filter: blur(20px)`) |
| Botones / inputs / links de menú | `8px` | inputs `0 1px 2px rgba(0,0,0,0.3)` |
| Badges glass | `6px` | — |
| Dropdowns campanitas | `12–14px` | `0 20px 40px rgba(0,0,0,.6)` a `0 24px 48px rgba(0,0,0,.7)` |
| Píldoras (badge contador) | `999px` | — |

### 1.4 Patrones de componentes (verificados en capturas)
- **Sidebar:** 260px, flotante (top/left 1rem, alto `calc(100vh-2rem)`), glassmorphism, secciones GERENCIA/ADMINISTRACIÓN/INVENTARIO/OPERACIONES/CONFIGURACIÓN, link activo con **borde izquierdo 3px dorado** + gradiente, auto-scroll al item activo, badges rojos pulsantes (`pulse-red` keyframes) con contadores vivos, campanitas dropdown (Notificaciones ámbar, Anomalías de Caja rojo, Aprobar Traslados índigo/cyan), tarjeta de usuario con avatar circular dorado + toggle tema 🌙/☀️.
- **KPI cards (Dashboard, captura legacy_01):** card oscura con **borde izquierdo grueso de color por tipo** — Total azul, Físico Esperado cyan, Declarado verde, Diferencia gris; dinero digital con icono de color (Yape rayo púrpura, Bipay tarjeta azul, Transferencia banco verde); banner "Ganancia Total" con borde verde y monto verde grande a la derecha.
- **Cards de detalle (VerAgente, captura 013):** **hairline superior de color por sección** (cyan la ficha, ámbar info laboral, púrpura ficha RRHH, naranja contactos de emergencia) + botones de acción cada uno con su color glass (Editar verde, Certificado ámbar, Dispositivo púrpura, Historial gris).
- **Tablas (capturas 007, 021):** fondo transparente sobre card oscura, thead uppercase pequeño, filas hover Zinc 800, precios en **amarillo**, saldos negativos en rojo, financiera como badge índigo (Krece/PayJoy), estado como badge-glass ámbar `PENDIENTE`, acción como botón glass verde `Confirmar`. Divisores de sección de tabla con fondo `rgba(255,194,0,0.04)` y texto dorado uppercase.
- **Botones:** primario índigo degradado; **botón de acción principal por página frecuentemente dorado sólido `#ffc200` con texto oscuro** (FILTRAR, GUARDAR ADELANTO, ACTUALIZAR REPORTE); familia completa `btn-glass-{cyan,amber,green,red,purple}` (fondo 10%, borde 35%, texto pastel); botones outline con fondo `rgba(24,24,27,0.5)` + blur.
- **Badges:** familia `badge-glass-*` (fondo 15%, borde 35%, texto pastel, radio 6px, weight 600); contadores `rounded-pill` rojos con animación `pulse-red`.
- **Modales SweetAlert2:** SIEMPRE tematizados — `background:'#18181b'`, `color:'#e4e4e7'`, confirm dorado `#ffc200` o verde/rojo según acción, cancel `#3f3f46`; modal PIN de autorización con borde índigo, icono candado Phosphor `ph-fill ph-lock-key`, inputs custom dark con focus índigo. **Es un rasgo de identidad fuerte del sistema.**
- **Micro-interacciones:** `pulse-red` (badges), `bell-shake` (campana), shimmer skeleton, hover lift en glass-panel, botones radio "LO ENTREGUÉ / EN TIENDA" con resplandor neón verde/ámbar al seleccionar.
- **Scrollbar:** 6px, thumb `#3f3f46`.

### 1.5 Iconografía del legacy
- **Librería principal: Phosphor Icons** (`@phosphor-icons/web@2`) — pesos `ph` (regular), `ph-fill`, `ph-bold`. Bootstrap Icons cargado como secundaria.
- Uso **semántico y consistente** en el sidebar: `ph-squares-four` Dashboard, `ph-trend-up` Productividad, `ph-megaphone` CRM, `ph-currency-circle-dollar` Precios, `ph-storefront` Tiendas, `ph-users` Usuarios, `ph-identification-card` Personal, `ph-calendar-check` Asistencias, `ph-money` Planilla, `ph-ticket` Tickets, `ph-gear-fine` Comisiones, `ph-buildings` Comisiones Empresa/Perfil, `ph-handshake` Financieras, `ph-bank` Reporte BCP, `ph-wallet` Bipay, `ph-chart-line-down` Churn, `ph-map-pin` Mapa de Calor, `ph-clipboard-text` Registro/Bitácora, `ph-package` Ingreso Stock, `ph-stack` Ver Inventario, `ph-file-plus` Reporte Diario, `ph-qr-code` QR Asistencia, `ph-receipt` Facturación, `ph-files` Comprobantes, `ph-plugs-connected` Integrador, `ph-sign-out` Salir, `ph-bell`/`ph-fill ph-bell` notificaciones, `ph-siren` anomalías.

---

## 2. Identidad actual del REFACTORIZADO — qué replica y qué difiere

Stack visual: Tailwind CSS 4 (tokens en `@theme` de `index.css`, sin tailwind.config), **lucide-react** para iconos, dark mode por clase `html.dark`.

### 2.1 Tokens que YA replican el legacy (fieles)
- Namespace `--color-kyro-*` calca la paleta: base `#09090b`, panel `#18181b`, elevated `#27272a`, gold `#ffc200`, indigo `#6366f1`, semánticos idénticos (`#22c55e/#ef4444/#f59e0b/#06b6d4`), bordes `rgba(255,255,255,0.08)` / `#3f3f46`.
- Radios legacy replicados: `--radius-kyro-sm:5px / kyro:8px / lg:12px / xl:16px`. Sombras: `--shadow-kyro-card: 0 4px 20px -2px rgb(0 0 0/0.5)` (idéntica) y popover `0 8px 24px rgb(0 0 0/0.8)`.
- Body dark: mismo `#09090b` + **los mismos dos radial-gradients** índigo/dorado con `background-attachment: fixed`. Fuentes Inter + Orbitron cargadas en `index.html`.
- Paleta KPI dedicada `--color-kpi-*` (total/esperado/declarado/yape/bipay/transfer/ganancia) — el Dashboard replica 1:1 los KPI del legacy: mismos títulos, acento por tipo, iconos equivalentes (Zap para Yape, CreditCard para Bipay, Landmark para Transferencia), `ProfitBanner` para Ganancia, botón **`variant="gold"`** para Filtrar (gradiente `#ffd028→#ffc200`, texto oscuro — clon del botón dorado legacy) y `glassSuccess` para Exportar.
- `ui/button.tsx` reimplementa la familia glass del legacy: `glassInfo/Success/Warning/Danger/Indigo` (fondo 10%, borde 35–40%, texto pastel, blur, hover lift) + `gold`.
- Sidebar (`AppLayout.tsx`): 260px, glass (`kyro-glass` = `rgba(9,9,11,0.75)` + blur 20px), **las 5 secciones del legacy en su orden y labels** (comentario en código lo declara explícito), separador de sección con borde izquierdo 3px dorado, link activo `border-l-[3px] border-kyro-gold` + icono dorado, badges pulsantes con contadores vivos (Control Center), tarjeta de usuario con avatar dorado degradado, toggle tema luna/sol dorado, botón Notificaciones ámbar, "Aprobar Traslados" cyan, logout rojo glass, auto-scroll al item activo. "Gerencia"→"Mi Panel" según rol, como el legacy.
- `PageHeader`: título en **Orbitron** con acento dorado (icono en caja glow o barrita lateral degradada) — extiende Orbitron más allá del logo, coherente con la identidad.
- `Dialog`: panel `zinc-900/95`, radio 16px, sombra profunda y **hairline superior degradado índigo→dorado** (motivo también usado en `premium-surface` y `public-premium-card`) — un refinamiento propio que respeta la identidad.
- Micro-detalles portados: `badge-pulse` (equivale a `pulse-red`), scrollbar fino oscuro, focus ring índigo doble (`0 0 0 2px #09090b, 0 0 0 4px rgba(99,102,241,.4)` — idéntico), tinte dorado del icono nativo del date-picker, overrides dark exhaustivos para utilidades Tailwind.
- Login: `public-premium-shell` (gradientes índigo/dorado + retícula) y `public-premium-card`, marca en Orbitron.

### 2.2 Tokens/patrones que DIFIEREN
| # | Divergencia | Gravedad |
|---|---|---|
| 1 | **Iconos: lucide-react (trazo lineal) en vez de Phosphor (con pesos fill/bold/duotone)**. Cambia la textura visual de todo el sistema y varios mapeos son incorrectos (ver §4). | Alta |
| 2 | **Confirmaciones: `confirm()`/`window.confirm()` nativo del navegador en ~30 llamadas** (eliminar agente/tienda/usuario/reporte/ticket/traslado, aprobar edición, recuperar tardanza…). El legacy usa SweetAlert2 dark tematizado con botones de color semántico en el 100% de los casos. Es la ruptura de identidad más visible en el uso diario. | **Alta** |
| 3 | Modo claro: el legacy pinta el **sidebar azul corporativo Bitel `rgba(0,53,128,.95)` con texto blanco** y tabs activos dorados; el refactor usa sidebar blanco glass. Se pierde el rasgo corporativo del tema claro. | Media |
| 4 | Sidebar no flotante: el legacy la separa 1rem del borde con radio 16px ("tarjeta flotante"); el refactor la pega al borde (aside full-height). Matiz menor pero perceptible. | Baja |
| 5 | Logo: el legacy muestra el logo de empresa (o SVG dorado custom) + razón social; el refactor usa un **icono lucide `Users` genérico en caja dorada** como marca "SIS-KYRO". | Media |
| 6 | Modal PIN de autorización (`solicitarAutorizacion` con DNI+PIN, candado índigo, auto-focus, letter-spacing 8px en PIN): no se encontró equivalente visual en el refactor. | Media (verificar si el flujo existe) |
| 7 | El refactor introduce en modo claro un lenguaje "glass claro" (`premium-surface`, blur 18px, fondos `rgba(255,255,255,.78)`) que no existe en el legacy — es una **mejora** direccional, no una pérdida, mientras el dark siga siendo el canónico. | — |

### 2.3 Estimación de fidelidad global
- **Modo oscuro: ~85% fiel.** Paleta, radios, sombras, sidebar, KPIs, botonería glass y dorado, badges y animaciones están portados con intención explícita de paridad. Lo que resta lo pierden los iconos (librería y mapeos) y los `confirm()` nativos.
- **Modo claro: ~60%.** Correcto y legible, pero sin el sidebar azul Bitel ni los tabs dorados; adopta una estética glass propia.

---

## 3. Tabla pantalla por pantalla

Leyenda fidelidad: **fiel** (réplica) / **mejorada** (respeta identidad y la pule) / **degradada** (existe pero pierde identidad) / **genérica** / **faltante** / **parcial**.

| Pantalla legacy | Refactor | ¿Existe? | Fidelidad visual | Notas |
|---|---|---|---|---|
| Login (`auth/`) | `LoginPage` | Sí | **Mejorada** | `public-premium-shell` con gradientes índigo/dorado + retícula, marca Orbitron. Identidad respetada. |
| Dashboard (`panel_gerencia.php`) | `DashboardPage` | Sí | **Fiel** | KPIs con acento por tipo idéntico a captura legacy_01, MoneyGroup Yape/Bipay/Transfer, ProfitBanner, Filtrar dorado + Exportar verde glass. Referente de cómo portar el resto. |
| Productividad (`estadisticas_ventas.php`) | `EstadisticasPage` | Sí | Parcial* | Usa Recharts + iconos correctos (TrendingUp, BarChart2). *Fidelidad de charts no verificada en vivo. |
| CRM (`crm_dashboard.php`) | `CrmPage` | Sí | Parcial* | Iconos semánticos (Megaphone, Star, MessageCircle). El legacy lo destaca en púrpura `#c084fc` en el menú; el refactor no. |
| Precios (`revisar_stock.php`) | `RevisarStockPage` | Sí | Parcial* | Legacy (captura 007): tabla agrupada por tienda con chips PUNDA/ACCESORIO y botón "Fijar" glass índigo por fila + badge contador rojo en menú. Refactor tiene la ruta y badge vivo. |
| Historial admin (dentro de panel_gerencia) | `HistorialPage` | Sí | Parcial* | Filas con semáforo (amarillo pendiente / rojo descuadre) replicadas con border-l-4 — patrón legacy respetado. Usa `confirm()` nativo (degrada). |
| Mi Reporte Personal (`mi_historial.php`) | `MiHistorialPage` | Sí | Parcial* | Panel equipo T1.2 "jefe de tienda" aún sin UI (handoff). `window.confirm` para Recuperar Tardanza (degrada). |
| Tiendas (`tiendas.php`) | `TiendasPage` | Sí | Parcial* | Iconos correctos (Store, MapPin, LocateFixed). `confirm()` nativo en eliminar. |
| Usuarios (`usuarios.php`) | `UsuariosPage` | Sí | Parcial* | Iconos correctos (KeyRound, ShieldCheck). `confirm()` nativo. |
| Personal (`gestionar_agentes.php`) | `AgentesPage` | Sí | Parcial* | `confirm()` nativo en eliminar. |
| Ver Agente (ficha) | `VerAgentePage` | Sí | **Parcial** | Legacy (captura 013) tiene cards con hairline superior de color por sección + botonera de acciones multicolor. Refactor: T2.5 boletas/ficha RRHH sin UI (handoff); 3 `confirm()` nativos. Verificar que se repliquen los top-borders por color. |
| Asistencias (`panel_asistencias.php` con pestañas) | `AsistenciasPage` + `/control` + `/liquidacion` + `/revisar-fotos` | Sí | Parcial | Legacy: pestañas dentro de una página; refactor: rutas separadas accesibles desde la cabecera (decisión documentada en AppLayout). Aceptable si la navegación interna se ve como tabs. |
| Planilla (`planilla_agentes.php`) | `PlanillaPage` | Sí | Parcial* | Iconos correctos (DollarSign, FileText). |
| Tickets (`tickets_emitidos.php`) | `TicketsPage` + `TicketImpresionPage` | Sí | Parcial* | `window.confirm` al anular (degrada). |
| Comisiones (`configurar_comisiones.php`) | `ComisionesPage` | Sí | **Parcial** | T1.3: editores de rangos PLAN/EQUIPO y bipay/krece/payjoy con backend listo y **sin UI** (handoff). |
| **Comisiones Empresa (`comisiones_empresa.php`)** | — | **No encontrado** | **Faltante** | No hay ruta ni página dedicada en `App.tsx`. Confirmar si se fusionó en ComisionesPage; si no, es gap visual y funcional. |
| Financieras (`panel_financieras.php`) | `PanelFinancierasPage` | Sí | Parcial* | Legacy (captura 021): 3 KPI con hairline superior amarillo/verde/índigo, badges Krece/PayJoy índigo, precios amarillos, saldos rojos, Confirmar verde glass. Refactor usa Handshake (icono correcto) pero `window.confirm` (degrada). |
| Reporte BCP (`reporte_bcp.php`) | `ReporteBcpPage` | Sí | Parcial* | Legacy lo tiñe sky `#38bdf8` en menú para rol tienda. |
| Bipay/Anypay (`panel_bipay.php`) | `PanelBipayPage` (+redirect `/cuadre-bitel`) | Sí | Parcial* | Set de iconos rico y semántico (Wallet, Scale, BellRing…). `confirm()` en eliminar cuenta. |
| Churn/Postpago (`panel_postpago.php`) | `PostpagoPage` | Sí | Parcial* | Legacy usa `ph-chart-line-down`; refactor Signal (aceptable). |
| Mapa de Calor (`mapa_calor.php`) | `MapaCalorPage` | Sí | Parcial* | Página usa MapPin correcto; el **menú** usa Activity (incorrecto, ver §4). |
| Registro de Datos RRHH (`public_onboarding.php`) | — | **Dudoso** | **Parcial/Faltante** | Existe `PostulacionPublicaPage` (`/postular`) que cubre la postulación pública (captura 024), pero el onboarding RRHH (link púrpura del menú legacy) no tiene ruta visible. |
| Postulantes (badge en Personal legacy) | `PostulacionesPage` (`/admin/postulaciones`) | Sí | Mejorada | En legacy era un badge dentro de Personal; el refactor le da página + entrada de menú con badge vivo. |
| Ingreso Stock (`registrar_stock.php`) | `InventarioForm` (dentro de Inventario) | Parcial | Parcial | En legacy es página propia del menú; en refactor no hay entrada "Ingreso Stock" en el sidebar. |
| Ver Inventario (`ver_inventario.php`) | `InventarioPage` + `MatrizInventarioPage` | Sí | Parcial* | Matriz es extra del refactor (mejora). |
| Bitácora Stock (`ver_bitacora_stock.php`) | `BitacoraStockPage` | Sí | Parcial* | Iconos semánticos correctos. |
| Reporte Diario (`nuevo_reporte.php` / editar cuadre) | `NuevoReportePage` / `EditarReportePage` | Sí | **Parcial — pantalla crítica** | Es la pantalla más rica del legacy (captura 004): 5 secciones numeradas con encabezado de color (azul/verde/ámbar/púrpura), toggles Ext/Mig/Upg/eSIM, panel lateral CUADRE FINAL con header dorado, EFECTIVO ESPERADO cyan gigante, par de botones "Lo Entregué/En Tienda" con glow, banner TOTAL SISTEMA cyan. Requiere verificación en vivo campo por campo; `window.confirm` al cerrar caja (degrada). |
| QR Asistencia (`qr_asistencia.php`) | `QrDisplayPage` | Sí | Parcial* | Captura 028 muestra el patrón de página QR pública. |
| Terminal asistencia (`asistencia.php`) | `TerminalAsistenciaPage` | Sí | Parcial* | |
| Perfil de Empresa (`configuracion_empresa.php`) | `ConfiguracionPage` | Sí | Parcial | Verificar si Facturación Electrónica vive aquí. |
| Facturación Electrónica (`configuracion_facturacion.php`) | ¿dentro de `ConfiguracionPage`? | Dudoso | Parcial/Faltante | Sin ruta propia en `App.tsx`. |
| Comprobantes (`comprobantes_emitidos.php`) | `ComprobantesPage` | Sí | Parcial* | |
| Integrador Bipay (`configuracion_integrador.php`) | `IntegradorPage` | Sí | Parcial* | Plug vs `ph-plugs-connected` — aceptable. `window.confirm` al regenerar token (degrada: acción sensible que en legacy sería SweetAlert). |
| Traslados (dropdown "Aprobar Traslados" del sidebar legacy) | `TrasladosPage` + botón sidebar | Sí | Mejorada | Página dedicada + acceso rápido cyan en el pie del sidebar (respeta el color legacy `#22d3ee`). `confirm()` nativo (degrada). |
| — (extras refactor) | `ClientesPage`, `ChipsGestionPage`, `KardexInventarioPage`, `DiagnosticoPage`, `ReportesPage` | Sí | — | Sin equivalente 1:1 en el menú legacy; deben heredar los mismos tokens (lo hacen vía componentes ui/*). |

\* *"Parcial\*" = la página existe y está construida sobre el sistema kyro (tokens fieles); la fidelidad pixel-a-pixel contra su captura legacy no pudo verificarse en vivo (servidores caídos). El riesgo principal en todas ellas es iconografía y `confirm()` nativos, no la paleta.*

---

## 4. Auditoría de ICONOGRAFÍA

**Legacy:** Phosphor Icons web v2 (pesos regular/fill/bold — los estados "llenos" usan `ph-fill`, p. ej. campana con notificaciones) + Bootstrap Icons de respaldo. Iconos elegidos con criterio semántico por dominio (banco→`ph-bank`, dinero→`ph-currency-circle-dollar`, QR→`ph-qr-code`).

**Refactor:** lucide-react (trazo lineal, un solo peso). ~50 iconos distintos importados en ~45 archivos. La mayoría de páginas eligen bien; **el problema está concentrado en el sidebar (`AppLayout.tsx`), que es lo que el usuario ve siempre**.

### 4.1 Mapeos INCORRECTOS o genéricos en el sidebar (AppLayout.tsx líneas 37–78)

| Entrada | Legacy (Phosphor) | Refactor (lucide) | Problema | Fix lucide correcto |
|---|---|---|---|---|
| **Precios** | `ph-currency-circle-dollar` | `Package` 📦 | Un paquete para una pantalla de PRECIOS — genérico y engañoso; además duplica el icono de "Ver Inventario" | `CircleDollarSign` (ya importado en MoneyGroup) o `Tag` |
| **QR Asistencia** | `ph-qr-code` | `Clock` | Existía `QrCode` en lucide; Clock además ya se usa en "Asistencias" → dos entradas con el mismo icono | `QrCode` |
| **Asistencias** | `ph-calendar-check` | `Clock` | `CalendarCheck` existe en lucide (¡y ya se importa en AgenteSeguridadDialog!) | `CalendarCheck` |
| **Reporte BCP** | `ph-bank` | `FileText` | Genérico; el "banco" es el significado | `Landmark` (ya importado en el propio archivo) |
| **Financieras** | `ph-handshake` | `Landmark` | Intercambiado con BCP; `Handshake` existe en lucide (ya se usa en PanelFinancierasPage) | `Handshake` |
| **Personal** | `ph-identification-card` | `Users` | Duplica exactamente el icono de "Usuarios" → dos entradas adyacentes idénticas | `IdCard` / `Contact` (Contact ya se usa en AgenteForm) |
| **Mapa de Calor** | `ph-map-pin` | `Activity` | La propia página usa `MapPin`; el menú no | `MapPin` |
| **Perfil de Empresa** | `ph-buildings` | `Settings` | Genérico "engranaje"; la página usa `Building2` | `Building2` |
| **Comisiones** | `ph-gear-fine` | `TrendingUp` | TrendingUp ya connota productividad; legacy lo ve como "configurar" | `Settings2`/`SlidersHorizontal`, o mantener TrendingUp como decisión consciente |
| **Bipay/Anypay** | `ph-wallet` | `CreditCard` | `Wallet` existe (la página lo usa) | `Wallet` |
| **Planilla** | `ph-money` | `DollarSign` | Aceptable | — |
| **Reporte Diario** | `ph-file-plus` | `ClipboardList` | Aceptable (pierde el matiz "nuevo") | `FilePlus2` opcional |
| **Churn/Postpago** | `ph-chart-line-down` | `Signal` | Aceptable | `TrendingDown` opcional |
| **Logo/marca** | Logo empresa o SVG dorado custom | `Users` en caja dorada | **Icono genérico como logo** — el peor caso de "icono al azar" | Usar el SVG dorado del legacy (header.php:278-281, dos trazos `#ffc200`) o el logo de empresa |

### 4.2 Duplicados que confunden
- `Users`: logo + Usuarios + Personal (3 usos en el mismo sidebar).
- `Clock`: Asistencias + QR Asistencia.
- `History`: Historial + Mi Historial (aceptable, son gemelas).
- `Package`: Precios + Ver Inventario.

### 4.3 Pérdida del sistema de pesos
El legacy comunica estado con el peso del icono: campana `ph ph-bell` (gris, sin notifs) → `ph-fill ph-bell` + `bell-shake` (dorada, con notifs). lucide no tiene fills; el refactor lo compensa solo con color/badge. Si se quiere paridad total, **`@phosphor-icons/react` existe como paquete oficial** con los mismos nombres y prop `weight="fill|bold|regular"` — sería el port natural.

### 4.4 Dentro de las páginas
El uso de lucide en páginas es en general correcto y consistente (Trash2 para eliminar, Pencil editar, Eye ver, FileSpreadsheet excel, Printer imprimir, ChevronL/R paginación). No se detectaron iconos absurdos a nivel de página; el déficit es de **librería** (textura) y de **sidebar** (mapeo).

---

## 5. Recomendación por pantalla / componente

**Regla aplicada:** replicar tal cual, o mejorar SIN perder identidad. Nunca genérico.

### Globales (afectan todas las pantallas — hacer primero)
1. **Reemplazar los ~30 `confirm()`/`window.confirm()` por un `ConfirmDialog` propio** construido sobre el `Dialog` existente (que ya tiene la identidad: hairline índigo→dorado, zinc-900, radio 16px): título + icono semántico, botón confirmar en el color de la acción (rojo eliminar, verde aprobar, dorado guardar — como los `confirmButtonColor` del legacy), cancelar `#3f3f46`. Alternativa de menor esfuerzo: instalar `sweetalert2` + `sweetalert2-react-content` con un preset dark idéntico al legacy. Archivos afectados listados en §2.2-2.
2. **Decisión de iconos** (elegir una):
   - **Opción A (paridad máxima):** migrar a `@phosphor-icons/react` — mismos nombres que el legacy, pesos fill/bold, port mecánico.
   - **Opción B (mínimo viable):** quedarse en lucide pero corregir los 10 mapeos de la tabla §4.1 (casi todos los iconos correctos ya están importados en otros archivos del propio proyecto — es un cambio de ~15 líneas en `AppLayout.tsx`).
3. **Logo:** sustituir el `Users` de la marca por el SVG dorado del legacy (2 paths `#ffc200`, está en `header.php:278-281`) o el logo configurable de empresa que `ConfiguracionPage` ya administra.
4. **Modo claro:** pintar el sidebar con el azul corporativo `rgba(0,53,128,0.95)` + texto blanco y tabs/links activos dorados, como el legacy. Es 1 bloque CSS en `index.css` + los estilos condicionales de `AppLayout`.
5. **Modal PIN de autorización:** si el flujo DNI+PIN existe en el refactor, darle el tratamiento del legacy (candado índigo, PIN con letter-spacing 8px, borde índigo). Si no existe, anotarlo como gap funcional, no solo visual.

### Por pantalla
| Pantalla | Recomendación |
|---|---|
| Dashboard | **Replicada — no tocar.** Es la referencia de calidad del port. |
| Login | **Mantener mejora.** Respeta identidad y la eleva. |
| Nuevo/Editar Reporte (cuadre) | **Replicar tal cual el legacy** (captura 004): encabezados de sección numerados con color propio (1 azul, 2 verde, 3 ámbar, 4 gris, 5 púrpura), panel CUADRE FINAL con header dorado y "EFECTIVO ESPERADO" cyan grande, par Lo Entregué/En Tienda con glow verde/ámbar, banner TOTAL SISTEMA cyan. Verificación en vivo pendiente. |
| Ver Agente | Replicar los **hairlines superiores de color por card** (cyan/ámbar/púrpura/naranja) y la botonera multicolor (verde/ámbar/púrpura/gris). Completar UI de boletas + ficha RRHH (T2.5). |
| Financieras | Replicar los 3 KPI con hairline superior (amarillo pendiente / verde confirmado / índigo facturado) y badges Krece/PayJoy índigo; precios amarillos, saldos rojos. |
| Precios | Replicar chips de agrupación por tienda + botón "Fijar" índigo por fila. Cambiar icono del menú. |
| Asistencias | Mantener rutas separadas pero presentarlas como **pestañas** en la cabecera (PageTabs ya existe) para calcar la percepción del legacy. |
| Comisiones | Terminar editores de rangos (T1.3) usando tablas `kyro-table-head` + botones glass. **Resolver el faltante de Comisiones Empresa** (página nueva o sección dentro de Comisiones con el icono `Building2`). |
| Historial / Mi Historial | Mantener el semáforo de filas (ya fiel). Completar panel jefe de tienda (T1.2). Sustituir confirms. |
| Traslados / Postulantes / Matriz / Kardex / Diagnóstico | Son mejoras del refactor sin espejo exacto: mantener, asegurando componentes ui/* (ya lo hacen). |
| Resto (Tiendas, Usuarios, Personal, Planilla, Tickets, BCP, Bipay, Postpago, MapaCalor, Comprobantes, Integrador, Bitácora, Chips, Config) | Replicar es suficiente: ya montan sobre tokens fieles. Acción única: confirms + iconos de menú (§ Globales). |

---

## 6. PENDIENTE (no cubierto en este inventario)

- **Comparación en vivo:** ni el legacy (Laragon) ni el refactor (Vite/Laravel) estaban corriendo; no se pudo validar pixel-a-pixel ninguna pantalla renderizada del refactor (la evaluación del refactor es 100% por código, que es fiable para tokens pero no para layout fino). Las capturas del VPS de producción del refactor tampoco estaban disponibles.
- **Capturas legacy no revisadas una a una:** se analizaron en detalle 4 de 33 (legacy_01 Dashboard, 004 Editar Cuadre, 013 Ver Agente, 021 Financieras) — suficientes para extraer los patrones, pero las capturas 002-003, 005-006, 008-009, 012, 014-020, 022-028 quedan como referencia visual para el port pantalla-a-pantalla de la tabla §3.
- **Confirmar 3 dudas de alcance:** (a) ¿`comisiones_empresa.php` se fusionó o falta?, (b) ¿`configuracion_facturacion.php` vive dentro de ConfiguracionPage?, (c) ¿existe equivalente de `public_onboarding.php` (ficha RRHH pública)?
- **Charts:** fidelidad de Recharts (Estadísticas, Mapa de Calor) vs los charts del legacy no evaluada.
- Skills `headroom`/`superpowers`/`frontend-design`/`agentbrowser` no disponibles en este entorno (anotado arriba; no bloqueó el análisis).
