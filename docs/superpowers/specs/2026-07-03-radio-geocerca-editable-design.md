# Radio de geocerca editable por tienda

**Fecha:** 2026-07-03
**Gap:** P0 #8 de `docs/comparacion/GAP_ANALYSIS_MAESTRO_2026-07-02.md` — "Radio de geocerca por tienda no editable (fijo 60m)".

## Problema

`AsistenciaController` ya lee `radio_permitido` por tienda (con fallback hardcodeado a 60m) para validar el marcado GPS de asistencia, pero no existe forma de escribir ese valor: `Tienda::$fillable` no lo incluye y ningún controller lo valida ni expone en el formulario. La columna sí existe en la BD — solo falta el camino de escritura.

## Diseño

**Backend**
- Agregar `radio_permitido` a `Tienda::$fillable` (`backend/app/Models/Tienda.php`).
- Agregar regla `radio_permitido => 'nullable|numeric|min:1'` en `TiendaController::store` y `TiendaController::update`. Sin admin. Sin tope máximo (decisión del usuario: no limitar por arriba, solo exigir positivo).
- Sin migración: la columna ya existe.
- Sin cambio de permisos: el CRUD de Tienda ya es `role:admin` únicamente (decisión del usuario: solo admin edita esto).

**Frontend**
- Campo numérico "Radio de geocerca (metros)" en el formulario de tienda (`frontend` — página de administración de Tiendas). Placeholder/default 60 para tiendas nuevas, igual al fallback actual del backend.

**Sin cambios** en `AsistenciaController`: ya lee `radio_permitido` con fallback; empezará a recibir el valor real en cuanto exista.

## Testing

- Feature test: `PUT /tiendas/{id}` con `radio_permitido` válido persiste el valor.
- Feature test: `PUT /tiendas/{id}` con `radio_permitido` negativo/cero/no-numérico es rechazado (422).
- Feature test: `AsistenciaController` usa el valor guardado de la tienda en vez del fallback de 60 cuando la tienda tiene `radio_permitido` seteado.

## Fuera de alcance

- No se toca la lógica de cálculo de distancia GPS en sí, solo la fuente del radio permitido.
