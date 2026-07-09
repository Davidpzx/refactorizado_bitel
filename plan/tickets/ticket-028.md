# TICKET-028 — Polish visual: Terminal Asistencia dorada + CRM púrpura + icono duplicado

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **frontend-design**
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Origen:** QA visual ticket-026 (plan/04-qa-visual-D2.md hallazgo ALTO, plan/04-qa-visual.md Bloque A)

## Alcance
1. **Terminal Asistencia (`/terminal`, TerminalAsistenciaPage) — severidad ALTA:** toda la pantalla está retematizada en ROJO (logo, botón, PIN, marco de cámara/QR) cuando el legacy (`asistencia.php`) y el resto del sistema usan el dorado kyro. Es la única pantalla de ~35 que rompe la identidad "Ultra Dark Premium", y es la vista pública de uso diario de los agentes. Retematizar al dorado/identidad del sistema comparando contra el legacy.
2. **CRM (CrmPage):** el estado activo del sidebar/tabs no usa el púrpura `#c084fc` del legacy (confirmado en vivo, Bloque A). Aplicarlo.
3. **Icono duplicado:** Comprobantes y Facturación Electrónica comparten `Receipt` en el sidebar (AppLayout). Diferenciar con criterio semántico (p.ej. Facturación=Receipt como config del emisor, Comprobantes=FileText/Files como documentos emitidos — decidir mirando la iconografía legacy `ph-*`).

## Criterio de aceptación
Terminal usa la paleta del sistema (cero rojo salvo estados de error); CRM con su púrpura; iconos únicos por entrada de menú; `tsc`+`vite build` limpios; sin tocar lógica.
