# TICKET-015 — Modal PIN de autorización con la estética legacy

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **frontend-design**
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si el presupuesto no alcanza, pedir subdivisión ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`

## Contexto
En el legacy, las acciones sensibles piden autorización **DNI + PIN con jerarquía** (admin > gerente > agente) vía un modal SweetAlert2 muy característico — **rasgo de identidad del sistema**: borde índigo, icono candado Phosphor `ph-fill ph-lock-key`, inputs dark custom, PIN con `letter-spacing: 8px`, auto-focus. Referencias legacy: `validar_autorizacion.php`, `api/verificar_pin_agente.php`, y el JS `solicitarAutorizacion` en `includes/` . El refactor tiene el backend (`auth/verify-pin`) pero el inventario de diseño no encontró equivalente visual.

## Alcance
1. **Verificar** dónde consume el frontend `verify-pin` hoy (export con PIN de HistorialPage, acciones admin, etc.) y qué UI usa.
2. Crear componente `PinAuthorizationDialog` sobre el `Dialog` kyro existente: candado índigo (lucide `LockKeyhole` o Phosphor si el ticket 018 se aprueba), input DNI + input PIN con letter-spacing 8px, auto-focus, manejo de error de PIN inválido con shake, jerarquía comunicada en el subtítulo.
3. Reemplazar todos los prompts/inputs ad-hoc de PIN por este componente.
4. Test de componente (o smoke E2E) del flujo: PIN correcto autoriza, incorrecto muestra error sin cerrar.

## Criterio de aceptación
Todas las autorizaciones PIN del frontend pasan por el componente nuevo; visual fiel al modal legacy (comparar contra el JS/capturas del legacy); cero prompts nativos.
