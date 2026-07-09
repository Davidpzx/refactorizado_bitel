# TICKET-021 — VerAgente: hairlines de color por card + botonera multicolor

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **frontend-design**
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` (`VerAgentePage`) · legacy `E:\laragon\www\sistema-rolando-salas` (`gerencia/ver_agente.php`)
- **Referencia visual:** captura `C:\xampp\htdocs\refactor_principal\legacy\...013*.png`

## Contexto
La ficha de agente del legacy (captura 013) usa **hairline superior de color por sección**: cyan la ficha personal, ámbar la info laboral, púrpura la ficha RRHH, naranja los contactos de emergencia; y una botonera de acciones donde cada botón tiene su color glass (Editar verde, Certificado ámbar, Dispositivo púrpura, Historial gris). La funcionalidad del refactor ya está completa y verificada (adelantos, boletas, perfil RRHH, documentos, token, reset dispositivo — NO construir nada de eso); hay 3 `confirm()` nativos (los cubre el ticket 016, coordinar para no pisarse).

## Alcance
1. Aplicar los hairlines superiores de color por card siguiendo el mapa del legacy (agregar variante `topAccent` al componente de card si no existe — patrón reutilizable para el ticket 022).
2. Botonera de acciones con los colores glass del legacy (variants `glassSuccess/Warning/Indigo` ya existen en `ui/button.tsx`).
3. Verificar que boletas + perfil RRHH quedan visualmente integrados (ya existen funcionalmente).
4. Iconos de cada acción semánticos (Pencil editar, Award certificado, Smartphone dispositivo, History historial).

## Criterio de aceptación
Comparación lado a lado contra la captura 013 en el PR; cada sección con su color legacy; cero regresiones funcionales (smoke test de la página).
