# TICKET-017 — Iconografía del sidebar: corregir 10 mapeos + logo de marca

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **frontend-design**
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada.
- **Repo:** refactor `C:\xampp\htdocs\refactorizado_bitel` (`frontend/src/components/AppLayout.tsx` líneas ~37–78)
- **Referencia obligatoria:** `plan/00-inventario-diseno.md` §4.1–4.3 (tabla exacta de mapeos).

## Contexto
El sidebar es lo que el usuario ve siempre y concentra los iconos incorrectos/duplicados. El legacy usa Phosphor con criterio semántico; el refactor (lucide) tiene 10 mapeos malos y 3 duplicados (`Users` ×3, `Clock` ×2, `Package` ×2). Además la marca usa un icono lucide `Users` genérico en caja dorada — el peor caso de "icono al azar".

## Alcance
1. Aplicar los fixes de la tabla §4.1: Precios→`CircleDollarSign`, QR Asistencia→`QrCode`, Asistencias→`CalendarCheck`, BCP→`Landmark`, Financieras→`Handshake`, Personal→`IdCard`, Mapa de Calor→`MapPin`, Perfil de Empresa→`Building2`, Bipay→`Wallet`, Comisiones→`Settings2`. Casi todos ya están importados en otros archivos del proyecto — es un cambio de ~15 líneas.
2. Logo de marca: sustituir el `Users` por el **SVG dorado del legacy** (dos trazos `#ffc200`, está en `includes/header.php:278-281` del legacy) con fallback al logo de empresa configurable que `ConfiguracionPage` administra (mismo criterio que el legacy: logo real si existe, SVG dorado si no).
3. Verificar que no quedan duplicados de icono entre entradas adyacentes del sidebar.

## Criterio de aceptación
Cada entrada del sidebar con icono semánticamente correcto y único donde corresponde; la marca muestra logo de empresa o el SVG dorado, nunca un icono de librería genérico; captura de pantalla del sidebar resultante en el PR.
